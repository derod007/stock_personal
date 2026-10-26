"""Independent next-open trades: no capital, position, sector or risk limits."""
import argparse,collections,hashlib,json,math
from pathlib import Path

def sha(p):return hashlib.sha256(Path(p).read_bytes()).hexdigest()
def valid(b):
    keys=('open','high','low','close','volume')
    return (all(isinstance(b.get(k),(int,float)) and math.isfinite(b[k]) for k in keys)
            and min(b[k] for k in keys[:-1])>0 and b['volume']>=0
            and b['low']<=min(b['open'],b['close'])<=max(b['open'],b['close'])<=b['high'])

def trade(signal,days,bars,event_days):
    r=dict(signal=signal,status='unresolved',filled=False,net_pct=None)
    entry=None;holding=0
    for day in days:
        if not signal['entry_day']<=day<=signal['end']:continue
        b=bars.get(day)
        reason=('reference_discontinuity' if day in event_days else 'missing_bar' if b is None else 'invalid_ohlcv' if not valid(b) else None)
        if reason:
            r.update(unresolved_day=day,unresolved_reason=reason)
            return r
        if entry is None:
            entry=b['open'];r.update(filled=True,entry_day=day,entry=entry)
        holding+=1;stop=entry*.95;target=entry*1.15
        hitstop=b['low']<=stop;hittarget=b['high']>=target
        ex=('stop',min(stop,b['open'])) if hitstop else ('target',target) if hittarget else ('time',b['close']) if day==signal['end'] else None
        if ex:
            r.update(status='closed',exit_day=day,exit_reason=ex[0],exit_price=ex[1],held_bars=holding,
                     net_pct=(ex[1]*.9985/(entry*1.0015)-1)*100,same_bar_order_unknown=hitstop and hittarget)
            return r
    r.update(unresolved_reason='calendar_window_missing')
    return r

def stats(rows):
    closed=[r for r in rows if r['status']=='closed'];xs=sorted(r['net_pct'] for r in closed)
    by=collections.defaultdict(list)
    for r in closed:by[r['signal']['symbol']].append(r['net_pct'])
    mean=lambda a:sum(a)/len(a) if a else None
    gains=sum(x for x in xs if x>0);losses=-sum(x for x in xs if x<0)
    return dict(signals=len(rows),known_fills=sum(r['filled'] for r in rows),closed=len(closed),
                unresolved=len(rows)-len(closed),unresolved_after_fill=sum(r['filled'] and r['status']!='closed' for r in rows),
                unresolved_reasons=dict(collections.Counter(r['unresolved_reason'] for r in rows if r['status']!='closed')),
                mean_pct=mean(xs),median_pct=(xs[(len(xs)-1)//2]+xs[len(xs)//2])/2 if xs else None,
                win_pct=mean([100*(x>0) for x in xs]),symbol_equal_mean_pct=mean([mean(a) for a in by.values()]),
                closed_symbols=len(by),exit_reasons=dict(collections.Counter(r['exit_reason'] for r in closed)),
                mean_held_bars=mean([r['held_bars'] for r in closed]),profit_factor_equal_notional=gains/losses if losses else None,
                same_bar_unknown=sum(r['same_bar_order_unknown'] for r in closed))

def main():
    p=argparse.ArgumentParser()
    for k in ('input','bars','output'):p.add_argument('--'+k,type=Path,required=True)
    a=p.parse_args()
    if a.output.exists():raise ValueError('New output required')
    d=json.loads(a.input.read_text());cache={}
    if d['price_basis']!='krx_tdd_ohlcv_adjustment_unverified':raise ValueError('Wrong basis')
    if d['days']!=sorted(set(d['days'])):raise ValueError('Calendar order')
    for sym,h in d['hashes']['bars'].items():
        f=a.bars/(sym+'.json')
        if sha(f)!=h:raise ValueError('Bar hash mismatch')
        rows=json.loads(f.read_text())['rows'];cache[sym]={b['day']:b for b in rows}
        if len(cache[sym])!=len(rows):raise ValueError('Duplicate bars')
    results=[]
    for s in d['signals']:
        i=d['days'].index(s['anchor'])
        if s['entry_day']!=d['days'][i+1] or s['end']!=d['days'][i+20]:raise ValueError('Window mismatch')
        results.append(trade(s,d['days'],cache[s['symbol']],set(d['reference_events'].get(s['symbol'],[]))))
    groups={'type1':[r for r in results if r['signal']['cluster']==1],
            'other_types':[r for r in results if r['signal']['cluster']!=1],'all_types':results}
    summary=dict(period=[d['days'][0],d['days'][-1]],sessions=len(d['days']),
                 scope='Independent returns, no capital or portfolio return',groups={k:stats(v) for k,v in groups.items()},
                 monthly_type1={m:stats([r for r in groups['type1'] if r['signal']['anchor'][:7]==m]) for m in sorted({r['signal']['anchor'][:7] for r in groups['type1']})},
                 inputs=dict(input_sha256=sha(a.input),source_sha256=sha(__file__),verified_bar_files=len(cache),centers=d['hashes']['centers']),
                 inherited_skips=d['skips'],limitations=['Saved PR113 entry quality and nonoverlap windows retained','Future-trained centers, not independent forward validation','Unknown trades not assigned zero return','Closed subset means may be biased by unresolved trades','No fixed monetary amount, no portfolio performance'])
    a.output.mkdir(parents=True)
    for name,obj in [('trades',results),('summary',summary)]:
        (a.output/(name+'.json')).write_text(json.dumps(obj,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(summary['groups'],ensure_ascii=False,indent=2))

if __name__=='__main__':main()
