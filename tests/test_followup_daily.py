import importlib.util
import json
import pathlib
import subprocess
import tempfile
import unittest
from types import SimpleNamespace
spec = importlib.util.spec_from_file_location('daily_followup', pathlib.Path(__file__).resolve().parents[1]/'bin/paper_daily.py')
daily = importlib.util.module_from_spec(spec)
spec.loader.exec_module(daily)

class FollowupDailyTest(unittest.TestCase):
    def run_case(self, fail=False, account_fail=False):
        with tempfile.TemporaryDirectory() as tmp:
            root=pathlib.Path(tmp); config=root/'config.json'
            config.write_text(json.dumps({'id':'test','universe':'kr_amount_scan'}))
            calls=[]
            def runner(cmd, **kw):
                calls.append(cmd)
                if cmd[1].endswith('paper_scan_universe.php'):
                    return SimpleNamespace(stdout=json.dumps({'ok':True,'symbols':{'005930.KS':'semi'} if account_fail else {}}))
                if cmd[1].endswith('paper_account.php'):
                    raise subprocess.CalledProcessError(1,cmd)
                if cmd[1].endswith('paper_followup.php'):
                    if fail: raise subprocess.TimeoutExpired(cmd,2400)
                    return SimpleNamespace(stdout=json.dumps({'status':'saved','observations':100}))
                return SimpleNamespace(returncode=0)
            code=daily.update(config,root/'state',runner)
            record=json.loads(next((root/'state/runs/test-forward').glob('*.json')).read_text())
            self.assertEqual(sum(c[1].endswith('paper_followup.php') for c in calls),1)
            self.assertEqual(code,1 if account_fail else 0)
            self.assertEqual(record['followup']['status'],'failed' if fail else 'saved')
            return record
    def test_zero_recommendations_still_followed(self):
        self.assertTrue(self.run_case()['summary']['empty_universe'])
    def test_tracking_failure_does_not_change_operating_result(self):
        self.assertEqual(self.run_case(fail=True)['status'],'success')
    def test_account_failure_still_tracks(self):
        self.assertEqual(self.run_case(account_fail=True)['status'],'failed')
