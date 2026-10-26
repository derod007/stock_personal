import importlib.util
from pathlib import Path
import unittest
s=importlib.util.spec_from_file_location('audit',Path(__file__).resolve().parents[1]/'docs/research/entry_cancel_audit.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class AuditTests(unittest.TestCase):
 def setUp(self):self.p=dict(entry=100,stop=95,target=110)
 def bar(self,o=99,h=111,l=98):return dict(open=o,high=h,low=l)
 def test_opening_target(self):self.assertEqual(m.classify(self.p,self.bar()),'opening_fill_target_only')
 def test_double_touch_unknown(self):self.assertEqual(m.classify(self.p,self.bar(l=94)),'opening_fill_both_touched_unknown')
 def test_intraday_unknown(self):self.assertEqual(m.classify(self.p,self.bar(o=105)),'intraday_entry_target_order_unknown')
 def test_no_entry(self):self.assertEqual(m.classify(self.p,self.bar(o=105,l=104)),'target_without_entry_touch')
 def test_gap_precedence(self):
  self.assertEqual(m.classify(self.p,self.bar(o=94,l=93)),'open_at_or_below_stop')
  self.assertEqual(m.classify(self.p,self.bar(o=111)),'open_at_or_above_target')
 def test_no_cancel(self):self.assertIsNone(m.classify(self.p,self.bar(h=105)))
 def test_cost_and_limit(self):
  r=m.result(self.p,self.bar(o=100),'target');self.assertEqual(r['entry'],100)
  self.assertAlmostEqual(r['net_return_pct'],(110*.999/(100*1.001)-1)*100,places=4)
if __name__=='__main__':unittest.main()
