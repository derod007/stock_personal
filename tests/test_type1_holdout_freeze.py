import importlib.util,json,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
p=Path(__file__).resolve().parents[1]/'docs/research/type1_holdout_freeze.py';s=importlib.util.spec_from_file_location('hf',p);m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class FreezeTests(unittest.TestCase):
 def fixture(self,root):
  for n in ['bars','raw','records']:(root/n).mkdir()
  raw=b'{"chart":{}}';bars=b'{"rows":[]}'
  (root/'raw/A.json').write_bytes(raw);(root/'bars/A.json').write_bytes(bars)
  r={'symbol':'A','status':'saved','raw_sha256':m.sha(raw),'bars_sha256':m.sha(bars)}
  (root/'records/A.json').write_text(json.dumps(r));(root/'collection.json').write_text(json.dumps([r]));(root/'manifest.json').write_text(json.dumps({'symbols':['A']}))
 def test_snapshot_immutable_from_source(self):
  with tempfile.TemporaryDirectory() as t:
   root=Path(t);src=root/'src';src.mkdir();self.fixture(src);dest=root/'snapshot';m.snapshot(src,dest)
   (src/'bars/A.json').write_text('changed');self.assertEqual((dest/'bars/A.json').read_bytes(),b'{"rows":[]}')
   for p,h in json.loads((dest/'frozen-hashes.json').read_text()).items():self.assertEqual(m.sha((dest/p).read_bytes()),h)
 def test_bad_hash_blocks(self):
  with tempfile.TemporaryDirectory() as t,patch.object(m.time,'sleep'):
   root=Path(t);src=root/'src';src.mkdir();self.fixture(src);(src/'bars/A.json').write_text('corrupt')
   with self.assertRaisesRegex(ValueError,'source changed'):m.snapshot(src,root/'snapshot')
   self.assertFalse((root/'snapshot/frozen-hashes.json').exists())
 def test_missing_symbol_blocks(self):
  with tempfile.TemporaryDirectory() as t:
   root=Path(t);self.fixture(root);(root/'collection.json').write_text('[]')
   with self.assertRaisesRegex(ValueError,'incomplete'):m.snapshot(root,root/'snapshot')
if __name__=='__main__':unittest.main()
