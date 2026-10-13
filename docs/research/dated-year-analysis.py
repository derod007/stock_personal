#!/usr/bin/env python3
"""Read-only analysis of PR76 exports. Output is a new research artifact, never an account rerun."""
import collections as C
import hashlib
import json
from decimal import Decimal as D
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/'docs/historical-top100-replay-20251008-20261008/account'
OUT=SRC.parent/'analysis'
def read(p):return json.loads(p.read_text(),parse_float=D)
def near(a,b):
    if abs(D(a)-D(b))>=D('.01'):raise ValueError(f'Ledger mismatch: {a} != {b}')
def pct(a,b):return D(a)/D(b)*100 if b else None
def group(ts):
    closed=[t for t in ts if t['exit']];win=[t for t in closed if t['exit']['net_pnl']>0];loss=[t for t in closed if t['exit']['net_pnl']<0]
    pos=sum((t['exit']['net_pnl'] for t in win),D(0));neg=-sum((t['exit']['net_pnl'] for t in loss),D(0))
    returns=[pct(t['exit']['net_pnl'],D(t['quantity'])*t['fill']['price']+t['fill']['fee']) for t in closed]
    return dict(orders=len(ts),fills=sum(bool(t['fill']) for t in ts),closed=len(closed),wins=len(win),losses=len(loss),realized=pos-neg,
        win_rate_pct=pct(len(win),len(closed)),profit_factor=pos/neg if neg else None,
        mean_closed_return_pct=sum(returns)/len(returns) if returns else None)
