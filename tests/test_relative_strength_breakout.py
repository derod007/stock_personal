import sys,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'docs/research'))
import numpy as np
from relative_strength_breakout import features,outcome,analyze
class Selection(unittest.TestCase):
 def hist(self):
  a=np.array([[100,105,95,100,1000]]*22,dtype=float);a[16:21]=[100,101,99,100,100];a[21]=[103,104,102,103,10000];return a
 def test_pipeline(self):
  f=features(self.hist(),1);self.assertTrue(f['strong']);self.assertTrue(f['contraction']);self.assertTrue(f['breakout']);self.assertAlmostEqual(f['volume_ratio'],.1)
 def test_strict_equal_strength(self):
  a=self.hist();f=features(a,(103/100-1)*100);self.assertFalse(f['strong'])
 def test_strict_breakout(self):
  a=self.hist();a[-1]=[101,102,100,101,100];self.assertFalse(features(a,0)['breakout'])
 def test_t_volume_not_filter(self):
  a=self.hist();f=features(a,0);a[-1,4]=0;self.assertEqual(f,features(a,0))
 def test_history_invalid(self):
  a=self.hist();a[5,2]=0;self.assertIsNone(features(a,0))
 def test_cost_horizon(self):
  a=np.array([[100,102,99,101,100]]*5,dtype=float);r=outcome(a,5);self.assertAlmostEqual(r['net_pct'],(101*.9985/(100*1.0015)-1)*100);self.assertEqual(outcome(a,10)['reason'],'segment_end')
 def test_future_missing_unknown(self):
  a=np.array([[100,101,99,100,100]]*5,dtype=float);a[3]=np.nan;self.assertEqual(outcome(a,5)['status'],'unknown')
 def test_zero_exit_volume(self):
  a=np.array([[100,101,99,100,100]]*5,dtype=float);a[-1,4]=0;self.assertEqual(outcome(a,5)['reason'],'zero_entry_or_exit_volume')
 def test_day_weight_and_repeat(self):
  rows=[]
  for i,date in [(0,'2020-01-01'),(1,'2020-01-02'),(21,'2020-02-01')]:
   for sym,selected,value in [('A',True,2),('B',False,0)]:rows.append(dict(i=i,date=date,symbol=sym,features=dict(all=True,strong=selected),outcomes={'20':dict(status='complete',net_pct=value,market_high_pct=3,market_low_pct=-1)}))
  z=analyze(rows,20,'strong');self.assertEqual(z['date_equal_complement_diff_pp'],2);self.assertEqual(analyze(rows,20,'strong',True)['signals'],2)
if __name__=='__main__':unittest.main()
