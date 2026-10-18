"""Isolated fixed-period Yahoo collection. No account execution or price repairs."""
import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone, timedelta
import hashlib
import json
import math
from pathlib import Path
import re
import time
import urllib.request
import urllib.error

BASIS='yahoo_chart_quote_ohlcv_daily_adjustment_unverified'
START='2023-01-01'  # Six months for >=60 completed bars; insufficient listings stay missing.
END='2024-06-29'    # Exclusive; never requests an outcome beyond the frozen evaluation end.
KST=timezone(timedelta(hours=9))
def sha(b):return hashlib.sha256(b).hexdigest()
def dump(p,x):p.write_text(json.dumps(x,ensure_ascii=False,indent=2,allow_nan=False)+'\n',encoding='utf-8')
def parse(raw,symbol):
    chart=json.loads(raw)['chart']
    if chart.get('error'):raise ValueError('provider_chart_error')
    data=chart['result'][0];meta=data['meta']
    if meta.get('symbol')!=symbol:raise ValueError('provider_symbol_mismatch')
    if meta.get('currency')!='KRW' or meta.get('instrumentType')!='EQUITY':raise ValueError('provider_instrument_mismatch')
    q=data['indicators']['quote'][0];rows=[];issues=[];seen=set()
    for i,stamp in enumerate(data.get('timestamp',[])):
        day=datetime.fromtimestamp(stamp,KST).strftime('%Y-%m-%d')
        if not START<=day<END:continue
        values={k:q.get(k,[])[i] if i<len(q.get(k,[])) else None for k in ('open','high','low','close','volume')}
        # Null/invalid rows are retained; unchanged research quality rules decide usability.
        if any(v is not None and (not isinstance(v,(int,float)) or not math.isfinite(v)) for v in values.values()):
            raise ValueError('nonfinite_or_nonnumeric_quote')
        if day in seen:issues.append({'day':day,'reason':'duplicate_day'})
        seen.add(day)
        if any(v is None for v in values.values()):issues.append({'day':day,'reason':'null_quote'})
        elif not (0<values['low']<=min(values['open'],values['close'])<=max(values['open'],values['close'])<=values['high']) or values['volume']<0:
            issues.append({'day':day,'reason':'invalid_ohlcv'})
        rows.append(dict(time=stamp,time_kst=datetime.fromtimestamp(stamp,KST).strftime('%Y-%m-%d %H:%M:%S'),**values,is_complete=True,synthetic=False,close_source='yahoo_daily'))
    if not rows:raise ValueError('no_requested_period_bars')
    return rows,meta,issues

def fetch(symbol,out):
    a=int(datetime.fromisoformat(START).replace(tzinfo=KST).timestamp());b=int(datetime.fromisoformat(END).replace(tzinfo=KST).timestamp())
    url=f'https://query1.finance.yahoo.com/v8/finance/chart/{symbol}?period1={a}&period2={b}&interval=1d&events=div%2Csplits'
    rec={'symbol':symbol,'url':url,'fetched_at':datetime.now(timezone.utc).isoformat()}
    try:
        with urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0'}),timeout=25) as r:
            raw=r.read();rec['http_status']=r.status
        (out/'raw'/f'{symbol}.json').write_bytes(raw);rec['raw_sha256']=sha(raw)
        rows,meta,issues=parse(raw,symbol)
        payload=dict(schema=1,symbol=symbol,provider='yahoo_chart_v8',price_basis=BASIS,rows=rows,request=rec,raw_meta=meta,
                     pipeline='Yahoo quote OHLCV unchanged; no close repair, no KRX substitution',quality={'collection_issues':issues})
        p=out/'bars'/f'{symbol}.json';dump(p,payload)
        rec.update(status='saved',bars_sha256=sha(p.read_bytes()),rows=len(rows),issues=issues,first_day=rows[0]['time_kst'][:10],last_day=rows[-1]['time_kst'][:10])
    except urllib.error.HTTPError as e:
        rec.update(status='unavailable',reason='http_error',http_status=e.code)
        raw=e.read();(out/'raw'/f'{symbol}.error').write_bytes(raw);rec['raw_sha256']=sha(raw)
    except Exception as e:rec.update(status='unavailable',reason=type(e).__name__,detail=str(e))
    dump(out/'records'/f'{symbol}.json',rec)
    return rec

def main():
    ap=argparse.ArgumentParser(description=__doc__);ap.add_argument('--candidates',required=True,type=Path);ap.add_argument('--output',required=True,type=Path)
    ap.add_argument('--resume',action='store_true')
    args=ap.parse_args();raw=args.candidates.read_bytes();c=json.loads(raw)
    if c['days'][0]!='2023-07-03' or c['days'][-1]!='2024-06-28' or len(c['days'])!=243:raise ValueError('wrong frozen period')
    symbols=sorted({r['symbol'] for d in c['dates'].values() for r in d['rows']})
    if any(not re.fullmatch(r'[0-9A-Z]{6}\.(KS|KQ)',s) for s in symbols):raise ValueError('invalid symbol')
    out=args.output
    definition=dict(candidates_sha256=sha(raw),start=START,end_exclusive=END,price_basis=BASIS,symbols=symbols,no_account_execution=True)
    if args.resume:
        existing=json.loads((out/'manifest.json').read_text())
        if any(existing.get(k)!=v for k,v in definition.items()):raise ValueError('resume definition mismatch')
    else:
        out.mkdir(parents=True,exist_ok=False)
        for n in ['raw','bars','records']:(out/n).mkdir()
        dump(out/'manifest.json',dict(**definition,script_sha256=sha(Path(__file__).read_bytes())))
    records=[];pending=[]
    for sym in symbols:
        p=out/'records'/f'{sym}.json'
        if not p.exists():pending.append(sym);continue
        rec=json.loads(p.read_text())
        if rec['symbol']!=sym:raise ValueError('record identity changed')
        if rec['status']=='saved':
            for key,path in [('raw_sha256',out/'raw'/f'{sym}.json'),('bars_sha256',out/'bars'/f'{sym}.json')]:
                if sha(path.read_bytes())!=rec[key]:raise ValueError('resume source changed')
        records.append(rec)
    dump(out/('resume-'+str(time.time_ns())+'.json'),dict(script_sha256=sha(Path(__file__).read_bytes()),reused=len(records),pending=len(pending),workers=16))
    with ThreadPoolExecutor(max_workers=16) as pool:
        jobs={pool.submit(fetch,s,out):s for s in pending}
        for f in as_completed(jobs):
            records.append(f.result())
            if len(records)%50==0:print(json.dumps({'processed':len(records),'total':len(symbols),'saved':sum(r['status']=='saved' for r in records)}),flush=True)
    records.sort(key=lambda r:r['symbol']);dump(out/'collection.json',records)
    print(json.dumps({'processed':len(records),'saved':sum(r['status']=='saved' for r in records)}),flush=True)
if __name__=='__main__':main()
