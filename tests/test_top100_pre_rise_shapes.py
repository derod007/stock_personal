import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "top100_pre_rise_shapes", ROOT / "docs/research/top100_pre_rise_shapes.py"
)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


def bar(close, volume=100.0):
    return {"open": close, "high": close, "low": close, "close": close, "volume": volume}


class ShapeClusterTests(unittest.TestCase):
    def test_series60_uses_t_close_and_window_mean_volume(self):
        past = [bar(10 + i, 50 + i) for i in range(60)]
        ser = m.series60(past)
        self.assertEqual(len(ser["price"]), 60)
        self.assertAlmostEqual(ser["price"][-1], 1.0)
        self.assertAlmostEqual(sum(ser["vol"]) / 60, 1.0)
        self.assertEqual(len(ser["feat"]), 120)

    def test_insufficient_history_is_none_not_nonrise(self):
        self.assertIsNone(m.series60([bar(10) for _ in range(59)]))

    def test_kmeans_is_deterministic_and_has_six_centers(self):
        rng = m.random.Random(1)
        X = [[rng.random() for _ in range(8)] for _ in range(30)]
        a = m.kmeans(X, k=6, seed=1042019, max_iter=20)
        b = m.kmeans(X, k=6, seed=1042019, max_iter=20)
        self.assertEqual(a["labels"], b["labels"])
        self.assertEqual(len(a["centers"]), 6)
        self.assertTrue(set(a["labels"]).issubset(set(range(6))))

    def test_assign_uses_frozen_centers(self):
        centers = [[0.0, 0.0], [10.0, 10.0]]
        labs = m.assign([[1.0, 1.0], [9.0, 9.0], [0.2, 0.1]], centers)
        self.assertEqual(labs, [0, 1, 0])

    def test_kmeans_does_not_take_labels(self):
        X = [[0.0, 0.0], [0.1, 0.0], [8.0, 8.0], [8.1, 8.0], [0.0, 0.2], [8.2, 7.9]]
        fit = m.kmeans(X, k=2, seed=3, max_iter=30)
        self.assertEqual(len(fit["centers"]), 2)

    def test_describe_skips_forced_names(self):
        flat = {"price": {"p50": [1.0] * 60}, "vol": {"p50": [1.0] * 60}}
        self.assertIn("진폭이 작다", m.describe_type(flat))
        price = [0.90 + 0.004 * i if i < 40 else 1.06 - 0.003 * (i - 40) for i in range(60)]
        mixed = {"price": {"p50": price}, "vol": {"p50": [1.0] * 60}}
        self.assertIn("이름을 붙이지 않음", m.describe_type(mixed))

    def test_judge_categories(self):
        def types(deltas, nets, base_net):
            return {
                "base": {"mean_net_pct": base_net, "hit15_pct": 40},
                "types": [
                    {"vs_all_hit15_pct": d, "mean_net_pct": n} for d, n in zip(deltas, nets)
                ],
            }

        j = m.judge(types([5, -1, 0, 0, 0, 0], [1, 0, 0, 0, 0, 0], 0.1), types([4, -2, 0, 0, 0, 0], [1.2, 0, 0, 0, 0, 0], 0.2))
        self.assertEqual(j["label"], "후속 진입 검증 후보")
        j2 = m.judge(types([5, -1, 0, 0, 0, 0], [-1, 0, 0, 0, 0, 0], -0.2), types([4, -2, 0, 0, 0, 0], [-0.5, 0, 0, 0, 0, 0], -0.1))
        self.assertEqual(j2["label"], "관찰 가치")
        j3 = m.judge(types([-1] * 6, [0] * 6, 0), types([-1] * 6, [0] * 6, 0))
        self.assertEqual(j3["label"], "근거 부족")
        self.assertEqual(j3["adoption"], "안 함")


if __name__ == "__main__":
    unittest.main()
