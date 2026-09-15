import importlib.util, unittest
s=importlib.util.spec_from_file_location("x","bin/crosscheck_prices.py")
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class CrosscheckTest(unittest.TestCase):
    def test_missing_and_invalid(self):
        self.assertFalse(m.valid(None))
        self.assertFalse(m.valid(dict(open=100,high=101,low=99,close=98)))
        self.assertFalse(m.same(None,{}))
    def test_full_ohlc_match(self):
        a=dict(open=100,high=110,low=90,close=105)
        self.assertTrue(m.valid(a));self.assertTrue(m.same(a,dict(a)))
        self.assertFalse(m.same(a,dict(a,open=99)))
if __name__=="__main__":unittest.main()
