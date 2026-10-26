"""Dated TOP100 selection diagnostic, with a separately supplied market index."""
import argparse,collections,csv,datetime as dt,hashlib,io,json,math,statistics,zipfile
from pathlib import Path
import numpy as np
TZ=dt.timezone(dt.timedelta(hours=9));STAGES=['all','strong','contraction','breakout']
def sha(b):return hashlib.sha256(b).hexdigest()
def stamp(day,h=15,m=30):return int(dt.datetime.strptime(day,'%Y-%m-%d').replace(hour=h,minute=m,tzinfo=TZ).timestamp())
def valid(a):
 return np.isfinite(a).all(axis=-1)&(a[...,:4]>0).all(axis=-1)&(a[...,4]>=0)&(a[...,2]<=np.minimum(a[...,0],a[...,3]))&(a[...,1]>=np.maximum(a[...,0],a[...,3]))&(a[...,1]>=a[...,2])
def features(hist,index_return):
 if len(hist)!=22 or not valid(hist).all():return None
 close=hist[:,3];tr=np.maximum(hist[1:,1]-hist[1:,2],np.maximum(abs(hist[1:,1]-close[:-1]),abs(hist[1:,2]-close[:-1])))
 ret=(close[-1]/close[-21]-1)*100;prev=float(np.mean(tr[:15]));recent=float(np.mean(tr[15:20]));vr=float(np.mean(hist[-6:-1,4])/np.mean(hist[-21:-6,4])) if np.mean(hist[-21:-6,4])>0 else None
 strong=ret>index_return;contract=recent<prev;br=hist[-1,3]>max(hist[-6:-1,1])
 return dict(return20=ret,index_return20=index_return,excess20=ret-index_return,tr_ratio=recent/prev if prev>0 else None,volume_ratio=vr,strong=bool(strong),contraction=bool(strong and contract),breakout=bool(strong and contract and br),all=True)
def outcome(future,h):
 if len(future)<h:return dict(status='unknown',reason='segment_end')
 a=future[:h]
 if not valid(a).all():return dict(status='unknown',reason='missing_or_invalid')
 if a[0,4]<=0 or a[-1,4]<=0:return dict(status='unknown',reason='zero_entry_or_exit_volume')
 entry=a[0,0];return dict(status='complete',net_pct=float((a[-1,3]*.9985/(entry*1.0015)-1)*100),market_high_pct=float((max(a[:,1])/entry-1)*100),market_low_pct=float((min(a[:,2])/entry-1)*100))
def analyze(rows,h,stage,nonoverlap=False,control_stage="all"):
 selected=[];last={};signals=[r for r in rows if r.get('features',{}).get(stage)]
 for r in sorted(signals,key=lambda r:(r['i'],r['symbol'])):
  if nonoverlap and r['i']<=last.get(r['symbol'],-1000)+20:continue
  selected.append(r);last[r['symbol']]=r['i']
 done=[r for r in selected if r['outcomes'][str(h)]['status']=='complete'];key={(r['date'],r['symbol']) for r in selected}
 daily=collections.defaultdict(list);by=collections.defaultdict(list);control=collections.defaultdict(list);all_day=collections.defaultdict(list)
 for r in rows:
  if 'features' not in r or r['outcomes'][str(h)]['status']!='complete':continue
  x=r['outcomes'][str(h)]['net_pct'];all_day[r['date']].append(x)
  if r['features'].get(control_stage) and (nonoverlap or (r['date'],r['symbol']) not in key):control[r['date']].append(x)
 for r in done:
  x=r['outcomes'][str(h)]['net_pct'];daily[r['date']].append(x);by[r['symbol']].append(x)
 avg=lambda a:float(np.mean(a)) if len(a) else None
 paired={d:avg(v)-avg(control[d]) for d,v in daily.items() if control[d]};months=collections.defaultdict(list)
 for d,x in paired.items():months[d[:7]].append(x)
 vals=[r['outcomes'][str(h)]['net_pct'] for r in done];nonwin=[x for x in vals if x<0]
 return dict(control_stage=control_stage,paired_selected_mean_pct=avg([avg(daily[d]) for d in paired]),paired_control_mean_pct=avg([avg(control[d]) for d in paired]),control_kind='same_date_all' if nonoverlap else 'same_date_complement',signals=len(selected),complete=len(done),unknown=len(selected)-len(done),unknown_reasons=dict(collections.Counter(r['outcomes'][str(h)]['reason'] for r in selected if r['outcomes'][str(h)]['status']!='complete')),symbols=len(by),dates=len(daily),trade_mean_pct=avg(vals),date_equal_mean_pct=avg([avg(v) for v in daily.values()]),symbol_equal_mean_pct=avg([avg(v) for v in by.values()]),win_pct=avg([100*(v>0) for v in vals]),median_pct=statistics.median(vals) if vals else None,mean_loss_pct=avg(nonwin),same_dates_all_mean_pct=avg([avg(all_day[d]) for d in daily]),paired_dates=len(paired),date_equal_complement_diff_pp=avg(list(paired.values())),paired_positive_dates=sum(v>0 for v in paired.values()),monthly_diff_pp={k:avg(v) for k,v in sorted(months.items())},market_high_mean_pct=avg([r['outcomes'][str(h)]['market_high_pct'] for r in done]),market_low_mean_pct=avg([r['outcomes'][str(h)]['market_low_pct'] for r in done]))
