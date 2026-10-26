"""Read-only matched-signal diagnostic; not a causal account-return decomposition."""
import argparse
from collections import Counter
import gzip
import json
import lzma
from pathlib import Path
from statistics import mean


def load(path):
    data=Path(path).read_bytes()
    if str(path).endswith('.gz'):data=gzip.decompress(data)
    if str(path).endswith('.xz'):data=lzma.decompress(data)
    return json.loads(data)


def compare(old,new):
    a={(t['symbol'],t['anchor']):t for t in old['trades']}
    b={(t['symbol'],t['anchor']):t for t in new['trades']}
    common=a.keys()&b.keys();onlya=a.keys()-b.keys();onlyb=b.keys()-a.keys()
    status={};active={}
    for e in new['events']:
        p=e['payload'];sym=p.get('symbol');key=(sym,e['day'])
        if e['type']=='decision':status[key]='blocked:'+p['reason']
        if e['type']=='order':active[sym]=key;status[key]='order'
        if e['type']=='fill':status[active[sym]]='filled'
        if e['type']=='order_cancelled':status[active.pop(sym)]='cancelled:'+p['reason']
        if e['type']=='exit':active.pop(sym,None)
    avg=lambda rows:mean(rows) if rows else None
    return {'same_signal_fills':len(common),'old_only_fills':len(onlya),'new_only_fills':len(onlyb),
            'old_only_engine_status':dict(Counter(status.get(k,'notfound') for k in onlya)),
            'common_old_trade_mean_pct':avg([a[k]['net_pct'] for k in common]),
            'common_new_trade_mean_pct':avg([b[k]['net_pct'] for k in common]),
            'old_only_trade_mean_pct':avg([a[k]['net_pct'] for k in onlya]),
            'new_only_trade_mean_pct':avg([b[k]['net_pct'] for k in onlyb]),
            'common_trades':[{'symbol':k[0],'anchor':k[1],'old':a[k],'new':b[k]} for k in sorted(common)],
            'limitation':'Selected matched trades; not a causal portfolio return decomposition'}


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    for k in ('old','new','output'):ap.add_argument('--'+k,required=True,type=Path)
    args=ap.parse_args()
    r=compare(load(args.old)['results']['type1'],load(args.new))
    with args.output.open('x',encoding='utf-8') as f:json.dump(r,f,ensure_ascii=False,indent=2)

if __name__=='__main__':main()
