"""Compare stored sources at the halt; diagnostics only, never repairs prices."""
import argparse,collections,hashlib,json
from pathlib import Path

def main():
 p=argparse.ArgumentParser();p.add_argument('--dataset',type=Path,required=True);p.add_argument('--krx',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args()
 date='2024-01-15';counts=collections.Counter();byday=collections.Counter();bad={};kr={}
 for market,suffix in [('kospi_equity','KS'),('kosdaq_equity','KQ')]:
  src=a.krx/'raw-root/raw'/f'20240115_{market}.json'
  for r in json.loads(src.read_text())['OutBlock_1']:kr[r['ISU_CD']+'.'+suffix]=r
 for path in sorted((a.dataset/'bars').glob('*.json')):
  d=json.loads(path.read_text());sym=d['symbol']
  for r in d['rows']:
   day=r['time_kst'][:10];v=[r[k] for k in ['open','high','low','close','volume']]
   if any(x is None for x in v):reason='null_quote'
   elif min(v[:4])<=0:reason='nonpositive_price'
   elif not r['low']<=min(r['open'],r['close'])<=max(r['open'],r['close'])<=r['high']:reason='ohlc_order'
   elif r['volume']<0:reason='negative_volume'
   else:continue
   counts[reason]+=1;byday[day]+=1
   if day==date:
    k=kr.get(sym);other={f:float(k[key]) for f,key in [('open','TDD_OPNPRC'),('high','TDD_HGPRC'),('low','TDD_LWPRC'),('close','TDD_CLSPRC'),('volume','ACC_TRDVOL')]} if k else None
    bad[sym]=dict(reason=reason,yahoo={k:r[k] for k in ['open','high','low','close','volume']},krx=other,bars_sha256=hashlib.sha256(path.read_bytes()).hexdigest())
 out=dict(halt_date=date,invalid_rows_by_reason=dict(counts),invalid_rows_by_day=dict(sorted(byday.items())),halt_date_affected=len(bad),halt_date_rows=bad,interpretation='Stored Yahoo OHLC inconsistency confirmed; KRX is comparison evidence only. Provider root cause and compatible adjustment basis remain unconfirmed. No prices substituted.')
 a.output.write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n');print(json.dumps({'invalid':dict(counts),'halt_date_affected':len(bad)},ensure_ascii=False))
if __name__=='__main__':main()
