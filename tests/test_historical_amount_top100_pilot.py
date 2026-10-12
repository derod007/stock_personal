import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOD_PATH = ROOT / "docs" / "research" / "historical-amount-top100-pilot.py"


def load_mod():
    spec = importlib.util.spec_from_file_location("hist_top100_pilot", MOD_PATH)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class HistoricalTop100PilotTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load_mod()

    def test_exclusion_matches_php_fixtures(self):
        drop = [
            "한국제17호스팩",
            "KB제34호스팩",
            "엔에이치스팩29호",
            "KODEX SK하이닉스단일종목레버리지",
            "SOL AI반도체TOP2플러스",
            "TIGER SK하이닉스단일종목레버리지",
            "TIGER CD1년금리액티브(합성)",
            "RISE 삼성전자SK하이닉스채권혼합50",
            "신한 인버스 2X 천연가스 선물 ETN(H)",
            "삼성 레버리지 항셍테크 ETN(H)",
            "히어로즈 단기통안채",
            "TIME 미국나스닥100액티브",
        ]
        keep = [
            "삼성전자",
            "삼성전자우",
            "SK하이닉스",
            "네오사피엔스",
            "스카이랩스",
            "인제니아테라퓨틱스(Reg.S)",
            "현대차",
            "카카오",
            "솔브레인",
            "에이스침대",
            "맥쿼리인프라",
        ]
        for name in drop:
            self.assertTrue(self.m.excluded_from_amount_rank(name), name)
        for name in keep:
            self.assertFalse(self.m.excluded_from_amount_rank(name), name)

    def test_amount_uses_acc_trdval_only(self):
        row = {"ACC_TRDVAL": "8782520125", "TDD_CLSPRC": "11490", "ACC_TRDVOL": "764132"}
        self.assertEqual(self.m.parse_amount_won(row), 8782520125.0)
        self.assertIsNone(self.m.parse_amount_won({"TDD_CLSPRC": "11490", "ACC_TRDVOL": "764132"}))
        self.assertIsNone(self.m.parse_amount_won({"ACC_TRDVAL": "0"}))

    def test_select_excludes_then_ranks_and_drops_konex(self):
        rows = [
            {"code": "A", "name": "삼성전자", "market": "KOSPI", "amount_won": 100, "product_source": "fixture"},
            {"code": "B", "name": "한국제17호스팩", "market": "KOSDAQ", "amount_won": 500, "product_source": "fixture"},
            {"code": "C", "name": "KODEX 200", "market": "KOSPI", "amount_won": 400, "product_source": "fixture"},
            {"code": "D", "name": "카카오", "market": "KOSDAQ", "amount_won": 90, "product_source": "fixture"},
            {"code": "E", "name": "코넥스종목", "market": "KONEX", "amount_won": 999, "product_source": "fixture"},
            {"code": "F", "name": "현대차", "market": "KOSPI", "amount_won": 100, "product_source": "fixture"},
        ]
        out = self.m.select_top100(rows)
        codes = [r["code"] for r in out["top100"]]
        self.assertEqual(codes, ["A", "F", "D"])
        self.assertEqual(out["top100"][0]["rank"], 1)
        self.assertTrue(any(e["code"] == "B" for e in out["excluded"]))
        self.assertTrue(any(e["code"] == "E" and e["reason"] == "konex_out_of_scanner_scope" for e in out["excluded"]))
        self.assertEqual(out["unique_codes_in_top100"], 3)

    def test_october_2025_candidate_days_skip_holidays(self):
        days = self.m.assumed_trading_days(2025, 10)
        self.assertIn("20251002", days)
        self.assertIn("20251010", days)
        self.assertIn("20251031", days)
        for blocked in ("20251003", "20251006", "20251008", "20251009", "20251004", "20251012"):
            self.assertNotIn(blocked, days)
        self.assertEqual(days[0], "20251001")
        self.assertEqual(days[-1], "20251031")
        self.assertEqual(len(days), 18)

    def _row(self, day, code, name, market, amount):
        return {
            "BAS_DD": day,
            "ISU_CD": code,
            "ISU_NM": name,
            "MKT_NM": market,
            "ACC_TRDVAL": str(amount),
        }

    def _body(self, day, market="KOSPI"):
        if market == "KOSPI":
            rows = [self._row(day, "005930", "삼성전자", "KOSPI", 1000)]
        else:
            rows = [self._row(day, "035420", "NAVER", "KOSDAQ", 900)]
        return json.dumps({"OutBlock_1": rows}, ensure_ascii=False).encode("utf-8")

    def _collect(self, tmp, fetch, days=None):
        root = Path(tmp)
        return self.m.collect_month(
            "test-key",
            sleep_s=0,
            days=days or ["20251002"],
            raw_dir=root / "raw",
            selected_dir=root / "selected",
            fetch_market=fetch,
        )

    def test_validate_distinguishes_http_json_auth_empty_and_not_holiday(self):
        self.assertEqual(self.m.classify_http_status(500, b"x"), "http")
        self.assertEqual(self.m.classify_http_status(401, b"Unauthorized Key"), "auth")
        self.assertEqual(self.m.classify_http_status(403, b""), "auth")
        empty = self.m.validate_openapi_body(b'{"OutBlock_1":[]}', "20251002")
        self.assertFalse(empty["ok"])
        self.assertEqual(empty["kind"], "empty")
        bad_json = self.m.validate_openapi_body(b"{", "20251002")
        self.assertEqual(bad_json["kind"], "json")
        auth_body = self.m.validate_openapi_body(
            b'{"respCode":"401","respMsg":"Unauthorized Key"}', "20251002"
        )
        self.assertEqual(auth_body["kind"], "auth")

    def test_one_market_http_failure_blocks_selection(self):
        def fetch(day, market):
            if market == "kosdaq_equity":
                return 500, b"server error"
            return 200, self._body(day, "KOSPI")
        with tempfile.TemporaryDirectory() as tmp:
            out = self._collect(tmp, fetch)
            self.assertTrue(out["ran"])
            self.assertEqual(out["ok_dates"], [])
            self.assertEqual(out["days"][0]["status"], "http")
            self.assertFalse(out["days"][0]["select"])
            self.assertFalse((Path(tmp) / "selected" / "20251002_top100.json").exists())

    def test_date_mismatch_blocks_selection(self):
        def fetch(day, market):
            if market == "kosdaq_equity":
                return 200, self._body("20251001", "KOSDAQ")
            return 200, self._body(day, "KOSPI")
        with tempfile.TemporaryDirectory() as tmp:
            out = self._collect(tmp, fetch)
            self.assertEqual(out["days"][0]["status"], "date_mismatch")
            self.assertFalse(out["days"][0]["select"])
            self.assertEqual(out["ok_dates"], [])
            self.assertFalse((Path(tmp) / "selected" / "20251002_top100.json").exists())

    def test_corrupt_cache_blocks_selection(self):
        def fetch(day, market):
            if market == "kospi_equity":
                return 200, b"not-json"
            return 200, self._body(day, "KOSDAQ")
        with tempfile.TemporaryDirectory() as tmp:
            raw = Path(tmp) / "raw"
            raw.mkdir()
            (raw / "20251002_kospi_equity.json").write_bytes(b"<<<broken")
            (raw / "20251002_kosdaq_equity.json").write_bytes(self._body("20251002", "KOSDAQ"))
            out = self._collect(tmp, fetch)
            kinds = out["days"][0]["market_kinds"]
            self.assertEqual(kinds["kospi_equity"], "json")
            self.assertFalse(out["days"][0]["select"])
            self.assertEqual(out["ok_dates"], [])
            counted, union = self.m.counted_selected_files(out, Path(tmp) / "selected")
            self.assertEqual(counted, [])
            self.assertEqual(union, set())

    def test_stale_selected_file_not_counted_on_failed_day(self):
        def fetch(day, market):
            if market == "kospi_equity":
                return 401, b'{"respMsg":"Unauthorized Key","respCode":"401"}'
            return 200, self._body(day, "KOSDAQ")
        with tempfile.TemporaryDirectory() as tmp:
            selected = Path(tmp) / "selected"
            selected.mkdir()
            stale = selected / "20251002_top100.json"
            stale.write_text(json.dumps({
                "date": "20251002",
                "top100": [{"code": "005930", "name": "삼성전자"}],
                "eligible_count": 1,
                "excluded_count": 0,
            }), encoding="utf-8")
            out = self._collect(tmp, fetch)
            self.assertIn("20251002_top100.json", out["stale_selected_ignored"])
            self.assertEqual(out["selected_files_counted"], [])
            counted, union = self.m.counted_selected_files(out, selected)
            self.assertEqual(counted, [])
            self.assertEqual(union, set())

    def test_both_markets_ok_writes_top100(self):
        def fetch(day, market):
            mkt = "KOSPI" if market == "kospi_equity" else "KOSDAQ"
            return 200, self._body(day, mkt)
        with tempfile.TemporaryDirectory() as tmp:
            out = self._collect(tmp, fetch)
            self.assertEqual(out["ok_dates"], ["20251002"])
            self.assertTrue(out["days"][0]["select"])
            path = Path(tmp) / "selected" / "20251002_top100.json"
            self.assertTrue(path.is_file())
            payload = json.loads(path.read_text(encoding="utf-8"))
            self.assertEqual(payload["markets_validated"], ["kospi_equity", "kosdaq_equity"])
            counted, union = self.m.counted_selected_files(out, Path(tmp) / "selected")
            self.assertEqual(len(counted), 1)
            self.assertEqual(union, {"005930", "035420"})

    def test_both_empty_is_not_holiday(self):
        empty = json.dumps({"OutBlock_1": []}).encode("utf-8")
        def fetch(day, market):
            return 200, empty
        with tempfile.TemporaryDirectory() as tmp:
            out = self._collect(tmp, fetch)
            self.assertEqual(out["days"][0]["status"], "empty_unconfirmed")
            self.assertFalse(out["days"][0]["holiday_confirmed"])
            self.assertFalse(out["days"][0]["select"])

    def test_no_auth_key_skipped_status(self):
        skipped = self.m.skipped_collect()
        self.assertFalse(skipped["ran"])
        self.assertEqual(skipped["status"], "skipped_no_auth_key")
        self.assertEqual(skipped["ok_dates"], [])
        counted, union = self.m.counted_selected_files(skipped, Path("."))
        self.assertEqual(counted, [])
        self.assertEqual(union, set())


if __name__ == "__main__":
    unittest.main()
