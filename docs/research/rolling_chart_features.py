"""Past-only chart features and frozen temporal discrimination study."""
import argparse,collections,gzip,hashlib,io,json,zipfile
from pathlib import Path
import numpy as np
FEATURES=['return20','drawdown20','low_change10','distance_high20','volume_ratio5_15','range_ratio5_15']
def features(a):
 if len(a)!=21 or not np.isfinite(a).all() or (a[:,:4]<=0).any() or (a[:,4]<0).any():return None
 c=a[:,3];volold=np.mean(a[1:16,4]);ranges=(a[:,1]-a[:,2])/c;rangeold=np.mean(ranges[1:16])
 if volold<=0 or rangeold<=0:return None
 x=[c[-1]/c[0]-1,np.min(c/np.maximum.accumulate(c)-1),np.min(a[-10:,2])/np.min(a[1:11,2])-1,c[-1]/np.max(a[1:,1])-1,np.mean(a[-5:,4])/volold,np.mean(ranges[-5:])/rangeold]
 return [float(v) for v in x] if np.isfinite(x).all() else None

def assign(x,cuts):return [int(np.searchsorted(c,v,side='right')) for v,c in zip(x,cuts)]
def avg(xs):return float(np.mean(xs)) if len(xs) else None
def stats(rows,keys,allcontrol=False):
 selected=[r for r in rows if (r['i'],r['symbol']) in keys];done=[r for r in selected if r['hit15'] is not None]
 d=collections.defaultdict(list);ctrl=collections.defaultdict(list);sym=collections.defaultdict(list)
 for r in done:d[r['date']].append(r);sym[r['symbol']].append(r)
 for r in rows:
  if r['hit15'] is not None and (allcontrol or (r['i'],r['symbol']) not in keys):ctrl[r['date']].append(r)
 pairs=sorted(set(d)&set(ctrl));out=dict(signals=len(selected),complete=len(done),unknown=len(selected)-len(done),symbols=len(sym),dates=len(d),paired_dates=len(pairs))
 for k in ['hit15','net20']:
  out[k]=dict(date_mean=avg([avg([r[k] for r in rs]) for rs in d.values()]),trade_mean=avg([r[k] for r in done]),symbol_mean=avg([avg([r[k] for r in rs]) for rs in sym.values()]),paired_selected=avg([avg([r[k] for r in d[t]]) for t in pairs]),paired_control=avg([avg([r[k] for r in ctrl[t]]) for t in pairs]),paired_diff=avg([avg([r[k] for r in d[t]])-avg([r[k] for r in ctrl[t]]) for t in pairs]))
 hits=[r for r in done if r['hit15']==100];out.update(hit_count=len(hits),hit_but_net_loss=sum(r['net20']<0 for r in hits),first_hit_median=float(np.median([r['first_hit'] for r in hits])) if hits else None,unknown_reasons=dict(collections.Counter(r['unknown_reason'] for r in selected if r['hit15'] is None)))
 return out

def no_overlap(rows,keys):
 last={};out=set()
 for r in sorted(rows,key=lambda r:(r['i'],r['symbol'])):
  k=(r['i'],r['symbol'])
  if k in keys and r['i']>last.get(r['symbol'],-10000)+20:out.add(k);last[r['symbol']]=r['i']
 return out

