"""Frozen PR119 fills: original structure exit vs 5% and 10%, no entry replay."""
import argparse,collections,datetime as dt,hashlib,json,math,statistics,zipfile
from pathlib import Path
TZ=dt.timezone(dt.timedelta(hours=9))
def stamp(day,h=15,m=30):return int(dt.datetime.strptime(day,'%Y-%m-%d').replace(hour=h,minute=m,tzinfo=TZ).timestamp())
def dayof(ts):return dt.datetime.fromtimestamp(ts,TZ).strftime('%Y-%m-%d')
def digest(b):return hashlib.sha256(b).hexdigest()
def valid(b):
 try:return (b is not None and not b.get('synthetic') and b.get('is_complete',True) is not False and all(math.isfinite(float(b[k])) for k in ['open','high','low','close','volume']) and min(b[k] for k in ['open','high','low','close'])>0 and b['volume']>=0 and b['low']<=min(b['open'],b['close'])<=max(b['open'],b['close'])<=b['high'])
 except (KeyError,TypeError,ValueError):return False

def load_prices(previous,archives,output):
 manifest=json.loads((previous/'input-manifest.json').read_text());trades=json.loads((previous/'trades.json').read_text());need=collections.defaultdict(set)
 for t in trades:
  if t['trade']['filled']:need[t['segment']].add(t['symbol'])
 expected={a['name']:a['sha256'] for a in manifest['archives']};raw={};prices={};verified=[]
 for p in sorted(archives.glob('*.zip')):
  if p.name not in expected:continue
  if digest(p.read_bytes())!=expected[p.name]:raise ValueError('Archive hash '+p.name)
  verified.append(p.name)
  with zipfile.ZipFile(p) as z:
   if 'kr-dated-amount-top100-' in p.name:
    label='yahoo_prior' if '20241008-20251002' in p.name else 'yahoo_recent'
    for n in z.namelist():
     if '/bars/' in n and n.endswith('.json') and Path(n).stem in need[label]:
      r=json.loads(z.read(n));prices[label,r['symbol']]=sorted(r['rows'],key=lambda b:b['time'])
   else:
    for n in z.namelist():
     if n.endswith('_equity.json') and ('/raw/' in n or '/2024_raw/' in n):
      name=n.split('/')[-1];b=z.read(n)
      if name in raw:assert json.loads(raw[name])==json.loads(b)
      raw[name]=b
 assert set(verified)==set(expected)
 krx=collections.defaultdict(dict)
 for name,data in sorted(raw.items()):
  date=f'{name[:4]}-{name[4:6]}-{name[6:8]}'
  for r in json.loads(data)['OutBlock_1']:
   if r.get('MKT_NM') not in ['KOSPI','KOSDAQ']:continue
   sym=r['ISU_CD']+('.KS' if r['MKT_NM']=='KOSPI' else '.KQ')
   if sym not in need['krx']:continue
   def num(k):
    try:return float(str(r[k]).replace(',',''))
    except (ValueError,KeyError):return None
   krx[sym][date]=dict(time=stamp(date,9,0),time_kst=date+' 09:00:00',available_at=stamp(date),is_complete=True,open=num('TDD_OPNPRC'),high=num('TDD_HGPRC'),low=num('TDD_LWPRC'),close=num('TDD_CLSPRC'),volume=num('ACC_TRDVOL'))
 for sym,rows in krx.items():prices['krx',sym]=sorted(rows.values(),key=lambda b:b['time'])
 for seg in manifest['segments']:
  for sym in need[seg['label']]:
   rows=prices[seg['label'],sym];data=json.dumps(rows,ensure_ascii=False,separators=(',',':')).encode()
   if digest(data)!=seg['bar_hashes'][sym]:raise ValueError('Prepared price hash '+seg['label']+' '+sym)
   dest=output/seg['label'];dest.mkdir(parents=True,exist_ok=True);(dest/(sym+'.json')).write_bytes(data)
 return prices,manifest,dict(archives_verified=len(verified),prepared_price_hashes_verified=sum(map(len,need.values())))

