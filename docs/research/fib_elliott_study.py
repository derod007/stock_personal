"""One fixed Elliott-inspired impulse/retracement/rebound hypothesis on daily TOP100."""
import argparse,collections,json
from pathlib import Path
from type1_opening_independent import valid,trade,stats,sha

def signal_at(rows,anchor,previous_session,events=()):
    past=[b for b in rows if b['day']<=anchor]
    good=[b for b in past if valid(b) and b.get('is_complete',True)]
    if not good or good[-1]['day']!=anchor:return dict(stage='missing_anchor')
    if len(good)<60:return dict(stage='under60')
    w=good[-60:]
    if w[-2]['day']!=previous_session:return dict(stage='missing_previous_session')
    if any(not valid(b) for b in past if b['day']>=w[0]['day']):return dict(stage='invalid_inside60')
    if any(w[0]['day']<d<=anchor for d in events):return dict(stage='reference_inside60')
    hs=[];ls=[]
    for i in range(3,57):
        nb=w[i-3:i]+w[i+1:i+4]
        hi=all(w[i]['high']>b['high'] for b in nb);lo=all(w[i]['low']<b['low'] for b in nb)
        if hi and not lo:hs.append(i)
        if lo and not hi:ls.append(i)
    if not hs:return dict(stage='no_high')
    h=hs[-1];before=[i for i in ls if i<h]
    if not before:return dict(stage='no_prior_low')
    l=before[-1];L=w[l]['low'];H=w[h]['high']
    if H<=L:return dict(stage='nonpositive_impulse')
    q=min(b['low'] for b in w[h+1:-1]);depth=(H-q)/(H-L)
    evidence=dict(low_day=w[l]['day'],high_day=w[h]['day'],low_confirmed=w[l+3]['day'],high_confirmed=w[h+3]['day'],
                  low=L,high=H,retracement_low=q,depth=depth,previous_high=w[-2]['high'],close=w[-1]['close'])
    if not .382<=depth<=.618:return dict(stage='retracement_outside',**evidence)
    if w[-1]['low']<H-.618*(H-L):return dict(stage='rebound_bar_too_deep',**evidence)
    if w[-1]['close']<=w[-2]['high']:return dict(stage='no_rebound',**evidence)
    return dict(stage='signal',**evidence)

def scan(candidates,bars,events):
    days=candidates['days'];seen=set();evaluations=[];signals=[]
    for i,day in enumerate(days):
        previous=days[i-1] if i else None
        for row in candidates['dates'][day]['rows']:
            sym=row['symbol'];past=bars[sym]
            prior=previous
            if prior is None:
                prior=max((b['day'] for b in past if b['day']<day),default=None)
            f=signal_at(past,day,prior,events.get(sym,[]))
            if f['stage']=='signal':
                pair=(sym,f['low_day'],f['high_day'])
                if pair in seen:f['stage']='duplicate_wave'
                else:
                    seen.add(pair)
                    s=dict(symbol=sym,anchor=day,wave=f,cluster='fib_elliott_only')
                    if i+20>=len(days):s['status']='period_end'
                    else:s.update(entry_day=days[i+1],end=days[i+20],status='eligible')
                    signals.append(s)
            evaluations.append(dict(symbol=sym,day=day,**f))
    return evaluations,signals

def main():
    p=argparse.ArgumentParser()
    for k in ('candidates','input','bars','output','protocol'):p.add_argument('--'+k,type=Path,required=True)
    a=p.parse_args()
    if a.output.exists():raise ValueError('New output required')
    data=json.loads(a.input.read_text());c=json.loads(a.candidates.read_text())
    if sha(a.candidates)!=data['hashes']['candidates'] or c['days']!=data['days']:raise ValueError('Candidate/calendar mismatch')
    if data['price_basis']!='krx_tdd_ohlcv_adjustment_unverified':raise ValueError('Wrong price basis')
    days=c['days']
    if days!=sorted(set(days)):raise ValueError('Invalid calendar')
    bars={};hashed={}
    for day in days:
        syms=[r['symbol'] for r in c['dates'][day]['rows']]
        if len(syms)!=100 or len(set(syms))!=100:raise ValueError('TOP100 count/duplicates')
    for sym in {r['symbol'] for day in days for r in c['dates'][day]['rows']}:
        path=a.bars/(sym+'.json');h=sha(path)
        if h!=data['hashes']['bars'].get(sym):raise ValueError('Bar hash mismatch')
        rows=json.loads(path.read_text())['rows']
        if [b['day'] for b in rows]!=sorted({b['day'] for b in rows}):raise ValueError('Unsorted/duplicate price dates')
        bars[sym]=rows;hashed[sym]=h
    evaluations,signals=scan(c,bars,data['reference_events'])
    trades=[]
    for s in signals:
        if s['status']!='eligible':continue
        trades.append(trade(s,days,{b['day']:b for b in bars[s['symbol']]},set(data['reference_events'].get(s['symbol'],[]))))
    summary=dict(period=[days[0],days[-1]],sessions=len(days),observations=len(evaluations),
                 stages=dict(collections.Counter(r['stage'] for r in evaluations)),
                 unique_signals=len(signals),period_end=sum(s['status']=='period_end' for s in signals),
                 results=stats(trades),monthly={m:stats([t for t in trades if t['signal']['anchor'][:7]==m]) for m in sorted({t['signal']['anchor'][:7] for t in trades})},
                 halves={n:stats([t for t in trades if (t['signal']['anchor']<'2024-01-01')==b]) for n,b in [('2023H2',True),('2024H1',False)]},
                 provenance=dict(input_sha256=sha(a.input),candidates_sha256=sha(a.candidates),bar_files=len(hashed),script_sha256=sha(__file__),trade_code_sha256=sha(Path(__file__).with_name('type1_opening_independent.py')),protocol_sha256=sha(a.protocol)),
                 limitations=['Elliott-inspired mechanical hypothesis, not full wave counting','Already-used period, not independent validation','Independent trade returns, not portfolio returns','No original recommendation or cluster selection','Unverified KRX adjustment basis; unresolved trades not zero'])
    a.output.mkdir(parents=True)
    for n,obj in [('summary',summary),('signals',signals),('trades',trades),('evaluations',evaluations)]:
        (a.output/(n+'.json')).write_text(json.dumps(obj,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(summary,ensure_ascii=False,indent=2))

if __name__=='__main__':main()
