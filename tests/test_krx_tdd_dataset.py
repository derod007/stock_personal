import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOD = ROOT / "docs" / "research" / "krx_tdd_dataset.py"


def load():
    spec = importlib.util.spec_from_file_location("krx_tdd_dataset", MOD)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class KrxTddDatasetTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load()

    def test_status_keeps_zero_price_and_no_row(self):
        zero = {"ohlc": "ohl_zero_close_positive", "volume": "zero", "normal_ohlc": False, "normal_ohlcv": False}
        self.assertEqual(self.m.dataset_status(zero, True), "ohl_zero")
        self.assertEqual(self.m.dataset_status(None, False), "no_row")
        full = {"ohlc": "normal_ohlc", "volume": "positive", "normal_ohlc": True, "normal_ohlcv": True}
        self.assertEqual(self.m.dataset_status(full, True), "normal_ohlcv")
        vol0 = {"ohlc": "normal_ohlc", "volume": "zero", "normal_ohlc": True, "normal_ohlcv": False}
        self.assertEqual(self.m.dataset_status(vol0, True), "normal_ohlc_not_ohlcv")

    def test_transfer_link_only_before_confirmed_date(self):
        spec = {"symbol": "022100.KS", "code": "022100", "suffix": "kospi_equity"}
        kosdaq = {"MKT_NM": "KOSDAQ", "class": {"ohlc": "normal_ohlc"}, "kind": "normal_ohlcv"}
        tables = {"kospi_equity": {}, "kosdaq_equity": {"022100": kosdaq}}
        rec, lookup = self.m.pick_row(spec, tables, "2023-12-28", link=True)
        self.assertEqual(lookup, "linked_prior_market")
        self.assertIs(rec, kosdaq)
        rec2, lookup2 = self.m.pick_row(spec, tables, "2024-01-02", link=True)
        self.assertEqual(lookup2, "missing")
        self.assertIsNone(rec2)
        rec3, lookup3 = self.m.pick_row(spec, tables, "2023-12-28", link=False)
        self.assertEqual(lookup3, "missing")
        self.assertIsNone(rec3)

    def test_no_ratio_adjust_on_confirmed_attach(self):
        rec = self.m.attach_confirmed({
            "symbol": "005930.KS", "from": "2024-01-02", "to": "2024-01-03", "code": "005930",
        })
        self.assertEqual(rec["corporate_action"], "미확정")
        self.assertFalse(rec["ratio_adjusted"])
        self.assertFalse(rec["excluded_by_rule"])

    def test_price_basis_and_three_confirmed_transfers(self):
        self.assertEqual(self.m.PRICE_BASIS, "KRX TDD 원본, 수정 여부 미확정")
        self.assertEqual(len(self.m.TRANSFERS), 3)
        self.assertTrue(all(v["confirmed"] for v in self.m.TRANSFERS.values()))
        self.assertEqual(self.m.TRANSFERS["022100.KS"]["code"], "022100")
        self.assertEqual(self.m.TRANSFERS["034230.KS"]["code"], "034230")
        self.assertEqual(self.m.TRANSFERS["066970.KS"]["code"], "066970")


if __name__ == "__main__":
    unittest.main()
