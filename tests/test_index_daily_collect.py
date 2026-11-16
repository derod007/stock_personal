import importlib.util
import json
import tempfile
import unittest
from datetime import date
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
MOD_PATH = ROOT / "docs" / "research" / "index_daily_collect.py"


def load_mod():
    spec = importlib.util.spec_from_file_location("index_daily_collect", MOD_PATH)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


def payload(name, close, day="20241008", extra=None):
    rows = [{"IDX_NM": name, "IDX_CLSS": "KOSPI", "BAS_DD": day, "CLSPRC_IDX": close}]
    if extra:
        rows.append(extra)
    return {"OutBlock_1": rows}


class IndexDailyCollectTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load_mod()

    def test_exact_index_name_only(self):
        parsed = self.m.bars_from_day(
            payload("코스피", "2600.12", extra={"IDX_NM": "코스피 200", "IDX_CLSS": "KOSPI", "BAS_DD": "20241008", "CLSPRC_IDX": "350.00"}),
            "코스피",
            "20241008",
        )
        self.assertEqual(parsed["bar"]["close"], "2600.12")
        self.assertEqual(parsed["bar"]["idx_nm"], "코스피")

    def test_empty_or_ambiguous_name_makes_no_bar(self):
        self.assertIsNone(self.m.bars_from_day({"OutBlock_1": []}, "코스피", "20241008")["bar"])
        doubled = payload("코스피", "1.00")
        doubled["OutBlock_1"].append(doubled["OutBlock_1"][0])
        self.assertEqual(self.m.bars_from_day(doubled, "코스피", "20241008")["issue"], "name_count")
        self.assertEqual(self.m.bars_from_day(payload("코스피", "1.00", day="20241009"), "코스피", "20241008")["issue"], "date")

    def test_existing_raw_is_not_requested_again(self):
        with tempfile.TemporaryDirectory() as tmp:
            raw = Path(tmp) / "research"
            dest = raw / "raw" / "kospi_dd_trd" / "20241008.json"
            other = raw / "raw" / "kosdaq_dd_trd" / "20241008.json"
            dest.parent.mkdir(parents=True)
            other.parent.mkdir(parents=True)
            dest.write_text("{}", encoding="utf-8")
            other.write_text("{}", encoding="utf-8")

            def http_get(url, headers):
                raise AssertionError(url)

            result = self.m.collect_raw(raw, "SECRET-KEY", http_get, ["20241008"])
            self.assertEqual(result["requests"], 0)
            self.assertEqual(result["reused"], 2)
            self.assertEqual(dest.read_text(encoding="utf-8"), "{}")

    def test_auth_failure_writes_nothing(self):
        with tempfile.TemporaryDirectory() as tmp:
            raw = Path(tmp) / "research"

            def http_get(url, headers):
                return 401, {}, b'{"respMsg":"Unauthorized API Call"}'

            result = self.m.collect_raw(raw, "SECRET-KEY", http_get, ["20241008"])
            self.assertEqual(result["written"], 0)
            self.assertEqual(result["stopped"]["http"], 401)
            self.assertFalse((raw / "raw").exists())

    def test_existing_summary_stops_before_collection(self):
        with tempfile.TemporaryDirectory() as tmp:
            dest = Path(tmp) / "series.json"
            dest.write_text("kept", encoding="utf-8")
            with patch.object(self.m, "DOCS", Path(tmp)), patch.object(self.m, "collect_raw") as collect:
                with self.assertRaises(SystemExit):
                    self.m.main()
                collect.assert_not_called()
            self.assertEqual(dest.read_text(encoding="utf-8"), "kept")

    def test_changed_raw_cannot_reuse_stale_normalized_data(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            raw = root / "raw" / "kospi_dd_trd" / "20241008.json"
            raw.parent.mkdir(parents=True)
            raw.write_text(json.dumps(payload("코스피", "2600.00")), encoding="utf-8")
            self.m.normalize_market(root, self.m.MARKETS[0])
            normalized = root / "normalized" / "kospi_dd_trd.json"
            before = normalized.read_bytes()
            raw.write_text(json.dumps(payload("코스피", "2700.00")), encoding="utf-8")
            with self.assertRaises(ValueError):
                self.m.normalize_market(root, self.m.MARKETS[0])
            self.assertEqual(normalized.read_bytes(), before)

    def test_operational_dir_is_refused(self):
        with self.assertRaises(SystemExit):
            self.m.collect_raw(Path("data") / "ohlcv" / "index", "x", lambda url, headers: (200, {}, b"{}"), [])

    def test_gap_is_not_filled_and_warmup_needs_sixty_opens(self):
        ranges = [{"from": "2024-09-01", "to": "2026-10-08", "closed": {"2025-01-01"}}]
        opens = []
        cursor = date(2024, 9, 1)
        while cursor <= date(2026, 10, 8):
            if self.m.day_kind(cursor.isoformat(), ranges) == "open":
                opens.append(cursor.isoformat())
            cursor += __import__("datetime").timedelta(days=1)
        bars = [{"date": day, "close": "1.00"} for day in opens]
        full = self.m.assess(bars, ranges)
        self.assertTrue(full["ready_60"])
        self.assertEqual(full["missing_open"], [])
        with_closed_bar = bars + [{"date": "2025-01-01", "close": "1.00"}]
        with_closed_bar.sort(key=lambda bar: bar['date'])
        conflict = self.m.assess(with_closed_bar, ranges)
        self.assertFalse(conflict['ready_60'])
        self.assertFalse(conflict['range_clear'])
        dropped = [bar for bar in bars if bar["date"] != "2025-06-02"]
        hole = self.m.assess(dropped, ranges)
        self.assertEqual(hole["missing_open"], ["2025-06-02"])
        self.assertNotIn("2025-06-03", hole["missing_open"])
        short = self.m.assess(bars[-59:], ranges)
        self.assertFalse(short["ready_60"])

    def test_bad_close_and_calendar_disagreement(self):
        ranges = [{"from": "2024-09-01", "to": "2026-10-08", "closed": set()}]
        weekend = self.m.assess([{"date": "2024-09-01", "close": "1.00"}, {"date": "2024-09-02", "close": "0"}], ranges)
        self.assertIn("2024-09-01", weekend["on_closed"])
        self.assertIn("2024-09-02", weekend["bad_close"])

    def test_repo_calendar_marks_new_year_closed(self):
        ranges = self.m.load_ranges(ROOT)
        self.assertEqual(self.m.day_kind("2025-01-01", ranges), "closed")
        self.assertEqual(self.m.day_kind("2024-10-08", ranges), "open")
        self.assertEqual(self.m.day_kind("2024-09-01", ranges), "closed")

    def test_naver_prefix_is_not_a_bar(self):
        text = "\n [['날짜', '시가', '고가', '저가', '종가', '거래량', '외국인소진율'],\n\n[\"20250602\", 1, 2, 3, 2698.97, 4, 0.0],\n"
        self.assertEqual(self.m.naver_closes(text), {"2025-06-02": "2698.97"})

    def test_compare_does_not_mix_sources(self):
        result = self.m.compare_closes({"2025-06-02": "2698.97"}, {"2025-06-02": "2698.97", "2025-06-04": "1"})
        self.assertEqual(result, {"overlap": 1, "equal": 1, "differ": []})
        other = self.m.compare_closes({"2025-06-02": "2698.97"}, {"2025-06-02": "2700.00"})
        self.assertEqual(other["differ"], ["2025-06-02"])
        residue = self.m.compare_closes({"2026-10-08": "2600.12"}, {"2026-10-08": "2600.1200000002"})
        self.assertEqual(residue["differ"], [])


if __name__ == "__main__":
    unittest.main()
