"""Descriptive per-trade atlas; consumes frozen trades, never executes an account."""
import argparse, json, hashlib, statistics, math
from pathlib import Path
import numpy as np
from type1_delay_study import load_recent
from type1_opening_independent import valid

FEATURES={'return5':'직전 5봉 수익률%','return20':'직전 20봉 수익률%','return59':'60봉 첫 종가 대비%','range60':'60봉 고저 진폭/T종가%','position20':'20봉 고저 내 종가 위치%','volratio':'거래량 10/50배','atr14':'14봉 평균 TR/T종가%','gap':'매수 시가/T종가 갭%'}
def mean(x):return float(np.mean(x)) if len(x) else None
def median(x):return float(np.median(x)) if len(x) else None
def features(pre,entry):
    if len(pre)!=60 or not all(valid(b) for b in pre):return None
    c=[b['close'] for b in pre];h=[b['high'] for b in pre];l=[b['low'] for b in pre];v=[b['volume'] for b in pre]
    tr=[max(h[i]-l[i],abs(h[i]-c[i-1]),abs(l[i]-c[i-1])) for i in range(1,60)]
    return dict(return5=(c[-1]/c[-6]-1)*100,return20=(c[-1]/c[-21]-1)*100,return59=(c[-1]/c[0]-1)*100,
      range60=(max(h)-min(l))/c[-1]*100,position20=(c[-1]-min(l[-20:]))/(max(h[-20:])-min(l[-20:]))*100 if max(h[-20:])>min(l[-20:]) else 50,
      volratio=mean(v[-10:])/mean(v[-50:]) if mean(v[-50:])>0 else None,atr14=mean(tr[-14:])/c[-1]*100,gap=(entry/c[-1]-1)*100 if entry else None)
def path_metrics(t,future,events):
    if t['status']!='closed':return None
    entry=t['entry'];exit=t['exit_day'];before=[b for b in future if b['day']<exit and valid(b)]
    # Exit candle highs cannot be ordered against stop execution from daily OHLC.
    peak=max([entry]+[b['high'] for b in before]);mfe=(peak/entry-1)*100
    post=[b for b in future if b['day']>exit]
    clean=all(valid(b) and b['day'] not in events for b in post)
    rebound=any(b['high']>=entry*1.15 for b in post) if clean else None
    stop=t['exit_reason']=='stop'
    return dict(pre_exit_mfe=mfe,pre_exit_peak_bar=next((i+1 for i,b in enumerate(before) if b['high']==peak),None),
      pre_exit_mae=(min([entry]+[b['low'] for b in before])/entry-1)*100,
      stop_before_3pct=stop and mfe<3,stop_after_3pct=stop and mfe>=3,
      later_hit15=rebound if stop else None,post_exit_quality=clean,
      close_returns=[(b['close']/entry-1)*100 if valid(b) and b['day'] not in events else None for b in future[:3]],
      close_returns_are_market_path_not_held=True)
def summarize(rows):
    closed=[r for r in rows if r['status']=='closed'];wins=[r for r in closed if r['net_pct']>0];loss=[r for r in closed if r['net_pct']<0];stops=[r for r in closed if r['exit_reason']=='stop']
    out=dict(n=len(rows),closed=len(closed),wins=len(wins),losses=len(loss),unresolved=len(rows)-len(closed),mean_pct=mean([r['net_pct'] for r in closed]),stops=len(stops),
      stop_before3=sum(r['path']['stop_before_3pct'] for r in stops),stop_after3=sum(r['path']['stop_after_3pct'] for r in stops),
      stop_later15=sum(r['path']['later_hit15'] is True for r in stops),stop_later_unknown=sum(r['path']['later_hit15'] is None for r in stops),
      held_median_win=median([r['held_bars'] for r in wins]),held_median_loss=median([r['held_bars'] for r in loss]),feature_comparison={})
    for k in FEATURES:
        a=[r['features'][k] for r in wins if r['features'] and r['features'][k] is not None];b=[r['features'][k] for r in loss if r['features'] and r['features'][k] is not None]
        # Common-language effect: probability a random winning trade has a larger feature.
        effect=mean([(sum(x>y for y in b)+.5*sum(x==y for y in b))/len(b) for x in a]) if a and b else None
        out['feature_comparison'][k]=dict(win_n=len(a),loss_n=len(b),win_median=median(a),loss_median=median(b),prob_win_larger=effect)
    out['stop_timing']={str(n):sum(r['held_bars']<=n for r in stops) for n in [1,2,3,5]}
    return out

