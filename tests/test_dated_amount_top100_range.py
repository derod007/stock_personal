import hashlib
import json
import tempfile
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


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


class FakePilot:
    def sha256_file(self, path: Path) -> str:
        return sha256_bytes(path.read_bytes())

    def yahoo_symbol(self, code: str, market: str) -> str:
        suffix = ".KQ" if str(market).upper() == "KOSDAQ" else ".KS"
        return code + suffix

    def rerank_from_raw_files(self, day: str, raw_dir: Path) -> dict:
        selected = json.loads((raw_dir.parent / "selected" / f"{day}_top100.json").read_text(encoding="utf-8"))
        return selected

    def top100_signature(self, payload: dict):
        return [(str(r["code"]), float(r["amount_won"])) for r in payload.get("top100") or []]


def top100_payload(day: str) -> dict:
    rows = []
    for i in range(1, 101):
        rows.append({
            "rank": i,
            "code": f"{i:06d}",
            "name": f"n{i}",
            "market": "KOSPI",
            "amount_won": 1000 - i,
            "yahoo": f"{i:06d}.KS",
        })
    return {"date": day, "top100": rows}


def write_day(raw_root: Path, day: str) -> tuple[str, str, str]:
    selected = json.dumps(top100_payload(day), ensure_ascii=False).encode("utf-8")
    kospi = b'{"market":"kospi"}'
    kosdaq = b'{"market":"kosdaq"}'
    (raw_root / "selected").mkdir(parents=True, exist_ok=True)
    (raw_root / "raw").mkdir(parents=True, exist_ok=True)
    (raw_root / "selected" / f"{day}_top100.json").write_bytes(selected)
    (raw_root / "raw" / f"{day}_kospi_equity.json").write_bytes(kospi)
    (raw_root / "raw" / f"{day}_kosdaq_equity.json").write_bytes(kosdaq)
    return sha256_bytes(selected), sha256_bytes(kospi), sha256_bytes(kosdaq)


def issue_codes(exc: SystemExit) -> list[str]:
    payload = json.loads(str(exc))
    return [i["issue"] for i in payload["issues"]]


