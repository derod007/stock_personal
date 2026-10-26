import sys,unittest
from datetime import date,timedelta
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'docs/research'))
from fib_elliott_study import signal_at,scan

class Wave(unittest.TestCase):
 def setUp(self):
  self.rows=[dict(day=(date(2020,1,1)+timedelta(days=i)).isoformat(),open=105,high=106,low=104,close=105,volume=1) for i in range(65)]
  self.rows[10]['low']=70;self.rows[40]['high']=130;self.rows[50]['low']=100
  self.rows[59].update(close=108,high=109);self.anchor=self.rows[59]['day'];self.prev=self.rows[58]['day']
 def f(self,events=()):return signal_at(self.rows,self.anchor,self.prev,events)
 def test_sequence(self):
  r=self.f();self.assertEqual(r['stage'],'signal');self.assertEqual(r['depth'],.5);self.assertEqual(r['high_confirmed'],self.rows[43]['day'])
 def test_rebound_required(self):
  self.rows[59].update(close=106);self.assertEqual(self.f()['stage'],'no_rebound')
 def test_deep_then_recover_not_accepted(self):
  self.rows[45]['low']=80;self.assertEqual(self.f()['stage'],'retracement_outside')
 def test_current_bar_deep(self):
  self.rows[59]['low']=90;self.assertEqual(self.f()['stage'],'rebound_bar_too_deep')
 def test_future_mutation(self):
  before=self.f()
  for r in self.rows[60:]:r.update(high=10000,low=1,close=1000)
  self.assertEqual(before,self.f())
 def test_event_future_vs_past(self):
  self.assertEqual(self.f([self.rows[60]['day']])['stage'],'signal')
  self.assertEqual(self.f([self.rows[20]['day']])['stage'],'reference_inside60')
 def test_previous_session_missing(self):self.assertEqual(signal_at(self.rows,self.anchor,'2020-02-27')['stage'],'missing_previous_session')
 def test_invalid_history(self):
  self.rows[30]['open']=0;self.assertIn(self.f()['stage'],['under60','invalid_inside60'])
 def test_high_unconfirmed(self):
  self.rows[57]['high']=200;self.assertEqual(self.f()['high'],130)
 def test_duplicate_wave(self):
  self.rows[60].update(close=111,high=112,low=105)
  days=[self.anchor,self.rows[60]['day']];c={'days':days,'dates':{d:{'rows':[{'symbol':'A'}]} for d in days}}
  evaluations,signals=scan(c,{'A':self.rows},{})
  self.assertEqual(len(signals),1);self.assertEqual(evaluations[1]['stage'],'duplicate_wave');self.assertEqual(signals[0]['status'],'period_end')

if __name__=='__main__':unittest.main()
