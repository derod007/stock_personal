import importlib.util, unittest
spec=importlib.util.spec_from_file_location("diagnosis","bin/diagnose_research.py")
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class DiagnosticsTest(unittest.TestCase):
    def test_intraday_exclusion(self):
        t=dict(status="closed",net_return_pct=-2,entry_fill=100,entry_at=1,exit_at=3,first_exit="stop",bars=3)
        bars=[dict(available_at=1,close=110),dict(available_at=2,close=101),dict(available_at=3,close=120)]
        self.assertEqual(m.classify(t,bars)["category"],"early_stop_within_3_bars")
        bars[1]["close"]=103
        self.assertEqual(m.classify(t,bars)["category"],"gave_back_2pct_close_gain")
    def test_time_and_nonloss(self):
        t=dict(status="closed",net_return_pct=-1,entry_fill=100,entry_at=1,exit_at=21,first_exit="time",bars=20)
        self.assertEqual(m.classify(t,[])["category"],"time_exit_loss")
        t["net_return_pct"]=1
        self.assertIsNone(m.classify(t,[]))
if __name__=="__main__": unittest.main()
