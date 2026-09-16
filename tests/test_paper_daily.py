import importlib.util
import json
import pathlib
import subprocess
import tempfile
import unittest
from types import SimpleNamespace
spec = importlib.util.spec_from_file_location('daily', pathlib.Path(__file__).resolve().parents[1]/'bin/paper_daily.py')
daily = importlib.util.module_from_spec(spec)
spec.loader.exec_module(daily)

class DailyTests(unittest.TestCase):
    def exercise(self, failure=None, halted=False):
        with tempfile.TemporaryDirectory() as tmp:
            root=pathlib.Path(tmp)
            config=root/'config.json'
            config.write_text(json.dumps({'id':'test','symbols':{'MU':'semi'}}))
            calls=[]
            def runner(cmd, **kw):
                calls.append((cmd,kw))
                script=' '.join(str(part) for part in cmd)
                stage='collect' if 'fetch_comparison_data.py' in script else (
                    'naver_session' if 'paper_patch_naver_daily.php' in script else 'account')
                if failure==stage:
                    raise subprocess.CalledProcessError(3,cmd,stderr='test failure')
                if stage=='account':
                    return SimpleNamespace(stdout=json.dumps({'account':'test','mode':'forward','last_session':100,'halted':halted}))
                return SimpleNamespace(returncode=0)
            code=daily.update(config,root/'state',runner)
            logs=list((root/'state/runs/test-forward').glob('*.json'))
            self.assertEqual(len(logs),1)
            record=json.loads(logs[0].read_text())
            self.assertIsNotNone(record['finished_at'])
            self.assertEqual(calls[0][1]['env']['PAPER_STATE_DIR'],str(root/'state'))
            self.assertTrue(pathlib.Path(calls[0][0][1]).is_absolute())
            return code,record,calls
    def test_success(self):
        code,r,calls=self.exercise()
        self.assertEqual(code,0);self.assertEqual(r['status'],'success');self.assertEqual(len(calls),2)
        self.assertIn('--years=2', calls[0][0])
    def test_collection_failure_never_updates_account(self):
        code,r,calls=self.exercise('collect')
        self.assertEqual(code,1);self.assertEqual(r['stage'],'collect');self.assertEqual(r['status'],'failed');self.assertEqual(len(calls),1)
    def test_account_failure(self):
        code,r,_=self.exercise('account')
        self.assertEqual(code,1);self.assertEqual(r['stage'],'account');self.assertEqual(r['exit_code'],3)
    def test_halt_is_not_success(self):
        code,r,_=self.exercise(halted=True)
        self.assertEqual(code,2);self.assertEqual(r['status'],'halted')
    def test_korean_symbols_overlay_naver_before_account(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=pathlib.Path(tmp)
            config=root/'config.json'
            config.write_text(json.dumps({'id':'test','symbols':{'005930.KS':'semi'}}))
            calls=[]
            def runner(cmd, **kw):
                calls.append(cmd)
                if str(cmd[1]).endswith('paper_account.php'):
                    return SimpleNamespace(stdout=json.dumps({'account':'test','mode':'forward','last_session':100,'halted':False}))
                return SimpleNamespace(returncode=0)
            code=daily.update(config,root/'state',runner)
            self.assertEqual(code,0)
            self.assertEqual(len(calls),3)
            self.assertTrue(str(calls[1][1]).endswith('paper_patch_naver_daily.php'))
            self.assertTrue(str(calls[2][1]).endswith('paper_account.php'))
            self.assertIn('--years=2', calls[0])
    def test_scan_universe_buys_from_recommendations(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=pathlib.Path(tmp)
            config=root/'config.json'
            config.write_text(json.dumps({'id':'test','universe':'kr_amount_scan','scan_limit':100,'symbols':{}}))
            calls=[]
            def runner(cmd, **kw):
                calls.append((cmd,kw))
                script=' '.join(str(part) for part in cmd)
                if 'paper_scan_universe.php' in script:
                    return SimpleNamespace(stdout=json.dumps({
                        'ok':True,'symbols':{'005930.KS':'semi'},'held':[],'candidates':['005930.KS'],
                        'summary':{'recommend':1}}))
                if str(cmd[1]).endswith('paper_account.php'):
                    return SimpleNamespace(stdout=json.dumps({'account':'test','mode':'forward','last_session':100,'halted':False}))
                return SimpleNamespace(returncode=0)
            code=daily.update(config,root/'state',runner)
            self.assertEqual(code,0)
            self.assertEqual(len(calls),4)
            self.assertTrue(str(calls[0][0][1]).endswith('paper_scan_universe.php'))
            self.assertIn('--years=2', calls[1][0])
            self.assertTrue(any('005930.KS' in str(part) for part in calls[1][0]))
            self.assertTrue(str(calls[2][0][1]).endswith('paper_patch_naver_daily.php'))
            self.assertTrue(any(str(part).startswith('--symbols-file=') for part in calls[3][0]))
            self.assertEqual(calls[0][1].get('encoding'), 'utf-8')
    def test_empty_scan_skips_account(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=pathlib.Path(tmp)
            config=root/'config.json'
            config.write_text(json.dumps({'id':'test','universe':'kr_amount_scan','symbols':{}}))
            calls=[]
            def runner(cmd, **kw):
                calls.append(cmd)
                return SimpleNamespace(stdout=json.dumps({'ok':True,'symbols':{},'held':[],'candidates':[]}))
            code=daily.update(config,root/'state',runner)
            self.assertEqual(code,0)
            self.assertEqual(len(calls),1)
            record=json.loads(next((root/'state/runs/test-forward').glob('*.json')).read_text())
            self.assertTrue(record['summary']['empty_universe'])
    def test_atomic_replacement(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=pathlib.Path(tmp)/'runs/a.json'
            daily.save_record(p,{'status':'running'})
            daily.save_record(p,{'status':'success'})
            self.assertEqual(json.loads(p.read_text()),{'status':'success'})
            self.assertEqual(len(list(p.parent.iterdir())),1)

if __name__=='__main__': unittest.main()
