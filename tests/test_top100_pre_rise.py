import importlib.util
import json
import tempfile
import unittest
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "top100_pre_rise", ROOT / "docs/research/top100_pre_rise.py"
)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


def bar(o, h, l, c, v=1000, complete=True, synthetic=False):
    return {
        "open": o,
        "high": h,
        "low": l,
        "close": c,
        "volume": v,
        "is_complete": complete,
        "synthetic": synthetic,
        "time_kst": "2024-01-01 09:00:00",
    }


def days(n, start=date(2024, 1, 2)):
    out = []
    d = start
    while len(out) < n:
        if d.weekday() < 5:
            out.append(d.isoformat())
        d += timedelta(days=1)
    return out


class FeatureAndSimTests(unittest.TestCase):
    def test_features_need_sixty_completed_bars(self):
        past = [bar(100, 101, 99, 100) for _ in range(59)]
        self.assertIsNone(m.features(past))
        past.append(bar(100, 101, 99, 100))
        self.assertIsNotNone(m.features(past))

    def test_close_near_uses_last_bar_only_from_past(self):
        past = [bar(100, 110, 90, 100) for _ in range(59)]
        past.append(bar(100, 100, 99, 107))
        feat = m.features(past)
        self.assertTrue(feat["close_near_20high"])
        far = list(past)
        far[-1] = bar(100, 100, 99, 100)
        self.assertFalse(m.features(far)["close_near_20high"])

    def test_higher_low_and_ma_split(self):
        past = []
        for i in range(60):
            px = 50 + i * 0.5
            past.append(bar(px, px + 1, px - 1, px))
        feat = m.features(past)
        self.assertTrue(feat["higher_10bar_low"])
        self.assertTrue(feat["ma20_above_ma60"])
        down = []
        for i in range(60):
            px = 100 - i
            down.append(bar(px, px + 1, px - 1, px))
        feat_down = m.features(down)
        self.assertFalse(feat_down["higher_10bar_low"])
        self.assertFalse(feat_down["ma20_above_ma60"])

    def test_same_bar_stop_before_target_and_high_touch_is_not_pnl(self):
        window = [bar(100, 120, 90, 110)] + [bar(110, 111, 109, 110) for _ in range(19)]
        out = m.simulate(window, 100)
        self.assertTrue(out["high_touch_15"])
        self.assertEqual(out["exit_reason"], "stop")
        self.assertFalse(out["tradable_win"])
        self.assertEqual(out["bars_held"], 1)

    def test_edge_rule_is_pre_specified(self):
        true_s = {
            "n": 40,
            "mean_tradable_net_pct": 0.5,
            "tradable_win_pct": 45.0,
        }
        false_s = {
            "n": 40,
            "mean_tradable_net_pct": 0.1,
            "tradable_win_pct": 40.0,
        }
        self.assertTrue(m.has_edge(true_s, false_s))
        self.assertFalse(m.has_edge({**true_s, "n": 10}, false_s))
        self.assertFalse(m.has_edge({**true_s, "mean_tradable_net_pct": -0.1}, false_s))
        self.assertFalse(m.has_edge({**true_s, "mean_tradable_net_pct": 0.1}, false_s))
        self.assertFalse(m.has_edge({**true_s, "tradable_win_pct": 30.0}, false_s))


class PeriodFixtureTests(unittest.TestCase):
    def _write_symbol(self, bars_dir: Path, symbol: str, session_days: list[str]):
        rows = []
        for i, day in enumerate(session_days):
            px = 80 + (i % 5)
            rows.append(
                {
                    "time_kst": f"{day} 09:00:00",
                    "open": px,
                    "high": px + 2,
                    "low": px - 2,
                    "close": px,
                    "volume": 1000,
                    "is_complete": True,
                    "synthetic": False,
                }
            )
        (bars_dir / f"{symbol}.json").write_text(
            json.dumps({"price_basis": m.PRICE_BASIS, "rows": rows}),
            encoding="utf-8",
        )

    def test_observations_unique_repeat_and_future_not_in_features(self):
        session = days(90)
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            bars_dir = root / "bars"
            bars_dir.mkdir()
            dates = {}
            for i, day in enumerate(session):
                rows = [{"symbol": "AAA.KS"}]
                if i in (0, 20):
                    rows.append({"symbol": "GAP.KS"})
                if i == 75:
                    rows.append({"symbol": "TAIL.KS"})
                dates[day] = {"rows": rows}
            cand = root / "candidates.json"
            cand.write_text(json.dumps({"days": session, "dates": dates}), encoding="utf-8")
            self._write_symbol(bars_dir, "AAA.KS", session)
            self._write_symbol(bars_dir, "GAP.KS", session)
            self._write_symbol(bars_dir, "TAIL.KS", session)
            log = root / "daily-log.jsonl"
            log.write_text(
                json.dumps({"date": session[70], "signals": [{"symbol": "AAA.KS"}]}) + "\n",
                encoding="utf-8",
            )
            spec = {
                "candidates": cand,
                "dataset": root,
                "signals": log,
                "signal_kind": "daily_log_jsonl",
                "label": "fixture",
            }
            period = m.load_period("recent", spec)
            rows = m.observations(period)
            aaa = [r for r in rows if r["symbol"] == "AAA.KS"]
            self.assertTrue(aaa[0]["unique"])
            self.assertFalse(aaa[1]["unique"])
            self.assertFalse(next(r for r in aaa if r["date"] == session[20])["unique"])
            gap = [r for r in rows if r["symbol"] == "GAP.KS"]
            self.assertTrue(gap[0]["unique"])
            self.assertTrue(gap[1]["unique"])
            scored = [r for r in aaa if r["outcome"] is not None]
            self.assertGreater(len(scored), 0)
            judgment = session[60]
            past_only = m.load_bars(bars_dir / "AAA.KS.json")
            past = [past_only[d] for d in sorted(d for d in past_only if d <= judgment)]
            row = next(r for r in aaa if r["date"] == judgment)
            self.assertEqual(row["features"]["close"], m.features(past)["close"])
            caught = next(r for r in aaa if r["date"] == session[70])
            self.assertTrue(caught["caught"])
            self.assertTrue(caught["rec_known"])
            tail = next(r for r in rows if r["symbol"] == "TAIL.KS")
            self.assertEqual(tail["skip"], "incomplete_future_window")

    def test_missing_judgment_bar_is_not_prior_close(self):
        session = days(90)
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            bars_dir = root / "bars"
            bars_dir.mkdir()
            dates = {d: {"rows": [{"symbol": "CCC.KS"}]} for d in session}
            cand = root / "candidates.json"
            cand.write_text(json.dumps({"days": session, "dates": dates}), encoding="utf-8")
            self._write_symbol(bars_dir, "CCC.KS", [d for d in session if d != session[70]])
            log = root / "daily-log.jsonl"
            log.write_text("", encoding="utf-8")
            spec = {
                "candidates": cand,
                "dataset": root,
                "signals": log,
                "signal_kind": "daily_log_jsonl",
                "label": "fixture",
            }
            rows = m.observations(m.load_period("recent", spec))
            hit = next(r for r in rows if r["date"] == session[70])
            self.assertEqual(hit["skip"], "missing_judgment_bar")
            self.assertIsNone(hit["features"])


if __name__ == "__main__":
    unittest.main()
