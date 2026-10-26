"""Same type1 signals, delayed next-open entries, same T+20 deadline."""
import argparse,collections,copy,hashlib,json,random,zipfile
from pathlib import Path
from type1_opening_independent import trade,stats,valid,sha

def delayed(signal,days,bars,events,lag):
    s=copy.deepcopy(signal);i=days.index(s['anchor']);s['entry_day']=days[i+1+lag]
    # Unowned missing candles are not a trade loss, but a price-basis event
    # between signal and delayed entry leaves signal/entry continuity unresolved.
    during=[d for d in events if s['anchor']<d<s['entry_day']]
    if during:return dict(signal=s,status='unresolved',filled=False,net_pct=None,unresolved_day=min(during),unresolved_reason='reference_during_wait')
    return trade(s,days,bars,set(events))

def pctile(xs,q):
    xs=sorted(xs);p=(len(xs)-1)*q;i=int(p);j=min(i+1,len(xs)-1)
    return xs[i]*(1-p+i)+xs[j]*(p-i)

def paired(a,b):
    pairs=[(x,y) for x,y in zip(a,b) if x['status']=='closed' and y['status']=='closed']
    diffs=[y['net_pct']-x['net_pct'] for x,y in pairs];by=collections.defaultdict(list)
    for x,y in pairs:by[x['signal']['symbol']].append(y['net_pct']-x['net_pct'])
    keys=sorted(by);rng=random.Random(1042026);means=[]
    if keys:
        totals={k:(sum(by[k]),len(by[k])) for k in keys}
        for _ in range(1000):
            chosen=rng.choices(keys,k=len(keys));means.append(sum(totals[k][0] for k in chosen)/sum(totals[k][1] for k in chosen))
    avg=lambda x:sum(x)/len(x) if x else None
    return dict(n=len(pairs),old_mean_pct=avg([x['net_pct'] for x,y in pairs]),new_mean_pct=avg([y['net_pct'] for x,y in pairs]),
                mean_difference_pp=avg(diffs),difference_cluster_ci95=[pctile(means,.025),pctile(means,.975)] if means else None,
                improved=sum(d>1e-10 for d in diffs),worsened=sum(d< -1e-10 for d in diffs),equal=sum(abs(d)<=1e-10 for d in diffs),
                baseline_stops=sum(x['exit_reason']=='stop' for x,y in pairs),
                stopped_to_profit=sum(x['exit_reason']=='stop' and y['net_pct']>0 for x,y in pairs),
                stopped_to_target=sum(x['exit_reason']=='stop' and y['exit_reason']=='target' for x,y in pairs),
                stopped_to_stop=sum(x['exit_reason']=='stop' and y['exit_reason']=='stop' for x,y in pairs),
                baseline_profits=sum(x['net_pct']>0 for x,y in pairs),profit_to_loss=sum(x['net_pct']>0 and y['net_pct']<0 for x,y in pairs),
                mean_entry_change_pct=avg([(y['entry']/x['entry']-1)*100 for x,y in pairs]))

def evaluate(days,signals,bars,events):
    arms={str(lag):[delayed(s,days,bars[s['symbol']],events.get(s['symbol'],[]),lag) for s in signals] for lag in range(3)}
    complete=[i for i in range(len(signals)) if all(arms[k][i]['status']=='closed' for k in arms)]
    common={k:[v[i] for i in complete] for k,v in arms.items()}
    summary=dict(signals=len(signals),arms={k:stats(v) for k,v in arms.items()},common_n=len(complete),
                 common_arms={k:stats(v) for k,v in common.items()},common_pairs={k:paired(common['0'],common[k]) for k in ['1','2']},
                 pairwise={k:paired(arms['0'],arms[k]) for k in ['1','2']})
    midpoint=len(days)//2;cut=days[midpoint]
    summary['half_cut']=cut
    summary['halves']={name:{k:paired([t for t in common['0'] if (t['signal']['anchor']<cut)==first],
                                     [t for t in common[k] if (t['signal']['anchor']<cut)==first]) for k in ['1','2']} for name,first in [('first',True),('second',False)]}
    return arms,summary

