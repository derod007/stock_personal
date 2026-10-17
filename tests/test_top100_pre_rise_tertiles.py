import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "top100_pre_rise_tertiles", ROOT / "docs/research/top100_pre_rise_tertiles.py"
)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


def row(**kw):
    base = {
        "symbol": "A.KS",
        "anchor": "2024-01-02",
        "close_change_60": 0.2,
        "vol_ratio_10_50": 1.2,
        "hit15": False,
        "net": -1.0,
        "win": False,
        "rec_status": "unknown",
        "new_entry_status": "unknown",
        "left_censored": False,
    }
    base.update(kw)
    return base


class TertileTests(unittest.TestCase):
    def test_cuts_use_values_not_labels(self):
        xs = [0.0, 0.1, 0.2, 0.3, 0.4, 0.5]
        c = m.tertile_cuts(xs)
        self.assertEqual(c["n"], 6)
        self.assertAlmostEqual(c["p33"], m.quantile(xs, 1 / 3))
        self.assertAlmostEqual(c["p67"], m.quantile(xs, 2 / 3))
        self.assertLess(c["p33"], c["p67"])

    def test_frozen_prior_cuts_applied_to_other_period(self):
        cuts = {
            "close_change_60": {"p33": 0.10, "p67": 0.20, "n": 99},
            "vol_ratio_10_50": {"p33": 1.0, "p67": 1.5, "n": 99},
        }
        labeled = m.apply_cuts(
            [
                row(close_change_60=0.25, vol_ratio_10_50=2.0),
                row(close_change_60=0.05, vol_ratio_10_50=0.5),
                row(close_change_60=0.25, vol_ratio_10_50=0.5),
            ],
            cuts,
        )
        self.assertTrue(labeled[0]["combo_top"])
        self.assertFalse(labeled[1]["combo_top"])
        self.assertFalse(labeled[2]["combo_top"])
        self.assertEqual(labeled[0]["band_close_change_60"], 3)
        self.assertEqual(labeled[2]["band_vol_ratio_10_50"], 1)

    def test_unknown_rec_is_not_not_caught(self):
        e = {
            "symbol": "A.KS",
            "anchor": "2024-01-02",
            "rec_known": False,
            "caught_in_window": False,
            "left_censored": True,
            "shape": {"close_change_60": 0.1, "vol_ratio_10_50": 1.1},
            "immediate": {"tradable_net_pct": -1.0, "tradable_win": False},
            "unconstrained": {"hit15": True},
        }
        r = m.valid_row(e)
        self.assertEqual(r["rec_status"], "unknown")
        self.assertEqual(r["new_entry_status"], "unknown")
        self.assertTrue(r["hit15"])

    def test_combo_judgment_categories(self):
        def block(mean_g, mean_b, hit_g, hit_b, n=50):
            return {
                "combo_top": {"n": n, "mean_net_pct": mean_g, "hit15_pct": hit_g},
                "base": {"n": 200, "mean_net_pct": mean_b, "hit15_pct": hit_b},
            }

        j = m.judge(block(1.0, 0.0, 40, 30), block(0.5, 0.1, 35, 28))
        self.assertEqual(j["label"], "유리함")
        j2 = m.judge(block(-0.2, -0.4, 40, 30), block(-0.1, -0.3, 35, 28))
        self.assertEqual(j2["label"], "구별력만 있음")
        j3 = m.judge(block(-0.5, -0.2, 20, 30), block(-0.4, -0.1, 22, 28))
        self.assertEqual(j3["label"], "근거 부족")

    def test_bootstrap_is_deterministic(self):
        rows = [row(symbol="A.KS", net=1.0, close_change_60=0.3)] + [
            row(symbol="B.KS", net=-1.0, close_change_60=0.0) for _ in range(3)
        ]
        cuts = {
            "close_change_60": {"p33": 0.05, "p67": 0.2, "n": 4},
            "vol_ratio_10_50": {"p33": 0.5, "p67": 1.0, "n": 4},
        }
        labeled = m.apply_cuts(rows, cuts)
        a = m.symbol_bootstrap_diff(labeled, lambda r: r["band_close_change_60"] == 3, n=50, seed=1042018)
        b = m.symbol_bootstrap_diff(labeled, lambda r: r["band_close_change_60"] == 3, n=50, seed=1042018)
        self.assertEqual(a, b)
        self.assertEqual(a["seed"], 1042018)


if __name__ == "__main__":
    unittest.main()
