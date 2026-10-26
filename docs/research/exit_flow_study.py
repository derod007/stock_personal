"""Frozen-entry exit alternatives with common initial risk and explicit open marks."""
import argparse,collections,gzip,hashlib,io,json
from pathlib import Path
import numpy as np
ARMS=['fixed20_risk','target15','trailing','trend10']
def valid(b):
 return bool(np.isfinite(b).all() and min(b[:4])>0 and b[4]>=0 and b[2]<=min(b[0],b[3]) and b[1]>=max(b[0],b[3]))
def atr(b,i):
 if i<14:return None
 a=b[i-14:i+1]
 if not all(valid(x) for x in a):return None
 return float(np.mean(np.maximum(a[1:,1]-a[1:,2],np.maximum(abs(a[1:,1]-a[:-1,3]),abs(a[1:,2]-a[:-1,3])))))
def net(entry,exit):return float((exit*.9985/(entry*1.0015)-1)*100)
def simulate(b,i,end,arm,precomputed=None):
 entryidx=i+1
 if entryidx>=end:return dict(status='unknown',reason='no_entry_session')
 if not valid(b[entryidx]) or b[entryidx,4]<=0:return dict(status='unknown',reason='invalid_entry')
 av=atr(b,i) if precomputed is None else precomputed[i]
 if av is None or not np.isfinite(av) or av<=0:return dict(status='unknown',reason='invalid_atr')
 entry=float(b[entryidx,0]);initial=entry-2*av
 if initial<=0:return dict(status='unknown',reason='nonpositive_initial_stop')
 stop=initial;target=entry*1.15;peak=-float('inf');pending=False;last=None
 def done(status,reason,j,price=None):
  out=dict(status=status,reason=reason,entry=entry,entry_i=entryidx,initial_stop=initial,initial_risk_pct=2*av/entry*100,last_stop=stop,last_i=j,held=j-entryidx+1,pending_trend=pending)
  if price is not None:out.update(exit_or_mark=float(price),net_pct=net(entry,price))
  return out
 for j in range(entryidx,end):
  x=b[j]
  if not valid(x):return done('unknown','missing_or_invalid_path',j)
  o,h,l,c,volume=map(float,x);reason=None;price=None
  if pending:reason='trend_next_open';price=o
  elif o<=stop:reason='stop_gap';price=o
  elif arm=='target15' and o>=target:reason='target_gap';price=o
  elif l<=stop:reason='trailing_stop' if arm=='trailing' and stop>initial else 'initial_stop';price=stop
  elif arm=='target15' and h>=target:reason='target';price=target
  elif arm=='fixed20_risk' and j==i+20:reason='time20';price=c
  if reason:
   if volume<=0:return done('unknown','zero_volume_trigger',j)
   return done('closed',reason,j,price)
  if arm=='trailing':
   peak=max(peak,c);current_atr=atr(b,j) if precomputed is None else precomputed[j]
   if current_atr is None or not np.isfinite(current_atr):return done('unknown','invalid_trailing_atr',j)
   stop=max(stop,peak-2*current_atr)
  elif arm=='trend10':pending=c<float(np.min(b[j-10:j,2]))
  last=j
 if last is None:return dict(status='unknown',reason='no_entry_session')
 if b[last,4]<=0:return done('unknown','zero_volume_terminal',last)
 return done('open','segment_end_mark',last,float(b[last,3]))
def avg(xs):return float(np.mean(xs)) if xs else None
def summarize(rows,arm):
 counts=collections.Counter(r['arms'][arm]['status'] for r in rows);done=[r for r in rows if 'net_pct' in r['arms'][arm]];dates=collections.defaultdict(list);syms=collections.defaultdict(list)
 for r in done:dates[r['date']].append(r['arms'][arm]['net_pct']);syms[r['symbol']].append(r['arms'][arm]['net_pct'])
 closed=[r['arms'][arm] for r in rows if r['arms'][arm]['status']=='closed'];opened=[r['arms'][arm] for r in rows if r['arms'][arm]['status']=='open']
 vals=[r['arms'][arm]['net_pct'] for r in done]
 return dict(entries=len(rows),counts=dict(counts),priced=len(done),dates=len(dates),symbols=len(syms),date_mean=avg([avg(v) for v in dates.values()]),trade_mean=avg(vals),symbol_mean=avg([avg(v) for v in syms.values()]),terminal_win_pct=100*avg([x>0 for x in vals]) if vals else None,median=float(np.median(vals)) if vals else None,worst=min(vals) if vals else None,closed_only_mean=avg([r['net_pct'] for r in closed]),open_only_mean=avg([r['net_pct'] for r in opened]),mean_held_closed=avg([r['held'] for r in closed]),mean_held_open=avg([r['held'] for r in opened]),reasons=dict(collections.Counter(r['arms'][arm]['reason'] for r in rows)))
