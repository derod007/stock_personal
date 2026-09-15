import importlib.util, unittest
spec=importlib.util.spec_from_file_location("audit","bin/audit_stops.py")
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class StopAuditTest(unittest.TestCase):
    def test_atr_uses_past_only(self):
        bars=[dict(open=100,high=101,low=99,close=100) for _ in range(16)]
        self.assertEqual(m.atr14(bars,14),2)
        bars[15]["high"]=10000
        self.assertEqual(m.atr14(bars,14),2)
        self.assertIsNone(m.atr14(bars,13))
    def test_partition_censoring(self):
        bars=[dict(close=110,low=90) for _ in range(30)]
        self.assertFalse(m.post_stop(bars,8,10,100,95,5)["complete"])
        self.assertTrue(m.post_stop(bars,8,14,100,95,5)["complete"])
    def test_exit_day_excluded(self):
        bars=[dict(close=200,low=90)]+[dict(close=98,low=94) for _ in range(5)]
        p=m.post_stop(bars,0,6,100,95,5)
        self.assertFalse(p["recovered_entry_on_close"])
        self.assertAlmostEqual(p["terminal_return_from_exit_pct"],(98/95-1)*100)
    def test_recovery_then_fall(self):
        bars=[dict(close=95,low=90),dict(close=105,low=94),dict(close=97,low=95)]
        p=m.post_stop(bars,0,3,100,95,2)
        self.assertTrue(p["recovered_entry_on_close"])
        self.assertFalse(p["terminal_above_entry"])
    def test_same_day_not_automatically_ambiguous(self):
        t=dict(status="closed",entry_at=1,exit_at=1,ambiguous_bar=False)
        self.assertEqual(m.ambiguity(t,[dict(open=100,low=89,high=110)],{1:0},90,120,False),[])
        flags=m.ambiguity(t,[dict(open=100,low=89,high=121)],{1:0},90,120,False)
        self.assertIn("stop_target_intraday_order_unknown",flags)
        self.assertEqual(m.ambiguity(t,[dict(open=121,low=89,high=122)],{1:0},90,120,False),[])
    def test_buckets_and_empty(self):
        self.assertEqual(m.width_bucket(0.49),"under_0.5_atr")
        self.assertEqual(m.width_bucket(0.5),"0.5_to_1_atr")
        self.assertEqual(m.width_bucket(1),"1_to_2_atr")
        self.assertEqual(m.summarize([])["mean_net_pct"],None)
if __name__=="__main__": unittest.main()
