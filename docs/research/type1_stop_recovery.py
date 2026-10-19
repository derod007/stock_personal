"""Read-only post-exit path audit. No trade simulation, fitting, or rule search."""
import argparse
import hashlib
import json
from pathlib import Path
from datetime import datetime, timezone, timedelta
from statistics import mean, median

FEATURES = ['return20_pct', 'atr14_pct', 'volume10_50', 'drawdown20_pct']

def day(ts):
    return datetime.fromtimestamp(ts, timezone(timedelta(hours=9))).date().isoformat()

def valid(b):
    return (b.get('is_complete') is True and all(b.get(k, 0) > 0 for k in ['open','high','low','close','volume'])
            and b['low'] <= min(b['open'],b['close']) <= max(b['open'],b['close']) <= b['high'])

def features(past):
    p = [b for b in past if valid(b)][-60:]
    if len(p)<60: return None
    c=p[-1]['close']
    tr=[max(p[i]['high']-p[i]['low'], abs(p[i]['high']-p[i-1]['close']), abs(p[i]['low']-p[i-1]['close'])) for i in range(len(p)-14,len(p))]
    return dict(zip(FEATURES,[(c/p[-21]['close']-1)*100,mean(tr)/c*100,
        mean(b['volume'] for b in p[-10:])/mean(b['volume'] for b in p[-50:]),
        (c/max(b['high'] for b in p[-20:])-1)*100]))

def audit(record, rows, days, events):
    s,r=record['signal'],record['result']
    out={'signal':s, 'trade':r, 'features':features([b for b in rows if b['day']<=s['anchor']])}
    if r['status']!='closed': return dict(out,label='not_closed')
    entry,exit=day(r['entry_at']),day(r['exit_at'])
    bm={b['day']:b for b in rows}
    window=[d for d in days if entry<=d<=s['end']]
    bad=[d for d in window if d in events or d not in bm or not valid(bm[d])]
    out['half']='2023H2' if s['anchor']<'2024-01-01' else '2024H1'
    out['profitable']=r['net_return_pct']>0
    if bad:return dict(out,label='future_unknown',bad_days=bad)
    future=[bm[d] for d in window if d>exit]
    hit=next((b for b in future if b['high']>=s['target']),None)
    same=bm[exit]['high']>=s['target'] and r['first_exit']=='stop'
    out.update(same_stop_day_target_touch=same, later_target_touch=hit is not None,
        end_return_from_fill_pct=(bm[s['end']]['close']/r['entry_fill']-1)*100)
    if r['first_exit']=='stop':
        out['label']='stop_same_day_ambiguous' if same else ('stop_later_target' if hit else 'stop_no_later_target')
    else:out['label']='profitable' if out['profitable'] else 'other_loss'
    if hit:
        # Hit-day low may occur after target touch; do not call it pre-hit drawdown.
        before=[bm[d] for d in window if d<hit['day']]
        out.update(hit_day=hit['day'], sessions_after_stop=days.index(hit['day'])-days.index(exit),
            sessions_after_entry=days.index(hit['day'])-days.index(entry),
            pre_hit_prior_day_low_pct=min((b['low']/r['entry_fill']-1)*100 for b in before) if before else None,
            hit_day_low_pct=(hit['low']/r['entry_fill']-1)*100)
    # Entry-day low may precede the fill; all OHLC path drawdowns are descriptive bounds.
    held=[bm[d] for d in window if d<=exit]
    out['held_daily_low_pct']=min((b['low']/r['entry_fill']-1)*100 for b in held)
    return out

def auc(pos,neg):
    if not pos or not neg:return None
    return sum((a>b)+0.5*(a==b) for a in pos for b in neg)/(len(pos)*len(neg))

def compare(rows,positive,negative):
    result={}
    for half in ['all','2023H2','2024H1']:
        rr=[r for r in rows if r.get('features') and (half=='all' or r.get('half')==half)]
        p=[r for r in rr if positive(r)];n=[r for r in rr if negative(r)]
        result[half]={'positive_n':len(p),'negative_n':len(n),'features':{}}
        for f in FEATURES:
            a=[r['features'][f] for r in p];b=[r['features'][f] for r in n]
            result[half]['features'][f]={'positive_median':median(a) if a else None,'negative_median':median(b) if b else None,'auc_high':auc(a,b)}
    return result

def main():
    p=argparse.ArgumentParser();p.add_argument('--trades',required=True);p.add_argument('--input-dir',required=True);p.add_argument('--output',required=True);a=p.parse_args()
    ip=Path(a.input_dir); manifest=json.loads((ip/'input.json').read_text()); raw=json.loads(Path(a.trades).read_text())
    assert hashlib.sha256((ip/'input.json').read_bytes()).hexdigest()==raw['input_sha256']
    records=[]; hashes={}
    for rec in raw['records']:
        if rec['signal']['cluster']!=1:continue
        sym=rec['signal']['symbol']; path=ip/'bars'/f'{sym}.json'
        blob=path.read_bytes(); hashes[str(path)]=hashlib.sha256(blob).hexdigest()
        records.append(audit(rec,json.loads(blob)['rows'],manifest['days'],manifest['reference_events'].get(sym,[])))
    for f,h in hashes.items():assert hashlib.sha256(Path(f).read_bytes()).hexdigest()==h
    from collections import Counter
    stopped=[r for r in records if r['trade'].get('first_exit')=='stop']
    recovered=[r for r in stopped if r['label']=='stop_later_target']
    path_groups={}
    for label in ['stop_later_target','stop_no_later_target','profitable']:
        group=[r for r in records if r['label']==label]
        path_groups[label]={'n':len(group),'symbols':len({r['signal']['symbol'] for r in group}),
            'median_held_daily_low_pct':median(r['held_daily_low_pct'] for r in group) if group else None,
            'median_holding_bars':median(r['trade']['bars'] for r in group) if group else None,
            'median_end_return_from_fill_pct':median(r['end_return_from_fill_pct'] for r in group) if group else None}
    summary={'path_groups':path_groups,'counts':dict(Counter(r['label'] for r in records)), 'stop_counts':dict(Counter(r['label'] for r in stopped)),
      'recovery_by_half':{h:dict(Counter(r['label'] for r in stopped if r.get('half')==h)) for h in ['2023H2','2024H1']},
      'recovered_medians':{k:median(r[k] for r in recovered if r.get(k) is not None) if recovered else None for k in ['sessions_after_stop','sessions_after_entry','pre_hit_prior_day_low_pct','hit_day_low_pct']},
      'no_target_end_above_entry':sum(r.get('end_return_from_fill_pct',-1)>0 for r in stopped if r['label']=='stop_no_later_target'),
      'recovered_vs_no_target':compare(records,lambda r:r['label']=='stop_later_target',lambda r:r['label']=='stop_no_later_target'),
      'profitable_vs_stop':compare(records,lambda r:r['trade']['status']=='closed' and r['trade']['net_return_pct']>0,lambda r:r['trade'].get('first_exit')=='stop')}
    out=Path(a.output);out.mkdir(parents=True,exist_ok=True)
    (out/'rows.json').write_text(json.dumps(records,ensure_ascii=False,indent=2))
    (out/'summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2))
    (out/'provenance.json').write_text(json.dumps({'account_execution':False,'input_sha256':raw['input_sha256'],'trades_sha256':hashlib.sha256(Path(a.trades).read_bytes()).hexdigest(),'script_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),'bar_hashes':hashes},indent=2))
    print(json.dumps(summary,ensure_ascii=False,indent=2))

if __name__=='__main__':main()
