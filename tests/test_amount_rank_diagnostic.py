import importlib.util
from pathlib import Path
import unittest
spec=importlib.util.spec_from_file_location('rankdiag',Path(__file__).resolve().parents[1]/'docs/research/amount_rank_diagnostic.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Tests(unittest.TestCase):
 def test_exact_window(self):
  p=[{'A':(100,120,90,110,1)} for _ in range(6)];p[0]['A']=(1,1,1,1,1);p[5]['A']=(100,130,95,130,1)
  v,reason=m.forward(p,0,'A',5,6);self.assertAlmostEqual(v[0],30);self.assertAlmostEqual(v[1],-10);self.assertIsNone(reason)
 def test_missing_no_shift(self):
  p=[{'A':(100,120,90,110,1)} for _ in range(6)];p[2]={}
  self.assertEqual(m.forward(p,0,'A',5,6),(None,'missing_session'))
 def test_boundary(self):self.assertEqual(m.forward([],0,'A',20,1),(None,'boundary'))
 def test_zero_volume(self):self.assertFalse(m.valid((100,100,100,100,0)))
 def test_locked_is_ambiguity(self):
  v,r=m.forward([{}, {'A':(100,100,100,100,1)}],0,'A',1,2);self.assertEqual(v,(0,0,0,True))
 def test_nonoverlap_before_outcomes(self):
  rs=[{'symbol':'A','index':i} for i in [0,20,21]];self.assertEqual(m.nonoverlap(rs),[True,False,True])
 def test_ranking_exclusions_and_ties(self):
  class Pilot:
   @staticmethod
   def exclusion_kind(n):return n=='excluded'
  rs=[dict(code=c,name=n,market='KOSPI',amount_won=a) for c,n,a in [('02','b',100),('01','a',100),('03','excluded',1000)]]
  self.assertEqual([r['code'] for r in m.rank_rows(rs,Pilot)],['01','02'])
 def test_equal_date(self):
  rows=[]
  for date,aa,bb,n in [('d1',10,0,9),('d2',0,10,1)]:
   rows.extend(dict(date=date,band='101-300',symbol='A',ret=aa,low=-1,high=10,reason='ok') for _ in range(n))
   rows.append(dict(date=date,band='1-100',symbol='B',ret=bb,low=-1,high=10,reason='ok'))
  s=m.compare(m.pd.DataFrame(rows));self.assertEqual(s['101-300']['return_diff']['mean'],0)
if __name__=='__main__':unittest.main()