def original_summary(rows):
 d=collections.defaultdict(list)
 for r in rows:
  if r['original20'] is not None:d[r['date']].append(r['original20'])
 return dict(n=sum(map(len,d.values())),date_mean=avg([avg(v) for v in d.values()]))
def main():
 ap=argparse.ArgumentParser()
 for n in ['source','prices','manifest','protocol','output']:ap.add_argument('--'+n,type=Path,required=True)
 a=ap.parse_args()
 if a.output.exists():raise ValueError('New output required')
 a.output.mkdir();choice=json.loads((a.source/'selection.json').read_text());cut=json.loads((a.source/'cuts.json').read_text());j=cut['features'].index(choice['feature']);oldval=json.loads((a.source/'validation.json').read_text());groups=collections.defaultdict(list)
 for line in gzip.open(a.source/'observations.jsonl.gz','rt'):
  r=json.loads(line)
  if r['bins'][j]==choice['bin']:groups[r['segment']].append(r)
 manifest=json.loads(a.manifest.read_text());segments={s['label']:s for s in manifest['segments']};checks=dict(protocol_sha256=hashlib.sha256(a.protocol.read_bytes()).hexdigest(),input_sha256=hashlib.sha256((a.source/'observations.jsonl.gz').read_bytes()).hexdigest(),cache_hashes={},original_matches=0);summary={};output=[]
 for seg,rows in groups.items():
  raw=(a.prices/(seg+'.npz')).read_bytes();h=hashlib.sha256(raw).hexdigest();assert h==oldval['cache_hashes'][seg];checks['cache_hashes'][seg]=h;z=np.load(io.BytesIO(raw));m=z['matrix'];si={str(s):i for i,s in enumerate(z['symbols'])};days=[str(d) for d in z['days']];end=days.index(segments[seg]['days'][-1])+1
  atrcache={};out=[];last={};nonkeys=set()
  for r in sorted(rows,key=lambda r:(r['i'],r['symbol'])):
   s=r['symbol'];i=r['i'];b=m[si[s]]
   if s not in atrcache:
    arr=np.full(len(b),np.nan)
    for k in range(14,end):
     v=atr(b,k)
     if v is not None:arr[k]=v
    atrcache[s]=arr
   if i>last.get(s,-10000)+20:nonkeys.add((i,s));last[s]=i
   if r['net20'] is not None:assert abs(net(b[i+1,0],b[i+20,3])-r['net20'])<1e-9;checks['original_matches']+=1
   v={k:r[k] for k in ['segment','date','i','symbol']};v['original20']=r['net20'];v['arms']={arm:simulate(b,i,end,arm,atrcache[s]) for arm in ARMS};out.append(v)
  common=[r for r in out if all('net_pct' in r['arms'][arm] for arm in ARMS)];commonref=[r for r in common if r['original20'] is not None];non=[r for r in commonref if (r['i'],r['symbol']) in nonkeys]
  result=dict(signals=len(out),period=[segments[seg]['days'][0],segments[seg]['days'][-1]],all={arm:summarize(out,arm) for arm in ARMS},common_terminal={arm:summarize(common,arm) for arm in ARMS},common_reference={arm:summarize(commonref,arm) for arm in ARMS},nonoverlap_common_reference={arm:summarize(non,arm) for arm in ARMS},original_common_reference=original_summary(commonref),original_nonoverlap=original_summary(non))
  summary[seg]=result;output.extend(out);print(seg,'common',len(commonref),json.dumps({arm:result['common_reference'][arm]['date_mean'] for arm in ARMS}),flush=True)
 serialized=''.join(json.dumps(r,separators=(',',':'))+'\n' for r in output).encode();f=a.output/'trades.jsonl.gz';f.write_bytes(gzip.compress(serialized,mtime=0));assert gzip.decompress(f.read_bytes())==serialized
 checks['rows']=len(output);(a.output/'validation.json').write_text(json.dumps(checks,indent=2));(a.output/'summary.json').write_text(json.dumps(summary,indent=2))
if __name__=='__main__':main()
