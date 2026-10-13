#!/usr/bin/env python3
"""Independent arithmetic audit; accepts extracted decision-day ZIP, never updates account data."""
import argparse, hashlib, json, math, statistics as st
from pathlib import Path
from datetime import datetime
from zoneinfo import ZoneInfo
from xml.etree import ElementTree as ET
ROOT=Path(__file__).resolve().parents[2]
PACK=ROOT/'docs/pullback-year-review'
def read(p): return json.loads(p.read_text())
def check(ok,label):
    if not ok: raise ValueError(label)
def near(a,b,label,tol=1e-6):
    check(a is not None and b is not None and abs(a-b)<=tol,label)
def digest(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def input_hash(rows):
    v=[[r[k] for k in ('date','open','high','low','close','volume','ohlc_invalid')] for r in rows]
    v=[[int(x) if isinstance(x,float) and x.is_integer() else x for x in row] for row in v]
    return hashlib.sha256(json.dumps(v,separators=(',',':'),ensure_ascii=False).encode()).hexdigest()
def check_svg(path,rows,levels=None):
    root=ET.parse(path).getroot(); ns={'s':'http://www.w3.org/2000/svg'}
    valid=[(i,r) for i,r in enumerate(rows) if not r['ohlc_invalid']]
    prices=[r[k] for _,r in valid for k in ('high','low')]+[r[k] for r in rows for k in ('ma20','ma60') if r[k] is not None]
    if levels:prices+=list(levels.values())
    hi,lo=max(prices),min(prices); pad=(hi-lo)*.04;hi+=pad;lo-=pad
    y=lambda p:28+(hi-p)/(hi-lo)*286
    lines=[e for e in root.findall('s:line',ns) if e.get('stroke') in ('#c0392b','#2471a3')]
    rects=[e for e in root.findall('s:rect',ns) if e.get('fill') in ('#c0392b','#2471a3')]
    check(len(lines)==len(valid) and len(rects)==2*len(valid),str(path)+' bar count')
    volmax=max(1,max(r['volume'] or 0 for r in rows))
    for j,(i,r) in enumerate(valid):
        x=72+(i+.5)*872/len(rows);line=lines[j];body,vol=rects[2*j:2*j+2]
        for k,want in {'x1':x,'x2':x,'y1':y(r['high']),'y2':y(r['low'])}.items():near(float(line.get(k)),want,str(path)+k,.051)
        for k,want in {'x':x-2.2,'y':min(y(r['open']),y(r['close'])),'height':max(1,abs(y(r['open'])-y(r['close'])))}.items():near(float(body.get(k)),want,str(path)+k,.051)
        h=max(1,(r['volume'] or 0)/volmax*80)
        near(float(vol.get('height')),h,str(path)+' volume',.051);near(float(vol.get('y')),402-h,str(path)+' volume y',.051)
    if levels:
        for key,color in (("entry","#1a1a1a"),("stop","#6c3483"),("target","#1a5276")):
            marks=[e for e in root.findall("s:line",ns) if e.get("stroke")==color and e.get("stroke-dasharray")=="5 3"]
            check(len(marks)==1,str(path)+key+" marker count")
            near(float(marks[0].get("y1")),y(levels[key]),str(path)+key+" marker y",.051)
    return len(valid)
def calculate(rows):
    b=[r for r in rows if not r['ohlc_invalid']];last,prev=b[-2:][::-1]
    ma=st.mean(r['close'] for r in b[-20:]);old=st.mean(r['close'] for r in b[-25:-5])
    atr=st.mean(max(b[i]['high']-b[i]['low'],abs(b[i]['high']-b[i-1]['close']),abs(b[i]['low']-b[i-1]['close'])) for i in range(len(b)-14,len(b)))
    atr=round(atr,4)  # FeatureEngine exposes ATR rounded to four decimals.
    low=lambda xs:min(r['low'] for r in xs)
    high=lambda xs:max(r['high'] for r in xs)
    rawstop=low(b[-10:])-.2*atr; rawtarget=high(b[-21:-1]);entry=math.floor(last['close']);stop=math.floor(rawstop);target=math.floor(rawtarget)
    zoneLow=math.floor(ma-.4*atr);zoneHigh=math.floor(ma+.4*atr)
    basevol=st.mean(r['volume'] for r in b[-24:-4]);pullvol=st.mean(r['volume'] for r in b[-4:-1])
    gates=dict(rising_structure=ma>old and low(b[-20:])>low(b[-40:-20]) and high(b[-20:])>high(b[-40:-20]),atr_valid=atr>0,
        valid_zone=0<stop<zoneLow<=zoneHigh<target and last['close']>rawstop,
        near_ma20=low(b[-6:-1])<=ma+.75*atr,volume_contracted=pullvol<basevol*.85,
        recovery_close=last['close']>prev['high'],bullish_candle=last['close']>last['open'],volume_recovery=last['volume']>prev['volume'],fresh_confirmation=prev['close']<=b[-3]['high'])
    hidx=max(i for i in range(len(b)-21,len(b)-1) if b[i]['high']==rawtarget)
    segment=b[hidx+1:-1]; l=low(segment) if segment else None
    stoplow=low(b[-10:]); stopdates=[r['date'] for r in b[-10:] if r['low']==stoplow]
    rec= dict(entry=entry,stop=stop,target=target,reward_risk=round((target-entry)/(entry-stop),3),gates=gates,
        values=dict(ma20=ma,old_ma20=old,atr14=atr,near_threshold=ma+.75*atr,stop_raw=rawstop,target_raw=rawtarget,base_volume=basevol,pull_volume=pullvol,volume_ratio=pullvol/basevol),
        signal_below_ma20=last['close']<ma,signal_ma20_atr=(last['close']-ma)/atr,
        near_low_ma20_atr=(low(b[-6:-1])-ma)/atr,near_low_below_minus_075=low(b[-6:-1])<ma-.75*atr,
        reference_H_date=b[hidx]['date'],reference_H_price=rawtarget,post_H_bars=len(segment),
        reference_L_price=l,reference_L_dates=[r['date'] for r in segment if r['low']==l],stop_basis_dates=stopdates,
        stop_basis_matches_post_H_low=stoplow==l if l is not None else None,
        signal_recovers_all_post_H_highs=last['close']>high(segment) if segment else None,
        initial_stop_width_pct=(entry-stop)/entry*100,below_H_pct=(rawtarget-last['close'])/rawtarget*100)
    return rec

def analyze(prices):
    listing=read(PACK/'list.json');check((prices/'list.json').read_bytes()==(PACK/'list.json').read_bytes(),'ZIP list differs')
    output=[];svg_bars=0;all_rows=0
    for c in listing['cases']:
        id=c['case_id'];data=read(prices/'json/a'/f'{id}-input.json');a=read(PACK/'json/a'/f'{id}.json');rows=data['bars'];all_rows+=len(rows)
        check(input_hash(rows)==c['a_input_sha256']==a['indicator_input']['sha256']==data['indicator_input']['sha256'],id+' input hash')
        ts=[r['available_at'] for r in rows];check(ts==sorted(set(ts)) and ts[-1]==c['session'],id+' timestamps')
        closes=[]
        for r in rows:
            check(datetime.fromtimestamp(r['available_at'],ZoneInfo('Asia/Seoul')).strftime('%Y-%m-%d')==r['date'],id+' date')
            invalid=r['low']>min(r['open'],r['close']) or r['high']<max(r['open'],r['close']) or r['low']>r['high']
            check(invalid==r['ohlc_invalid'],id+' invalid flag')
            if not invalid:closes.append(r['close'])
            for n in (20,60):
                want=st.mean(closes[-n:]) if not invalid and len(closes)>=n else None
                got=r[f'ma{n}']
                if want is None:check(got is None,id+' null MA')
                else:near(want,got,id+' MA')
        for n in (120,240):svg_bars+=check_svg(PACK/'a'/f'{id}-{n}.svg',rows[-n:])
        rec=calculate(rows);b=read(PACK/'json/b'/f'{id}.json')
        check(rec['gates']==b['gates']==b['pullback']['gates'] and all(rec['gates'].values()),id+' gates')
        for k in ('entry','stop','target','reward_risk'):near(rec[k],b['pullback'][k],id+k)
        for k,v in rec['values'].items():near(v,b['windows']['values'][k],id+k)
        check_svg(PACK/'b'/f'{id}.svg',rows[-240:],{k:rec[k] for k in ('entry','stop','target')})
        valid=[r for r in rows if not r['ohlc_invalid']]
        slices={'rising_recent':valid[-20:],'rising_prior':valid[-40:-20],'ma20_closes':valid[-20:],'old_ma_closes':valid[-25:-5],'near_ma20':valid[-6:-1],'stop_window':valid[-10:],'target_window':valid[-21:-1],'volume_base':valid[-24:-4],'volume_pull':valid[-4:-1],'signal_bar':valid[-1:]}
        for key,bs in slices.items():
            win=b['windows'][key];check(win['bar_count']==len(bs) and win['first_date']==bs[0]['date'] and win['last_date']==bs[-1]['date'],id+key+' window')
            near(win['min_low'],min(r['low'] for r in bs),id+key+' low');near(win['max_high'],max(r['high'] for r in bs),id+key+' high')
        check(rec['reward_risk']>=1.5 and rec['target']>rec['entry'],id+' ready')
        output.append(dict(index=c['index'],case_id=id,symbol=c['symbol'],name=c['name'],signal_date=c['signal_date'],**rec))
    return dict(scope='independent pullback arithmetic and A OHLCV/MA/SVG audit; not independent common risk or execution audit',cases=output,
        verification=dict(cases=len(output),input_bars=all_rows,A_charts=84,B_charts=42,B_price_markers=126,svg_valid_candles_and_volume_bars=svg_bars,issues=0),
        inputs=dict(list_sha256=digest(PACK/'list.json'),zip_sha256='870bcd66f231b8cecdc62f0931cc94d91924947c439e3e8a6fad6b1527f1f957'))
def join_outcomes(result):
    audit=PACK/'audit';freeze=read(audit/'asof-freeze.json')
    for f,h in freeze['files'].items():check(digest(audit/f)==h,'As-of freeze changed: '+f)
    frozen=read(audit/'asof-metrics.json')
    check(result['cases']==frozen['cases'] and result['inputs']==frozen['inputs'],'Recomputed metrics differ from freeze')
    path=ROOT/'docs/historical-top100-replay-20251008-20261008/account/trades.json'
    check(digest(path)==read(PACK/'list.json')['trades_sha256'],'Trade source changed')
    trades={(t['symbol'],t['signal_date']):t for t in read(path)['trades']}
    cases=[]
    for c in result['cases']:
        t=trades[c['symbol'],c['signal_date']];check(t['pattern']=='trend_pullback_v1' and t['status']=='closed','Trade membership')
        cases.append(c|dict(net_pnl=t['exit']['net_pnl'],net_return_pct=100*t['exit']['net_pnl']/(t['quantity']*t['fill']['price']+t['fill']['fee']),exit_reason=t['exit']['reason']))
    def stats(xs):
        return dict(n=len(xs),wins=sum(x['net_pnl']>0 for x in xs),losses=sum(x['net_pnl']<0 for x in xs),net_pnl=sum(x['net_pnl'] for x in xs),mean_net_return_pct=st.mean(x['net_return_pct'] for x in xs) if xs else None)
    flags=['signal_below_ma20','near_low_below_minus_075','stop_basis_matches_post_H_low','signal_recovers_all_post_H_highs']
    return dict(trades_sha256=digest(path),asof_freeze_sha256=digest(audit/'asof-freeze.json'),total=stats(cases),
        groups={f:{str(v):stats([c for c in cases if c[f] is v]) for v in (True,False,None)} for f in flags},
        stop_width_pct=dict(min=min(c['initial_stop_width_pct'] for c in cases),median=st.median(c['initial_stop_width_pct'] for c in cases),max=max(c['initial_stop_width_pct'] for c in cases)),cases=cases)
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--prices-dir',type=Path,required=True);p.add_argument('--output',type=Path,required=True);p.add_argument('--join-outcomes',action='store_true');args=p.parse_args()
    result=analyze(args.prices_dir)
    if args.join_outcomes:result=join_outcomes(result)
    args.output.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n');print(json.dumps(result.get('verification',result.get('total'))))
