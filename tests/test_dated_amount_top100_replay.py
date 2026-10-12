import hashlib
import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOD_PATH = ROOT / "docs" / "research" / "dated-amount-top100-replay.py"


def load_mod():
    spec = importlib.util.spec_from_file_location("dated_top100_replay", MOD_PATH)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class DatedAmountTop100ReplayTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load_mod()

    def test_frozen_hashes_require_collect_days_and_prefer_them(self):
        m = self.m
        selected, raw = m.frozen_hashes({
            "selected_files": [
                {"file": "20251001_top100.json", "sha256": "old"},
                {"file": "20251002_top100.json", "sha256": "keep"},
            ],
            "collect": {"days": [
                {
                    "date": "20251001",
                    "selected_sha256": "new",
                    "selected_file": "20251001_top100.json",
                    "files": [
                        {"market": "kospi_equity", "sha256": "k1"},
                        {"market": "kosdaq_equity", "sha256": "q1"},
                    ],
                }
            ]},
        })
        self.assertEqual(selected["20251001_top100.json"], "new")
        self.assertEqual(selected["20251002_top100.json"], "keep")
        self.assertEqual(raw["20251001_kospi_equity.json"], "k1")
        self.assertEqual(raw["20251001_kosdaq_equity.json"], "q1")

    def test_missing_selected_baseline_fails(self):
        issues = self.m.required_file_hash_issues(
            {"20251001_top100.json": "abc"},
            {},
            ["20251001_top100.json"],
            "selected_hash_missing",
            "selected_hash_mismatch",
        )
        self.assertEqual(issues, [{"file": "20251001_top100.json", "issue": "selected_hash_missing"}])

    def test_missing_raw_baseline_fails(self):
        issues = self.m.required_file_hash_issues(
            {"20251001_kospi_equity.json": "abc"},
            {},
            ["20251001_kospi_equity.json"],
            "raw_hash_missing",
            "raw_hash_mismatch",
        )
        self.assertEqual(issues, [{"file": "20251001_kospi_equity.json", "issue": "raw_hash_missing"}])

    def test_selected_and_raw_mismatch_fail(self):
        m = self.m
        sel = m.required_file_hash_issues(
            {"a.json": "got"}, {"a.json": "exp"}, ["a.json"],
            "selected_hash_missing", "selected_hash_mismatch",
        )
        raw = m.required_file_hash_issues(
            {"b.json": "got"}, {"b.json": "exp"}, ["b.json"],
            "raw_hash_missing", "raw_hash_mismatch",
        )
        self.assertEqual(sel[0]["issue"], "selected_hash_mismatch")
        self.assertEqual(raw[0]["issue"], "raw_hash_mismatch")

    def test_matching_required_hashes_pass(self):
        issues = self.m.required_file_hash_issues(
            {"a.json": "abc"}, {"a.json": "abc"}, ["a.json"],
            "selected_hash_missing", "selected_hash_mismatch",
        )
        self.assertEqual(issues, [])

    def test_git_lf_links_to_recorded_crlf_hash(self):
        lf = b'{\n  "a": 1\n}\n'
        crlf = lf.replace(b"\n", b"\r\n")
        recorded = hashlib.sha256(crlf).hexdigest()
        link = self.m.link_recorded_hash(lf, recorded)
        self.assertTrue(link["pass"])
        self.assertEqual(link["match"], "crlf_from_lf")
        self.assertEqual(link["ending"], "lf")
        self.assertTrue(self.m.link_recorded_hash(crlf, recorded)["pass"])
        self.assertFalse(self.m.link_recorded_hash(lf, "0" * 64)["pass"])
        self.assertFalse(self.m.link_recorded_hash(lf + b"x", recorded)["pass"])


if __name__ == "__main__":
    unittest.main()
