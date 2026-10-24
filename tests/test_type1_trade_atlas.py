import sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"docs/research"))
import unittest
from type1_trade_atlas import features,path_metrics

def bar(day,high=101,low=99,close=100):return dict(day=day,open=100,high=high,low=low,close=close,volume=1000)
class AtlasTests(unittest.TestCase):
 def test_exit_day_high_excluded(self):
  t=dict(status='closed',entry=100,exit_day='02',exit_reason='stop');p=path_metrics(t,[bar('01'),bar('02',120,90)],set());self.assertAlmostEqual(p['pre_exit_mfe'],1);self.assertFalse(p['later_hit15'])
 def test_later_recovery(self):
  t=dict(status='closed',entry=100,exit_day='01',exit_reason='stop');p=path_metrics(t,[bar('01',116,90),bar('02',116)],set());self.assertTrue(p['later_hit15']);self.assertEqual(p['pre_exit_mfe'],0)
 def test_post_gap_unknown(self):
  t=dict(status='closed',entry=100,exit_day='01',exit_reason='stop');p=path_metrics(t,[bar('01'),{'day':'02'}],set());self.assertIsNone(p['later_hit15'])
 def test_reference_unknown(self):
  t=dict(status='closed',entry=100,exit_day='01',exit_reason='stop');self.assertIsNone(path_metrics(t,[bar('01'),bar('02',120)],{'02'})['later_hit15'])
 def test_features_use_sixty(self):
  p=[bar(str(i)) for i in range(60)];f=features(p,101);self.assertEqual(f['return20'],0);self.assertEqual(f['volratio'],1);self.assertAlmostEqual(f['gap'],1);self.assertEqual(f['atr14'],2);self.assertIsNone(features(p[:-1],101))
 def test_unresolved_not_zero(self):self.assertIsNone(path_metrics({'status':'unresolved'},[],set()))
if __name__=='__main__':unittest.main()
