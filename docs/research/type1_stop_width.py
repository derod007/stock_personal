"""Frozen type1 signals; stop width is the only changed trading rule."""
import argparse,json,hashlib
from pathlib import Path
from type1_opening_independent import valid,stats,sha
from type1_delay_study import load_recent,paired

def stop_trade(signal,days,bars,event_days,stop_pct):
    r=dict(signal=signal,status='unresolved',filled=False,net_pct=None)
    entry=None;holding=0
    for day in days:
        if not signal['entry_day']<=day<=signal['end']:continue
        b=bars.get(day)
        reason=('reference_discontinuity' if day in event_days else 'missing_bar' if b is None else 'invalid_ohlcv' if not valid(b) else None)
        if reason:
            r.update(unresolved_day=day,unresolved_reason=reason)
            return r
        if entry is None:
            entry=b['open'];r.update(filled=True,entry_day=day,entry=entry)
        holding+=1;stop=entry*(1-stop_pct);target=entry*1.15
        hitstop=b['low']<=stop;hittarget=b['high']>=target
        ex=('stop',min(stop,b['open'])) if hitstop else ('target',target) if hittarget else ('time',b['close']) if day==signal['end'] else None
        if ex:
            r.update(status='closed',exit_day=day,exit_reason=ex[0],exit_price=ex[1],held_bars=holding,
                     net_pct=(ex[1]*.9985/(entry*1.0015)-1)*100,same_bar_order_unknown=hitstop and hittarget)
            return r
    r.update(unresolved_reason='calendar_window_missing')
    return r

def evaluate(trades,days,bars,events):
    baseline=[stop_trade(t['signal'],days,bars[t['signal']['symbol']],set(events.get(t['signal']['symbol'],[])),.05) for t in trades]
    if baseline!=trades:raise ValueError('Saved baseline identity mismatch')
    wide=[stop_trade(t['signal'],days,bars[t['signal']['symbol']],set(events.get(t['signal']['symbol'],[])),.10) for t in trades]
    pairs=[(a,b) for a,b in zip(baseline,wide) if a['status']==b['status']=='closed']
    a=[x for x,y in pairs];b=[y for x,y in pairs];cut=days[len(days)//2]
    summary=dict(baseline=stats(baseline),wide=stats(wide),common=dict(baseline=stats(a),wide=stats(b),paired=paired(a,b)),
      halves={label:paired([x for x,y in pairs if (x['signal']['anchor']<cut)==first],[y for x,y in pairs if (x['signal']['anchor']<cut)==first]) for label,first in [('first',True),('second',False)]},half_cut=cut,
      old_closed_new_unknown=sum(x['status']=='closed' and y['status']!='closed' for x,y in zip(baseline,wide)),
      stopped_to_bigger_loss=sum(x['exit_reason']=='stop' and y['net_pct']<x['net_pct']-1e-10 for x,y in pairs),
      stop_rescue_gain_pp=sum(y['net_pct']-x['net_pct'] for x,y in pairs if x['exit_reason']=='stop' and y['net_pct']>x['net_pct']+1e-10),
      stop_worsen_loss_pp=sum(y['net_pct']-x['net_pct'] for x,y in pairs if x['exit_reason']=='stop' and y['net_pct']<x['net_pct']-1e-10),
      mean_loss_pct={k:sum(t['net_pct'] for t in v if t['net_pct']<0)/sum(t['net_pct']<0 for t in v) for k,v in [('baseline',a),('wide',b)]})
    return {'baseline':baseline,'wide':wide},summary

def main():
    p=argparse.ArgumentParser()
    for n in ['krx-input','krx-bars','krx-trades','recent-record','recent-archive','recent-trades','output']:p.add_argument('--'+n,type=Path,required=True)
    a=p.parse_args()
    if a.output.exists():raise ValueError('New output required')
    d=json.loads(a.krx_input.read_text());kt=json.loads(a.krx_trades.read_text())['0'];kb={}
    for sym in {t['signal']['symbol'] for t in kt}:
        f=a.krx_bars/(sym+'.json')
        if sha(f)!=d['hashes']['bars'][sym]:raise ValueError('KRX hash mismatch')
        rows=json.loads(f.read_text())['rows'];kb[sym]={b['day']:b for b in rows}
        if len(rows)!=len(kb[sym]):raise ValueError('Duplicate dates')
    rd,rs,rb,record=load_recent(a.recent_record,a.recent_archive);rt=json.loads(a.recent_trades.read_text())['0']
    kr,ks=evaluate(kt,d['days'],kb,d['reference_events']);rr,ss=evaluate(rt,rd,rb,{})
    chosen={(t['symbol'],t['anchor']) for t in record['results']['type1']['trades']}
    subset=[t for t in rt if (t['signal']['symbol'],t['signal']['anchor']) in chosen]
    sr,st=evaluate(subset,rd,rb,{})
    a.output.mkdir()
    summary=dict(krx=ks,recent=ss,original183_frozen_subset=st,limitations=['Independent trades, not portfolio rerun','Same notional; 10% stop doubles planned price risk','Original 183 frozen subset does not reproduce 10% stop account selection','Already explored periods and post-hoc type selection; not independent validation'],input_hashes={str(f):sha(f) for f in [a.krx_input,a.krx_trades,a.recent_record,a.recent_archive,a.recent_trades]})
    for n,obj in [('summary',summary),('krx-trades',kr),('recent-trades',rr),('original183-trades',sr)]:
        (a.output/(n+'.json')).write_text(json.dumps(obj,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(summary,ensure_ascii=False,indent=2))
if __name__=='__main__':main()
