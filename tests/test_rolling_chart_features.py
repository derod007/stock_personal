import importlib.util,unittest
from pathlib import Path
import numpy as np
P=Path(__file__).parent/'rolling_chart_features.py'
if not P.exists():P=Path(__file__).parents[1]/'docs/research/rolling_chart_features.py'
s=importlib.util.spec_from_file_location('study',P);m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class Tests(unittest.TestCase):
 def bars(self):
  c=np.arange(100.,121);return np.column_stack([c,c+1,c-1,c,np.ones(21)*10])
 def test_features_exact(self):
  a=self.bars();f=m.features(a);self.assertAlmostEqual(f[0],.2);self.assertEqual(f[1],0);self.assertAlmostEqual(f[2],110/100-1);self.assertAlmostEqual(f[3],120/121-1);self.assertEqual(f[4],1)
 def test_drawdown_path_order(self):
  a=self.bars();a[10]=[80,81,79,80,10];self.assertAlmostEqual(m.features(a)[1],80/109-1)
 def test_volume_windows(self):
  a=self.bars();a[0,4]=99999;a[-5:,4]=20;self.assertEqual(m.features(a)[4],2)
 def test_unknown_zero_volume(self):
  a=self.bars();a[1:16,4]=0;self.assertIsNone(m.features(a))
 def test_price_scale_invariance(self):
  a=self.bars();b=a.copy();b[:,:4]*=100;self.assertTrue(np.allclose(m.features(a),m.features(b)))
 def test_volume_scale_invariance(self):
  a=self.bars();b=a.copy();b[:,4]*=50;self.assertTrue(np.allclose(m.features(a),m.features(b)))
 def test_no_future_change(self):
  a=np.concatenate([self.bars(),self.bars()]);f=m.features(a[:21]);a[21:]=999;self.assertEqual(f,m.features(a[:21]))
 def test_fixed_cut_equality(self):self.assertEqual(m.assign([1,2],[[1,2],[1,2]]),[1,2])
 def test_nooverlap_before_outcome(self):
  rows=[dict(i=i,symbol='A',hit15=None if i==0 else 100) for i in [0,1,20,21]];keys={(r['i'],'A') for r in rows};self.assertEqual(m.no_overlap(rows,keys),{(0,'A'),(21,'A')})
 def test_date_equal_pair(self):
  rows=[dict(i=0,date='a',symbol='A',hit15=100,net20=10,first_hit=1,unknown_reason=None),dict(i=0,date='a',symbol='B',hit15=0,net20=-5,first_hit=None,unknown_reason=None)]
  v=m.stats(rows,{(0,'A')});self.assertEqual(v['hit15']['paired_diff'],100);self.assertEqual(v['net20']['paired_diff'],15)
if __name__=='__main__':unittest.main()
