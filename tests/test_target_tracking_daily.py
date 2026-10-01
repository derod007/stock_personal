import importlib.util
import json
import pathlib
import subprocess
import tempfile
import unittest
from types import SimpleNamespace
spec=importlib.util.spec_from_file_location('target_daily', pathlib.Path(__file__).resolve().parents[1]/'bin/paper_daily.py')
daily=importlib.util.module_from_spec(spec);spec.loader.exec_module(daily)

class TargetDailyTests(unittest.TestCase):
    def test_independent_update_after_zero_scan_and_failures(self):
        for fail in (None,'scan','followup','target'):
            with self.subTest(fail=fail),tempfile.TemporaryDirectory() as tmp:
                root=pathlib.Path(tmp);conf=root/'config.json';conf.write_text(json.dumps({'id':'paper-kr','universe':'kr_amount_scan'}));calls=[]
                def runner(cmd,**kwargs):
                    name=pathlib.Path(cmd[1]).name;calls.append(name)
                    if name=='paper_scan_universe.php':
                        if fail=='scan':raise subprocess.CalledProcessError(1,cmd)
                        return SimpleNamespace(stdout=json.dumps({'ok':True,'symbols':{}}))
                    if (name=='paper_followup.php' and fail=='followup') or (name=='paper_target_tracking.php' and fail=='target'):
                        raise subprocess.TimeoutExpired(cmd,2400)
                    return SimpleNamespace(stdout=json.dumps({'status':'saved'}))
                code=daily.update(conf,root/'state',runner)
                r=json.loads(next((root/'state/runs/paper-kr-forward').glob('*.json')).read_text())
                self.assertEqual(calls[-1],'paper_target_tracking.php')
                self.assertEqual(calls.count('paper_target_tracking.php'),1)
                self.assertEqual(r['target_tracking']['status'],'failed' if fail=='target' else 'saved')
                self.assertIsNotNone(r['target_tracking']['finished_at'])
                self.assertEqual(code,1 if fail=='scan' else 0)
                if fail!='scan':self.assertLess(calls.index('paper_followup.php'),calls.index('paper_target_tracking.php'))
