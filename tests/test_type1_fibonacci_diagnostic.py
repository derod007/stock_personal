import sys,unittest
from datetime import date,timedelta
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'docs/research'))
from type1_fibonacci_diagnostic import feature,band,percentile,stats

class Fibonacci(unittest.TestCase):
 def setUp(self):
  self.rows=[dict(day=(date(2020,1,1)+timedelta(days=i)).isoformat(),open=100,high=101,low=99,close=100,volume=1) for i in range(65)]
  self.rows[10]['low']=70;self.rows[40]['high']=130;self.anchor=self.rows[59]['day']
 def test_half_retracement(self):
  f=feature(self.rows,self.anchor);self.assertEqual(f['depth'],.5);self.assertTrue(f['selected']);self.assertEqual(f['high_confirmed'],self.rows[43]['day'])
 def test_future_cannot_change(self):
  before=feature(self.rows,self.anchor)
  for b in self.rows[60:]:b.update(high=10000,low=1)
  self.assertEqual(before,feature(self.rows,self.anchor))
 def test_last_three_not_confirmed(self):
  self.rows[58]['high']=1000;self.assertEqual(feature(self.rows,self.anchor)['high'],130)
 def test_exact_three_confirmed(self):
  self.rows[56]['high']=140;self.assertEqual(feature(self.rows,self.anchor)['high'],140)
 def test_tied_high_excluded(self):
  self.rows[41]['high']=130;self.assertEqual(feature(self.rows,self.anchor)['status'],'unknown')
 def test_no_prior_low(self):
  self.rows[10]['low']=99;self.assertEqual(feature(self.rows,self.anchor)['reason'],'no_preceding_confirmed_low')
 def test_outside_bar_ambiguous(self):
  self.rows[40]['low']=60;self.assertEqual(feature(self.rows,self.anchor)['status'],'unknown')
 def test_event_future_vs_inside(self):
  self.assertEqual(feature(self.rows,self.anchor,[self.rows[61]['day']])['status'],'known')
  self.assertEqual(feature(self.rows,self.anchor,[self.rows[20]['day']])['reason'],'reference_inside60')
 def test_boundaries(self):
  self.assertEqual([band(x) for x in [-.1,0,.382,.5,.618,.619,1,1.1]],['above_high','0_38.2','38.2_50','50_61.8','50_61.8','61.8_100','61.8_100','below_low'])
 def test_history(self):self.assertEqual(feature(self.rows,self.rows[58]['day'])['reason'],'under60')
 def test_percentile(self):self.assertEqual(percentile([0,10],.25),2.5)
 def test_unknown_profit_not_zero(self):
  a={'trade':dict(status='closed',net_pct=5,exit_reason='time',signal={'symbol':'A'})};b={'trade':dict(status='unresolved',net_pct=None)}
  self.assertEqual(stats([a,b])['mean_pct'],5);self.assertEqual(stats([a,b])['unresolved'],1)

if __name__=='__main__':unittest.main()
