import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def load():
    spec = importlib.util.spec_from_file_location(
        "provider_review",
        ROOT / "docs" / "research" / "dated-amount-top100-provider-review.py",
    )
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class ProviderReviewTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load()

    def test_code_class_does_not_treat_letters_as_invalid_listing(self):
        self.assertEqual(self.m.classify_krx_code("005930"), "six_digit")
        self.assertEqual(self.m.classify_krx_code("00088K"), "preferred_or_class_letter_suffix")
        self.assertEqual(self.m.classify_krx_code("0220WL"), "preferred_or_class_letter_suffix")
        self.assertEqual(self.m.classify_krx_code("0001A0"), "alphanumeric_short_code")
        self.assertEqual(self.m.classify_krx_code("45014K"), "preferred_or_class_letter_suffix")

    def test_appearance_counts_unique_and_occurrences(self):
        cand = {
            "dates": {
                "2025-10-10": {"rows": [{"symbol": "A.KQ"}, {"symbol": "B.KS"}]},
                "2025-10-13": {"rows": [{"symbol": "A.KQ"}]},
            }
        }
        out = self.m.appearance_counts(cand, {"A.KQ", "B.KS", "C.KQ"})
        self.assertEqual(out["appearances"], 3)
        self.assertEqual(out["distinct_days"], 2)
        self.assertEqual(out["by_symbol"]["A.KQ"]["n"], 2)
        self.assertEqual(out["by_symbol"]["C.KQ"]["n"], 0)


if __name__ == "__main__":
    unittest.main()
