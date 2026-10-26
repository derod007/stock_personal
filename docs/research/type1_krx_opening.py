"""Frozen PR107 opening model on saved PR113 KRX data; isolated research only."""
import argparse, hashlib, importlib.util, json, math
from pathlib import Path

ORIGINAL_SHA = '30d56a98f94b4e0a14ad4ecc183cdf0bc41fa970154e71cf69c0133489b77cc2'
CENTERS_SHA = '768c083a5c51cb452dad032f018a97b20527d07ff6fc1ab36559b53c406b4dfd'
SETTINGS = dict(initial_cash=100000000,max_positions=4,risk_pct=.01,position_pct=.2,sector_pct=.4,total_risk_pct=.03)

def sha(p): return hashlib.sha256(Path(p).read_bytes()).hexdigest()
def read(p): return json.loads(Path(p).read_text())
def original(path):
    if sha(path) != ORIGINAL_SHA: raise ValueError('Frozen PR107 code mismatch')
    s=importlib.util.spec_from_file_location('original_opening',path)
    m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m

def valid(b):
    keys=['open','high','low','close','volume']
    if any(not isinstance(b.get(k),(int,float)) or not math.isfinite(b[k]) for k in keys):return False
    return min(b[k] for k in keys[:-1])>0 and b['volume']>=0 and b['low']<=min(b['open'],b['close'])<=max(b['open'],b['close'])<=b['high']

class GuardedBars(dict):
    """EOD ambiguity stops evaluation, not a historically actionable cancellation."""
    def __init__(self,rows,events):
        super().__init__((b['day'],b) for b in rows)
        if len(self)!=len(rows):raise ValueError('Duplicate date')
        self.events=set(events)
    def problem(self,day):
        if day in self.events:return 'unresolved_reference_discontinuity'
        if not dict.__contains__(self,day):return 'missing_required_bar'
        if not valid(dict.__getitem__(self,day)):return 'invalid_required_ohlcv'
        return None
    def __contains__(self,day):return self.problem(day) is None

def audit_result(r,bars,cfg):
    cash=cfg['initial_cash']
    for e in r['events']:
        if e['event']=='fill':
            b=e['before'];eq=b['equity'];cost=e['cost']
            risk=e['qty']*e['price']*(1.0015-.95*.9985)
            assert abs(b['cash']-cash)<.01
            assert b['held']<cfg['max_positions']
            assert cost<=cash+.01 and cost<=eq*cfg['position_pct']+.01
            assert cost+b['sector_used']<=eq*cfg['sector_pct']+.01
            assert risk<=eq*cfg['risk_pct']+.01 and risk+b['risk_used']<=eq*cfg['total_risk_pct']+.01
            assert e['price']==bars[e['symbol']][e['day']]['open']
            cash-=cost
        elif e['event']=='exit':cash+=e['proceeds']
    for t in r['trades']:
        assert abs(t['qty']*t['entry']*1.0015-t['cost'])<.01
        assert abs(t['qty']*t['exit_price']*.9985-t['cost']-t['pnl'])<.01
        for p in r['equity']:
            day=p['day']
            if not t['entry_day']<=day<=t['exit_day']:continue
            b=bars[t['symbol']][day];stop=t['entry']*.95;target=t['entry']*1.15
            ex=('stop',min(stop,b['open'])) if b['low']<=stop else ('target',target) if b['high']>=target else ('time',b['close']) if day==t['end'] else None
            if ex:break
        assert (day,*ex)==(t['exit_day'],t['exit_reason'],t['exit_price'])
    if r['equity']:
        assert abs(cash-r['equity'][-1]['cash'])<.01
        marked=sum(t['pnl'] for t in r['trades'])+sum(p['qty']*p['mark']-p['cost'] for p in r['open_positions'].values())
        assert abs(marked-(r['equity'][-1]['equity']-cfg['initial_cash']))<.01
    return dict(cash=True,entry_limits=True,first_exit=True,cost_and_pnl=True,marked_equity=True)

def execute(data,bars,model):
    # Old limit plans are intentionally discarded. PR107 determines all trading
    # levels/sizing from next open, with entries before intraday exits.
    signals=[{k:s[k] for k in ('symbol','anchor','entry_day','end','cluster')} for s in data['signals']]
    results={}
    for arm,typ in [('all_types',None),('type1',1)]:
        r=model.simulate(data['days'],bars,signals,SETTINGS,data['sectors'],typ)
        r['audit']=audit_result(r,bars,SETTINGS)
        r['partial_marked_return_pct']=(r['equity'][-1]['equity']/SETTINGS['initial_cash']-1)*100 if r['equity'] else None
        if r['halt']:
            d=r['halt']['day'];r['halt']['evidence']={s:bars[s].problem(d) for s in r['halt']['symbols']}
            r['halt']['scope']='EOD evaluation stopped before this session; no inferred cancellation or trade'
            r['halt']['held_symbols']=list(r['open_positions'])
        results[arm]=r
    common=min(r['through'] for r in results.values() if r['through'])
    for r in results.values():
        pts=[p for p in r['equity'] if p['day']<=common]
        r['common']=dict(through=common,marked_return_pct=(pts[-1]['equity']/1e8-1)*100) if pts else None
    return results

def main():
    p=argparse.ArgumentParser()
    for n in ('input','bars','original','output'):p.add_argument('--'+n,type=Path,required=True)
    a=p.parse_args()
    if a.output.exists():raise ValueError('New output directory required')
    data=read(a.input)
    if data['price_basis']!='krx_tdd_ohlcv_adjustment_unverified' or data['hashes']['centers']!=CENTERS_SHA:raise ValueError('Wrong frozen input')
    if data['days']!=sorted(set(data['days'])):raise ValueError('Invalid calendar')
    model=original(a.original);bars={}
    for sym,h in data['hashes']['bars'].items():
        path=a.bars/(sym+'.json')
        if sha(path)!=h:raise ValueError('Bar hash mismatch: '+sym)
        bars[sym]=GuardedBars(read(path)['rows'],data['reference_events'].get(sym,[]))
    results=execute(data,bars,model)
    out=dict(period=[data['days'][0],data['days'][-1]],sessions=len(data['days']),config=SETTINGS,
             definition='PR107 trading rules; inherited PR113 KRX signal quality; continuity guard',
             input_sha256=sha(a.input),original_sha256=sha(a.original),adapter_sha256=sha(__file__),
             bar_files_verified=len(bars),price_basis=data['price_basis'],results=results)
    a.output.mkdir(parents=True)
    (a.output/'results.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
    for r in results.values():
        r['processed_sessions']=len(r['equity'])
        r['realized_pnl']=sum(t['pnl'] for t in r['trades'])
        r['last_equity']=r['equity'][-1] if r['equity'] else None
        for k in ('trades','events','equity','open_positions'):r.pop(k)
    (a.output/'summary.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(results,ensure_ascii=False,indent=2))

if __name__=='__main__':main()
