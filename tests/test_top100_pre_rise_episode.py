import importlib.util
import json
import tempfile
import unittest
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "top100_pre_rise_episode", ROOT / "docs/research/top100_pre_rise_episode.py"
)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


def days(n, start=date(2024, 1, 2)):
    out = []
    d = start
    while len(out) < n:
        if d.weekday() < 5:
            out.append(d.isoformat())
        d += timedelta(days=1)
    return out


def write_symbol(bars_dir: Path, symbol: str, session_days: list[str], close_at=None, extra=None):
    rows = []
    for i, day in enumerate(session_days):
        px = 100 + i
        if close_at and day in close_at:
            px = close_at[day]
        rows.append(
            {
                "time_kst": f"{day} 09:00:00",
                "open": px,
                "high": px + 2,
                "low": px - 2,
                "close": px,
                "volume": 1000 + i,
                "is_complete": True,
                "synthetic": False,
            }
        )
    for b in extra or []:
        rows.append(b)
    (bars_dir / f"{symbol}.json").write_text(
        json.dumps({"price_basis": m.PRICE_BASIS, "rows": rows, "quality": {"price_jumps": [], "corrections": {"count": 0, "items": []}}}),
        encoding="utf-8",
    )


def period_spec(root: Path, session, dates, log_text=""):
    cand = root / "candidates.json"
    cand.write_text(json.dumps({"days": session, "dates": dates}), encoding="utf-8")
    log = root / "daily-log.jsonl"
    log.write_text(log_text, encoding="utf-8")
    return {
        "candidates": cand,
        "dataset": root,
        "signals": log,
        "signal_kind": "daily_log_jsonl",
        "label": "fixture",
    }