def build(krx_input,krx_bars,krx_trades,recent_record,recent_archive,recent_trades,out):
    out.mkdir(parents=True,exist_ok=True)
    d=json.loads(krx_input.read_text());rd,rs,rb,record=load_recent(recent_record,recent_archive)
    original={(t['symbol'],t['anchor']):t for t in record['results']['type1']['trades']}
    allrows=[];summary={};validation={}
    for period,days,ts,bars,events in [('krx',d['days'],json.loads(krx_trades.read_text())['0'],{},d['reference_events']),('recent',rd,json.loads(recent_trades.read_text())['0'],rb,{})]:
        if period=='krx':
            for sym in {t['signal']['symbol'] for t in ts}:
                p=krx_bars/(sym+'.json');assert hashlib.sha256(p.read_bytes()).hexdigest()==d['hashes']['bars'][sym]
                bars[sym]={b['day']:b for b in json.loads(p.read_text())['rows']}
        rows=[]
        for t in ts:
            s=t['signal'];sym=s['symbol'];by=bars[sym];prekeys=sorted(k for k in by if k<=s['anchor'])[-60:]
            pre=[dict(by[k],day=k) for k in prekeys];entry=t.get('entry');i=days.index(s['anchor']);end=days.index(s['end'])
            future=[dict(by.get(k,{}),day=k) for k in days[i+1:end+1]]
            f=features(pre,entry);eventset=set(events.get(sym,[]));pre_events=[k for k in eventset if prekeys and prekeys[0]<=k<=s['anchor']]
            if pre_events:f=None
            oid=original.get((sym,s['anchor'])) if period=='recent' else None
            r=dict(t,id=period+'-'+sym+'-'+s['anchor'],period=period,symbol=sym,anchor=s['anchor'],features=f,path=path_metrics(t,future,eventset),original183=oid is not None,
              original_pnl=oid['pnl'] if oid else None,pre_reference_events=pre_events,reference_events=sorted(eventset),pre=pre,post=future)
            if t['status']=='closed':assert abs((t['exit_price']*.9985/(entry*1.0015)-1)*100-t['net_pct'])<1e-9
            rows.append(r)
        # Match only pre-decision 60-close + 60-volume vectors (no gap or outcomes in distance).
        def vector(r):
            pre=r['pre']
            if len(pre)!=60 or r['pre_reference_events'] or not all(valid(b) for b in pre):return None
            c=np.array([b['close'] for b in pre],float);v=np.array([b['volume'] for b in pre],float)
            if np.linalg.norm(v)==0:return None
            return np.r_[c/np.linalg.norm(c),v/np.linalg.norm(v)]
        vectors={r['id']:vector(r) for r in rows};good=[r for r in rows if r['status']=='closed' and r['net_pct']!=0 and vectors[r['id']] is not None]
        for r in good:
            opposites=[q for q in good if (q['net_pct']>0)!=(r['net_pct']>0) and q['symbol']!=r['symbol']]
            if opposites:
                dist,q=min((float(np.linalg.norm(vectors[r['id']]-vectors[q['id']])),q['id']) for q in opposites)
                r['match_id']=q;r['match_distance']=dist
        summary[period]=summarize(rows);validation[period]=dict(saved_trades=len(ts),atlas_rows=len(rows),complete_features=sum(r['features'] is not None for r in rows),matched=sum('match_id' in r for r in rows),pre_lengths=sorted(set(len(r['pre']) for r in rows)),post_lengths=sorted(set(len(r['post']) for r in rows)))
        allrows+=rows
    summary['original183']=summarize([r for r in allrows if r['original183']]);assert summary['original183']['n']==183
    # Compact OHLC arrays for private viewer. Missing prices stay null, never forward-filled.
    for r in allrows:
        for name in ['pre','post']:r[name]=[[b['day']]+[b.get(k) for k in ['open','high','low','close','volume']] for b in r[name]]
    data=dict(rows=allrows,summary=summary,features=FEATURES)
    (out/'trades.json').write_text(json.dumps(data,ensure_ascii=False,separators=(',',':')))
    (out/'summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2))
    validation['input_hashes']={str(p):hashlib.sha256(p.read_bytes()).hexdigest() for p in [krx_input,krx_trades,recent_record,recent_archive,recent_trades]}
    (out/'validation.json').write_text(json.dumps(validation,ensure_ascii=False,indent=2))
    template=Path(__file__).with_name('type1_trade_atlas.html').read_text()
    (out/'type1-trade-atlas.html').write_text(template.replace('__DATA__',json.dumps(data,ensure_ascii=False,separators=(',',':')).replace('</','<\\/')))
    print(json.dumps(summary,ensure_ascii=False,indent=2))
if __name__=='__main__':
    p=argparse.ArgumentParser()
    for n in ['krx-input','krx-bars','krx-trades','recent-record','recent-archive','recent-trades','output']:p.add_argument('--'+n,type=Path,required=True)
    a=p.parse_args();build(a.krx_input,a.krx_bars,a.krx_trades,a.recent_record,a.recent_archive,a.recent_trades,a.output)
