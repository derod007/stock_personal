"""Read-only, decision-time retracement annotations on frozen independent trades."""
import argparse,collections,hashlib,json,math,random
from pathlib import Path

def sha(p):return hashlib.sha256(Path(p).read_bytes()).hexdigest()
def valid(b):
    keys=('open','high','low','close','volume')
    return (all(isinstance(b.get(k),(int,float)) and math.isfinite(b[k]) for k in keys)
            and min(b[k] for k in keys[:-1])>0 and b['volume']>=0
            and b['low']<=min(b['open'],b['close'])<=max(b['open'],b['close'])<=b['high'])

def band(d):
    if d<0:return 'above_high'
    if d<.382:return '0_38.2'
    if d<.5:return '38.2_50'
    if d<=.618:return '50_61.8'
    if d<=1:return '61.8_100'
    return 'below_low'

def feature(rows,anchor,event_days=()):
    past=[b for b in rows if b['day']<=anchor and valid(b) and b.get('is_complete',True)]
    if not past or past[-1]['day']!=anchor:return dict(status='unknown',reason='missing_anchor')
    if len(past)<60:return dict(status='unknown',reason='under60')
    win=past[-60:]
    if any(win[0]['day']<d<=anchor for d in event_days):return dict(status='unknown',reason='reference_inside60')
    highs=[];lows=[]
    for i in range(3,57):
        neighbors=win[i-3:i]+win[i+1:i+4]
        hi=all(win[i]['high']>b['high'] for b in neighbors)
        lo=all(win[i]['low']<b['low'] for b in neighbors)
        if hi and not lo:highs.append(i)
        if lo and not hi:lows.append(i)
    if not highs:return dict(status='unknown',reason='no_confirmed_high')
    h=highs[-1];before=[i for i in lows if i<h]
    if not before:return dict(status='unknown',reason='no_preceding_confirmed_low')
    l=before[-1];high=win[h]['high'];low=win[l]['low'];close=win[-1]['close']
    if high<=low:return dict(status='unknown',reason='nonpositive_swing')
    depth=(high-close)/(high-low)
    return dict(status='known',depth=depth,band=band(depth),selected=.382<=depth<=.618,
                low=low,high=high,close=close,low_day=win[l]['day'],high_day=win[h]['day'],
                low_confirmed=win[l+3]['day'],high_confirmed=win[h+3]['day'],anchor=anchor,
                levels={'38.2':high-.382*(high-low),'50':high-.5*(high-low),'61.8':high-.618*(high-low)})

