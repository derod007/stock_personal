import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import zipfile

P=Path(__file__).resolve().parents[1]/'docs/research/type1_holdout_collect.py'
spec=importlib.util.spec_from_file_location('holdout',P);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class HoldoutTests(unittest.TestCase):
    def test_calendar_fixed(self):
        r=m.preflight();self.assertEqual(r['expected_sessions'],243);self.assertEqual(r['expected_market_files'],486)
        self.assertFalse(r['account_execution'])
    def test_missing_key_no_network_or_output(self):
        with tempfile.TemporaryDirectory() as t,patch.dict(os.environ,{},clear=True),patch.object(m.sys.stdin,'isatty',return_value=False),patch.object(m.subprocess,'run') as run:
            p=Path(t)/'out';self.assertEqual(m.main(['--collect','--output',str(p)]),2)
            run.assert_not_called();self.assertFalse(p.exists())
    def test_resume_definition_mismatch(self):
        with tempfile.TemporaryDirectory() as t,patch.dict(os.environ,{'KRX_AUTH_KEY':'fake-key'}),patch.object(m.subprocess,'run') as run:
            p=Path(t);(p/'run-manifest.json').write_text('{}')
            with self.assertRaisesRegex(ValueError,'definition changed'):m.main(['--collect','--resume','--output',str(p)])
            run.assert_not_called()
    def test_failed_collection_not_packaged(self):
        with tempfile.TemporaryDirectory() as t,patch.dict(os.environ,{'KRX_AUTH_KEY':'fake-key'}),patch.object(m.subprocess,'run') as run:
            run.return_value.returncode=2;p=Path(t)/'out'
            self.assertEqual(m.main(['--collect','--output',str(p)]),2)
            self.assertFalse(p.with_suffix('.zip').exists());self.assertTrue((p/'run-manifest.json').exists())
    def fixture(self,p):
        (p/'docs').mkdir(parents=True);(p/'run-manifest.json').write_text('{}')
        for n in ('collect-summary.json','file-hashes.json','candidates.json','universe.json','input-validation.json'):(p/'docs'/n).write_text('{}')
    def test_zip_allowlist_excludes_credentials(self):
        with tempfile.TemporaryDirectory() as t:
            p=Path(t)/'out';self.fixture(p);(p/'.krx-auth-key').write_text('secret')
            z=m.package(p,'secret')
            with zipfile.ZipFile(z) as f:self.assertNotIn('.krx-auth-key',f.namelist())
    def test_echoed_secret_blocks_zip(self):
        with tempfile.TemporaryDirectory() as t:
            p=Path(t)/'out';self.fixture(p);(p/'docs/candidates.json').write_text('{"bad":"secret"}')
            with self.assertRaisesRegex(ValueError,'Secret detected'):m.package(p,'secret')
            self.assertFalse(p.with_suffix('.zip').exists())
if __name__=='__main__':unittest.main()
