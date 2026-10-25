"""Frozen causal membership and pre-breakout diagnostic; no account execution."""
import argparse,collections,csv,hashlib,io,json,zipfile
from pathlib import Path
import numpy as np

def valid(a):
 return np.isfinite(a).all(axis=-1)&(a[...,:4]>0).all(axis=-1)&(a[...,4]>=0)&(a[...,2]<=np.minimum(a[...,0],a[...,3]))&(a[...,1]>=np.maximum(a[...,0],a[...,3]))
def features(hist,index_return):
 if len(hist)!=22 or not valid(hist).all():return None
 c=hist[:,3];tr=np.maximum(hist[1:,1]-hist[1:,2],np.maximum(abs(hist[1:,1]-c[:-1]),abs(hist[1:,2]-c[:-1])))
 strong=c[-1]/c[-21]-1>index_return
 contract=float(np.mean(tr[15:20]))<float(np.mean(tr[:15]))
 breakout=c[-1]>max(hist[-6:-1,1])
 return dict(strong=bool(strong),contraction=bool(strong and contract),breakout=bool(strong and contract and breakout),precursor=bool(strong and contract and not breakout))
def memberships(daily,i,start,rolling):
 return set().union(*(daily.get(j,set()) for j in range(max(start,i-19) if rolling else i,i+1)))
def result(b,i,stop,h):
 if i+h>=stop:return dict(status='unknown',reason='segment_end')
 a=b[i+1:i+h+1]
 if not valid(a).all():return dict(status='unknown',reason='missing_or_invalid')
 if a[0,4]<=0 or a[-1,4]<=0:return dict(status='unknown',reason='zero_entry_or_exit_volume')
 return dict(status='complete',net_pct=float((a[-1,3]*.9985/(a[0,0]*1.0015)-1)*100))
def mean(xs):return float(np.mean(xs)) if xs else None
def groupstats(rows,key,chosen=None,allcontrol=False):
 selected=rows if chosen is None else [r for r in rows if (r['i'],r['symbol']) in chosen]
 done=[r for r in selected if r[key] is not None];d=collections.defaultdict(list);c=collections.defaultdict(list)
 selectedkeys={(r['i'],r['symbol']) for r in selected}
 for r in done:d[r['date']].append(r[key])
 for r in rows:
  if r[key] is not None and (allcontrol or (r['i'],r['symbol']) not in selectedkeys):c[r['date']].append(r[key])
 pairs=sorted(set(d)&set(c))
 return dict(signals=len(selected),complete=len(done),unknown=len(selected)-len(done),symbols=len({r['symbol'] for r in done}),dates=len(d),trade_mean=mean([r[key] for r in done]),date_mean=mean([mean(v) for v in d.values()]),paired_dates=len(pairs),paired_selected=mean([mean(d[x]) for x in pairs]),paired_control=mean([mean(c[x]) for x in pairs]),paired_difference=mean([mean(d[x])-mean(c[x]) for x in pairs]))
def summarize(rows):
 keys={(r['i'],r['symbol']) for r in rows if r['precursor']};last={};non=set()
 for r in sorted(rows,key=lambda r:(r['i'],r['symbol'])):
  if not r['precursor'] or r['i']<=last.get(r['symbol'],-10000)+20:continue
  non.add((r['i'],r['symbol']));last[r['symbol']]=r['i']
 out={}
 for label,chosen,ctrl in [('all',None,False),('precursor',keys,False),('precursor_nonoverlap',non,True)]:
  chosenrows=rows if chosen is None else [r for r in rows if (r['i'],r['symbol']) in chosen]
  out[label]={k:groupstats(rows,k,chosen,ctrl) for k in ['jump10','gap_pct','net1','net5','net20']}
  out[label]['jump_hits']=sum(r['jump10']==100 for r in chosenrows)
  out[label]['outcome_unknown_reasons']={str(h):dict(collections.Counter(r['outcomes'][str(h)]['reason'] for r in chosenrows if r['outcomes'][str(h)]['status']=='unknown')) for h in [1,5,20]}
 p=out['precursor'];n=out['precursor_nonoverlap']['net20'];x=p['net20']
 enough=x['complete']>=30 and x['symbols']>=20 and x['dates']>=10
 out['gate']=dict(enough=enough,positive=bool(enough and x['date_mean']>0 and (x['paired_difference'] or 0)>0 and (p['jump10']['paired_difference'] or 0)>0 and n['date_mean'] is not None and n['date_mean']>0 and (n['paired_difference'] or 0)>0))
 return out

