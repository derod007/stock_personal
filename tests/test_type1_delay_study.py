import sys,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'docs/research'))
from type1_delay_study import delayed,paired

class Delay(unittest.TestCase):
 def setUp(self):
  self.days=[f'2020-01-{i:02d}' for i in range(1,25)]
  self.s=dict(symbol='A',anchor=self.days[0],entry_day=self.days[1],end=self.days[20],cluster=1)
  self.b={d:dict(open=100,high=101,low=99,close=100,volume=1) for d in self.days}
 def go(self,lag,events=()):return delayed(self.s,self.days,self.b,events,lag)
 def test_offsets_and_same_end(self):
  for lag in range(3):
   r=self.go(lag);self.assertEqual(r['entry_day'],self.days[1+lag]);self.assertEqual(r['exit_day'],self.days[20]);self.assertEqual(r['held_bars'],20-lag)
 def test_original_signal_not_mutated(self):
  self.go(2);self.assertEqual(self.s['entry_day'],self.days[1])
 def test_waiting_stop_not_applied(self):
  self.b[self.days[1]]['low']=90;self.assertEqual(self.go(0)['exit_reason'],'stop');self.assertEqual(self.go(1)['exit_reason'],'time')
 def test_waiting_gain_not_used_as_filter(self):
  self.b[self.days[2]].update(open=120,high=121,low=119,close=120);r=self.go(1);self.assertEqual(r['entry'],120)
 def test_missing_wait_day(self):
  del self.b[self.days[1]];self.assertEqual(self.go(0)['status'],'unresolved');self.assertEqual(self.go(1)['status'],'closed')
 def test_reference_wait_day(self):
  r=self.go(2,[self.days[1]]);self.assertEqual(r['unresolved_reason'],'reference_during_wait');self.assertFalse(r['filled'])
 def test_reset_target_to_delayed_entry(self):
  self.b[self.days[2]].update(open=90,high=110,low=89,close=100);r=self.go(1);self.assertEqual(r['exit_reason'],'target');self.assertAlmostEqual(r['exit_price'],103.5)
 def test_future_after_exit_irrelevant(self):
  self.b[self.days[2]]['high']=120;r=self.go(1,[self.days[3]]);self.assertEqual(r['status'],'closed')

if __name__=='__main__':unittest.main()
