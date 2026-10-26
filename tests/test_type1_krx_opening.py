import os,unittest,sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'docs/research'))
from type1_krx_opening import GuardedBars,valid,original,SETTINGS

class Guards(unittest.TestCase):
 def bar(self,day='2020-01-01',lo=99,hi=101):return dict(day=day,open=100,high=hi,low=lo,close=100,volume=1)
 def test_future_event(self):
  b=GuardedBars([self.bar()],['2020-01-02']);self.assertIn('2020-01-01',b);self.assertNotIn('2020-01-02',b)
 def test_missing(self):self.assertEqual(GuardedBars([],[]).problem('2020-01-01'),'missing_required_bar')
 def test_invalid(self):
  b=self.bar();b['open']=0;self.assertFalse(valid(b))
 def test_nonfinite(self):
  for v in [None,float('nan'),float('inf')]:
   b=self.bar();b['high']=v;self.assertFalse(valid(b))
 def test_duplicate(self):
  with self.assertRaises(ValueError):GuardedBars([self.bar(),self.bar()],[])

@unittest.skipUnless(os.environ.get('PR107_SOURCE'),'Set PR107_SOURCE to the hash-pinned source')
class Execution(Guards):
 def model(self,rows,events=[]):
  days=['2020-01-01','2020-01-02'];sig=[dict(symbol='A',anchor='2019-12-31',entry_day=days[0],end=days[1],cluster=1)]
  return original(Path(os.environ['PR107_SOURCE'])).simulate(days,{'A':GuardedBars(rows,events)},sig,SETTINGS,{},1)
 def test_stop_first(self):
  r=self.model([self.bar(lo=94,hi=116),self.bar('2020-01-02')]);self.assertEqual(r['trades'][0]['exit_reason'],'stop')
 def test_entry_day_target(self):
  r=self.model([self.bar(hi=116),self.bar('2020-01-02')]);self.assertEqual(r['trades'][0]['exit_reason'],'target')
 def test_held_event_halts_without_retroactive_removal(self):
  r=self.model([self.bar(),self.bar('2020-01-02')],['2020-01-02']);self.assertEqual((r['fills'],r['closed'],r['through']),(1,0,'2020-01-01'))
 def test_event_after_exit_irrelevant(self):
  r=self.model([self.bar(hi=116),self.bar('2020-01-02')],['2020-01-02']);self.assertEqual(r['status'],'completed')

if __name__=='__main__':unittest.main()
