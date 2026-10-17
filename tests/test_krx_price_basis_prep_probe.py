import importlib.util
import unittest
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOD_PATH = ROOT / "docs" / "research" / "krx_price_basis_prep_probe.py"


def load_mod():
    spec = importlib.util.spec_from_file_location("krx_price_basis_prep_probe", MOD_PATH)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class KrxPriceBasisPrepProbeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load_mod()

    def test_request_cap_is_four(self):
        self.assertEqual(self.m.MAX_REQUESTS, 4)
        self.assertEqual(len(self.m.PROBE_DAYS), 2)

    def test_prep_day_estimate_excludes_weekends_and_listed_holidays(self):
        rec = self.m.weekdays_minus_listed_holidays(date(2023, 10, 10), date(2024, 10, 7))
        self.assertGreater(rec["weekdays_minus_listed_holidays"], 230)
        self.assertLess(rec["weekdays_minus_listed_holidays"], 250)
        self.assertIn("2024-09-17", rec["listed_holidays_skipped"])
        self.assertFalse(rec["holiday_list_complete"])
        self.assertEqual(rec["first"], "2023-10-10")
        self.assertEqual(rec["last"], "2024-10-07")

    def test_public_row_drops_payload(self):
        row = self.m.public_probe_row({
            "date": "20231010",
            "market": "kospi_equity",
            "n": 12,
            "block": [{"ISU_CD": "005930", "TDD_CLSPRC": "1"}],
            "head": "secret",
            "ok": True,
            "kind": "ok",
        })
        self.assertNotIn("block", row)
        self.assertEqual(row["n"], 12)


if __name__ == "__main__":
    unittest.main()
