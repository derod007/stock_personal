"""Strict collection preflight and timing for separate fixed-universe research accounts."""
import argparse
import hashlib
import json
import math
import os
import pathlib
import re
import subprocess
import sys
import time
import uuid
from paper_daily import ROOT, save_record, update


def audit_collection(folder, symbols):
    result={'requested':len(symbols),'available':0,'missing':[],'symbols':{}}
    manifest=json.loads((folder/'sources.json').read_text(encoding='utf-8'))
    if not isinstance(manifest,list):raise ValueError('Invalid source manifest')
    for symbol in symbols:
        matches=[x for x in manifest if isinstance(x,dict) and x.get('symbol')==symbol]
        row={'status':'blocked','bars':0,'reason':None}
        try:
            if len(matches)!=1:raise ValueError('missing_or_duplicate_manifest_entry')
            source=matches[0]
            if source.get('error'):raise ValueError('provider_error')
            path=folder/(symbol+'.json')
            raw=path.read_bytes()
            if hashlib.sha256(raw).hexdigest()!=source.get('sha256'):raise ValueError('normalized_hash_mismatch')
            bars=json.loads(raw)
            if not isinstance(bars,list):raise ValueError('invalid_bar_list')
            valid=0
            for b in bars:
                if not isinstance(b,dict):continue
                values=[b.get(k) for k in ('open','high','low','close','volume')]
                if not all(type(v) in (int,float) and math.isfinite(v) for v in values):continue
                o,h,l,c,v=values
                if min(o,h,l,c)>0 and v>=0 and h>=max(o,l,c) and l<=min(o,c):valid+=1
            row['bars']=len(bars);row['valid_bars']=valid
            if valid<60:raise ValueError('fewer_than_60_valid_bars')
            provider=source.get('provider_file')
            if not isinstance(provider,str) or not source.get('provider_sha256'):raise ValueError('provider_archive_missing')
            if hashlib.sha256((folder/pathlib.Path(provider).name).read_bytes()).hexdigest()!=source['provider_sha256']:raise ValueError('provider_hash_mismatch')
            row.update(status='available',source=source.get('source'),fetched_at=source.get('fetched_at'))
            result['available']+=1
        except (ValueError,TypeError,OSError) as exc:
            row['reason']=str(exc) if isinstance(exc,ValueError) else type(exc).__name__
            result['missing'].append(symbol)
        result['symbols'][symbol]=row
    result['complete']=not result['missing']
    return result


def run(config_path,state_dir,runner=subprocess.run):
    config=json.loads(config_path.read_text(encoding='utf-8'))
    account=config.get('id','')
    if not isinstance(account,str) or not re.fullmatch(r'research-[a-z0-9_-]{1,55}',account):raise ValueError('Separate research- account ID required')
    symbols=config.get('symbols')
    if not isinstance(symbols,dict) or not 1<=len(symbols)<=30:raise ValueError('Research universe must contain 1..30 symbols')
    if any(not re.fullmatch(r'[A-Z0-9][A-Z0-9.=-]{0,24}',s) for s in symbols):raise ValueError('Invalid symbol')
    if not isinstance(config.get('universe'),dict) or not config['universe'].get('version'):raise ValueError('Versioned universe required')
    run_id=uuid.uuid4().hex
    log=state_dir/'research-runs'/account/(run_id+'.json')
    record={'schema':1,'run_id':run_id,'account':account,'started_at':int(time.time()),'finished_at':None,
            'status':'running','stage':'collect','requested_symbols':list(symbols),'config_sha256':hashlib.sha256(config_path.read_bytes()).hexdigest()}
    save_record(log,record);begin=time.monotonic()
    def measured(cmd,**kwargs):
        stage=('collect' if any(str(x).endswith('fetch_comparison_data.py') for x in cmd) else
               'naver_session' if any(str(x).endswith('paper_patch_naver_daily.php') for x in cmd) else 'account')
        record['stage']=stage;save_record(log,record);start=time.monotonic()
        try:
            result=runner(cmd,**kwargs)
            if stage in ('collect','naver_session'):
                prefix='--out=' if stage=='collect' else '--dir='
                folder=pathlib.Path(next(x[len(prefix):] for x in cmd if x.startswith(prefix)))
                record['coverage']=audit_collection(folder,symbols)
                save_record(log,record)
                if not record['coverage']['complete']:raise ValueError('Incomplete research collection; account not updated')
            return result
        except Exception as exc:
            record['error_type']=type(exc).__name__
            raise
        finally:
            record[stage+'_seconds']=round(time.monotonic()-start,3)
    try:
        code=update(config_path,state_dir,measured)
        record['status']='success' if code==0 else ('halted' if code==2 else 'failed')
        return code
    except (Exception,KeyboardInterrupt) as exc:
        record['status']='failed';record['error_type']=type(exc).__name__
        raise
    finally:
        record['finished_at']=int(time.time());record['elapsed_seconds']=round(time.monotonic()-begin,3);save_record(log,record)


def main():
    p=argparse.ArgumentParser();p.add_argument('--config',default='config/paper-research-us-v1.json');a=p.parse_args()
    config=pathlib.Path(a.config)
    if not config.is_absolute():config=ROOT/config
    state=pathlib.Path(os.environ.get('PAPER_STATE_DIR',str(ROOT.parent/'stock-personal-paper')))
    if not state.is_absolute():state=ROOT/state
    return run(config.resolve(),state.resolve())


if __name__=='__main__':sys.exit(main())
