"""Tamper checks for the research fill audit. Does not need the local datasets."""
from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "fill_audit", ROOT / "docs/research/account-replay-fill-audit.py"
)
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)


class FillAuditTamper(unittest.TestCase):
    def test_time_exit_fixture_matches_model(self):
        order, bars, last = audit.fixture_time_exit()
        row = audit.run_check(order, bars, last)
        self.assertEqual(row["checks"]["model_match"]["verdict"], "pass")
        self.assertEqual(row["independent"]["reason"], "time")
        self.assertEqual(row["independent"]["exit_date"], order["exit"]["date"])

    def test_rewritten_exit_price_fails(self):
        order, bars, last = audit.fixture_time_exit()
        caught = audit.tamper_caught(order, bars, last)
        self.assertEqual(caught["price"], "error")

    def test_rewritten_exit_date_fails(self):
        order, bars, last = audit.fixture_time_exit()
        caught = audit.tamper_caught(order, bars, last)
        self.assertEqual(caught["date"], "error")

    def test_rewritten_exit_reason_fails(self):
        order, bars, last = audit.fixture_time_exit()
        caught = audit.tamper_caught(order, bars, last)
        self.assertEqual(caught["reason"], "error")

    def test_rewritten_price_and_pnl_together_still_fails(self):
        order, bars, last = audit.fixture_time_exit()
        caught = audit.tamper_caught(order, bars, last)
        self.assertEqual(caught["price_and_pnl"], "error")

    def test_model_exit_below_low_is_assumption_not_model_error(self):
        order, bars, last = audit.fixture_stop_below_low()
        row = audit.run_check(order, bars, last)
        self.assertEqual(row["checks"]["model_match"]["verdict"], "pass")
        self.assertEqual(row["checks"]["exit_ohlc"]["verdict"], "assumption_dependent")
        self.assertTrue(row["checks"]["exit_ohlc"]["model_also_outside"])
        self.assertEqual(row["checks"]["exit_ohlc"]["stored_side"], "below_low")

    def test_ttl_cancel_uses_third_session_not_fourth(self):
        order, bars, last = audit.fixture_unfilled()
        row = audit.run_check(order, bars, last)
        self.assertEqual(row["checks"]["model_match"]["verdict"], "pass")
        self.assertEqual(row["independent"]["cancel_date"], order["cancelled"]["date"])
        self.assertEqual(row["independent"]["reason"], "unfilled")

    def test_rewritten_cancel_date_fails(self):
        order, bars, last = audit.fixture_unfilled()
        caught = audit.tamper_cancel_caught(order, bars, last)
        self.assertEqual(caught["date"], "error")

    def test_rewritten_cancel_reason_fails(self):
        order, bars, last = audit.fixture_unfilled()
        caught = audit.tamper_cancel_caught(order, bars, last)
        self.assertEqual(caught["reason"], "error")


if __name__ == "__main__":
    unittest.main()
