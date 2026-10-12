import importlib.util
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


if __name__ == "__main__":
    unittest.main()
