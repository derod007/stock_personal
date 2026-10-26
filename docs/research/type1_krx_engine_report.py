"""Summarize frozen results without running accounts."""
import argparse,json,datetime,collections
from pathlib import Path
from zoneinfo import ZoneInfo

def day(p):return datetime.datetime.fromtimestamp(p['session'],ZoneInfo('Asia/Seoul')).strftime('%Y-%m-%d')
def summarize(folder):
    data={a:json.loads((folder/(a+'.json')).read_text()) for a in ('all_types','type1')}
    common=min(r['last_good_day'] for r in data.values());out={}
    for a,r in data.items():
        pts=[p for p in r['state']['equity'] if day(p)<=r['last_good_day']]
        matched=[p for p in pts if day(p)<=common];initial=r['state']['config']['initial_cash'] if 'config' in r['state'] else 100000000
        h=r['state'].get('halted');active=r['state']['active'];monthly=collections.defaultdict(float)
        for t in r['trades']:monthly[t['exit_day'][:7]]+=t['exit']['net_pnl']
        out[a]=dict(status=r['status'],last_good_day=r['last_good_day'],annual_return_pct=r['return_pct'],partial_return_pct=(pts[-1]['equity']/initial-1)*100,max_drawdown_pct=r['max_drawdown_pct'],counts=r['counts'],audit=r['audit'],halt=h,halt_orders={s:{'filled':active[s]['filled']} for s in h['symbols']} if isinstance(h,dict) and 'symbols' in h else {},common_end=common,common_return_pct=(matched[-1]['equity']/initial-1)*100,common_mdd_pct=max(p['drawdown'] for p in matched)*100,monthly_realized=dict(monthly))
    return out
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--results',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args()
    with a.output.open('x') as f:json.dump(summarize(a.results),f,ensure_ascii=False,indent=2);f.write('\n')
