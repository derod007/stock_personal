"""Dated TOP100 selection diagnostic, with a separately supplied market index."""
import argparse,collections,csv,datetime as dt,hashlib,io,json,math,statistics,zipfile
from pathlib import Path
import numpy as np
TZ=dt.timezone(dt.timedelta(hours=9));STAGES=['all','strong','contraction','breakout']
def sha(b):return hashlib.sha256(b).hexdigest()
def stamp(day,h=15,m=30):return int(dt.datetime.strptime(day,'%Y-%m-%d').replace(hour=h,minute=m,tzinfo=TZ).timestamp())
def valid(a):
 return np.isfinite(a).all(axis=-1)&(a[...,:4]>0).all(axis=-1)&(a[...,4]>=0)&(a[...,2]<=np.minimum(a[...,0],a[...,3]))&(a[...,1]>=np.maximum(a[...,0],a[...,3]))&(a[...,1]>=a[...,2])
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
 
 with (a.output/'cache-note.txt').open('w') as dest:
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
   buffer=io.BytesIO();np.savez_compressed(buffer,matrix=matrix,symbols=np.array(symbols),days=np.array(days));(a.output/(label+'.npz')).write_bytes(buffer.getvalue())
   print(label,verified,flush=True)
 (a.output/'validation.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
if __name__=='__main__':main()
