"""Read-only audit of saved cancelled orders; no portfolio/account execution."""
import argparse
from collections import Counter,defaultdict
import hashlib,json,math
from pathlib import Path
from statistics import mean


def sha(p):return hashlib.sha256(Path(p).read_bytes()).hexdigest()

def classify(plan,bar):
    e,s,t=(plan[k] for k in ('entry','stop','target'));o,h,l=(bar[k] for k in ('open','high','low'))
    if o<=s:return 'open_at_or_below_stop'
    if o>=t:return 'open_at_or_above_target'
    if h>=t:
        if o<=e:return 'opening_fill_both_touched_unknown' if l<=s else 'opening_fill_target_only'
        return 'intraday_entry_target_order_unknown' if l<=e else 'target_without_entry_touch'
    return None

def result(plan,bar,exit_kind):
    fill=min(plan['entry'],bar['open']*1.0005)
    exit_price=plan['target'] if exit_kind=='target' else min(plan['stop'],bar['open'])*.9995
    net=(exit_price*.999/(fill*1.001)-1)*100
    qty=math.floor(10000000/(fill*1.001))
    return dict(entry=fill,exit=exit_price,net_return_pct=round(net,4),quantity=qty,pnl_won=qty*(exit_price*.999-fill*1.001))

def stats(rows):
    return {'n':len(rows),'mean_net':mean(r['net_return_pct'] for r in rows) if rows else None,'pnl_won':sum(r['pnl_won'] for r in rows),'positive':sum(r['net_return_pct']>0 for r in rows)}

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--previous',type=Path,required=True);ap.add_argument('--prepared',type=Path,required=True);ap.add_argument('--output',type=Path,required=True);a=ap.parse_args()
    if a.output.exists():raise ValueError('Use new output')
    hashes=json.loads((a.previous/'file-hashes.json').read_text());assert sha(a.previous/'trades.json')==hashes['trades.json']
    assert sha(a.prepared/'manifest.json')==sha(a.previous/'input-manifest.json')
    records=json.loads((a.previous/'trades.json').read_text());manifest=json.loads((a.prepared/'manifest.json').read_text());segs={s['label']:s for s in manifest['segments']};cache={};audit=[]
    for r in records:
        if r['trade']['status']!='cancelled_before_entry':continue
        plan=r['plan'];assert plan['stop']<plan['entry']<plan['target'];key=(r['segment'],r['symbol'])
        if key not in cache:
            p=a.prepared/r['segment']/(r['symbol']+'.json');assert sha(p)==r['source_sha256'];cache[key]={b['time_kst'][:10]:b for b in json.loads(p.read_text())}
        days=segs[r['segment']]['days'];i=days.index(r['date']);found=False
        for day in days[i+1:i+4]:
            b=cache[key].get(day);assert b is not None,(key,day)
            kind=classify(plan,b)
            if kind is not None:
                row=dict(segment=r['segment'],signal_date=r['date'],symbol=r['symbol'],name=r['name'],cancel_date=day,category=kind,plan=plan,bar=b)
                if kind.startswith('opening_fill_'):
                    row['target_case']=result(plan,b,'target')
                    row['conservative_case']=result(plan,b,'stop' if kind.endswith('unknown') else 'target')
                audit.append(row);found=True;break
            assert b['low']>plan['entry'],'saved cancelled order would have filled earlier'
        assert found,'cancel not reproducible'
    summaries={}
    for scope in ['all']+list(segs):
        rs=[r for r in records if scope=='all' or r['segment']==scope];au=[r for r in audit if scope=='all' or r['segment']==scope]
        base=[dict(net_return_pct=r['trade']['net_return_pct'],pnl_won=r['pnl_won']) for r in rs if r['trade']['status']=='closed']
        certain=[r['target_case'] for r in au if r['category']=='opening_fill_target_only']
        uncertain=[r for r in au if r['category']=='opening_fill_both_touched_unknown']
        lower=[r['conservative_case'] for r in uncertain];upper=[r['target_case'] for r in uncertain]
        summaries[scope]={'cancel_categories':dict(Counter(r['category'] for r in au)),'original_closed':stats(base),'target_only_additions':stats(certain),'uncertain_stop_first':stats(lower),'uncertain_target_first':stats(upper),'certain_additions_only':stats(base+certain),'opening_cases_stop_first':stats(base+certain+lower),'opening_cases_target_first':stats(base+certain+upper)}
    a.output.mkdir(parents=True)
    def dump(n,x):(a.output/n).write_text(json.dumps(x,ensure_ascii=False,indent=2,allow_nan=False)+'\n')
    dump('summary.json',summaries);dump('audit.json',audit)
    proto=Path(__file__).parents[1]/'entry-cancel-audit-v1/protocol.md'
    dump('provenance.json',{'trades_sha256':sha(a.previous/'trades.json'),'manifest_sha256':sha(a.prepared/'manifest.json'),'script_sha256':sha(__file__),'protocol_sha256':sha(proto),'account_execution':False,'saved_attempts_fixed':True})
    dump('file-hashes.json',{p.name:sha(p) for p in a.output.iterdir() if p.is_file()})
    print(json.dumps(summaries,ensure_ascii=False,indent=2))
if __name__=='__main__':main()
