import sys,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'docs/research'))
from type1_stop_width import stop_trade
class StopWidth(unittest.TestCase):
 def setUp(self):
  self.days=['01','02','03'];self.s=dict(symbol='X',anchor='00',entry_day='01',end='03');self.b={d:dict(open=100,high=101,low=99,close=100,volume=1) for d in self.days}
 def runtrade(self,width,events=()):return stop_trade(self.s,self.days,self.b,set(events),width)
 def test_stop_rescue(self):
  self.b['01']['low']=94;self.b['02']['high']=116
  self.assertEqual(self.runtrade(.05)['exit_reason'],'stop');self.assertEqual(self.runtrade(.10)['exit_reason'],'target')
 def test_worse_loss(self):
  self.b['01']['low']=89
  self.assertEqual(self.runtrade(.05)['exit_price'],95);self.assertEqual(self.runtrade(.10)['exit_price'],90)
 def test_same_bar(self):
  self.b['01'].update(high=120,low=89);r=self.runtrade(.10);self.assertEqual(r['exit_reason'],'stop');self.assertTrue(r['same_bar_order_unknown'])
 def test_gap(self):
  self.b['02'].update(open=80,low=79,close=82,high=85);self.assertEqual(self.runtrade(.10)['exit_price'],80)
 def test_time_and_cost(self):
  r=self.runtrade(.10);self.assertEqual(r['exit_reason'],'time');self.assertEqual(r['held_bars'],3);self.assertAlmostEqual(r['net_pct'],(.9985/1.0015-1)*100)
 def test_extra_exposure_unknown(self):
  self.b['01']['low']=94;self.assertEqual(self.runtrade(.05,['02'])['status'],'closed');self.assertEqual(self.runtrade(.10,['02'])['status'],'unresolved')
 def test_future_irrelevant(self):
  self.b['01']['high']=116;del self.b['02'];self.assertEqual(self.runtrade(.10)['status'],'closed')
if __name__=='__main__':unittest.main()
