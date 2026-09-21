import json,pathlib,sys,tempfile,unittest
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/'bin'))
import paper_entry_daily as entry

class EntryTests(unittest.TestCase):
    def test_source_failure_never_compares(self):
        for code in [1,2]:
            with tempfile.TemporaryDirectory() as t:
                p=pathlib.Path(t);c=p/'c.json';c.write_text(json.dumps({'id':'research-test'}))
                result=entry.update(c,p,'test',lambda *a,**k:self.fail('must not compare'),lambda *a:code)
                self.assertEqual(result,code)
                r=json.loads(next((p/'entry-experiment-runs/test').glob('*.json')).read_text())
                self.assertEqual(r['stage'],'source');self.assertIsNotNone(r['finished_at'])
    def test_source_then_one_comparison(self):
        with tempfile.TemporaryDirectory() as t:
            p=pathlib.Path(t);c=p/'c.json';c.write_text(json.dumps({'id':'research-test'}));calls=[]
            def source(*a):calls.append('source');return 0
            def runner(cmd,**kw):calls.append(cmd);self.assertTrue(kw['check'])
            self.assertEqual(entry.update(c,p,'test',runner,source),0)
            self.assertEqual(calls[0],'source');self.assertEqual(len(calls),2)
            self.assertIn('--source=research-test',calls[1])
    def test_invalid_experiment(self):
        with self.assertRaises(ValueError):entry.update(pathlib.Path('unused'),pathlib.Path('unused'),'../bad')

if __name__=='__main__':unittest.main()
