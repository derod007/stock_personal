import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOD = ROOT / "docs" / "research" / "krx_eval_input_overlap.py"


def load():
    spec = importlib.util.spec_from_file_location("krx_eval_input_overlap", MOD)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class KrxEvalInputOverlapTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load()
        cls.facts = cls.m.read_engine_ranges(ROOT)

    def test_engine_windows_are_not_all_240(self):
        f = self.facts
        self.assertEqual(f["chart_min_completed"], 40)
        self.assertEqual(f["quality_min_completed"], 60)
        self.assertEqual(f["quality_recent_invalid_days"], 90)
        self.assertEqual(f["pullback_stop_bars"], 10)
        self.assertEqual(f["pullback_target_bars"], 20)
        self.assertEqual(f["horizontal_lookback"], 120)
        self.assertEqual(f["spike_peak_lookback"], 8)
        self.assertEqual(f["feature_sma"], [5, 10, 20, 60])
        self.assertFalse(f["feature_has_ma240"])
        self.assertFalse(f["chart_uses_envelope_ma240"])
        self.assertFalse(f["replay_truncates_to_240"])
        self.assertTrue(f["replay_past_is_through_session"])
        self.assertTrue(f["retest_overhead_high_uses_all_prior"])
        self.assertTrue(f["recovery_scans_full_series"])
        self.assertEqual(f["envelope_required_bars"], 240)
        self.assertFalse(f["envelope_in_chart_plan"])
        self.assertFalse(f["quality_checks_price_jump"])
        ids = {w["id"] for w in f["component_windows"]}
        self.assertIn("envelope_ma240", ids)
        env = next(w for w in f["component_windows"] if w["id"] == "envelope_ma240")
        self.assertFalse(env["in_new_entry"])

    def test_ohl_zero_dropped_from_completed_but_quality_sees_past(self):
        self.assertFalse(self.m.engine_completed_ohlc(0, 0, 0, 10000))
        self.assertTrue(self.m.engine_completed_ohlc(10, 12, 9, 11))
        self.assertFalse(self.m.engine_completed_ohlc(10, 9, 12, 11))

    def test_prep_break_overlaps_later_top100_not_same_day(self):
        rec = {"symbol": "AAA.KS", "from": "2024-06-01", "to": "2024-06-02", "to_in_eval_top100": False}
        days = ["2024-10-08", "2024-10-10", "2024-10-11"]
        top = {"2024-10-08": set(), "2024-10-10": {"AAA.KS"}, "2024-10-11": {"ZZZ.KS"}}
        out = self.m.classify_break(rec, top, days)
        self.assertEqual(out["bucket"], "평가 입력과 겹침")
        self.assertEqual(out["overlap_sessions"], 1)
        self.assertEqual(out["overlap_first"], "2024-10-10")
        self.assertTrue(out["same_day_top100_not_used_as_no_impact"])
        self.assertFalse(out["to_in_eval_top100_same_day"])

    def test_future_break_does_not_block_past_session(self):
        rec = {"symbol": "AAA.KS", "from": "2024-10-11", "to": "2024-10-14", "to_in_eval_top100": True}
        days = ["2024-10-08", "2024-10-10", "2024-10-14"]
        top = {
            "2024-10-08": {"AAA.KS"},
            "2024-10-10": {"AAA.KS"},
            "2024-10-14": {"AAA.KS"},
        }
        out = self.m.classify_break(rec, top, days)
        self.assertEqual(out["bucket"], "평가 입력과 겹침")
        self.assertEqual(out["overlap_sessions"], 1)
        self.assertEqual(out["overlap_first"], "2024-10-14")
        self.assertNotIn("2024-10-08", [out["overlap_first"]])

    def test_only_earlier_top100_is_no_overlap(self):
        rec = {"symbol": "AAA.KS", "from": "2025-01-02", "to": "2025-01-03"}
        days = ["2024-10-08", "2024-10-10"]
        top = {"2024-10-08": {"AAA.KS"}, "2024-10-10": {"AAA.KS"}}
        out = self.m.classify_break(rec, top, days)
        self.assertEqual(out["bucket"], "겹치지 않음")
        self.assertEqual(out["overlap_sessions"], 0)

    def test_missing_top100_file_is_unknown(self):
        rec = {"symbol": "AAA.KS", "from": "2024-06-01", "to": "2024-06-02"}
        out = self.m.classify_break(rec, {"2024-10-08": {"AAA.KS"}}, ["2024-10-08", "2024-10-10"])
        self.assertEqual(out["bucket"], "판단 불가")

    def test_list_absence_is_not_corporate_action_clearance(self):
        self.assertTrue(self.m.cannot_conclude_no_corporate_action(False))
        self.assertTrue(self.m.cannot_conclude_no_corporate_action(True))

    def test_quality_90_day_window_on_events(self):
        top = {"2024-10-08": {"AAA.KS"}, "2025-02-03": {"AAA.KS"}}
        days = ["2024-10-08", "2025-02-03"]
        out = self.m.summarize_events(
            "ohl_zero",
            {"AAA.KS": ["2024-09-01"]},
            top,
            days,
            None,
        )
        self.assertEqual(out["events_overlapping"], 1)
        row = out["rows"][0]
        self.assertEqual(row["overlap_sessions"], 2)
        self.assertEqual(row["quality_90_sessions"], 1)
        self.assertEqual(row["judgment_day_sessions"], 0)
        self.assertEqual(out["session_symbol_pairs"], 2)
        self.assertEqual(out["unique_symbol_sessions"], 2)
        self.assertEqual(out["quality_90_pairs"], 1)
        self.assertEqual(out["unique_quality_90_symbol_sessions"], 1)

    def test_two_causes_same_symbol_session_dedupes_unique_count(self):
        top = {"2024-10-08": {"AAA.KS"}, "2024-10-10": {"AAA.KS"}}
        days = ["2024-10-08", "2024-10-10"]
        out = self.m.summarize_events(
            "ohl_zero",
            {"AAA.KS": ["2024-09-01", "2024-09-10"]},
            top,
            days,
            None,
        )
        self.assertEqual(out["session_symbol_pairs"], 4)
        self.assertEqual(out["unique_symbol_sessions"], 2)
        self.assertEqual(out["quality_90_pairs"], 4)
        self.assertEqual(out["unique_quality_90_symbol_sessions"], 2)
        self.assertLessEqual(out["unique_symbol_sessions"], self.m.EVAL_SESSION_SYMBOL_CAP)
        self.assertLessEqual(out["unique_symbol_sessions"], out["session_symbol_pairs"])
        self.assertEqual(out["count_units"]["session_symbol_pairs"], self.m.COUNT_UNIT_LINKS)
        self.assertEqual(out["count_units"]["unique_symbol_sessions"], self.m.COUNT_UNIT_UNIQUE)

    def test_unique_symbol_sessions_cap(self):
        self.assertEqual(self.m.EVAL_SESSION_SYMBOL_CAP, 24300)
        self.assertEqual(self.m.unique_within_cap(24300), 24300)
        self.assertEqual(self.m.unique_within_cap(0), 0)
        with self.assertRaises(ValueError):
            self.m.unique_within_cap(24301)

    def test_two_breaks_same_symbol_session_dedupes_unique_count(self):
        recs = [
            {"symbol": "AAA.KS", "from": "2024-06-01", "to": "2024-06-02"},
            {"symbol": "AAA.KS", "from": "2024-07-01", "to": "2024-07-02"},
        ]
        days = ["2024-10-08", "2024-10-10"]
        top = {"2024-10-08": {"AAA.KS"}, "2024-10-10": {"AAA.KS"}}
        classified = [self.m.classify_break(r, top, days) for r in recs]
        links, unique, sessions = self.m.overlap_pair_counts(classified, days, top)
        self.assertEqual(links, 4)
        self.assertEqual(unique, 2)
        self.assertEqual(len(sessions), 2)
        self.assertLessEqual(unique, 24300)

    def test_missing_bars_are_not_reported_as_confirmed_counts(self):
        facts = self.m.read_engine_ranges(ROOT)
        notes = self.m.adapter_notes(None, facts)
        ids = {n["id"]: n["status"] for n in notes}
        self.assertEqual(ids["bars_jsonl"], "막힘")
        self.assertEqual(ids["chart_plan_not_run"], "이번 범위 밖")
        self.assertIn("보유·대기 주문", self.m.SCOPE)


