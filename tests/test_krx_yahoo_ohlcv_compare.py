import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOD_PATH = ROOT / "docs" / "research" / "krx_yahoo_ohlcv_compare.py"


def load_mod():
    spec = importlib.util.spec_from_file_location("krx_yahoo_ohlcv_compare", MOD_PATH)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class KrxYahooOhlcvCompareTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load_mod()

    def test_close_only_vs_ohl_also(self):
        krx = {"open": 100, "high": 110, "low": 90, "close": 105, "volume": 10}
        close_only = {"open": 100, "high": 110, "low": 90, "close": 99, "volume": 10}
        ohl_also = {"open": 101, "high": 110, "low": 90, "close": 105, "volume": 10}
        both = {"open": 101, "high": 111, "low": 89, "close": 99, "volume": 10}
        exact = {"open": 100, "high": 110, "low": 90, "close": 105, "volume": 10}
        vol_only = {"open": 100, "high": 110, "low": 90, "close": 105, "volume": 11}
        self.assertEqual(self.m.classify_pair(krx, exact), "exact_ohlcv_close")
        self.assertEqual(self.m.classify_pair(krx, vol_only), "volume_only_close")
        self.assertEqual(self.m.classify_pair(krx, close_only), "price_diff_close_only")
        self.assertEqual(self.m.classify_pair(krx, ohl_also), "price_diff_ohl_also")
        self.assertEqual(self.m.classify_pair(krx, both), "price_diff_ohl_also")

    def test_empty_day_has_split_keys(self):
        d = self.m.empty_day()
        self.assertIn("price_diff_close_only", d)
        self.assertIn("price_diff_ohl_also", d)
        self.assertEqual(d["price_diff_close"], 0)


if __name__ == "__main__":
    unittest.main()