def main():
 ap=argparse.ArgumentParser()
 for n in ['input','input_validation','prices','protocol','output']:ap.add_argument('--'+n,type=Path,required=True)
 a=ap.parse_args()
 if a.output.exists():raise ValueError('New output directory required')
 a.output.mkdir();priorval=json.loads(a.input_validation.read_text());source=collections.defaultdict(list)
 for line in a.input.open():
  r=json.loads(line)
  if r['universe']=='rolling20':source[r['segment']].append(r)
 checks=dict(input_sha256=hashlib.sha256(a.input.read_bytes()).hexdigest(),protocol_sha256=hashlib.sha256(a.protocol.read_bytes()).hexdigest(),cache_hashes={},net_matches=0)
 summary={};allrows={};cuts=None;chosen=None
 for seg in ['krx','yahoo_prior','yahoo_recent']:
  raw=(a.prices/(seg+'.npz')).read_bytes();h=hashlib.sha256(raw).hexdigest();assert h==priorval['cache_hashes'][seg];checks['cache_hashes'][seg]=h
  z=np.load(io.BytesIO(raw));matrix=z['matrix'];si={str(v):i for i,v in enumerate(z['symbols'])};rows=[];missing=0
  for r in source[seg]:
   b=matrix[si[r['symbol']]];i=r['i'];f=features(b[i-20:i+1])
   if f is None:missing+=1;continue
   v={k:r[k] for k in ['segment','date','i','symbol','net20']};v['features']=f
   if r['net20'] is None:v.update(hit15=None,first_hit=None,unknown_reason=r['outcomes']['20']['reason'])
   else:
    future=b[i+1:i+21];entry=future[0,0];assert abs((future[-1,3]*.9985/(entry*1.0015)-1)*100-r['net20'])<1e-9;checks['net_matches']+=1
    hits=np.flatnonzero(future[:,1]>=entry*1.15);v.update(hit15=100*int(len(hits)>0),first_hit=int(hits[0])+1 if len(hits) else None,unknown_reason=None)
   rows.append(v)
  if cuts is None:
   cuts=np.quantile(np.array([r['features'] for r in rows]),[1/3,2/3],axis=0).T.tolist();(a.output/'cuts.json').write_text(json.dumps(dict(features=FEATURES,cuts=cuts),indent=2))
  for r in rows:r['bins']=assign(r['features'],cuts)
  basekeys={(r['i'],r['symbol']) for r in rows};result=dict(source_rows=len(source[seg]),feature_unknown=missing,eligible=len(rows),all=stats(rows,basekeys),bins={},distributions={})
  for j,f in enumerate(FEATURES):
   result['bins'][f]={str(k):stats(rows,{(r['i'],r['symbol']) for r in rows if r['bins'][j]==k}) for k in [0,1,2]}
   win=[r['features'][j] for r in rows if r['hit15']==100];lose=[r['features'][j] for r in rows if r['hit15']==0]
   wm=float(np.median(win)) if win else None;lm=float(np.median(lose)) if lose else None
   result['distributions'][f]=dict(hit_n=len(win),nonhit_n=len(lose),hit_quartiles=np.quantile(win,[.25,.5,.75]).tolist() if win else None,nonhit_quartiles=np.quantile(lose,[.25,.5,.75]).tolist() if lose else None,hit_at_or_below_nonhit_median_pct=100*avg([x<=lm for x in win]) if win and lose else None)
  if seg=='krx':
   choices=[]
   for j,f in enumerate(FEATURES):
    for k in [0,2]:
     v=result['bins'][f][str(k)];diff=v['hit15']['paired_diff']
     if v['complete']>=500 and v['dates']>=60 and v['symbols']>=50 and diff is not None and diff>0:choices.append((diff,-j,-k,f,k))
   if choices:
    winner=max(choices);chosen=dict(feature=winner[3],bin=winner[4],training_uplift_pp=winner[0])
   (a.output/'selection.json').write_text(json.dumps(chosen,indent=2))
  if chosen:
   j=FEATURES.index(chosen['feature']);keys={(r['i'],r['symbol']) for r in rows if r['bins'][j]==chosen['bin']};result['chosen']=stats(rows,keys);result['chosen_nonoverlap']=stats(rows,no_overlap(rows,keys),True)
  allrows[seg]=rows;summary[seg]=result;print(seg,'chosen',chosen,'stats',json.dumps(result.get('chosen')),flush=True)
 serialized=''.join(json.dumps(r,separators=(',',':'))+'\n' for rows in allrows.values() for r in rows).encode()
 path=a.output/'observations.jsonl.gz';path.write_bytes(gzip.compress(serialized,mtime=0))
 restored=gzip.decompress(path.read_bytes());assert restored==serialized
 checks['serialized_rows']=len(restored.splitlines());assert checks['serialized_rows']==sum(len(rows) for rows in allrows.values())
 (a.output/'summary.json').write_text(json.dumps(summary,indent=2));(a.output/'validation.json').write_text(json.dumps(checks,indent=2))
if __name__=='__main__':main()
