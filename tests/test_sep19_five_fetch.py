import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOD = ROOT / "docs" / "research" / "sep19_five_fetch.py"


def load():
    spec = importlib.util.spec_from_file_location("sep19_five_fetch", MOD)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class Sep19FiveFetchTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load()

    def test_krx_row_states(self):
        m = self.m
        valid = m.classify_krx_row({
            "TDD_OPNPRC": "11,890", "TDD_HGPRC": "12,000", "TDD_LWPRC": "11,800",
            "TDD_CLSPRC": "11,950", "ACC_TRDVOL": "1,000",
        })
        self.assertEqual(valid["status"], "valid_ohlcv")
        self.assertEqual(valid["open"], 11890)
        self.assertEqual(valid["volume_state"], "positive")
        zero = m.classify_krx_row({
            "TDD_OPNPRC": "10", "TDD_HGPRC": "10", "TDD_LWPRC": "10",
            "TDD_CLSPRC": "10", "ACC_TRDVOL": "0",
        })
        self.assertEqual(zero["status"], "valid_ohlcv")
        self.assertEqual(zero["volume_state"], "zero")
        missing_vol = m.classify_krx_row({
            "TDD_OPNPRC": "10", "TDD_HGPRC": "10", "TDD_LWPRC": "10", "TDD_CLSPRC": "10",
        })
        self.assertEqual(missing_vol["status"], "bad_price")
        self.assertEqual(missing_vol["volume_state"], "missing")
        null_price = m.classify_krx_row({
            "TDD_OPNPRC": None, "TDD_HGPRC": "10", "TDD_LWPRC": "9",
            "TDD_CLSPRC": "10", "ACC_TRDVOL": "0",
        })
        self.assertEqual(null_price["status"], "bad_price")
        self.assertEqual(null_price["volume_state"], "zero")
        self.assertEqual(m.classify_krx_row(None)["status"], "no_row")
        bad_order = m.classify_krx_row({
            "TDD_OPNPRC": "10", "TDD_HGPRC": "9", "TDD_LWPRC": "8",
            "TDD_CLSPRC": "10", "ACC_TRDVOL": "1",
        })
        self.assertEqual(bad_order["status"], "bad_price")

    def test_yahoo_null_bar_is_not_missing_date(self):
        m = self.m
        ts = 1758240000
        payload = {"chart": {"result": [{
            "timestamp": [ts],
            "indicators": {
                "quote": [{"open": [None], "high": [None], "low": [None], "close": [None], "volume": [None]}],
                "adjclose": [{"adjclose": [None]}],
            },
        }]}}
        parsed = m.classify_yahoo_payload(payload)
        day = parsed["days"]["2025-09-19"]
        self.assertEqual(day["status"], "bad_price")
        self.assertEqual(day["timestamp"], ts)
        self.assertEqual(parsed["days"]["2025-09-18"]["status"], "no_date")
        self.assertEqual(day["volume_state"], "missing")
        self.assertEqual(day["adjclose_state"], "null")

    def test_yahoo_valid_keeps_adjclose_apart_and_basis_open(self):
        m = self.m
        ts = 1758153600
        payload = {"chart": {"result": [{
            "timestamp": [ts],
            "indicators": {
                "quote": [{"open": [10], "high": [12], "low": [9], "close": [11], "volume": [0]}],
                "adjclose": [{"adjclose": [10.5]}],
            },
        }]}}
        day = m.classify_yahoo_payload(payload)["days"]["2025-09-18"]
        self.assertEqual(day["status"], "valid_ohlcv")
        self.assertEqual(day["volume_state"], "zero")
        self.assertEqual(day["close"], 11)
        self.assertEqual(day["adjclose"], 10.5)
        krx = {"status": "valid_ohlcv", "open": 11, "high": 12, "low": 9, "close": 11, "volume": 0}
        compared = m.compare_day(krx, day)
        self.assertEqual(compared["price_basis"], "미확정")
        self.assertEqual(compared["yahoo_adjclose_vs_close"], "differ")
        self.assertEqual(compared["fields"]["open"], "differ")
        self.assertEqual(compared["fields"]["close"], "equal")
        failed = m.classify_yahoo_response(401, b"no")
        self.assertEqual(failed["days"]["2025-09-19"]["status"], "request_failed")
        broken = m.classify_yahoo_response(200, b"{")
        self.assertEqual(broken["status"], "unreadable")

    def test_rerun_stops_before_auth_network_or_write(self):
        m = self.m
        calls = {"auth": 0, "http": 0, "resolve": 0}

        class Pilot:
            def load_auth_key(self):
                calls["auth"] += 1
                raise AssertionError("auth")

            def http_get(self, *_args, **_kwargs):
                calls["http"] += 1
                raise AssertionError("http")

            def resolve_market_response(self, **_kwargs):
                calls["resolve"] += 1
                raise AssertionError("resolve")

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            raw = root / "raw"
            docs = root / "docs"
            raw.mkdir()
            docs.mkdir()
            original = raw / "kept.json"
            manifest = docs / "manifest.json"
            comparison = docs / "comparison.json"
            original.write_bytes(b"original-bytes")
            manifest.write_bytes(b"manifest-bytes")
            comparison.write_bytes(b"comparison-bytes")
            with self.assertRaises(SystemExit):
                m.fetch(raw_root=raw, docs=docs, pilot=Pilot())
            self.assertEqual(calls, {"auth": 0, "http": 0, "resolve": 0})
            self.assertEqual(original.read_bytes(), b"original-bytes")
            self.assertEqual(manifest.read_bytes(), b"manifest-bytes")
            self.assertEqual(comparison.read_bytes(), b"comparison-bytes")


if __name__ == "__main__":
    unittest.main()
