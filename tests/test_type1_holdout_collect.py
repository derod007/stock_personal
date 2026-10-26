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
    def test_extracted_bundle_reuse_preflight_and_resume_compatibility(self):
        bundle=m.DOC/'type1-holdout-collector.zip'
        with tempfile.TemporaryDirectory() as t:
            root=Path(t)/'code'
            with zipfile.ZipFile(bundle) as z:z.extractall(root)
            manifest=json.loads((root/'bundle-manifest.json').read_text())
            for name, digest in manifest.items():
                self.assertEqual(m.sha(root/name),digest,name)
            wrapper=m.module(root/'docs/research/type1_holdout_collect.py')
            self.assertEqual(wrapper.preflight(),m.preflight())
            pilot=m.module(root/'docs/research/historical-amount-top100-pilot.py')
            cal=json.loads((root/'docs/type1-holdout-v1/trading-calendar.json').read_text())
            days=pilot.candidate_trading_days(pilot.parse_ymd(m.START),pilot.parse_ymd(m.END),cal)
            with patch.object(pilot,'copy_if_hash_matches',side_effect=AssertionError('unexpected reuse')):
                report=pilot.reuse_verified_oct(days,Path(t)/'raw',Path(t)/'selected',src_root=Path(t)/'absent')
            self.assertEqual(report['overlap_days'],[])
            self.assertEqual(report['issues'],[])
            self.assertTrue(report['source_untouched'])
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