class DatedAmountTop100RangeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load(ROOT / "docs" / "research" / "dated-amount-top100-range.py", "dated_top100_range")
        cls.days = ["20251010", "20251013"]

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

    def _layout(self, tmp: Path, ok_dates=None, write_files=True, frozen=None, hash_file=True):
        docs = tmp / "docs"
        raw_root = tmp / "raw"
        docs.mkdir()
        hashes = {"selected": {}, "raw": {}}
        if write_files:
            for day in self.days:
                sel, k, q = write_day(raw_root, day)
                hashes["selected"][f"{day}_top100.json"] = sel
                hashes["raw"][f"{day}_kospi_equity.json"] = k
                hashes["raw"][f"{day}_kosdaq_equity.json"] = q
        if ok_dates is not None:
            (docs / "collect-summary.json").write_text(
                json.dumps({"ok_dates": ok_dates, "blocked_dates": []}),
                encoding="utf-8",
            )
        if hash_file:
            payload = frozen if frozen is not None else hashes
            (docs / "file-hashes.json").write_text(
                json.dumps({"schema": 1, "selected": payload["selected"], "raw": payload["raw"]}),
                encoding="utf-8",
            )
        return docs, raw_root, hashes

    def _validate(self, docs, raw_root, hash_mode="verify"):
        return self.m.validate_inputs(
            FakePilot(),
            days=self.days,
            docs=docs,
            raw_root=raw_root,
            hash_mode=hash_mode,
        )

    def test_validate_fails_when_summary_missing(self):
        with tempfile.TemporaryDirectory() as tmp:
            docs, raw_root, _ = self._layout(Path(tmp), ok_dates=None)
            with self.assertRaises(SystemExit) as ctx:
                self._validate(docs, raw_root)
            self.assertIn("collect_summary_missing", issue_codes(ctx.exception))

    def test_validate_fails_when_ok_dates_empty(self):
        with tempfile.TemporaryDirectory() as tmp:
            docs, raw_root, _ = self._layout(Path(tmp), ok_dates=[])
            with self.assertRaises(SystemExit) as ctx:
                self._validate(docs, raw_root)
            codes = issue_codes(ctx.exception)
            self.assertIn("ok_dates_empty", codes)
            self.assertIn("success_date_missing", codes)

    def test_validate_fails_when_ok_dates_partially_missing(self):
        with tempfile.TemporaryDirectory() as tmp:
            docs, raw_root, _ = self._layout(Path(tmp), ok_dates=["20251010"])
            with self.assertRaises(SystemExit) as ctx:
                self._validate(docs, raw_root)
            codes = issue_codes(ctx.exception)
            self.assertIn("success_date_missing", codes)
            self.assertIn("ok_dates_mismatch", codes)

    def test_validate_fails_on_wrong_selected_hash(self):
        with tempfile.TemporaryDirectory() as tmp:
            docs, raw_root, hashes = self._layout(Path(tmp), ok_dates=self.days)
            hashes["selected"]["20251010_top100.json"] = "0" * 64
            (docs / "file-hashes.json").write_text(
                json.dumps({"schema": 1, "selected": hashes["selected"], "raw": hashes["raw"]}),
                encoding="utf-8",
            )
            with self.assertRaises(SystemExit) as ctx:
                self._validate(docs, raw_root)
            self.assertIn("selected_hash_mismatch", issue_codes(ctx.exception))

    def test_validate_fails_on_wrong_raw_hash(self):
        with tempfile.TemporaryDirectory() as tmp:
            docs, raw_root, hashes = self._layout(Path(tmp), ok_dates=self.days)
            hashes["raw"]["20251010_kospi_equity.json"] = "1" * 64
            (docs / "file-hashes.json").write_text(
                json.dumps({"schema": 1, "selected": hashes["selected"], "raw": hashes["raw"]}),
                encoding="utf-8",
            )
            with self.assertRaises(SystemExit) as ctx:
                self._validate(docs, raw_root)
            self.assertIn("raw_hash_mismatch", issue_codes(ctx.exception))

    def test_validate_does_not_use_local_collect_when_frozen_missing(self):
        with tempfile.TemporaryDirectory() as tmp:
            docs, raw_root, hashes = self._layout(Path(tmp), ok_dates=self.days, hash_file=False)
            local = {
                "collect": {
                    "ok_dates": self.days,
                    "days": [
                        {
                            "date": "20251010",
                            "selected_file": "20251010_top100.json",
                            "selected_sha256": hashes["selected"]["20251010_top100.json"],
                            "files": [
                                {"market": "kospi_equity", "sha256": hashes["raw"]["20251010_kospi_equity.json"]},
                                {"market": "kosdaq_equity", "sha256": hashes["raw"]["20251010_kosdaq_equity.json"]},
                            ],
                        }
                    ],
                }
            }
            (raw_root / "collect-summary.json").write_text(json.dumps(local), encoding="utf-8")
            with self.assertRaises(SystemExit) as ctx:
                self._validate(docs, raw_root)
            self.assertIn("frozen_hashes_missing", issue_codes(ctx.exception))

    def test_verify_does_not_rewrite_frozen_hashes(self):
        with tempfile.TemporaryDirectory() as tmp:
            docs, raw_root, _ = self._layout(Path(tmp), ok_dates=self.days)
            before = (docs / "file-hashes.json").read_bytes()
            out = self._validate(docs, raw_root, hash_mode="verify")
            self.assertEqual(out["hash_mode"], "verify")
            self.assertEqual((docs / "file-hashes.json").read_bytes(), before)

    def test_freeze_refuses_existing_hash_file(self):
        with tempfile.TemporaryDirectory() as tmp:
            docs, raw_root, _ = self._layout(Path(tmp), ok_dates=self.days)
            with self.assertRaises(SystemExit) as ctx:
                self._validate(docs, raw_root, hash_mode="freeze")
            self.assertIn("freeze_refused_existing", issue_codes(ctx.exception))


if __name__ == "__main__":
    unittest.main()
