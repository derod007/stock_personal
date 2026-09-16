"""Synthetic offline CLI regression: rolling windows, revisions, halt and original history."""
import datetime,hashlib,json,os,pathlib,subprocess,tempfile
with tempfile.TemporaryDirectory() as tmp:
    root=pathlib.Path(tmp); data=root/'input';data.mkdir();state=root/'state'
    config={'id':'fixture','currency':'USD','initial_cash':10000,'max_positions':4,'risk_pct':.01,'position_pct':.2,'sector_pct':.4,'total_risk_pct':.03,'symbols':{'MU':'semi'}}
    cfg=root/'config.json';cfg.write_text(json.dumps(config))
    first=datetime.datetime(2025,1,1,21,tzinfo=datetime.timezone.utc)
    bars=[{'timestamp':int((first+datetime.timedelta(days=i)).timestamp()),'available_at':int((first+datetime.timedelta(days=i)).timestamp()),'open':100,'high':102,'low':98,'close':100,'volume':1000} for i in range(65)]
    def write(rows):
        raw=json.dumps(rows).encode();(data/'MU.json').write_bytes(raw)
        (data/'sources.json').write_text(json.dumps([{'symbol':'MU','source':'fixture','sha256':hashlib.sha256(raw).hexdigest(),'fetched_at':'2025-04-01T00:00:00Z'}]))
    cmd=['php','bin/paper_account.php','--config='+str(cfg),'--data='+str(data),'--mode=replay']
    env=dict(os.environ,PAPER_STATE_DIR=str(state));write(bars)
    subprocess.run(cmd,env=env,check=True,capture_output=True)
    path=state/'fixture-replay.json';original=path.read_bytes();old=json.loads(original)
    write(bars[3:]);subprocess.run(cmd,env=env,check=True,capture_output=True)
    assert path.read_bytes()==original,'Rolling window changed state'
    bars[0]['volume']=1001;write(bars)
    subprocess.run(cmd,env=env,check=True,capture_output=True)
    current=json.loads(path.read_bytes());assert current['state']['halted']
    assert current['events'][:-1]==old['events']
    assert current['state']['history']==old['state']['history']
    assert current['state']['frozen']==old['state']['frozen']
    event=current['events'][-1];assert event['type']=='data_revision'
    h=event['payload']['report_hash'];raw=(state/'inputs'/(h+'.data')).read_bytes();assert hashlib.sha256(raw).hexdigest()==h
    report=json.loads(raw);assert report['change_count']==1
    f=report['changes'][0]['fields'][0];assert (f['field'],f['old'],f['new'])==('volume',1000,1001)
    before=path.read_bytes();p=subprocess.run(cmd,env=env,capture_output=True);assert p.returncode!=0 and path.read_bytes()==before
    print('PASS rolling window, detailed revision, halt, source preservation, archive hash, repeat rejection')
