"""Offline preparation; never modifies archives or creates operational accounts."""
import argparse, collections, datetime, hashlib, importlib.util, json, pathlib, zipfile

def main():
 ap=argparse.ArgumentParser();ap.add_argument('--inputs',type=pathlib.Path,required=True);ap.add_argument('--output',type=pathlib.Path,required=True);a=ap.parse_args()
 if a.output.exists():raise ValueError('Use a new output directory')
 a.output.mkdir(parents=True)
 spec=importlib.util.spec_from_file_location('pilot',pathlib.Path(__file__).with_name('historical-amount-top100-pilot.py'));mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
 def sha(b):return hashlib.sha256(b).hexdigest()
 def ts(d,h=15,m=30):return int(datetime.datetime.strptime(d,'%Y-%m-%d').replace(hour=h,minute=m,tzinfo=datetime.timezone(datetime.timedelta(hours=9))).timestamp())
 def dump(p,d):p.write_text(json.dumps(d,ensure_ascii=False,separators=(',',':')))
 raw={};candidates={};manifest=[];yahoo=[]
 for p in sorted(a.inputs.glob('*.zip')):
  manifest.append({'name':p.name,'sha256':sha(p.read_bytes())})
  with zipfile.ZipFile(p) as z:
   names=z.namelist()
   if any('/bars/' in n and n.endswith('.json') for n in names):yahoo.append(p);continue
   for n in names:
    if n.endswith('_equity.json') and ('/raw/' in n or '/2024_raw/' in n):
     name=n.split('/')[-1];b=z.read(n)
     if name in raw:assert json.loads(raw[name])==json.loads(b),name
     raw[name]=b
    if n.endswith('candidates.json'):
     for day,rec in json.loads(z.read(n))['dates'].items():
      if day in candidates:assert candidates[day]==rec['rows'],day
      candidates[day]=rec['rows']
    if '_selected/' in n and n.endswith('_top100.json'):
     d=json.loads(z.read(n));assert len(d['top100'])==100
 # Selection uses the existing KRX selector, including original exclusions and tie ordering.
 byday=collections.defaultdict(dict)
 for name,b in raw.items():byday[name[:8]][name[9:-5]]=json.loads(b)['OutBlock_1']
 barrows=collections.defaultdict(dict);raw_days=[];rec_checks=0
 for compact,markets in sorted(byday.items()):
  assert set(markets)=={'kospi_equity','kosdaq_equity'},compact
  day=f'{compact[:4]}-{compact[4:6]}-{compact[6:]}';allrows=[]
  for market,rows in markets.items():
   allrows+=mod.rows_from_openapi_block(rows,'krx_openapi_'+market)
   for r in rows:
    if r.get('MKT_NM') not in ('KOSPI','KOSDAQ'):continue
    sym=r['ISU_CD']+('.KS' if r['MKT_NM']=='KOSPI' else '.KQ')
    def num(k):
     try:return float(str(r[k]).replace(',',''))
     except (ValueError,KeyError):return None
    bar={'time':ts(day,9,0),'time_kst':day+' 09:00:00','available_at':ts(day),'is_complete':True,'open':num('TDD_OPNPRC'),'high':num('TDD_HGPRC'),'low':num('TDD_LWPRC'),'close':num('TDD_CLSPRC'),'volume':num('ACC_TRDVOL')}
    barrows[sym][day]=bar
  selected=mod.select_top100(allrows)['top100'];assert len(selected)==100
  if day in candidates:
   assert [r['code'] for r in selected]==[r['code'] for r in candidates[day]],day
   rec_checks+=1
  else:candidates[day]=[dict(r,symbol=r['yahoo']) for r in selected]
  raw_days.append(day)
 segments=[]
 def segment(label,days,bars,basis):
  dest=a.output/label;dest.mkdir();union={r['symbol'] for d in days for r in candidates[d]};missing=[];hashes={}
  for sym in sorted(union):
   if sym not in bars:missing.append(sym);continue
   rows=bars[sym];rows=list(rows.values()) if isinstance(rows,dict) else rows;rows=sorted(rows,key=lambda b:b['time'])
   p=dest/(sym+'.json');dump(p,rows);hashes[sym]=sha(p.read_bytes())
  c={d:candidates[d] for d in days};dump(dest/'candidates.json',c)
  segments.append({'label':label,'days':days,'observations':len(days)*100,'symbols':len(union),'missing_symbols':missing,'basis':basis,'directory':label,'bar_hashes':hashes,'candidates_sha256':sha((dest/'candidates.json').read_bytes())})
 segment('krx',raw_days,barrows,'krx_tdd_ohlcv_adjustment_unverified')
 for p in yahoo:
  bars={}
  with zipfile.ZipFile(p) as z:
   mn=next(n for n in z.namelist() if n.endswith('/manifest.json'));m=json.loads(z.read(mn));start=m['window']['evaluation_start_day'];end=m['window']['as_of_day'] if 'as_of_day' in m['window'] else None
   # Archive names encode the original research evaluation end, never infer from latest downloaded bars.
   label='yahoo_prior' if '20241008-20251002' in p.name else 'yahoo_recent'
   if label=='yahoo_prior':start,end='2024-10-08','2025-10-02'
   else:
    assert '20251008-20261008' in p.name,p.name
    start,end='2025-10-10','2026-10-08'
   for n in z.namelist():
    if '/bars/' in n and n.endswith('.json'):
     d=json.loads(z.read(n));bars[d['symbol']]=d['rows']
  segment(label,sorted(d for d in candidates if start<=d<=end),bars,'yahoo_chart_quote_ohlcv_daily_adjustment_unverified')
 out={'segments':segments,'archives':manifest,'raw_files':len(raw),'krx_selected_reproduced':rec_checks,'score_history_minimum':120,'history_window':'past two calendar years only','source_boundary':'separate segments; no price-provider splice; no forced boundary liquidation'}
 dump(a.output/'manifest.json',out)
 print(json.dumps({'raw_files':len(raw),'selected_reproduced':rec_checks,'segments':[{k:v for k,v in s.items() if k not in ('days','bar_hashes')}|{'from':s['days'][0],'to':s['days'][-1],'sessions':len(s['days'])} for s in segments]},ensure_ascii=False,indent=2))
if __name__=='__main__':main()