class EpisodeTests(unittest.TestCase):
    def test_nonoverlap_and_left_censor(self):
        session = days(90)
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            bars = root / "bars"
            bars.mkdir()
            dates = {d: {"rows": [{"symbol": "AAA.KS"}]} for d in session}
            write_symbol(bars, "AAA.KS", session)
            eps = m.build_episodes(m.load_period("recent", period_spec(root, session, dates)))
            scored = [e for e in eps if e.get("immediate")]
            anchors = [e["anchor"] for e in scored]
            self.assertEqual(anchors, [session[0], session[21], session[42], session[63]])
            self.assertTrue(scored[0]["left_censored"])
            self.assertFalse(scored[1]["left_censored"])
            for e in scored:
                self.assertEqual(e["new_entry_status"], "unknown")
            self.assertEqual(scored[0]["nonoverlap_seq"], 1)
            self.assertEqual(scored[1]["nonoverlap_seq"], 2)
            self.assertTrue(any(e.get("skip") == "incomplete_future_window" for e in eps))
            self.assertEqual(sum(e.get("skip") == "incomplete_future_window" for e in eps), 1)

    def test_observe_after_leaving_top100(self):
        session = days(50)
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            bars = root / "bars"
            bars.mkdir()
            dates = {}
            for i, d in enumerate(session):
                dates[d] = {"rows": [{"symbol": "BBB.KS"}] if i == 21 else [{"symbol": "ZZZ.KS"}]}
            write_symbol(bars, "BBB.KS", session)
            write_symbol(bars, "ZZZ.KS", session)
            eps = m.build_episodes(m.load_period("recent", period_spec(root, session, dates)))
            bbb = next(e for e in eps if e["symbol"] == "BBB.KS" and e.get("immediate"))
            self.assertTrue(bbb["left_top100"])
            self.assertEqual(bbb["days_in_top100"], 1)
            self.assertIsNotNone(bbb["immediate"])

    def test_feature_later_buys_next_open_not_future_peak(self):
        session = days(90)
        hist = days(60, start=date(2023, 10, 2))
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            bars = root / "bars"
            bars.mkdir()
            dates = {d: {"rows": [{"symbol": "CCC.KS"}]} for d in session}
            # Falling history so MA20 < MA60 and lower lows at T; later days rise enough for higher_10bar_low.
            hist_px = {d: 200 - i for i, d in enumerate(hist)}
            sess_px = {}
            for i, d in enumerate(session):
                sess_px[d] = 140 + i
            write_symbol(bars, "CCC.KS", hist + session, close_at={**hist_px, **sess_px})
            period = m.load_period("recent", period_spec(root, session, dates))
            eps = [e for e in m.build_episodes(period) if e["symbol"] == "CCC.KS" and e.get("immediate")]
            self.assertGreater(len(eps), 0)
            e0 = eps[0]
            info = e0["features"]["higher_10bar_low"]
            self.assertIn(info["timing"], ("first_day", "later", "never"))
            if info["timing"] == "later":
                self.assertIsNotNone(info["trade"])
                self.assertGreaterEqual(info["trade"]["entry_offset"], 2)
            # Shape ignores post-T closes: last point is 1.0 and uses T close.
            self.assertAlmostEqual(e0["shape"]["path"]["-0"], 1.0)
            t_close = sess_px[session[0]]
            self.assertEqual(e0["shape"]["path"]["-0"], 1.0)
            future_days = session[21:]
            self.assertTrue(all(sess_px[d] > t_close for d in future_days))

    def test_unconstrained_dd_does_not_change_stop(self):
        window = [
            {"open": 100, "high": 101, "low": 90, "close": 91, "volume": 1},
            {"open": 91, "high": 120, "low": 90, "close": 119, "volume": 1},
        ] + [{"open": 119, "high": 120, "low": 118, "close": 119, "volume": 1} for _ in range(18)]
        stopped = m.simulate(window, 100)
        free = m.unconstrained(window, 100)
        self.assertEqual(stopped["exit_reason"], "stop")
        self.assertFalse(stopped["high_touch_15"])
        self.assertTrue(free["hit15"])
        self.assertEqual(free["bars_to_15"], 2)
        self.assertLess(free["max_dd_before_hit_day_pct"], -5)
        self.assertLess(free["max_dd_through_hit_day_pct"], free["max_dd_before_hit_day_pct"] + 0.0001)
        self.assertEqual(m.STOP, 0.95)
        self.assertEqual(m.TARGET, 1.15)

    def test_large_loss_gap_is_kept_as_evidence(self):
        window = [{"open": 70, "high": 71, "low": 69, "close": 70, "volume": 1} for _ in range(20)]
        sim = m.simulate(window, 100)
        self.assertLess(sim["tradable_net_pct"], m.LARGE_LOSS)
        ev = m.gap_evidence(window, 100, {"price_jumps": [], "corrections": {"items": []}}, "2024-01-02", "2024-02-01")
        self.assertEqual(ev["kind"], "open_gap_vs_prior_close")
        ev2 = m.gap_evidence(
            [{"open": 99, "high": 99, "low": 90, "close": 91, "volume": 1} for _ in range(20)],
            100,
            {"price_jumps": [], "corrections": {"items": []}},
            "2024-01-02",
            "2024-02-01",
        )
        self.assertEqual(ev2["kind"], "unknown")

    def test_shape_uses_only_bars_through_t(self):
        past = [{"open": 50 + i, "high": 51 + i, "low": 49 + i, "close": 50 + i, "volume": 10} for i in range(60)]
        future = [{"open": 500, "high": 600, "low": 500, "close": 600, "volume": 999} for _ in range(20)]
        a = m.shape60(past)
        leaked = m.shape60(past + future)
        self.assertAlmostEqual(a["path"]["-0"], 1.0)
        self.assertLess(a["close_change_60"], leaked["close_change_60"])
        self.assertEqual(m.shape60(past)["close_change_60"], a["close_change_60"])

    def test_stop_then_later_hit15_is_chart_rise(self):
        dummy_shape = {
            "path": {f"-{k}": 1.0 for k in m.KNOTS},
            "pullback_depth": 0.1,
            "vol_ratio_10_50": 1.0,
            "close_change_60": 0.0,
        }
        window = [
            {"open": 100, "high": 101, "low": 90, "close": 91, "volume": 1},
            {"open": 91, "high": 101, "low": 90, "close": 92, "volume": 1},
            {"open": 92, "high": 101, "low": 91, "close": 93, "volume": 1},
            {"open": 93, "high": 101, "low": 92, "close": 94, "volume": 1},
            {"open": 94, "high": 120, "low": 94, "close": 119, "volume": 1},
        ] + [{"open": 119, "high": 120, "low": 118, "close": 119, "volume": 1} for _ in range(15)]
        imm = m.simulate(window, 100)
        unc = m.unconstrained(window, 100)
        self.assertEqual(imm["exit_reason"], "stop")
        self.assertFalse(imm["high_touch_15"])
        self.assertTrue(unc["hit15"])
        self.assertEqual(unc["bars_to_15"], 5)
        e = {
            "immediate": imm,
            "unconstrained": unc,
            "shape": dummy_shape,
            "left_censored": False,
            "nonoverlap_seq": 1,
            "features": {},
        }
        out = m.summarize({"name": "x", "label": "x", "days": [], "dataset_name": "x", "candidates_sha256": "0", "signals_sha256": "0"}, [e])
        self.assertEqual(out["shape_rise"]["n"], 1)
        self.assertEqual(out["shape_nonrise"]["n"], 0)
        self.assertEqual(out["stopped_then_later_hit15"], 1)
        self.assertEqual(out["immediate"]["stop_n"], 1)

    def test_same_bar_stop_and_target_order_unknown(self):
        bar = {"open": 100, "high": 120, "low": 90, "close": 110, "volume": 1}
        window = [bar] + [{"open": 110, "high": 111, "low": 109, "close": 110, "volume": 1} for _ in range(19)]
        imm = m.simulate(window, 100)
        unc = m.unconstrained(window, 100)
        self.assertTrue(imm["same_bar_stop_and_target"])
        self.assertEqual(imm["intraday_order"], "unknown")
        self.assertTrue(imm["stop_first_is_model_assumption"])
        self.assertEqual(imm["exit_reason"], "stop")
        self.assertTrue(unc["same_bar_stop_and_target"])
        self.assertEqual(unc["intraday_order"], "unknown")
        self.assertIsNone(unc["max_dd_before_hit_day_pct"])
        self.assertIsNotNone(unc["max_dd_through_hit_day_pct"])

    def test_later_pair_clips_hold_at_t20_and_never_is_not_entry_filter(self):
        later = {
            "immediate": {"tradable_net_pct": 1.0, "high_touch_15": False, "tradable_win": True, "exit_reason": "time", "bars_held": 20, "same_bar_stop_and_target": False},
            "unconstrained": {"hit15": False, "bars_to_15": None, "max_dd_before_hit_day_pct": -1, "max_dd_through_hit_day_pct": None, "max_dd_window_pct": -1, "same_bar_stop_and_target": False, "intraday_order": "not_same_bar"},
            "features": {
                "close_near_20high": {
                    "timing": "later",
                    "trade": {
                        "tradable_net_pct": -0.5,
                        "high_touch_15": False,
                        "tradable_win": False,
                        "exit_reason": "time",
                        "bars_held": 5,
                        "entry_offset": 16,
                        "entry_delay_bars": 15,
                        "remaining_bars": 5,
                        "hold_clipped_at_t20": True,
                        "same_bar_stop_and_target": False,
                    },
                }
            },
        }
        never = {
            "immediate": {"tradable_net_pct": 2.0, "high_touch_15": True, "tradable_win": True, "exit_reason": "target", "bars_held": 3, "same_bar_stop_and_target": False},
            "unconstrained": {"hit15": True, "bars_to_15": 3, "max_dd_before_hit_day_pct": -1, "max_dd_through_hit_day_pct": -1, "max_dd_window_pct": -1, "same_bar_stop_and_target": False, "intraday_order": "not_same_bar"},
            "features": {"close_near_20high": {"timing": "never", "trade": None}},
        }
        paired = m.pair_later([later, never], "close_near_20high")
        self.assertEqual(paired["n"], 1)
        self.assertEqual(paired["mean_entry_delay_bars"], 15)
        self.assertEqual(paired["mean_remaining_bars"], 5)
        self.assertAlmostEqual(paired["mean_wait_minus_immediate_pct"], -1.5)
        out = m.summarize({"name": "x", "label": "x", "days": [], "dataset_name": "x", "candidates_sha256": "0", "signals_sha256": "0"}, [later, never])
        self.assertNotIn("never_immediate", out["features"]["close_near_20high"])
        self.assertEqual(out["features"]["close_near_20high"]["never_n"], 1)


if __name__ == "__main__":
    unittest.main()
