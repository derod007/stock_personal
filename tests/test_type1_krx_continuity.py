import importlib.util,sys,unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'docs/research'))
from type1_krx_continuity import affected
class WindowTests(unittest.TestCase):
 def test_future_not_used(self):
  self.assertFalse(affected(['2023-01-02','2023-01-03'],['2023-01-04']))
 def test_first_bar_has_no_internal_previous(self):
  self.assertFalse(affected(['2023-01-02','2023-01-03'],['2023-01-02']))
 def test_anchor_included(self):
  self.assertTrue(affected(['2023-01-02','2023-01-03'],['2023-01-03']))
if __name__=='__main__':unittest.main()