def main():
 ap=argparse.ArgumentParser()
 for n in ['previous','archives','index','protocol','output']:ap.add_argument('--'+n,type=Path,required=True)
 a=ap.parse_args()
 if a.output.exists():raise ValueError('New output required')
 a.output.mkdir();manifest=json.loads((a.previous/'input-manifest.json').read_text());checks=dict(input_hashes={},calendar_checks={},stock_price_hashes={})
 hashes=json.loads((a.previous/'file-hashes.json').read_text())
 for f in ['input-manifest.json','observations.jsonl']:
  b=(a.previous/f).read_bytes();assert sha(b)==hashes[f];checks['input_hashes'][f]=sha(b)
 with zipfile.ZipFile(a.index) as z:
  for line in z.read('SHA256SUMS.txt').decode().splitlines():
   h,n=line.split();assert sha(z.read(n.lstrip('*')))==h
  idx={}
  for market,name in [('KS','kospi_daily_20230102_latest.csv'),('KQ','kosdaq_daily_20230102_latest.csv')]:
   idx[market]=list(csv.DictReader(io.StringIO(z.read(name).decode('utf-8-sig'))));days=[r['date'] for r in idx[market]];assert days==sorted(set(days));assert valid(np.array([[float(r[k]) for k in ['open','high','low','close','volume']] for r in idx[market]])).all()
  days=[r['date'] for r in idx['KS']];assert days==[r['date'] for r in idx['KQ']];di={d:i for i,d in enumerate(days)};ic={m:np.array([float(r['close']) for r in rs]) for m,rs in idx.items()}
 checks['input_hashes'].update(index_archive=sha(a.index.read_bytes()),protocol=sha(a.protocol.read_bytes()))
 cand=collections.defaultdict(list)
 with (a.previous/'observations.jsonl').open() as f:
  for l in f:
   r=json.loads(l);cand[r['segment']].append({k:r[k] for k in ['date','symbol','name','rank']})
 archivepaths={p.name:p for p in a.archives.glob('*.zip')};expected={p['name']:p['sha256'] for p in manifest['archives']}
 for name,h in expected.items():assert sha(archivepaths[name].read_bytes())==h
 allsummary={};audit=[]
 with (a.output/'observations.jsonl').open('w') as dest:
  for seg in manifest['segments']:
   label=seg['label'];sd=seg['days'];assert sd==[d for d in days if sd[0]<=d<=sd[-1]];checks['calendar_checks'][label]=len(sd)
   symbols=sorted(seg['bar_hashes']);si={s:i for i,s in enumerate(symbols)};matrix=np.full((len(symbols),len(days),5),np.nan);seen=collections.defaultdict(set);verified=0
   if label=='krx':
    rawseen={}
    for name in expected:
     if name.startswith('kr-dated-amount-top100'):continue
     p=archivepaths[name]
     with zipfile.ZipFile(p) as z:
      for fn in z.namelist():
       if not(fn.endswith('_equity.json') and ('/raw/' in fn or '/2024_raw/' in fn)):continue
       raw=z.read(fn);base=fn.rsplit('/',1)[-1]
       if base in rawseen:
        oldp,oldfn=rawseen[base]
        with zipfile.ZipFile(oldp) as zz:assert json.loads(zz.read(oldfn))==json.loads(raw)
        continue
       rawseen[base]=(str(p),fn);day=f'{base[:4]}-{base[4:6]}-{base[6:8]}';j=di[day]
       for r in json.loads(raw)['OutBlock_1']:
        if r.get('MKT_NM') not in ['KOSPI','KOSDAQ']:continue
        sym=r['ISU_CD']+('.KS' if r['MKT_NM']=='KOSPI' else '.KQ')
        if sym not in si:continue
        def num(k):
         try:return float(str(r[k]).replace(',',''))
         except (KeyError,ValueError):return float('nan')
        matrix[si[sym],j]=[num(k) for k in ['TDD_OPNPRC','TDD_HGPRC','TDD_LWPRC','TDD_CLSPRC','ACC_TRDVOL']];seen[sym].add(day)
    for sym in symbols:
     raw=[]
     for day in sorted(seen[sym]):
      vals=matrix[si[sym],di[day]];raw.append(dict(time=stamp(day,9,0),time_kst=day+' 09:00:00',available_at=stamp(day),is_complete=True,**{k:float(v) if math.isfinite(v) else None for k,v in zip(['open','high','low','close','volume'],vals)}))
     assert sha(json.dumps(raw,ensure_ascii=False,separators=(',',':')).encode())==seg['bar_hashes'][sym],sym
     verified+=1
   else:
    name=next(n for n in expected if ('20241008-20251002' if label=='yahoo_prior' else '20251008-20261008') in n)
    with zipfile.ZipFile(archivepaths[name]) as z:
     for fn in z.namelist():
      if '/bars/' not in fn or not fn.endswith('.json'):continue
      sym=Path(fn).stem
      if sym not in si:continue
      raw=sorted(json.loads(z.read(fn))['rows'],key=lambda b:b['time']);assert sha(json.dumps(raw,ensure_ascii=False,separators=(',',':')).encode())==seg['bar_hashes'][sym],sym;verified+=1
      for b in raw:
       day=dt.datetime.fromtimestamp(b['time'],TZ).strftime('%Y-%m-%d')
       if day not in di:continue
       assert day not in seen[sym];seen[sym].add(day)
       if b.get('synthetic') or b.get('is_complete',True) is False:continue
       matrix[si[sym],di[day]]=[b.get(k,float('nan')) for k in ['open','high','low','close','volume']]
   checks['stock_price_hashes'][label]=verified;assert verified==len(symbols)
   rows=[];stop=di[sd[-1]]+1
   for c in cand[label]:
    i=di[c['date']];sym=c['symbol'];r=dict(c,i=i,segment=label)
    if i<21:r['blocked']='index_history_short'
    elif sym not in si:r['blocked']='missing_stock'
    else:
     b=matrix[si[sym]];ir=(ic[sym[-2:]][i]/ic[sym[-2:]][i-20]-1)*100;f=features(b[i-21:i+1],ir)
     if f is None:r['blocked']='stock_history_missing_or_invalid'
     else:r.update(features=f,outcomes={str(h):outcome(b[i+1:stop],h) for h in [5,10,20]})
    rows.append(r);dest.write(json.dumps(r,ensure_ascii=False,separators=(',',':'))+'\n')
   summary=dict(period=[sd[0],sd[-1]],observations=len(rows),eligible=sum('features' in r for r in rows),blocked=dict(collections.Counter(r['blocked'] for r in rows if 'blocked' in r)),horizons={str(h):{st:analyze(rows,h,st) for st in STAGES} for h in [5,10,20]},nonoverlap20={st:analyze(rows,20,st,True) for st in STAGES})
   summary['incremental20']={st:analyze(rows,20,st,control_stage=prev) for st,prev in [('strong','all'),('contraction','strong'),('breakout','contraction')]}
   p=summary['horizons']['20']['breakout'];nn=summary['nonoverlap20']['breakout'];enough=p['complete']>=30 and p['symbols']>=20 and p['dates']>=10
   summary['gate']=dict(enough=enough,primary_positive=bool(enough and p['date_equal_mean_pct']>0 and (p['date_equal_complement_diff_pp'] or 0)>0),nonoverlap_positive=bool(nn['complete'] and nn['date_equal_mean_pct']>0 and (nn['date_equal_complement_diff_pp'] or 0)>0))
   allsummary[label]=summary;print(label,summary['eligible'],json.dumps(p),flush=True)
   del matrix
 (a.output/'summary.json').write_text(json.dumps(allsummary,ensure_ascii=False,indent=2));(a.output/'validation.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
if __name__=='__main__':main()
