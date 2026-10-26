"""Read-only KRX TDD input audit. No repairs, price mixing, or account execution."""
import argparse,collections,hashlib,json,math
from pathlib import Path

FIELDS={'open':'TDD_OPNPRC','high':'TDD_HGPRC','low':'TDD_LWPRC','close':'TDD_CLSPRC','volume':'ACC_TRDVOL'}
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def number(x):
 try:
  v=float(str(x).replace(',',''));return v if math.isfinite(v) else None
 except (ValueError,TypeError):return None

def quality(row):
 b={key:number(row.get(field)) for key,field in FIELDS.items()}
 if any(v is None for v in b.values()):return b,'missing_or_nonnumeric'
 if any(b[k]<=0 for k in ['open','high','low','close']):return b,'nonpositive_price'
 if b['volume']<0:return b,'negative_volume'
 if not b['low']<=min(b['open'],b['close'])<=max(b['open'],b['close'])<=b['high']:return b,'ohlc_order'
 return b,None

def audit(root):
 c=json.loads((root/'docs/candidates.json').read_text());days=c['days'];syms={r['symbol'] for d in c['dates'].values() for r in d['rows']}
 frozen=json.loads((root/'docs/file-hashes.json').read_text());expected=frozen['raw'];rows={};rawhash={};duplicates=[]
 for day in days:
  by={}
  for market,suffix in [('kospi_equity','KS'),('kosdaq_equity','KQ')]:
   name=day.replace('-','')+'_'+market+'.json';p=root/'raw-root/raw'/name;h=digest(p)
   if h!=expected[name]:raise ValueError('raw hash mismatch: '+name)
   rawhash[name]=h
   for row in json.loads(p.read_text())['OutBlock_1']:
    if row['BAS_DD']!=day.replace('-',''):raise ValueError('wrong raw date')
    sym=row['ISU_CD']+'.'+suffix
    if sym not in syms:continue
    if sym in by:duplicates.append({'day':day,'symbol':sym});continue
    by[sym]=row
  rows[day]=by
 counts=collections.Counter();daily={};candidate_issues=[];issues=[];reference=[];previous={};examples=[]
 for day in days:
  dc=collections.Counter()
  for sym,row in rows[day].items():
   b,reason=quality(row);dc[reason or 'valid']+=1;counts[reason or 'valid']+=1
   if reason:issues.append({'day':day,'symbol':sym,'reason':reason})
   delta=number(row.get('CMPPREVDD_PRC'))
   if b['close'] is not None and b['close']>0 and delta is not None and sym in previous:
    prevday,prevclose=previous[sym];ref=b['close']-delta
    if ref>0 and abs(ref-prevclose)>max(1.0,prevclose*.00001):
     reference.append({'day':day,'symbol':sym,'previous_observed_day':prevday,'previous_close':prevclose,'implied_comparison_price':ref,'relative_difference_pct':(ref/prevclose-1)*100,'cause':'unconfirmed_reference_discontinuity'})
   if b['close'] is not None and b['close']>0:previous[sym]=(day,b['close'])
   if day=='2024-01-15' and sym in ['003010.KS','012330.KS','041510.KQ']:examples.append({'symbol':sym,'day':day,**b,'quality':reason or 'valid'})
  daily[day]=dict(dc)
  for r in c['dates'][day]['rows']:
   sym=r['symbol'];row=rows[day].get(sym);reason='missing_row' if row is None else quality(row)[1]
   if reason:candidate_issues.append({'day':day,'symbol':sym,'reason':reason})
 # Recheck originals after all reads.
 if any(digest(root/'raw-root/raw'/name)!=h for name,h in rawhash.items()):raise ValueError('source changed during audit')
 return dict(price_basis='krx_tdd_ohlcv_adjustment_unverified',period=[days[0],days[-1]],sessions=len(days),raw_files=len(rawhash),symbols=len(syms),candidate_observations=sum(len(d['rows']) for d in c['dates'].values()),observed_rows=sum(counts.values()),counts=dict(counts),daily=daily,candidate_issues=candidate_issues,issues=issues,duplicates=duplicates,reference_discontinuities=reference,halt_date_examples=examples,candidates_sha256=digest(root/'docs/candidates.json'),raw_hashes=rawhash,prehistory={'needed':'at least 60 valid completed bars at the first decision date','available_before_evaluation_start':0,'status':'missing'},account_execution=False,limitations=['A coherent daily OHLC does not establish adjustment consistency across dates.','Implied reference differences are evidence to investigate, not confirmed splits or automatic adjustment factors.','Market-specific symbols are not stitched; missing dates are not filled.','Future-trained Yahoo centers cannot be called point-in-time KRX validation.'])

def main():
 p=argparse.ArgumentParser();p.add_argument('--input',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args();r=audit(a.input)
 with a.output.open('x',encoding='utf-8') as f:json.dump(r,f,ensure_ascii=False,indent=2);f.write('\n')
 print(json.dumps({k:r[k] for k in ['sessions','raw_files','observed_rows','counts','prehistory']},ensure_ascii=False));print('candidate_issues',len(r['candidate_issues']),'reference_discontinuities',len(r['reference_discontinuities']))
if __name__=='__main__':main()
