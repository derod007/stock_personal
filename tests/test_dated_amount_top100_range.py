import unittest

from pathlib import Path
import importlib.util

ROOT = Path(__file__).resolve().parents[1]


def load(path, name):
    spec = importlib.util.spec_from_file_location(name, path)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class DatedAmountTop100RangeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load(ROOT / "docs" / "research" / "dated-amount-top100-range.py", "dated_top100_range")

    def test_missing_baseline_hash_fails(self):
        issues = self.m.required_hash_issues(
            {"20251010_top100.json": "abc"},
            {},
            ["20251010_top100.json"],
            "selected_hash_missing",
            "selected_hash_mismatch",
        )
        self.assertEqual(issues[0]["issue"], "selected_hash_missing")
        raw = self.m.required_hash_issues(
            {"20251010_kospi_equity.json": "abc"},
            {},
            ["20251010_kospi_equity.json"],
            "raw_hash_missing",
            "raw_hash_mismatch",
        )
        self.assertEqual(raw[0]["issue"], "raw_hash_missing")

    def test_mismatch_and_match(self):
        self.assertEqual(
            self.m.required_hash_issues(
                {"a.json": "got"}, {"a.json": "exp"}, ["a.json"],
                "selected_hash_missing", "selected_hash_mismatch",
            )[0]["issue"],
            "selected_hash_mismatch",
        )
        self.assertEqual(
            self.m.required_hash_issues(
                {"a.json": "exp"}, {"a.json": "exp"}, ["a.json"],
                "selected_hash_missing", "selected_hash_mismatch",
            ),
            [],
        )


if __name__ == "__main__":
    unittest.main()
