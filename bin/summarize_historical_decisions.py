"""Summarize frozen diagnostic days; optional episode outcomes never feed engine decisions."""
import argparse, collections, hashlib, json, math
from pathlib import Path

def obj(v): return v if isinstance(v,dict) else {}
def read(p): return json.loads(Path(p).read_text())
def summarize(diagnosis, dataset, candidates):
    c=read(candidates);cal=c['days'];idx={d:i for i,d in enumerate(cal)}
    rows={};states=collections.Counter();quality=collections.Counter();fails=collections.Counter();unknown=collections.Counter();signals=0
    for p in sorted(Path(diagnosis).glob('day-*.json')):
        day=read(p)['payload']
        assert len(day['records'])==100
        for r in day['records']:
            rows[(r['date'],r['symbol'])]=r
            states['quality_blocked' if not r['quality']['can_simulate'] else (obj(r['final']).get('status') or 'unavailable')]+=1
            quality.update(r['quality']['reasons']);signals+=r['recommendation_eligible']
            for name,pat in obj(r['patterns']).items():
                if not pat['selected']:continue
                fails.update(name+':'+k for k,g in obj(pat['gates']).items() if g['state']=='fail')
                unknown.update(name+':'+k for k,g in obj(pat['gates']).items() if g['state']=='unknown')
    assert len(rows)==len(cal)*100,'incomplete diagnosis'
    anchors=[];last={}
    for d in cal:
        for rr in c['dates'][d]['rows']:
            sym=rr['symbol']
            if sym not in last or idx[d]-last[sym]>=20:
                last[sym]=idx[d];anchors.append((d,sym))
    cache={};episodes=[];excluded=collections.Counter()
    def valid(b):
        return b and b.get('is_complete',True) and not b.get('synthetic',False) and all(isinstance(b.get(k),(int,float)) and math.isfinite(b[k]) and b[k]>0 for k in ['open','high','low','close','volume']) and b['low']<=min(b['open'],b['close'])<=max(b['open'],b['close'])<=b['high']
    for d,sym in anchors:
        i=idx[d];future=cal[i+1:i+21]
        if len(future)!=20:excluded['incomplete_future_window']+=1;continue
        if sym not in cache:
            p=Path(dataset)/'bars'/f'{sym}.json';bars=read(p)['rows'] if p.exists() else [];by={}
            for b in bars:
                day=b['time_kst'][:10];by[day]=None if day in by else b
            cache[sym]=by
        bs=[cache[sym].get(x) for x in future]
        if not all(valid(b) for b in bs):excluded['missing_invalid_or_zero_volume_future']+=1;continue
        r=rows[(d,sym)];entry=bs[0]['open'];hit=next((n+1 for n,b in enumerate(bs) if b['high']>=entry*1.15),None)
        stop=next((n+1 for n,b in enumerate(bs) if b['low']<=entry*.95),None)
        order='same_bar_unknown' if hit is not None and hit==stop else 'up_first' if hit is not None and (stop is None or hit<stop) else 'down_first' if stop is not None else 'neither'
        state='quality_blocked' if not r['quality']['can_simulate'] else obj(r['final']).get('status','unavailable')
        episodes.append({'date':d,'symbol':sym,'name':r['name'],'state':state,'ready':r['recommendation_eligible'],
                         'hit15':hit is not None,'hit_day':hit,'end_return':(bs[-1]['close']/entry-1)*100,
                         'max_up':(max(b['high'] for b in bs)/entry-1)*100,'order_15_minus5':order})
    def groups(key):
        out=[]
        for g in sorted(set(e[key] for e in episodes),key=str):
            q=[e for e in episodes if e[key]==g];n=len(q);hits=sum(e['hit15'] for e in q)
            out.append({'group':g,'n':n,'hit15':hits,'hit15_pct':round(hits/n*100,2),'end_loss':sum(e['end_return']<0 for e in q),'end_minus15':sum(e['end_return']<=-15 for e in q),'mean_end':sum(e['end_return'] for e in q)/n})
        return out
    return {'days':len(cal),'observations':len(rows),'signals':signals,'states':dict(states),'quality_reasons':dict(quality),
            'selected_pattern_failed_gates':dict(fails),'selected_pattern_unknown_gates':dict(unknown),
            'anchors':len(anchors),'valid_outcome_episodes':len(episodes),'excluded_episodes':dict(excluded),
            'outcomes_by_state':groups('state'),'outcomes_by_ready':groups('ready'),'episodes':episodes,
            'limitations':['Recomputed fixed-code decisions, not original contemporaneous records','Overlapping blockers are not additive causes','Episode anchor every >=20 market sessions; not matched calendar dates or equal rise time','Future-window validity differs from earlier 60-history-bar shape study; denominators differ','High touch is not realized return; no costs or account replay','Already studied periods are not fresh independent validation']}
if __name__=='__main__':
    ap=argparse.ArgumentParser();ap.add_argument('--diagnosis',required=True);ap.add_argument('--dataset',required=True);ap.add_argument('--candidates',required=True);ap.add_argument('--output',required=True);a=ap.parse_args()
    result=summarize(a.diagnosis,a.dataset,a.candidates)
    with open(a.output,'x') as f:json.dump(result,f,ensure_ascii=False,indent=2)
    print(json.dumps({k:v for k,v in result.items() if k not in ['episodes','limitations']},ensure_ascii=False,indent=2))
