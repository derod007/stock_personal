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
            self.assertTrue(scored[0]["first_entry_unverified"])
            self.assertFalse(scored[1]["first_entry_unverified"])
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
        self.assertTrue(free["hit15"])
        self.assertEqual(free["bars_to_15"], 2)
        self.assertLess(free["max_dd_before_15_pct"], -5)
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


if __name__ == "__main__":
    unittest.main()
