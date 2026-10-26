import importlib.util,unittest
from pathlib import Path
import numpy as np
P=Path(__file__).parent/'pre_rise_candidates.py'
if not P.exists():P=Path(__file__).parents[1]/'docs/research/pre_rise_candidates.py'
spec=importlib.util.spec_from_file_location('study',P);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Tests(unittest.TestCase):
 def test_no_future_membership(self):
  d={0:{'a'},1:{'b'},2:{'future'}}
  self.assertEqual(m.memberships(d,1,0,True),{'a','b'})
  self.assertEqual(m.memberships(d,1,0,False),{'b'})
 def test_rolling_boundary(self):
  self.assertEqual(m.memberships({0:{'old'},1:{'yes'},20:{'new'}},20,0,True),{'yes','new'})
 def test_segment_boundary(self):
  self.assertEqual(m.memberships({0:{'old'},10:{'new'}},10,10,True),{'new'})
 def hist(self):
  a=np.tile([100.,110.,90.,100.,1000.],(22,1));a[16:21,1:3]=[101,99];return a
 def test_precursor_and_breakout_exclusive(self):
  a=self.hist();f=m.features(a,-.01);self.assertTrue(f['precursor']);self.assertFalse(f['breakout'])
  a[-1]=[100,103,99,102,1000];f=m.features(a,-.01);self.assertFalse(f['precursor']);self.assertTrue(f['breakout'])
 def test_missing_not_failure(self):
  a=self.hist();a[5,0]=np.nan;self.assertIsNone(m.features(a,0))
 def test_future_does_not_change_features(self):
  a=np.concatenate([self.hist(),np.tile([100.,500,1,400,1000],(10,1))]);f=m.features(a[:22],-.01);a[22:]=0;self.assertEqual(f,m.features(a[:22],-.01))
 def test_entry_gap_not_profit(self):
  b=np.array([[100,100,100,100,1],[110,110,110,110,1]],float)
  self.assertAlmostEqual(m.result(b,0,2,1)['net_pct'],(.9985/1.0015-1)*100)
 def test_future_missing_and_end(self):
  b=np.tile([100.,101,99,100,1],(5,1));b[2,0]=np.nan
  self.assertEqual(m.result(b,0,5,3)['reason'],'missing_or_invalid')
  self.assertEqual(m.result(b,3,5,2)['reason'],'segment_end')
 def test_date_weighting(self):
  rows=[dict(i=0,date='a',symbol=str(i),x=10) for i in range(10)]+[dict(i=1,date='b',symbol='a',x=-10)]
  self.assertEqual(m.groupstats(rows,'x')['date_mean'],0)
if __name__=='__main__':unittest.main()
