"""Private descriptive report for independent trades; no account returns."""
import argparse,json,statistics,collections
from pathlib import Path

def stats(records):
    closed=[r for r in records if r['result']['status']=='closed'];v=[r['result']['net_return_pct'] for r in closed]
    statuses=collections.Counter(r['result']['status'] for r in records)
    syms=collections.defaultdict(list)
    for r in closed:syms[r['signal']['symbol']].append(r['result']['net_return_pct'])
    sums={s:sum(x) for s,x in syms.items()};rank=sorted(sums,key=lambda s:(-sums[s],s))
    removed={}
    for k in (1,5):
        rest=[r['result']['net_return_pct'] for r in closed if r['signal']['symbol'] not in rank[:k]]
        removed[str(k)]=dict(n=len(rest),mean_pct=statistics.mean(rest) if rest else None,symbols=rank[:k])
    return dict(signals=len(records),statuses=dict(statuses),closed=len(v),known_fills=sum(bool(r['result'].get('filled') or r['result'].get('known_filled')) for r in records),mean_pct=statistics.mean(v) if v else None,median_pct=statistics.median(v) if v else None,win_pct=100*sum(x>0 for x in v)/len(v) if v else None,symbols=len(syms),symbol_equal_mean_pct=statistics.mean(statistics.mean(x) for x in syms.values()) if syms else None,top_symbols=[dict(symbol=s,trades=len(syms[s]),sum_pct=sums[s],mean_pct=statistics.mean(syms[s])) for s in rank[:10]],remove_top=removed,exit_reasons=dict(collections.Counter(r['result']['first_exit'] for r in closed)))

def run(data):
    records=data['records'];groups={'all':records,'type1':[r for r in records if r['signal']['cluster']==1],'other':[r for r in records if r['signal']['cluster']!=1]}
    result={}
    for name,rs in groups.items():
        months=sorted({r['signal']['anchor'][:7] for r in rs});result[name]=stats(rs)
        result[name]['monthly']={m:stats([r for r in rs if r['signal']['anchor'].startswith(m)]) for m in months}
        result[name]['halves']={k:stats([r for r in rs if (r['signal']['anchor']<'2024-01-01')==is_first]) for k,is_first in [('2023H2',True),('2024H1',False)]}
        result[name]['unresolved']=[dict(signal=r['signal'],result=r['result']) for r in rs if r['result']['status']=='unresolved']
    return dict(input_sha256=data['input_sha256'],input_skips=data['input_skips'],groups=result,account_execution=False,warning='Closed-subset descriptive statistics; unresolved returns excluded, not zero; not portfolio performance')
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--input',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args();r=run(json.loads(a.input.read_text()))
    with a.output.open('x') as f:json.dump(r,f,ensure_ascii=False,indent=2);f.write('\n')
    for k,v in r['groups'].items():print(k,json.dumps({x:v[x] for x in ['signals','statuses','known_fills','mean_pct','median_pct','win_pct','symbols','symbol_equal_mean_pct','remove_top']},ensure_ascii=False))
