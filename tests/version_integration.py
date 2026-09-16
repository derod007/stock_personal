"""Actual account CLI migration preserves history, balances and legacy version identity."""
import datetime, hashlib, json, os, pathlib, subprocess, tempfile
with tempfile.TemporaryDirectory() as tmp:
    root=pathlib.Path(tmp);data=root/'data';data.mkdir();state=root/'state'
    cfg=root/'config.json';cfg.write_text(json.dumps({'id':'fixture','currency':'USD','initial_cash':10000,'max_positions':4,'risk_pct':.01,'position_pct':.2,'sector_pct':.4,'total_risk_pct':.03,'symbols':{'MU':'semi'}}))
    first=datetime.datetime(2025,1,1,21,tzinfo=datetime.timezone.utc)
    bars=[{'timestamp':int((first+datetime.timedelta(days=i)).timestamp()),'available_at':int((first+datetime.timedelta(days=i)).timestamp()),'open':100,'high':102,'low':98,'close':100,'volume':1000} for i in range(65)]
    raw=json.dumps(bars).encode();(data/'MU.json').write_bytes(raw)
    (data/'sources.json').write_text(json.dumps([{'symbol':'MU','source':'fixture','sha256':hashlib.sha256(raw).hexdigest(),'fetched_at':'2025-04-01T00:00:00Z'}]))
    env=dict(os.environ,PAPER_STATE_DIR=str(state))
    cmd=['php','bin/paper_account.php','--config='+str(cfg),'--data='+str(data),'--mode=replay']
    subprocess.run(cmd,env=env,check=True,capture_output=True)
    # Build a hash-valid pre-scope fixture using an explicitly verified historical version.
    php="require 'bin/bootstrap.php'; $p=getenv('PAPER_STATE_DIR').'/fixture-replay.json'; $m=json_decode(file_get_contents('config/paper-legacy-versions.json'),true); (new ChartEntryLab\\PaperJournal($p))->transact(function(&$s,$emit)use($m){$s['version']=array_key_first($m['versions']);unset($s['strategy_fingerprint']);});"
    subprocess.run(['php','-r',php],env=env,check=True,capture_output=True)
    path=state/'fixture-replay.json';before=json.loads(path.read_bytes())
    subprocess.run(cmd,env=env,check=True,capture_output=True)
    after=json.loads(path.read_bytes())
    assert after['events'][:-1]==before['events']
    assert after['events'][-1]['type']=='strategy_scope_verified'
    assert after['state']['version']==before['state']['version']
    for key in ['cash','active','realized','frozen','history','equity','last_session']:
        assert after['state'][key]==before['state'][key],key
    same=path.read_bytes();subprocess.run(cmd,env=env,check=True,capture_output=True);assert path.read_bytes()==same
    # Unknown versions must fail without rewriting the journal.
    php="require 'bin/bootstrap.php'; (new ChartEntryLab\\PaperJournal(getenv('PAPER_STATE_DIR').'/fixture-replay.json'))->transact(function(&$s,$emit){$s['version']=str_repeat('0',64);unset($s['strategy_fingerprint']);});"
    subprocess.run(['php','-r',php],env=env,check=True,capture_output=True)
    same=path.read_bytes();p=subprocess.run(cmd,env=env,capture_output=True);assert p.returncode!=0 and path.read_bytes()==same
    print('VERSION_INTEGRATION_PASS: preserved history, identity, balances; repeat unchanged; unknown blocked')