def exit_path(t,days,by,width=None):
 old=t['trade'];entry=old['entry_fill'];start=dayof(old['entry_at']);index=days.index(start);window=days[index:index+20];stop=t['plan']['stop'] if width is None else entry*(1-width);target=t['plan']['target']
 result=dict(status='unresolved',filled=True,entry=entry,entry_day=start,stop=stop,target=target)
 for i,day in enumerate(window):
  b=by.get(day)
  if not valid(b):return dict(result,unresolved_day=day,unresolved_reason='missing_or_invalid_future_bar')
  hitstop=b['low']<=stop;hittarget=b['high']>=target
  if i>0 and b['open']>=target:reason,price='target',target
  elif hitstop:reason,price='stop',min(stop,b['open'])*.9995
  elif hittarget:reason,price='target',target
  elif i==19:reason,price='time',b['close']*.9995
  else:continue
  # Original simulator rounds stored fill to six decimals and returns to four.
  ret=(price*.999/(entry*1.001)-1)*100;stored=round(price,6)
  return dict(result,status='closed',exit_day=day,exit_price=stored,exit_reason=reason,held_bars=i+1,net_pct=round(ret,4),pnl_won=t['quantity']*stored*.999-t['buy_cost'],same_bar_unknown=hitstop and hittarget and not(i>0 and b['open']>=target))
 return dict(result,unresolved_reason='segment_end')

def stats(rows):
 c=[r for r in rows if r['status']=='closed'];a=[r['net_pct'] for r in c];loss=[v for v in a if v<0]
 return dict(n=len(rows),closed=len(c),unresolved=len(rows)-len(c),mean_pct=statistics.mean(a) if a else None,win_pct=100*sum(v>0 for v in a)/len(a) if a else None,median_pct=statistics.median(a) if a else None,mean_loss_pct=statistics.mean(loss) if loss else None,mean_held=statistics.mean(r['held_bars'] for r in c) if c else None,pnl_won=sum(r['pnl_won'] for r in c),exit_counts=dict(collections.Counter(r['exit_reason'] for r in c)))
def compare(rows):
 common=[r for r in rows if all(r[k]['status']=='closed' for k in ['original','stop5','stop10'])]
 return dict(filled=len(rows),common_n=len(common),all_arms={k:stats([r[k] for r in rows]) for k in ['original','stop5','stop10']},common={k:stats([r[k] for r in common]) for k in ['original','stop5','stop10']},transitions={k:dict(improved=sum(r[k]['net_pct']>r['original']['net_pct']+1e-6 for r in common),worsened=sum(r[k]['net_pct']<r['original']['net_pct']-1e-6 for r in common),stop_to_profit=sum(r['original']['exit_reason']=='stop' and r[k]['net_pct']>0 for r in common)) for k in ['stop5','stop10']})
def main():
 p=argparse.ArgumentParser();p.add_argument('--previous',type=Path,required=True);p.add_argument('--archives',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args()
 if a.output.exists():raise ValueError('New output required')
 for f,h in json.loads((a.previous/'file-hashes.json').read_text()).items():
  if f.startswith('.'):continue
  if digest((a.previous/f).read_bytes())!=h:raise ValueError('Prior result hash '+f)
 a.output.mkdir();prices,manifest,validation=load_prices(a.previous,a.archives,a.output/'prepared');trades=json.loads((a.previous/'trades.json').read_text());cal={s['label']:s['days'] for s in manifest['segments']};out=[]
 for t in trades:
  if not t['trade']['filled']:continue
  raw=prices[t['segment'],t['symbol']];by={dayof(b.get('available_at') or stamp(dayof(b['time']))):b for b in raw};arms={k:exit_path(t,cal[t['segment']],by,w) for k,w in [('original',None),('stop5',.05),('stop10',.10)]};r=arms['original'];old=t['trade']
  assert r['status']==old['status'],(t['symbol'],t['date'],r,old)
  if r['status']=='closed':
   assert (r['exit_day'],r['exit_reason'],r['held_bars'])==(dayof(old['exit_at']),old['first_exit'],old['bars'])
   assert abs(r['exit_price']-old['exit_fill'])<1e-6 and abs(r['net_pct']-old['net_return_pct'])<.00011
   assert abs(r['pnl_won']-t['pnl_won'])<.01
  out.append(dict(segment=t['segment'],symbol=t['symbol'],name=t['name'],signal_day=t['date'],score=t['score'],quantity=t['quantity'],original_stop_width_pct=(1-t['plan']['stop']/old['entry_fill'])*100,original_target_width_pct=(t['plan']['target']/old['entry_fill']-1)*100,**arms))
 validation['baseline_filled_matches']=len(out);validation['baseline_closed_matches']=sum(r['original']['status']=='closed' for r in out)
 summary={label:compare([r for r in out if r['segment']==label]) for label in cal};summary['all']=compare(out)
 for n,obj in [('trades',out),('summary',summary),('validation',validation),('calendars',cal)]: (a.output/(n+'.json')).write_text(json.dumps(obj,ensure_ascii=False,indent=2))
 print(json.dumps(summary,ensure_ascii=False,indent=2))
if __name__=='__main__':main()
