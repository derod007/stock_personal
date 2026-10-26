import sys,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'docs/research'))
from original_score_stop_review import exit_path,stamp
class FrozenExit(unittest.TestCase):
 def setUp(self):
  self.days=[f'2020-01-{i:02d}' for i in range(1,25)];self.b={d:dict(open=100,high=101,low=99,close=100,volume=1) for d in self.days};self.t=dict(trade=dict(entry_fill=100,entry_at=stamp(self.days[0])),plan=dict(stop=98,target=110),quantity=10,buy_cost=1001)
 def go(self,w=None):return exit_path(self.t,self.days,self.b,w)
 def test_original_structure(self):
  self.b[self.days[0]]['low']=97;self.assertEqual(self.go()['exit_reason'],'stop');self.assertEqual(self.go(.05)['exit_reason'],'time')
 def test_two_widths(self):
  self.b[self.days[0]]['low']=94;self.b[self.days[1]]['high']=111;self.assertEqual(self.go(.05)['exit_reason'],'stop');self.assertEqual(self.go(.10)['exit_reason'],'target')
 def test_later_gap_priority(self):
  self.b[self.days[1]].update(open=115,high=120,low=80,close=100);self.assertEqual(self.go(.10)['exit_reason'],'target')
 def test_entry_bar_stop_priority(self):
  self.b[self.days[0]].update(high=115,low=89);self.assertEqual(self.go(.10)['exit_reason'],'stop');self.assertTrue(self.go(.10)['same_bar_unknown'])
 def test_costs(self):
  self.b[self.days[0]]['low']=89;r=self.go(.10);self.assertAlmostEqual(r['exit_price'],90*.9995);self.assertAlmostEqual(r['pnl_won'],10*90*.9995*.999-1001)
 def test_longer_unknown(self):
  self.b[self.days[0]]['low']=97;del self.b[self.days[1]];self.assertEqual(self.go()['status'],'closed');self.assertEqual(self.go(.10)['status'],'unresolved')
 def test_deadline(self):
  r=self.go(.10);self.assertEqual(r['held_bars'],20);self.assertEqual(r['exit_day'],self.days[19])
 def test_segment_end(self):self.assertEqual(exit_path(self.t,self.days[:3],self.b,.10)['status'],'unresolved')
if __name__=='__main__':unittest.main()
