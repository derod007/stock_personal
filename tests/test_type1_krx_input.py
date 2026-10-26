import importlib.util,json,os,tempfile,unittest,zipfile
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
def module(name):
 s=importlib.util.spec_from_file_location(name,ROOT/'docs/research'/f'{name}.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
m=module('type1_krx_prehistory_collect');a=module('type1_krx_input_audit')
class InputTests(unittest.TestCase):
 def test_calendar(self):
  r=m.preflight();self.assertEqual(r['expected_sessions'],123);self.assertEqual(r['expected_market_files'],246);self.assertFalse(r['account_execution'])
 def test_missing_key_no_writes(self):
  with tempfile.TemporaryDirectory() as t,patch.dict(os.environ,{},clear=True),patch.object(m.sys.stdin,'isatty',return_value=False),patch.object(m.subprocess,'run') as run:
   p=Path(t)/'out';self.assertEqual(m.main(['--collect','--output',str(p)]),2);self.assertFalse(p.exists());run.assert_not_called()
 def test_existing_path_checked_before_key(self):
  with tempfile.TemporaryDirectory() as t,patch.dict(os.environ,{},clear=True),patch.object(m.getpass,'getpass') as prompt:
   with self.assertRaises(ValueError):m.main(['--collect','--output',t])
   prompt.assert_not_called()
 def test_definition_mismatch_stops(self):
  with tempfile.TemporaryDirectory() as t,patch.object(m.subprocess,'run') as run:
   (Path(t)/'run-manifest.json').write_text('{}')
   with self.assertRaisesRegex(ValueError,'definition changed'):m.main(['--collect','--resume','--output',t])
   run.assert_not_called()
 def test_failed_collection_no_zip(self):
  with tempfile.TemporaryDirectory() as t,patch.dict(os.environ,{'KRX_AUTH_KEY':'test-only'}),patch.object(m.subprocess,'run') as run:
   run.return_value.returncode=1;p=Path(t)/'out';self.assertEqual(m.main(['--collect','--output',str(p)]),1);self.assertFalse(p.with_suffix('.zip').exists())
 def test_ohlc(self):
  r=dict(TDD_OPNPRC='10',TDD_HGPRC='12',TDD_LWPRC='9',TDD_CLSPRC='11',ACC_TRDVOL='1,000');self.assertIsNone(a.quality(r)[1]);r['TDD_CLSPRC']='13';self.assertEqual(a.quality(r)[1],'ohlc_order');r['TDD_OPNPRC']='0';self.assertEqual(a.quality(r)[1],'nonpositive_price')
 def test_nonfinite(self):self.assertIsNone(a.number('nan'))
 def test_bundle_actual_dependency(self):
  with tempfile.TemporaryDirectory() as t:
   bundle=Path(t)/'collector.zip';module('build_type1_krx_prehistory_bundle').build(bundle)
   with zipfile.ZipFile(bundle) as z:z.extractall(t)
   root=Path(t);pilot=m.module(root/'docs/research/historical-amount-top100-pilot.py');cal=pilot.load_trading_calendar(root/'docs/type1-krx-input-v1/trading-calendar.json');days=pilot.candidate_trading_days(pilot.parse_ymd(m.START),pilot.parse_ymd(m.END),cal)
   r=pilot.reuse_verified_oct(days,root/'tmp/raw',root/'tmp/selected',src_root=root/'not-present');self.assertEqual(r['issues'],[]);self.assertEqual(r['overlap_days'],[])
   for n,h in json.loads((root/'bundle-manifest.json').read_text()).items():self.assertEqual(m.sha(root/n),h)
if __name__=='__main__':unittest.main()
