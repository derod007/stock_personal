import importlib.util
import unittest
spec=importlib.util.spec_from_file_location('audit','docs/research/type1_stop_recovery.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class TestPaths(unittest.TestCase):
    def base(self):
        ds=['2023-07-03','2023-07-04','2023-07-05','2023-07-06']
        rows=[dict(day=d,open=100,high=110,low=94,close=100,volume=100,is_complete=True) for d in ds]
        from datetime import datetime,timezone
        ts=int(datetime(2023,7,4,6,30,tzinfo=timezone.utc).timestamp())
        rec={'signal':dict(symbol='S',anchor=ds[0],end=ds[-1],target=115), 'result':dict(status='closed',entry_at=ts,exit_at=ts,entry_fill=100,first_exit='stop',net_return_pct=-5)}
        return ds,rows,rec
    def test_later_recovery_and_day_order(self):
        ds,rows,r=self.base();rows[2].update(high=116,low=80)
        o=m.audit(r,rows,ds,[])
        self.assertEqual(o['label'],'stop_later_target');self.assertEqual(o['sessions_after_stop'],1)
        self.assertAlmostEqual(o['pre_hit_prior_day_low_pct'],-6);self.assertAlmostEqual(o['hit_day_low_pct'],-20)
    def test_same_day_separate(self):
        ds,rows,r=self.base();rows[1]['high']=116;rows[2]['high']=116
        o=m.audit(r,rows,ds,[]);self.assertEqual(o['label'],'stop_same_day_ambiguous');self.assertTrue(o['later_target_touch'])
    def test_future_event_preserves_realized_loss(self):
        ds,rows,r=self.base();o=m.audit(r,rows,ds,[ds[2]])
        self.assertEqual(o['label'],'future_unknown');self.assertEqual(o['trade']['net_return_pct'],-5)
    def test_missing_or_invalid_unknown(self):
        ds,rows,r=self.base();self.assertEqual(m.audit(r,rows[:-1],ds,[])['label'],'future_unknown')
        rows[-1]['volume']=0;self.assertEqual(m.audit(r,rows,ds,[])['label'],'future_unknown')
    def test_no_target_can_finish_positive(self):
        ds,rows,r=self.base();rows[-1]['close']=105
        o=m.audit(r,rows,ds,[]);self.assertEqual(o['label'],'stop_no_later_target');self.assertGreater(o['end_return_from_fill_pct'],0)
    def test_auc(self):
        self.assertEqual(m.auc([2],[1]),1);self.assertEqual(m.auc([1],[1]),0.5)

if __name__=='__main__':unittest.main()
