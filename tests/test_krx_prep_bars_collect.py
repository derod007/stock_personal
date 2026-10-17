import importlib.util
import json
import tempfile
import unittest
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOD_PATH = ROOT / "docs" / "research" / "krx_prep_bars_collect.py"
CAL = ROOT / "docs" / "krx-prep-bars-before-20241008" / "trading-calendar.json"
QUAL = ROOT / "docs" / "research" / "krx_prep_bars_quality.py"


def load_mod(path: Path, name: str):
    spec = importlib.util.spec_from_file_location(name, path)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class KrxPrepBarsCollectTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load_mod(MOD_PATH, "krx_prep_bars_collect")
        cls.q = load_mod(QUAL, "krx_prep_bars_quality")
        cls.cal = json.loads(CAL.read_text(encoding="utf-8"))

    def test_last_240_before_eval_includes_year_end_and_excludes_eval_start(self):
        days = self.m.last_n_trading_days(self.cal, date(2024, 10, 8), 240)
        self.assertEqual(len(days), 240)
        self.assertEqual(len(set(days)), 240)
        self.assertNotIn("20241008", days)
        self.assertEqual(days[-1], "20241007")
        self.assertNotIn("20231229", days)
        self.assertNotIn("20240501", days)
        self.assertNotIn("20240410", days)
        self.assertNotIn("20241001", days)
        self.assertEqual(days[0], "20231013")
        self.assertTrue(days[0] > "20231009")

    def test_empty_is_not_holiday_flag(self):
        self.assertTrue(self.cal["empty_response_is_not_holiday"])
        self.assertEqual(self.m.PRICE_BASIS, "KRX TDD 원본, 수정 여부 미확정")

    def test_probe_hash_mismatch_is_issue(self):
        with tempfile.TemporaryDirectory() as td:
            src = Path(td) / "probe"
            src.mkdir()
            (src / "20241007_kospi_equity.json").write_text("{}", encoding="utf-8")
            dest = Path(td) / "dest"
            old = list(self.m.PROBE_CANDIDATES)
            self.m.PROBE_CANDIDATES[:] = [src]
            try:
                rec = self.m.reuse_pr89(dest, ["20241007"])
            finally:
                self.m.PROBE_CANDIDATES[:] = old
            self.assertTrue(rec["hash_mismatch"] or rec["issues"])
            self.assertFalse((dest / "20241007_kospi_equity.json").is_file())

    def test_probe_outside_calendar_is_hash_checked_not_copied(self):
        with tempfile.TemporaryDirectory() as td:
            src = Path(td) / "probe"
            src.mkdir()
            dest = Path(td) / "dest"
            (src / "20231010_kospi_equity.json").write_bytes(b"probe-kospi")
            old = list(self.m.PROBE_CANDIDATES)
            old_sha = dict(self.m.PROBE_SHA)
            self.m.PROBE_CANDIDATES[:] = [src]
            self.m.PROBE_SHA.clear()
            self.m.PROBE_SHA["20231010_kospi_equity.json"] = self.m.sha256_file(src / "20231010_kospi_equity.json")
            try:
                rec = self.m.reuse_pr89(dest, ["20241007"])
            finally:
                self.m.PROBE_CANDIDATES[:] = old
                self.m.PROBE_SHA.clear()
                self.m.PROBE_SHA.update(old_sha)
            self.assertTrue(rec["skipped_not_in_calendar"])
            self.assertTrue(rec["skipped_not_in_calendar"][0]["hash_ok"])
            self.assertFalse((dest / "20231010_kospi_equity.json").is_file())

    def test_ohlc_issues_do_not_fill(self):
        self.assertEqual(self.q.ohlc_issues({"open": 10, "high": 11, "low": 9, "close": 10}), [])
        self.assertIn("high_lt_low", self.q.ohlc_issues({"open": 10, "high": 8, "low": 9, "close": 10}))
        self.assertIn("ohlc_null", self.q.ohlc_issues({"open": None, "high": 1, "low": 1, "close": 1}))


if __name__ == "__main__":
    unittest.main()
