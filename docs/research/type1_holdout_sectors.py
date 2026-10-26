"""Collect missing current industry evidence; no historical classification claim."""
import argparse,json,hashlib,urllib.request
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor,as_completed

def main():
 ap=argparse.ArgumentParser();ap.add_argument('--candidates',type=Path,required=True);ap.add_argument('--output',type=Path,required=True);ap.add_argument('--resume',action='store_true');a=ap.parse_args()
 root=Path(__file__).resolve().parents[2];out=a.output;out.mkdir(parents=True,exist_ok=a.resume);(out/'raw').mkdir(exist_ok=a.resume)
 c=json.loads(a.candidates.read_text());symbols=sorted({r['symbol'] for d in c['dates'].values() for r in d['rows']})
 known={};sources=[]
 for period in ['20241008-20251002','20251008-20261008']:
  p=root/f'docs/historical-top100-{period}/sector-map-full-v2.json';sources.append({'path':str(p.relative_to(root)),'sha256':hashlib.sha256(p.read_bytes()).hexdigest()})
  for s,v in json.loads(p.read_text())['sectors'].items():
   if s in known and known[s]!=v:raise ValueError('sector conflict')
   known[s]=v
 def get(url,name):
  if a.resume and (out/'raw'/name).is_file():return json.loads((out/'raw'/name).read_bytes())
  with urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0','Referer':'https://m.stock.naver.com/'}),timeout=20) as r:raw=r.read()
  (out/'raw'/name).write_bytes(raw);return json.loads(raw)
 groups={}
 for page in range(1,11):
  d=get(f'https://m.stock.naver.com/api/stocks/industry?page={page}&pageSize=100',f'industry-{page}.json')
  groups.update({str(g['no']):g['name'] for g in d['groups']})
  if len(groups)==d['totalCount']:break
 else:raise ValueError('incomplete industry list')
 missing=[s for s in symbols if s not in known]
 def item(s):
  try:
   d=get(f'https://m.stock.naver.com/api/stock/{s[:6]}/integration',s+'.json')
   if d.get('itemCode')!=s[:6]:raise ValueError('itemCode mismatch')
   name=groups.get(str(d.get('industryCode')))
   if not name or name=='기타':raise ValueError('unknown industry')
   return s,{'industry':name,'name':d.get('stockName',''),'status':'confirmed_current'}
  except Exception as e:return s,{'status':'unconfirmed','reason':str(e)}
 results={}
 with ThreadPoolExecutor(max_workers=12) as pool:
  for f in as_completed([pool.submit(item,s) for s in missing]):
   s,r=f.result();results[s]=r
   if len(results)%50==0:print('sector',len(results),len(missing),flush=True)
 doc={'known':{s:known[s] for s in symbols if s in known},'new':results,'sources':sources,'assumption':'current industry applied historically; unresolved shared unclassified bucket'}
 (out/'evidence.json').write_text(json.dumps(doc,ensure_ascii=False,indent=2)+'\n');print('sector complete',len(results),flush=True)
if __name__=='__main__':main()
