import importlib.util,unittest
from pathlib import Path
import numpy as np
P=Path(__file__).parent/'exit_flow_study.py'
if not P.exists():P=Path(__file__).parents[1]/'docs/research/exit_flow_study.py'
s=importlib.util.spec_from_file_location('study',P);m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class Tests(unittest.TestCase):
 def bars(self,n=50):return np.tile([100.,101,99,100,1000],(n,1))
 def sim(self,b,arm):return m.simulate(b,20,len(b),arm)
 def test_atr_known(self):self.assertEqual(m.atr(self.bars(),20),2)
 def test_target(self):
  b=self.bars();b[21]=[100,116,99,110,1000];r=self.sim(b,'target15');self.assertEqual(r['reason'],'target');self.assertAlmostEqual(r['exit_or_mark'],115)
 def test_both_stop_first(self):
  b=self.bars();b[21]=[100,116,95,110,1000];r=self.sim(b,'target15');self.assertEqual(r['exit_or_mark'],96)
 def test_gap_stop(self):
  b=self.bars();b[22]=[90,92,89,91,1000];r=self.sim(b,'target15');self.assertEqual(r['exit_or_mark'],90)
 def test_gap_target(self):
  b=self.bars();b[22]=[120,125,119,123,1000];r=self.sim(b,'target15');self.assertEqual(r['reason'],'target_gap');self.assertEqual(r['exit_or_mark'],120)
 def test_trail_activates_next_day(self):
  b=self.bars();b[21]=[100,111,99,110,1000];b[22]=[103,104,102,103,1000];r=self.sim(b,'trailing');self.assertEqual(r['last_i'],22);self.assertEqual(r['exit_or_mark'],103);self.assertGreater(r['last_stop'],96)
 def test_trend_next_open(self):
  b=self.bars();b[21]=[100,100,97,98,1000];b[22]=[99,100,98,99,1000];r=self.sim(b,'trend10');self.assertEqual(r['reason'],'trend_next_open');self.assertEqual(r['last_i'],22);self.assertEqual(r['exit_or_mark'],99)
 def test_pending_at_end_remains_open(self):
  b=self.bars(22);b[21]=[100,100,97,98,1000];r=self.sim(b,'trend10');self.assertEqual(r['status'],'open');self.assertTrue(r['pending_trend'])
 def test_fixed20_only(self):
  b=self.bars();self.assertEqual(self.sim(b,'fixed20_risk')['last_i'],40);self.assertEqual(self.sim(b,'target15')['status'],'open');self.assertEqual(self.sim(b,'target15')['held'],29)
 def test_missing_not_skipped(self):
  b=self.bars();b[22]=np.nan;self.assertEqual(self.sim(b,'target15')['reason'],'missing_or_invalid_path')
 def test_closed_before_missing(self):
  b=self.bars();b[21]=[100,116,99,110,1000];b[22]=np.nan;self.assertEqual(self.sim(b,'target15')['status'],'closed')
 def test_zero_volume_trigger(self):
  b=self.bars();b[21]=[100,116,99,110,1000];b[22]=[120,125,119,123,0];b[21]=[100,101,99,100,1000];self.assertEqual(self.sim(b,'target15')['reason'],'zero_volume_trigger')
 def test_zero_volume_entry(self):
  b=self.bars();b[21,4]=0;self.assertEqual(self.sim(b,'target15')['reason'],'invalid_entry')
 def test_nonpositive_stop(self):
  b=self.bars();b[:21,1]=200;b[:21,2]=1;self.assertEqual(self.sim(b,'target15')['reason'],'nonpositive_initial_stop')
 def test_cost(self):self.assertAlmostEqual(m.net(100,100),(.9985/1.0015-1)*100)
 def test_unchanged_past_atr(self):
  b=self.bars();a=m.atr(b,20);b[21:]=10000;self.assertEqual(a,m.atr(b,20))
if __name__=='__main__':unittest.main()
