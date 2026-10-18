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

from type1_krx_continuity import entry_reasons,active_action
from datetime import date,timedelta
class PolicyTests(unittest.TestCase):
 def setUp(self):
  self.days=[(date(2023,1,1)+timedelta(days=i)).isoformat() for i in range(60)]
 def test_future_does_not_block(self):
  self.assertEqual(entry_reasons(self.days,self.days[-1],['2024-01-01'],['2024-01-01']),[])
 def test_known_event_blocks(self):
  self.assertIn('reference_inside60',entry_reasons(self.days,self.days[-1],[self.days[30]],[]))
 def test_invalid90_blocks(self):
  self.assertIn('invalid_recent90',entry_reasons(self.days,self.days[-1],[],[self.days[0]]))
 def test_eod_active_halt_only(self):
  self.assertEqual(active_action('2023-01-02',['2023-01-02'],True),'halt_unresolved_valuation')
  self.assertEqual(active_action('2023-01-01',['2023-01-02'],True),'continue')
  self.assertEqual(active_action('2023-01-02',['2023-01-02'],False),'continue')

if __name__=='__main__':unittest.main()
