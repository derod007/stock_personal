import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOD = ROOT / "docs" / "research" / "krx_prep_ohlcv_recount.py"


def load():
    spec = importlib.util.spec_from_file_location("krx_prep_ohlcv_recount", MOD)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class KrxPrepOhlcvRecountTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load()

    def test_zero_ohl_close_positive_volume_zero(self):
        cls = self.m.classify_row({
            "open": 0, "high": 0, "low": 0, "close": 1054, "volume": 0, "amount_won": 0,
        })
        self.assertEqual(cls["kind"], "ohl_zero_close_positive_volume_zero")
        self.assertEqual(cls["volume"], "zero")
        self.assertTrue(self.m.is_abnormal(cls["kind"]))

    def test_volume_null_zero_negative_distinct(self):
        self.assertEqual(self.m.classify_volume(None), "null")
        self.assertEqual(self.m.classify_volume(0), "zero")
        self.assertEqual(self.m.classify_volume(-1), "negative")
        self.assertEqual(self.m.classify_volume(10), "positive")
        null_v = self.m.classify_row({"open": 10, "high": 11, "low": 9, "close": 10, "volume": None})
        self.assertEqual(null_v["kind"], "normal_ohlc_volume_null")
        self.assertTrue(null_v["normal_ohlc"])
        self.assertFalse(null_v["normal_ohlcv"])
        neg = self.m.classify_row({"open": 10, "high": 11, "low": 9, "close": 10, "volume": -3})
        self.assertEqual(neg["volume"], "negative")
        self.assertFalse(neg["normal_ohlcv"])
        ohl_null_vol = self.m.classify_row({"open": 0, "high": 0, "low": 0, "close": 10, "volume": None})
        self.assertEqual(ohl_null_vol["kind"], "ohl_zero_close_positive_volume_null")

    def test_normal_ohlc_vs_normal_ohlcv(self):
        full = self.m.classify_row({"open": 10, "high": 11, "low": 9, "close": 10, "volume": 1})
        self.assertEqual(full["kind"], "normal_ohlcv")
        self.assertTrue(full["normal_ohlc"])
        self.assertTrue(full["normal_ohlcv"])
        self.assertFalse(self.m.is_abnormal(full["kind"]))
        vol0 = self.m.classify_row({"open": 10, "high": 11, "low": 9, "close": 10, "volume": 0})
        self.assertEqual(vol0["kind"], "normal_ohlc_volume_zero")
        self.assertTrue(vol0["normal_ohlc"])
        self.assertFalse(vol0["normal_ohlcv"])
        zero_px = self.m.classify_row({"open": 0, "high": 0, "low": 0, "close": 0, "volume": 0})
        self.assertNotEqual(zero_px["ohlc"], "normal_ohlc")

    def test_split_scale_and_limit_rules(self):
        cal = ["2024-01-02", "2024-01-03", "2024-01-04"]
        split = self.m.scan_breaks(
            [("2024-01-02", 10000, "normal"), ("2024-01-03", 5000, "normal")],
            cal,
        )
        self.assertEqual(split[0]["rule"], "close_ratio_ge_1.9_or_le_1_over_1.9")
        self.assertEqual(split[0]["cause"], "미확정")
        self.assertFalse(split[0]["adjusted"])
        limit = self.m.scan_breaks(
            [("2024-01-02", 10000, "normal"), ("2024-01-03", 14000, "normal")],
            cal,
        )
        self.assertEqual(limit[0]["rule"], "adjacent_days_abs_return_gt_0.301")
        none = self.m.scan_breaks(
            [("2024-01-02", 10000, "normal"), ("2024-01-03", 11000, "normal")],
            cal,
        )
        self.assertEqual(none, [])

    def test_request_482_splits_auto_and_manual(self):
        n = self.m.request_482_note()
        self.assertEqual(n["total_http_requests"], 482)
        self.assertEqual(n["auto_retries"], 0)
        kinds = {p["id"]: p for p in n["parts"]}
        self.assertEqual(kinds["manual_rerequest_20241001"]["kind"], "manual_rerequest")
        self.assertEqual(kinds["manual_rerequest_20241001"]["requests"], 2)
        self.assertEqual(sum(p["requests"] for p in n["parts"]), 482)

    def test_price_basis(self):
        self.assertEqual(self.m.PRICE_BASIS, "KRX TDD 원본, 수정 여부 미확정")


if __name__ == "__main__":
    unittest.main()
