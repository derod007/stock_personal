"""Run the existing daily updater, then the isolated comparison. Schedule this wrapper."""
import argparse
import json
import pathlib
import subprocess
import sys
from paper_daily import ROOT, save_record
import os
import time
import uuid


def main():
    p=argparse.ArgumentParser()
    p.add_argument('--config',default='config/paper-us.json')
    p.add_argument('--experiment',required=True)
    p.add_argument('--candidate-ttl',choices=['1','2','3','4','5'])
    a=p.parse_args()
    import re
    if not re.fullmatch('[a-z0-9_-]{1,64}',a.experiment):p.error('Invalid experiment ID')
    config=pathlib.Path(a.config)
    if not config.is_absolute():config=ROOT/config
    account=json.loads(config.read_text(encoding='utf-8'))['id']
    state=pathlib.Path(os.environ.get('PAPER_STATE_DIR',str(ROOT.parent/'stock-personal-paper')))
    if not state.is_absolute():state=ROOT/state
    env=dict(os.environ,PAPER_STATE_DIR=str(state.resolve()))
    log=state/'experiment-runs'/a.experiment/(uuid.uuid4().hex+'.json')
    r={'started_at':int(time.time()),'finished_at':None,'status':'running','stage':'source'}
    save_record(log,r)
    try:
        subprocess.run([sys.executable,str(ROOT/'bin/paper_daily.py'),'--config='+str(config)],cwd=ROOT,env=env,check=True,timeout=4200)
        r['stage']='comparison';save_record(log,r)
        cmd=['php',str(ROOT/'bin/paper_compare.php'),'--source='+account,'--experiment='+a.experiment]
        if a.candidate_ttl:cmd.append('--candidate-ttl='+a.candidate_ttl)
        subprocess.run(cmd,cwd=ROOT,env=env,check=True,timeout=1800)
        r['status']='success'
        return 0
    except (Exception,KeyboardInterrupt) as exc:
        r['status']='failed';r['error_type']=type(exc).__name__
        print('Comparison update failed: '+str(exc),file=sys.stderr)
        return 1
    finally:
        r['finished_at']=int(time.time());save_record(log,r)

if __name__=='__main__':sys.exit(main())
