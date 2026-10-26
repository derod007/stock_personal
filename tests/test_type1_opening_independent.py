import sys,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'docs/research'))
from type1_opening_independent import trade,stats

class Independent(unittest.TestCase):
 def setUp(self):
  self.days=['2020-01-01','2020-01-02'];self.s=dict(symbol='A',anchor='2019-12-31',entry_day=self.days[0],end=self.days[-1],cluster=1)
  self.b={d:dict(open=100,high=101,low=99,close=100,volume=1) for d in self.days}
 def runtrade(self,events=()):return trade(self.s,self.days,self.b,set(events))
 def test_target(self):
  self.b[self.days[0]]['high']=116;r=self.runtrade();self.assertEqual(r['exit_reason'],'target');self.assertAlmostEqual(r['net_pct'],(115*.9985/(100*1.0015)-1)*100)
 def test_both_stop_first(self):
  self.b[self.days[0]].update(high=116,low=94);r=self.runtrade();self.assertEqual(r['exit_reason'],'stop');self.assertTrue(r['same_bar_order_unknown'])
 def test_gap_stop(self):
  self.b[self.days[1]].update(open=80,low=79,high=101);r=self.runtrade();self.assertEqual(r['exit_price'],80)
 def test_time(self):self.assertEqual(self.runtrade()['exit_reason'],'time')
 def test_preentry_unknown(self):
  self.b[self.days[0]]['open']=0;r=self.runtrade();self.assertFalse(r['filled']);self.assertIsNone(r['net_pct'])
 def test_event_while_held(self):
  r=self.runtrade([self.days[1]]);self.assertTrue(r['filled']);self.assertEqual(r['status'],'unresolved')
 def test_event_after_exit(self):
  self.b[self.days[0]]['high']=116;self.assertEqual(self.runtrade([self.days[1]])['status'],'closed')
 def test_unknown_not_zero(self):
  closed=self.runtrade();unknown=self.runtrade([self.days[0]]);self.assertAlmostEqual(stats([closed,unknown])['mean_pct'],closed['net_pct'])
 def test_missing_after_fill(self):
  del self.b[self.days[1]];r=self.runtrade();self.assertTrue(r['filled']);self.assertEqual(r['unresolved_reason'],'missing_bar')

if __name__=='__main__':unittest.main()