def analyze(src=SRC):
    ts=read(src/'trades.json')['trades'];s=read(src/'summary.json');ds=[json.loads(l,parse_float=D) for l in (src/'daily-log.jsonl').read_text().splitlines()]
    cand=read(ROOT/'docs/historical-top100-20251008-20261008/candidates.json')['dates'];by={d['date']:d for d in ds}
    assert len(by)==len(ds)==244 and list(by)==sorted(by)
    sig=[r for d in ds for r in d['signals']];outcomes=C.Counter(r['outcome'] for r in sig)
    assert len(ts)==outcomes['order']==68
    for t in ts:
        day=t['signal_date'];sym=t['symbol']
        assert sym in {r['symbol'] for r in cand[day]['rows']}
        matches=[r for r in by[day]['signals'] if r['symbol']==sym and r['outcome']=='order'];assert len(matches)==1
        for f in ('entry','stop','target'):near(t[f],matches[0][f])
        assert t['pattern']==matches[0]['pattern']
    def contribution(t,d):
        if not t['fill'] or t['fill']['date']>d['date']:return D(0)
        if t['exit'] and t['exit']['date']<=d['date']:return t['exit']['net_pnl']
        h=d['held'][t['symbol']];assert h['quantity']==t['quantity'];near(h['entry_fill'],t['fill']['price'])
        return D(t['quantity'])*(D(h['mark_close'])-t['fill']['price'])-t['fill']['fee']
    for d in ds:
        near(sum((contribution(t,d) for t in ts),D(0)),d['equity']-s['initial_cash'])
        near(sum((t['exit']['net_pnl'] for t in ts if t['exit'] and t['exit']['date']<=d['date']),D(0)),d['realized'])
        near(d['cash']+sum((D(h['quantity'])*h['mark_close'] for h in (d['held'] or {}).values()),D(0)),d['equity'])
    total=group(ts);near(total['realized'],s['realized_pnl']);near(ds[-1]['equity'],s['equity'])
    peak=ds[0];worst=D(0);pair=(peak,peak)
    for d in ds:
        if d['equity']>peak['equity']:peak=d
        dd=1-D(d['equity'])/D(peak['equity'])
        if dd>worst:worst=dd;pair=(peak,d)
    peak,trough=pair;near(worst*100,s['max_close_drawdown']*100)
    impacts=[]
    for t in ts:
        delta=contribution(t,trough)-contribution(t,peak)
        if delta:impacts.append(dict(symbol=t['symbol'],signal_date=t['signal_date'],pattern=t['pattern'],delta=delta,
            peak_contribution=contribution(t,peak),trough_contribution=contribution(t,trough)))
    impacts.sort(key=lambda r:r['delta']);near(sum((r['delta'] for r in impacts),D(0)),trough['equity']-peak['equity'])
    wins=sorted([t for t in ts if t['exit'] and t['exit']['net_pnl']>0],key=lambda t:t['exit']['net_pnl'],reverse=True)
    gross=sum((t['exit']['net_pnl'] for t in wins),D(0));con=[]
    for n in (1,3,5):
        v=sum((t['exit']['net_pnl'] for t in wins[:n]),D(0));con.append(dict(top=n,pnl=v,share_realized_pct=pct(v,total['realized']),share_gross_wins_pct=pct(v,gross),remainder=total['realized']-v))
    patterns={p:group([t for t in ts if t['pattern']==p]) for p in sorted({t['pattern'] for t in ts})}
    for p,g in patterns.items():g['signal_outcomes']=dict(C.Counter(r['outcome'] for r in sig if r['pattern']==p))
    statuses=C.Counter();blocked=0
    for d in ds:statuses.update(d['plan_status']);blocked+=len({sym for syms in (d['quality_blocked'] or {}).values() for sym in syms})
    assert sum(statuses.values())+blocked==sum(d['evaluated'] for d in ds)
    monthly=[]
    for month in sorted({d['date'][:7] for d in ds}):
        days=[d for d in ds if d['date'].startswith(month)]
        monthly.append(dict(month=month,sessions=len(days),signals=sum(len(d['signals']) for d in days),fills=sum(len(d['fills']) for d in days),
            realized=sum((t['exit']['net_pnl'] for t in ts if t['exit'] and t['exit']['date'].startswith(month)),D(0))))
    return dict(input_sha256={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in [src/'trades.json',src/'summary.json',src/'daily-log.jsonl',ROOT/'docs/historical-top100-20251008-20261008/candidates.json']},
        validation=dict(daily_equity_and_realized_reconciled=244,orders_matched=68,dd_contributions_reconciled=True),total=total,
        funnel=dict(signals=len(sig),outcomes=dict(outcomes),no_signal_sessions=sum(not d['signals'] for d in ds),
            unique_signal_symbols=len({r['symbol'] for r in sig}),unique_filled_symbols=len({t['symbol'] for t in ts if t['fill']}),
            cancellations=dict(C.Counter(t['cancelled']['reason'] for t in ts if t['cancelled'])),
            average_cash_pct=sum(D(d['cash'])/D(d['equity'])*100 for d in ds)/len(ds),
            average_unreserved_cash_pct=sum((D(d['cash'])-D(d['reserved_cash']))/D(d['equity'])*100 for d in ds)/len(ds),
            four_active_sessions=sum(len(d['held'])+len(d['pending_orders'])==4 for d in ds),
            evaluated_with_held=sum(d['evaluated'] for d in ds),blocked_unique_slots=blocked,status_counts_with_held=dict(statuses)),
        patterns=patterns,monthly=monthly,concentration=con,
        drawdown=dict(pct=worst*100,peak_date=peak['date'],peak_equity=peak['equity'],trough_date=trough['date'],trough_equity=trough['equity'],
            recovery_date=next((d['date'] for d in ds if d['date']>trough['date'] and d['equity']>=peak['equity']),None),contributions=impacts))
if __name__=='__main__':
    result=analyze();OUT.mkdir(exist_ok=True)
    (OUT/'metrics.json').write_text(json.dumps(result,ensure_ascii=False,indent=2,default=float)+'\n')
    print(json.dumps({k:result[k] for k in ('total','funnel','patterns','drawdown')},ensure_ascii=False,default=float))
