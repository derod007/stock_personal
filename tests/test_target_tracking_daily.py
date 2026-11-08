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
        for fail in (None,'scan','followup','target','envelope','higher_low','type1'):
            with self.subTest(fail=fail),tempfile.TemporaryDirectory() as tmp:
                root=pathlib.Path(tmp);conf=root/'config.json';conf.write_text(json.dumps({'id':'paper-kr','universe':'kr_amount_scan'}));calls=[]
                def runner(cmd,**kwargs):
                    name=pathlib.Path(cmd[1]).name;calls.append(name)
                    if name=='paper_scan_universe.php':
                        if fail=='scan':raise subprocess.CalledProcessError(1,cmd)
                        return SimpleNamespace(stdout=json.dumps({'ok':True,'symbols':{}}))
                    if (name=='paper_followup.php' and fail=='followup') or (name=='paper_target_tracking.php' and fail=='target') or (name=='paper_envelope_research.php' and fail=='envelope') or (name=='paper_higher_low.php' and fail=='higher_low') or (name=='type1_track.php' and fail=='type1'):
                        raise subprocess.TimeoutExpired(cmd,2400)
                    return SimpleNamespace(stdout=json.dumps({'status':'saved'}))
                code=daily.update(conf,root/'state',runner)
                r=json.loads(next((root/'state/runs/paper-kr-forward').glob('*.json')).read_text())
                self.assertEqual(calls[-1],'type1_track.php')
                self.assertEqual(r['type1_tracking']['status'],'failed' if fail=='type1' else 'saved')
                self.assertEqual(calls.count('paper_target_tracking.php'),1)
                self.assertEqual(calls.count('paper_envelope_research.php'),1)
                self.assertEqual(calls.count('paper_higher_low.php'),1)
                self.assertEqual(r['higher_low_research']['status'],'failed' if fail=='higher_low' else 'saved')
                self.assertEqual(r['envelope_research']['status'],'failed' if fail=='envelope' else 'saved')
                self.assertEqual(r['target_tracking']['status'],'failed' if fail=='target' else 'saved')
                self.assertNotIn('exit_code', r['target_tracking'])
                self.assertNotIn('error_detail', r['target_tracking'])
                self.assertIsNotNone(r['target_tracking']['finished_at'])
                self.assertEqual(code,1 if fail=='scan' else 0)
                if fail!='scan':self.assertLess(calls.index('paper_followup.php'),calls.index('paper_target_tracking.php'))

    def test_target_process_failure_records_output_and_continues(self):
        cases=(
            subprocess.CalledProcessError(255,['php'],stderr='Frozen ledger integrity mismatch'),
            subprocess.CalledProcessError(4,['php'],output='not json'),
            subprocess.CalledProcessError(9,['php']),
        )
        for exc in cases:
            with self.subTest(code=exc.returncode),tempfile.TemporaryDirectory() as tmp:
                root=pathlib.Path(tmp);conf=root/'config.json';conf.write_text(json.dumps({'id':'paper-kr','universe':'kr_amount_scan'}));calls=[]
                def runner(cmd,**kwargs):
                    name=pathlib.Path(cmd[1]).name;calls.append(name)
                    if name=='paper_scan_universe.php':
                        return SimpleNamespace(stdout=json.dumps({'ok':True,'symbols':{}}))
                    if name=='paper_target_tracking.php':
                        raise exc
                    return SimpleNamespace(stdout=json.dumps({'status':'saved'}))
                code=daily.update(conf,root/'state',runner)
                r=json.loads(next((root/'state/runs/paper-kr-forward').glob('*.json')).read_text())['target_tracking']
                self.assertEqual(code,0)
                self.assertEqual(r['status'],'failed')
                self.assertEqual(r['exit_code'],exc.returncode)
                self.assertEqual(calls[calls.index('paper_target_tracking.php')+1],'paper_envelope_research.php')
                text=(exc.stderr or '')+(exc.stdout or '')
                if text:
                    self.assertIn(text,r['error_detail'])
                    self.assertLessEqual(len(r['error_detail']),4000)
                else:
                    self.assertNotIn('error_detail',r)
        with tempfile.TemporaryDirectory() as tmp:
            root=pathlib.Path(tmp);conf=root/'config.json';conf.write_text(json.dumps({'id':'paper-kr','universe':'kr_amount_scan'}))
            def runner(cmd,**kwargs):
                if pathlib.Path(cmd[1]).name=='paper_target_tracking.php':
                    raise subprocess.CalledProcessError(255,cmd,output='x'*5000,stderr='needle-message')
                if pathlib.Path(cmd[1]).name=='paper_scan_universe.php':
                    return SimpleNamespace(stdout=json.dumps({'ok':True,'symbols':{}}))
                return SimpleNamespace(stdout=json.dumps({'status':'saved'}))
            daily.update(conf,root/'state',runner)
            detail=json.loads(next((root/'state/runs/paper-kr-forward').glob('*.json')).read_text())['target_tracking']['error_detail']
            self.assertIn('needle-message',detail)
            self.assertLessEqual(len(detail),4000)
