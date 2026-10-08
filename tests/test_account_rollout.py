import importlib.util
import json
import pathlib
import subprocess
import tempfile
import unittest
from types import SimpleNamespace
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('roll_daily',ROOT/'bin/paper_daily.py')
daily=importlib.util.module_from_spec(spec);spec.loader.exec_module(daily)
class Rollout(unittest.TestCase):
    def test_zero_candidates_still_checks_account(self):
        with tempfile.TemporaryDirectory() as tmp:
            state=pathlib.Path(tmp);calls=[]
            def runner(cmd,**kw):
                script=pathlib.Path(cmd[1]).name;calls.append(script)
                if script=='paper_account_preflight.php':
                    return SimpleNamespace(stdout=json.dumps({'status':'new_account','account':'paper-kr-recovery-v1'}))
                if script=='paper_scan_universe.php':return SimpleNamespace(stdout='{"ok":true,"symbols":{}}')
                self.assertIn('--account=paper-kr-recovery-v1',cmd)
                return SimpleNamespace(stdout='{"status":"saved"}')
            self.assertEqual(daily.update(ROOT/'config/paper-kr-recovery-v1.json',state,runner),0)
            self.assertEqual(calls[:2],['paper_account_preflight.php','paper_scan_universe.php'])
            self.assertNotIn('paper_account.php',calls)
            log=json.loads(next((state/'runs/paper-kr-recovery-v1-forward').glob('*.json')).read_text())
            self.assertEqual(log['account_preflight']['status'],'new_account')
            self.assertTrue(log['summary']['empty_universe'])
    def test_mismatch_stops_scan(self):
        with tempfile.TemporaryDirectory() as tmp:
            calls=[]
            def runner(cmd,**kw):
                calls.append(pathlib.Path(cmd[1]).name)
                if calls[-1]=='paper_account_preflight.php':raise subprocess.CalledProcessError(1,cmd,stderr='Pinned strategy changed')
                return SimpleNamespace(stdout='{"status":"saved"}')
            self.assertEqual(daily.update(ROOT/'config/paper-kr-recovery-v1.json',pathlib.Path(tmp),runner),1)
            self.assertNotIn('paper_scan_universe.php',calls)
