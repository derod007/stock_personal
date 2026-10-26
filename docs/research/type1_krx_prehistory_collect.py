"""Collect a frozen historical TOP100 evaluation period; never executes accounts."""
from __future__ import annotations
import argparse
from datetime import datetime
import getpass
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import zipfile

ROOT=Path(__file__).resolve().parents[2]
DOC=ROOT/'docs/type1-krx-input-v1'
START,END='2023-01-02','2023-06-30'

def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def module(path):
    spec=importlib.util.spec_from_file_location('holdout_pilot',path)
    obj=importlib.util.module_from_spec(spec);spec.loader.exec_module(obj);return obj

def preflight():
    cal=json.loads((DOC/'trading-calendar.json').read_text(encoding='utf-8'))
    pilot=module(ROOT/'docs/research/historical-amount-top100-pilot.py')
    days=pilot.candidate_trading_days(pilot.parse_ymd(START),pilot.parse_ymd(END),cal)
    return {'period':[START,END],'expected_sessions':len(days),'expected_market_files':2*len(days),
            'calendar_sha256':sha(DOC/'trading-calendar.json'),
            'collector_sha256':sha(ROOT/'docs/research/historical-amount-top100-pilot.py'),
            'validator_sha256':sha(ROOT/'docs/research/dated-amount-top100-range.py'),
            'protocol_sha256':sha(DOC/'protocol.md'),
            'account_execution':False}

def package(output,secret):
    # Allowlist: no credentials, environment dump, .git, or arbitrary user files.
    files=[output/'run-manifest.json']
    files+=sorted((output/'raw-root/raw').glob('*.json'))
    files+=sorted((output/'raw-root/selected').glob('*.json'))
    for name in ('collect-summary.json','file-hashes.json','candidates.json','universe.json','input-validation.json'):
        files.append(output/'docs'/name)
    dest=output.parent/(output.name+'.zip')
    if dest.exists():raise ValueError('Output ZIP already exists')
    # Refuse a response that unexpectedly echoes the credential.
    for p in files:
        if not p.is_file():raise ValueError('Missing validated package member')
        if secret and secret.encode() in p.read_bytes():raise ValueError('Secret detected; ZIP not created')
    with zipfile.ZipFile(dest,'x',zipfile.ZIP_DEFLATED) as z:
        for p in files:z.write(p,p.relative_to(output))
        z.write(DOC/'trading-calendar.json','trading-calendar.json')
        z.write(DOC/'protocol.md','protocol.md')
    return dest

def main(argv=None):
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--collect',action='store_true')
    ap.add_argument('--resume',action='store_true',help='Resume this collection only; never an account')
    ap.add_argument('--output',type=Path,default=ROOT.parent/'type1-krx-prehistory-20230102-20230630-data')
    args=ap.parse_args(argv);meta=preflight()
    key=(os.environ.get('KRX_AUTH_KEY') or os.environ.get('KRX_API_KEY') or '').strip()
    print(json.dumps({**meta,'key_present':bool(key),'mode':'collect' if args.collect else 'preflight'},ensure_ascii=False))
    if not args.collect:return 0
    out=args.output.resolve()
    if out==ROOT or ROOT in out.parents:raise ValueError('Use a separate output directory outside the code folder')
    marker=out/'run-manifest.json'
    if out.exists():
        if not args.resume or not marker.is_file():raise ValueError('Existing directory requires --resume and matching run-manifest')
        if json.loads(marker.read_text(encoding='utf-8'))!=meta:raise ValueError('Frozen collection definition changed; use a new directory')
    elif args.resume:raise ValueError('Cannot resume a missing collection')
    if not key:
        if not sys.stdin.isatty():
            print('blocked_no_auth_key: enter the key only in your local terminal');return 2
        key=getpass.getpass('KRX AUTH KEY (hidden; not saved): ').strip()
    if not key:print('blocked_no_auth_key');return 2
    if not out.exists():
        out.mkdir(parents=True);marker.write_text(json.dumps(meta,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    env=os.environ.copy();env['KRX_AUTH_KEY']=key
    common=['--from',START,'--to',END,'--calendar',str(DOC/'trading-calendar.json'),
            '--raw-root',str(out/'raw-root'),'--docs',str(out/'docs')]
    commands=[[sys.executable,str(ROOT/'docs/research/historical-amount-top100-pilot.py'),'--collect',*common],
              [sys.executable,str(ROOT/'docs/research/dated-amount-top100-range.py'),
               '--validate' if (out/'docs/file-hashes.json').exists() else '--freeze-hashes','--write-public',*common]]
    try:
        for cmd in commands:
            result=subprocess.run(cmd,env=env,check=False)
            if result.returncode:
                print('Collection or validation incomplete. Raw files preserved. No complete ZIP created.');return result.returncode
        # Validator checks dates, hashes and independently reranks KOSPI+KOSDAQ.
        valid=json.loads((out/'docs/input-validation.json').read_text(encoding='utf-8'))
        if not valid.get('ok') or valid['ok_day_count']!=meta['expected_sessions']:raise ValueError('Incomplete coverage')
        dest=package(out,key);print('READY_TO_UPLOAD: '+str(dest));return 0
    finally:
        env.pop('KRX_AUTH_KEY',None);key=''

if __name__=='__main__':
    try:raise SystemExit(main())
    except ValueError as exc:
        print('Collection blocked: '+str(exc));raise SystemExit(2)
