import importlib.util,json,hashlib,pathlib,sys,tempfile,unittest
from types import SimpleNamespace
ROOT=pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'bin'))
import paper_research_daily as research


def data(folder,symbol='MU',count=60):
    bars=[dict(open=10,high=11,low=9,close=10,volume=100) for _ in range(count)]
    raw=json.dumps(bars).encode();(folder/(symbol+'.json')).write_bytes(raw)
    provider=b'provider';(folder/(symbol+'-provider.json')).write_bytes(provider)
    return {'symbol':symbol,'sha256':hashlib.sha256(raw).hexdigest(),'provider_file':symbol+'-provider.json','provider_sha256':hashlib.sha256(provider).hexdigest(),'source':'fixture'}


class ResearchTests(unittest.TestCase):
    def test_coverage_and_missing(self):
        with tempfile.TemporaryDirectory() as t:
            p=pathlib.Path(t);m=data(p);(p/'sources.json').write_text(json.dumps([m]))
            good=research.audit_collection(p,['MU']);self.assertTrue(good['complete'])
            bad=research.audit_collection(p,['MU','AAPL']);self.assertEqual(bad['missing'],['AAPL'])
            (p/'sources.json').write_text(json.dumps([m,m]));self.assertFalse(research.audit_collection(p,['MU'])['complete'])
    def test_short_and_modified(self):
        with tempfile.TemporaryDirectory() as t:
            p=pathlib.Path(t);m=data(p,count=59);(p/'sources.json').write_text(json.dumps([m]))
            self.assertEqual(research.audit_collection(p,['MU'])['symbols']['MU']['reason'],'fewer_than_60_valid_bars')
            (p/'MU.json').write_text('[]');self.assertEqual(research.audit_collection(p,['MU'])['symbols']['MU']['reason'],'normalized_hash_mismatch')
    def test_provider_integrity(self):
        with tempfile.TemporaryDirectory() as t:
            p=pathlib.Path(t);m=data(p);(p/'sources.json').write_text(json.dumps([m]));(p/'MU-provider.json').write_text('modified')
            self.assertEqual(research.audit_collection(p,['MU'])['symbols']['MU']['reason'],'provider_hash_mismatch')
    def exercise(self,complete):
        with tempfile.TemporaryDirectory() as t:
            p=pathlib.Path(t);cfg=p/'cfg.json';cfg.write_text(json.dumps({'id':'research-test','symbols':{'MU':'semi','AAPL':'tech'},'universe':{'version':1}}))
            calls=[]
            def runner(cmd,**kw):
                calls.append(cmd)
                if len(calls)==1:
                    out=pathlib.Path(next(x[6:] for x in cmd if x.startswith('--out=')))
                    manifest=[data(out)];
                    if complete:manifest.append(data(out,'AAPL'))
                    (out/'sources.json').write_text(json.dumps(manifest))
                    return SimpleNamespace(returncode=0)
                return SimpleNamespace(stdout=json.dumps({'account':'research-test','mode':'forward','halted':False}))
            code=research.run(cfg,p/'state',runner)
            logs=list((p/'state/research-runs/research-test').glob('*.json'));record=json.loads(logs[0].read_text())
            self.assertIn('elapsed_seconds',record);self.assertIn('collect_seconds',record)
            return code,record,len(calls)
    def test_partial_never_runs_account(self):
        code,r,n=self.exercise(False);self.assertEqual((code,r['status'],n),(1,'failed',1));self.assertEqual(r['coverage']['missing'],['AAPL'])
    def test_success_runs_account(self):
        code,r,n=self.exercise(True);self.assertEqual((code,r['status'],n),(0,'success',2));self.assertIn('account_seconds',r)
    def test_korean_patch_failure_blocks_account(self):
        import subprocess
        for fail in (False, True):
            with self.subTest(fail=fail), tempfile.TemporaryDirectory() as t:
                p=pathlib.Path(t);cfg=p/'cfg.json'
                cfg.write_text(json.dumps({'id':'research-kr-test','symbols':{'005930.KS':'semi'},'universe':{'version':1}}))
                calls=[]
                def runner(cmd,**kw):
                    calls.append(cmd)
                    if len(calls)==1:
                        out=pathlib.Path(next(x[6:] for x in cmd if x.startswith('--out=')))
                        (out/'sources.json').write_text(json.dumps([data(out,'005930.KS')]))
                    elif len(calls)==2:
                        self.assertTrue(any(x.endswith('paper_patch_naver_daily.php') for x in cmd))
                        if fail: raise subprocess.CalledProcessError(1,cmd)
                    return SimpleNamespace(stdout=json.dumps({'account':'research-kr-test','mode':'forward','halted':False}))
                code=research.run(cfg,p/'state',runner)
                record=json.loads(next((p/'state/research-runs/research-kr-test').glob('*.json')).read_text())
                self.assertEqual(code,1 if fail else 0)
                self.assertEqual(len(calls),2 if fail else 3)
                self.assertIn('naver_session_seconds',record)
                if fail:self.assertNotIn('account_seconds',record)

    def test_existing_account_id_rejected(self):
        with tempfile.TemporaryDirectory() as t:
            p=pathlib.Path(t);cfg=p/'cfg.json';cfg.write_text(json.dumps({'id':'paper-us','symbols':{'MU':'semi'},'universe':{'version':1}}))
            with self.assertRaises(ValueError):research.run(cfg,p,lambda *a,**k:self.fail('must not run'))
    def test_frozen_rosters(self):
        for market in ['us','kr']:
            c=json.loads((ROOT/f'config/paper-research-{market}-v1.json').read_text())
            self.assertEqual(len(c['symbols']),10);self.assertEqual(len(set(c['symbols'].values())),5)
            self.assertTrue(c['id'].startswith('research-'))
            for sector in set(c['symbols'].values()):self.assertEqual(list(c['symbols'].values()).count(sector),2)

if __name__=='__main__':unittest.main()