def main():
 ap=argparse.ArgumentParser()
 for name in ['observations','manifest','prices','index','protocol','output']:ap.add_argument('--'+name,type=Path,required=True)
 a=ap.parse_args()
 if a.output.exists():raise ValueError('Output must be new')
 a.output.mkdir();manifest=json.loads(a.manifest.read_text());original=[json.loads(l) for l in a.observations.open()]
 validation={'hashes':{n:hashlib.sha256(getattr(a,n).read_bytes()).hexdigest() for n in ['observations','manifest','index','protocol']},'feature_matches':0,'outcome_matches':0,'cache_hashes':{}}
 with zipfile.ZipFile(a.index) as z:
  idx={m:{r['date']:float(r['close']) for r in csv.DictReader(io.StringIO(z.read(n).decode('utf-8-sig')))} for m,n in [('KS','kospi_daily_20230102_latest.csv'),('KQ','kosdaq_daily_20230102_latest.csv')]}
 summary={}
 with (a.output/'observations.jsonl').open('w') as dest,(a.output/'event_coverage.jsonl').open('w') as covdest:
  for seg in manifest['segments']:
   label=seg['label'];raw=(a.prices/(label+'.npz')).read_bytes();validation['cache_hashes'][label]=hashlib.sha256(raw).hexdigest()
   with zipfile.ZipFile(io.BytesIO(raw)) as zz:assert zz.testzip() is None
   z=np.load(io.BytesIO(raw));b=z['matrix'];symbols=[str(s) for s in z['symbols']];si={s:i for i,s in enumerate(symbols)};days=[str(d) for d in z['days']];di={d:i for i,d in enumerate(days)}
   assert set(symbols)==set(seg['bar_hashes']);start=di[seg['days'][0]];stop=di[seg['days'][-1]]+1
   assert days[start:stop]==seg['days']
   rr=[r for r in original if r['segment']==label];daily=collections.defaultdict(set);lookup={}
   for r in rr:daily[r['i']].add(r['symbol']);lookup[(r['i'],r['symbol'])]=r
   featurecache={};outcache={}
   def feat(i,s):
    key=(i,s)
    if key not in featurecache:
     f=None
     if i>=21 and s in si:
      market=idx[s[-2:]];ir=market[days[i]]/market[days[i-20]]-1
      f=features(b[si[s],i-21:i+1],ir)
     featurecache[key]=f
     if key in lookup:
      old=lookup[key].get('features');assert (f is None)==(old is None)
      if f is not None:
       assert all(f[k]==old[k] for k in ['strong','contraction','breakout']);validation['feature_matches']+=1
    return featurecache[key]
   def observation(i,s):
    key=(i,s)
    if key not in outcache:
     f=feat(i,s)
     if f is None:return None
     bs=b[si[s]];outcomes={str(h):result(bs,i,stop,h) for h in [1,5,20]}
     if key in lookup:
      for h in [5,20]:
       old=lookup[key]['outcomes'][str(h)];new=outcomes[str(h)];assert old['status']==new['status']
       if new['status']=='complete':assert abs(old['net_pct']-new['net_pct'])<1e-9
       validation['outcome_matches']+=1
     nextvalid=i+1<stop and valid(bs[i+1])
     jump=100*int(bs[i+1,3]/bs[i,3]>=1.1-1e-12) if nextvalid else None
     gap=float((bs[i+1,0]/bs[i,3]-1)*100) if nextvalid else None
     outcache[key]=dict(segment=label,date=days[i],i=i,symbol=s,precursor=f['precursor'],jump10=jump,gap_pct=gap,outcomes=outcomes,**{'net'+str(h):outcomes[str(h)].get('net_pct') for h in [1,5,20]})
    return outcache[key]
   segmentout={};knownmembership={};blocked={}
   for universe,rolling in [('daily',False),('rolling20',True)]:
    rows=[];nobs=0;incomplete=0
    for i in range(start,stop):
     members=memberships(daily,i,start,rolling);knownmembership[(universe,i)]=members
     if rolling and i-start<19:incomplete+=len(members);continue
     for s in sorted(members):
      nobs+=1;r=observation(i,s)
      if r is not None:
       rows.append(r);dest.write(json.dumps(dict(r,universe=universe),ensure_ascii=False,separators=(',',':'))+'\n')
    segmentout[universe]=summarize(rows);segmentout[universe].update(observations=nobs,eligible=len(rows),feature_unknown=nobs-len(rows),incomplete_membership_observations=incomplete)
   # Retrospective coverage audit only, never used to choose candidates.
   coverage={u:{kind:collections.Counter() for kind in ['next_top100_jump10','pr131_breakout']} for u in ['daily','rolling20']}
   for r in rr:
    i=r['i'];s=r['symbol'];bs=b[si[s]] if s in si else None
    event_jump=i>0 and bs is not None and valid(bs[i-1:i+1]).all() and bs[i,3]/bs[i-1,3]>=1.1-1e-12
    event_break=r.get('features',{}).get('breakout',False)
    if not(event_jump or event_break):continue
    for u in ['daily','rolling20']:
     if i-1<start:reason='previous_day_outside_segment'
     elif u=='rolling20' and i-1-start<19:reason='membership_lookback_incomplete'
     elif s not in knownmembership[(u,i-1)]:reason='not_in_known_universe'
     else:
      f=feat(i-1,s)
      reason='features_unknown' if f is None else ('precursor' if f['precursor'] else 'known_but_not_precursor')
     for kind,yes in [('next_top100_jump10',event_jump),('pr131_breakout',event_break)]:
      if yes:coverage[u][kind][reason]+=1
     covdest.write(json.dumps(dict(segment=label,date=r['date'],symbol=s,universe=u,jump10=bool(event_jump),pr131_breakout=bool(event_break),reason=reason),separators=(',',':'))+'\n')
   segmentout['coverage']=coverage;summary[label]=segmentout
   print(label,json.dumps({u:segmentout[u]['precursor']['net20'] for u in ['daily','rolling20']}),flush=True)
 (a.output/'summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2));(a.output/'validation.json').write_text(json.dumps(validation,indent=2))
if __name__=='__main__':main()