def load_recent(record,archive):
    raw=json.loads(Path(record).read_text());r=raw['results']['type1'];days=[p['day'] for p in r['equity']]
    signals=[]
    for e in r['events']:
        if e['event'] not in ['fill','blocked']:continue
        i=days.index(e['day'])-1
        signals.append(dict(symbol=e['symbol'],anchor=days[i],entry_day=e['day'],end=days[i+20],cluster=1))
    if len(signals)!=r['signals'] or len({(s['symbol'],s['anchor']) for s in signals})!=len(signals):raise ValueError('Signal reconstruction mismatch')
    bars={}
    with zipfile.ZipFile(archive) as z:
        paths={Path(n).stem:n for n in z.namelist() if '/bars/' in n and n.endswith('.json')}
        for sym in {s['symbol'] for s in signals}:
            b=z.read(paths[sym])
            if hashlib.sha256(b).hexdigest()!=raw['hashes']['bars'][sym]:raise ValueError('Recent bar hash mismatch')
            by={}
            for row in json.loads(b)['rows']:
                day=str(row.get('time_kst',''))[:10]
                if len(day)!=10:continue
                if day in by:by[day]=None;continue
                by[day]=row if valid(row) and row.get('is_complete',True) and not row.get('synthetic') else None
            bars[sym]={d:b for d,b in by.items() if b}
    return days,signals,bars,raw

def main():
    p=argparse.ArgumentParser()
    for n in ['recent-record','recent-archive','krx-input','krx-bars','output','protocol']:p.add_argument('--'+n,type=Path,required=True)
    a=p.parse_args()
    if a.output.exists():raise ValueError('New output required')
    d=json.loads(a.krx_input.read_text());signals=[s for s in d['signals'] if s['cluster']==1];bars={}
    for sym in {s['symbol'] for s in signals}:
        path=a.krx_bars/(sym+'.json')
        if sha(path)!=d['hashes']['bars'][sym]:raise ValueError('KRX bar hash mismatch')
        rows=json.loads(path.read_text())['rows'];bars[sym]={b['day']:b for b in rows}
        if len(rows)!=len(bars[sym]):raise ValueError('Duplicate dates')
    ka,ks=evaluate(d['days'],signals,bars,d['reference_events'])
    rd,rs,rb,record=load_recent(a.recent_record,a.recent_archive);ra,rr=evaluate(rd,rs,rb,{})
    # Original capital-selected trades must match baseline independent outcomes.
    by={(t['signal']['symbol'],t['signal']['anchor']):t for t in ra['0']}
    for t in record['results']['type1']['trades']:
        u=by[(t['symbol'],t['anchor'])]
        if u['status']!='closed' or (u['entry'],u['exit_day'],u['exit_reason'])!=(t['entry'],t['exit_day'],t['exit_reason']) or abs(u['net_pct']-t['net_pct'])>1e-10:raise ValueError('Original 183 trade mismatch')
    summary=dict(periods={'krx':dict(period=[d['days'][0],d['days'][-1]],**ks),'recent':dict(period=[rd[0],rd[-1]],**rr)},
                 provenance=dict(krx_input=sha(a.krx_input),recent_record=sha(a.recent_record),recent_archive=sha(a.recent_archive),script=sha(__file__),protocol=sha(a.protocol)),
                 limitations=['Independent trades, not the +40.56% capital portfolio','Same T+20 end means shorter exposure after delay','Already used periods; type1 was selected post-hoc','Price sources kept separate; unresolved trades excluded only from paired completion statistics'])
    a.output.mkdir(parents=True)
    for n,obj in [('summary',summary),('krx-trades',ka),('recent-trades',ra)]:
        (a.output/(n+'.json')).write_text(json.dumps(obj,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:{'n':v['common_n'],'means':{a:b['mean_pct'] for a,b in v['common_arms'].items()},'pairs':v['common_pairs']} for k,v in summary['periods'].items()},ensure_ascii=False,indent=2))

if __name__=='__main__':main()
