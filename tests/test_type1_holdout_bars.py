import importlib.util,json,unittest
from pathlib import Path
p=Path(__file__).resolve().parents[1]/'docs/research/type1_holdout_bars.py'
s=importlib.util.spec_from_file_location('hb',p);m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class ParseTests(unittest.TestCase):
 def fixture(self):
  return {'chart':{'error':None,'result':[{'meta':{'symbol':'005930.KS','currency':'KRW','instrumentType':'EQUITY'},'timestamp':[1688342400], 'indicators':{'quote':[dict(open=[100],high=[110],low=[90],close=[105],volume=[10])],'adjclose':[{'adjclose':[50]}]}}]}}
 def test_quote_not_adjclose(self):
  rows,_,issues=m.parse(json.dumps(self.fixture()),'005930.KS');self.assertEqual(rows[0]['close'],105);self.assertEqual(issues,[])
 def test_invalid_row_retained(self):
  d=self.fixture();d['chart']['result'][0]['indicators']['quote'][0]['close']=[80]
  rows,_,issues=m.parse(json.dumps(d),'005930.KS');self.assertEqual(rows[0]['close'],80);self.assertEqual(issues[0]['reason'],'invalid_ohlcv')
 def test_null_retained(self):
  d=self.fixture();d['chart']['result'][0]['indicators']['quote'][0]['close']=[None]
  rows,_,issues=m.parse(json.dumps(d),'005930.KS');self.assertIsNone(rows[0]['close']);self.assertEqual(issues[0]['reason'],'null_quote')
 def test_wrong_symbol_rejected(self):
  with self.assertRaisesRegex(ValueError,'symbol_mismatch'):m.parse(json.dumps(self.fixture()),'000660.KS')
 def test_future_not_included(self):
  d=self.fixture();d['chart']['result'][0]['timestamp']=[1720000000]
  with self.assertRaisesRegex(ValueError,'no_requested_period'):m.parse(json.dumps(d),'005930.KS')
if __name__=='__main__':unittest.main()
