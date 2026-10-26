import sys,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'docs/research'))
from type1_independent_summary import stats
class StatsTests(unittest.TestCase):
 def test_unknown_not_zero_and_symbol_weighting(self):
  r=[{'signal':{'symbol':s},'result':{'status':'closed','filled':True,'first_exit':'time','net_return_pct':v}} for s,v in [('A',10),('A',10),('B',-10)]]
  r.append({'signal':{'symbol':'C'},'result':{'status':'unresolved','known_filled':True,'net_return_pct':None}})
  x=stats(r);self.assertEqual(x['signals'],4);self.assertEqual(x['closed'],3);self.assertEqual(x['known_fills'],4);self.assertAlmostEqual(x['mean_pct'],10/3);self.assertEqual(x['symbol_equal_mean_pct'],0);self.assertEqual(x['remove_top']['1']['mean_pct'],-10)
 def test_no_closed(self):
  self.assertIsNone(stats([])['mean_pct']);self.assertIsNone(stats([])['win_pct'])
if __name__=='__main__':unittest.main()
