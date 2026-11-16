import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOD_PATH = ROOT / "docs" / "research" / "index_daily_access.py"


def load_mod():
    spec = importlib.util.spec_from_file_location("index_daily_access", MOD_PATH)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class IndexDailyAccessTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = load_mod()

    def test_missing_key_makes_no_request(self):
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "index-daily"
            calls = []

            def http_get(url, headers):
                calls.append(url)
                return 200, {}, b"{}"

            def http_post(url, data, headers):
                calls.append(url)
                return 200, b"{}"

            result = self.m.run_access(out, "", http_get, http_post)
            self.assertEqual(result["status"], "skipped_no_auth_key")
            self.assertEqual(result["requests"], 0)
            self.assertEqual(calls, [])
            self.assertFalse(result["bars_written"])

    def test_unauthorized_index_api_stores_no_prices_or_key(self):
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "index-daily"
            seen = []

            def http_get(url, headers):
                seen.append(url)
                if "MDCSTAT00301" in url:
                    return 200, {}, "<title>에러페이지</title>".encode()
                body = b'{"respMsg":"Unauthorized SECRET-KEY","respCode":"401"}'
                return 401, {}, body

            def http_post(url, data, headers):
                seen.append(url)
                return 400, b"LOGOUT"

            result = self.m.run_access(out, "SECRET-KEY", http_get, http_post)
            self.assertEqual(result["status"], "blocked")
            self.assertEqual(result["requests"], 4)
            self.assertFalse(result["bars_written"])
            self.assertEqual(len(list(out.iterdir())), 1)
            text = (out / "access.json").read_text(encoding="utf-8")
            self.assertNotIn("SECRET-KEY", text)
            self.assertNotIn("OutBlock_1", text)
            hosts = {url.split("/")[2] for url in seen}
            self.assertEqual(hosts, {"data-dbg.krx.co.kr", "data.krx.co.kr"})

    def test_existing_record_is_not_overwritten(self):
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "index-daily"
            out.mkdir()
            dest = out / "access.json"
            dest.write_text('{"status":"kept"}\n', encoding="utf-8")

            def http_get(url, headers):
                raise AssertionError(url)

            def http_post(url, data, headers):
                raise AssertionError(url)

            result = self.m.run_access(out, "SECRET-KEY", http_get, http_post)
            self.assertEqual(result["status"], "exists")
            self.assertEqual(result["requests"], 0)
            self.assertEqual(dest.read_text(encoding="utf-8"), '{"status":"kept"}\n')

    def test_operational_dirs_are_refused(self):
        for name in ("ohlcv", "cache"):
            with self.assertRaises(SystemExit):
                self.m.assert_research_dir(Path("data") / name / "index")

    def test_writer_does_not_replace_a_record_created_after_probe(self):
        with tempfile.TemporaryDirectory() as tmp:
            dest = Path(tmp) / "access.json"
            dest.write_text("kept", encoding="utf-8")
            with self.assertRaises(FileExistsError):
                self.m._write(dest, {"status": "new"})
            self.assertEqual(dest.read_text(encoding="utf-8"), "kept")

    def test_ok_response_is_not_saved_as_bars(self):
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "index-daily"

            def http_get(url, headers):
                if "MDCSTAT00301" in url:
                    return 200, {}, b"<html></html>"
                body = json.dumps({"OutBlock_1": [{"BAS_DD": "20241008", "IDX_NM": "코스피", "CLSPRC_IDX": "2600.00"}]}).encode()
                return 200, {}, body

            def http_post(url, data, headers):
                return 400, b"LOGOUT"

            result = self.m.run_access(out, "SECRET-KEY", http_get, http_post)
            self.assertEqual(result["status"], "openapi_ok_not_stored")
            self.assertFalse(result["bars_written"])
            self.assertNotIn("2600.00", (out / "access.json").read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