class KrxEvalInputOverlapCliTest(unittest.TestCase):
    def test_missing_bars_dir_does_not_fabricate_ohl_zero_totals(self):
        m = load()
        with tempfile.TemporaryDirectory() as tmp:
            docs = Path(tmp) / "docs"
            import sys
            old = sys.argv
            sys.argv = [
                "krx_eval_input_overlap.py",
                "--docs-out", str(docs),
                "--bars", str(Path(tmp) / "missing-bars"),
                "--root", str(ROOT),
            ]
            try:
                self.assertEqual(m.main(), 0)
            finally:
                sys.argv = old
            summary = m.load_json(docs / "summary.json")
            self.assertIsNone(summary["ohl_zero"]["events_overlapping"])
            self.assertIn("blocked", summary["ohl_zero"])
            self.assertEqual(summary["breaks"]["n"], 96)
            self.assertTrue(summary["chart_plan_not_executed"])
            self.assertEqual(summary["price_basis"], m.PRICE_BASIS)
            self.assertEqual(sum(summary["breaks"]["counts"].values()), 96)
            self.assertEqual(summary["eval_session_symbol_cap"], 24300)
            unique = summary["breaks"]["overlap_unique_symbol_sessions"]
            links = summary["breaks"]["overlap_pairs"]
            self.assertLessEqual(unique, 24300)
            self.assertLessEqual(unique, links)
            self.assertGreater(unique, 0)
            self.assertEqual(
                summary["count_units"]["overlap_pairs"],
                m.COUNT_UNIT_LINKS,
            )
            self.assertEqual(
                summary["breaks"]["count_units"]["overlap_unique_symbol_sessions"],
                m.COUNT_UNIT_UNIQUE,
            )


if __name__ == "__main__":
    unittest.main()