def stats(rows):
    done=[r for r in rows if r['trade']['status']=='closed'];xs=sorted(r['trade']['net_pct'] for r in done)
    mean=lambda a:sum(a)/len(a) if a else None
    syms=collections.defaultdict(list)
    for r in done:syms[r['trade']['signal']['symbol']].append(r['trade']['net_pct'])
    return dict(candidates=len(rows),closed=len(done),unresolved=len(rows)-len(done),
                closed_symbols=len(syms),mean_pct=mean(xs),
                median_pct=(xs[(len(xs)-1)//2]+xs[len(xs)//2])/2 if xs else None,
                win_pct=mean([100*(x>0) for x in xs]),
                stop_pct=mean([100*(r['trade']['exit_reason']=='stop') for r in done]),
                symbol_equal_mean_pct=mean([mean(v) for v in syms.values()]))

def percentile(xs,q):
    xs=sorted(xs);f=(len(xs)-1)*q;i=int(f);j=min(i+1,len(xs)-1)
    return xs[i]*(1-f+i)+xs[j]*(f-i)

def bootstrap(rows):
    totals=collections.defaultdict(lambda:[0.,0,0.,0])
    for r in rows:
        if r['feature']['status']!='known' or r['trade']['status']!='closed':continue
        v=totals[r['trade']['signal']['symbol']];i=0 if r['feature']['selected'] else 2
        v[i]+=r['trade']['net_pct'];v[i+1]+=1
    syms=sorted(totals);rng=random.Random(1042026);diffs=[];selected_means=[]
    if not syms:return dict(valid=0)
    for _ in range(1000):
        a=[0.,0,0.,0]
        for sym in rng.choices(syms,k=len(syms)):
            for i,v in enumerate(totals[sym]):a[i]+=v
        if a[1] and a[3]:
            selected_means.append(a[0]/a[1]);diffs.append(a[0]/a[1]-a[2]/a[3])
    ci=lambda xs:[percentile(xs,.025),percentile(xs,.975)] if xs else None
    return dict(valid=len(diffs),difference_pp_ci95=ci(diffs),selected_mean_pct_ci95=ci(selected_means),seed=1042026)

def summarize(rows):
    known=[r for r in rows if r['feature']['status']=='known']
    selected=[r for r in known if r['feature']['selected']];other=[r for r in known if not r['feature']['selected']]
    unknown=[r for r in rows if r['feature']['status']=='unknown']
    return dict(all=stats(rows),selected=stats(selected),other_known=stats(other),feature_unknown=stats(unknown),
                unknown_reasons=dict(collections.Counter(r['feature']['reason'] for r in unknown)),
                candidate_retention_pct=100*len(selected)/len(rows) if rows else None,
                bands={k:stats([r for r in known if r['feature']['band']==k]) for k in ['above_high','0_38.2','38.2_50','50_61.8','61.8_100','below_low']},
                bootstrap=bootstrap(rows))

def main():
    p=argparse.ArgumentParser()
    for n in ('input','bars','previous','output','protocol'):p.add_argument('--'+n,type=Path,required=True)
    a=p.parse_args()
    if a.output.exists():raise ValueError('New output required')
    data=json.loads(a.input.read_text());previous=json.loads((a.previous/'summary.json').read_text())
    if sha(a.input)!=previous['inputs']['input_sha256']:raise ValueError('Input differs from saved trades')
    hashes=json.loads((a.previous/'file-hashes.json').read_text())
    expected=[h for name,h in hashes.items() if name.endswith('/trades.json')]
    if expected!=[sha(a.previous/'trades.json')]:raise ValueError('Saved trade hash mismatch')
    signals={(s['symbol'],s['anchor']):s for s in data['signals']};rows=[];cache={}
    for t in json.loads((a.previous/'trades.json').read_text()):
        if t['signal']['cluster']!=1:continue
        s=t['signal'];sym=s['symbol'];anchor=s['anchor']
        if signals[(sym,anchor)]!=s:raise ValueError('Signal differs')
        if sym not in cache:
            path=a.bars/(sym+'.json')
            if sha(path)!=data['hashes']['bars'][sym]:raise ValueError('Bar hash mismatch')
            bars=json.loads(path.read_text())['rows']
            if [b['day'] for b in bars]!=sorted({b['day'] for b in bars}):raise ValueError('Unsorted or duplicate dates')
            cache[sym]=bars
        f=feature(cache[sym],anchor,data['reference_events'].get(sym,[]))
        rows.append(dict(trade=t,feature=f))
    summary=summarize(rows)
    summary['halves']={name:summarize([r for r in rows if (r['trade']['signal']['anchor']<'2024-01-01')==prior]) for name,prior in [('2023H2',True),('2024H1',False)]}
    summary['provenance']=dict(input_sha256=sha(a.input),trades_sha256=sha(a.previous/'trades.json'),script_sha256=sha(__file__),protocol_sha256=sha(a.protocol),bar_files_checked=len(cache),period=previous['period'])
    a.output.mkdir(parents=True)
    for name,obj in [('annotated',rows),('summary',summary)]:
        (a.output/(name+'.json')).write_text(json.dumps(obj,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(summary,ensure_ascii=False,indent=2))

if __name__=='__main__':main()
