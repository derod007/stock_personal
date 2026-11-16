#!/usr/bin/env python3
"""See whether official KOSPI and KOSDAQ daily index calls are allowed.

This probe writes no price bars. It checks Open API and the KRX data site only.
A 401 identifies an authentication failure, not its precise account-side cause.
"""
from __future__ import annotations

import hashlib
import json
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Callable

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "docs" / "index-daily-20240901-20261008"
OPENAPI = "https://data-dbg.krx.co.kr"
PROBE_DAY = "20241008"
RANGE_FROM = "2024-09-01"
RANGE_TO = "2026-10-08"
KST = timezone(timedelta(hours=9))
Get = Callable[[str, dict[str, str]], tuple[int, dict[str, str], bytes]]
Post = Callable[[str, dict[str, str], dict[str, str]], tuple[int, bytes]]


def now_kst() -> str:
    return datetime.now(KST).isoformat(timespec="seconds")


def assert_research_dir(out_dir: Path) -> None:
    parts = {part.lower() for part in out_dir.resolve().parts}
    if "ohlcv" in parts or "cache" in parts:
        raise SystemExit("refusing operational dir")


def _kind(status: int, body: bytes) -> str:
    if body.startswith(b"LOGOUT"):
        return "logout"
    if status in {401, 403} or b"Unauthorized" in body[:240]:
        return "auth"
    if status != 200:
        return "http"
    return "ok_http"


def _step(market: str, api_id: str, url: str, status: int, body: bytes) -> dict[str, Any]:
    return {
        "market": market,
        "api_id": api_id,
        "url": url,
        "http": status,
        "kind": _kind(status, body),
        "bytes": len(body),
        "sha256": hashlib.sha256(body).hexdigest(),
    }


def run_access(out_dir: Path, key: str, http_get: Get, http_post: Post) -> dict[str, Any]:
    """Write access.json once. Never stores the key or a price payload."""
    assert_research_dir(out_dir)
    dest = out_dir / "access.json"
    if dest.is_file():
        return {"status": "exists", "requests": 0, "bars_written": False}
    record: dict[str, Any] = {
        "schema": 1,
        "checked_at_kst": now_kst(),
        "range": {"from": RANGE_FROM, "to": RANGE_TO},
        "probe_day": PROBE_DAY,
        "requests": 0,
        "bars_written": False,
        "official": [],
        "public_site": None,
    }
    if not key.strip():
        record["status"] = "skipped_no_auth_key"
        _write(dest, record)
        return record
    blocked = False
    for market, api_id in (("KOSPI", "kospi_dd_trd"), ("KOSDAQ", "kosdaq_dd_trd")):
        url = f"{OPENAPI}/svc/apis/idx/{api_id}?basDd={PROBE_DAY}"
        status, _, body = http_get(url, {"Accept": "application/json", "AUTH_KEY": key})
        record["requests"] += 1
        step = _step(market, api_id, url, status, body)
        record["official"].append(step)
        if step["kind"] != "ok_http":
            blocked = True
    page_url = "https://data.krx.co.kr/contents/MDC/STAT/standard/MDCSTAT00301.jsp"
    page_status, _, page_body = http_get(page_url, {})
    record["requests"] += 1
    json_url = "https://data.krx.co.kr/comm/bldAttendant/getJsonData.cmd"
    json_status, json_body = http_post(
        json_url,
        {
            "bld": "dbms/MDC/STAT/standard/MDCSTAT00301",
            "locale": "ko_KR",
            "indIdx": "1",
            "indIdx2": "001",
            "strtDd": PROBE_DAY,
            "endDd": PROBE_DAY,
            "share": "1",
            "money": "1",
            "csvxls_isNo": "false",
        },
        {
            "Referer": page_url,
            "Origin": "https://data.krx.co.kr",
            "Accept": "application/json, text/javascript, */*; q=0.01",
        },
    )
    record["requests"] += 1
    record["public_site"] = {
        "page_url": page_url,
        "page_http": page_status,
        "page_bytes": len(page_body),
        "page_sha256": hashlib.sha256(page_body).hexdigest(),
        "json_url": json_url,
        "json_http": json_status,
        "json_kind": _kind(json_status, json_body),
        "json_bytes": len(json_body),
        "json_sha256": hashlib.sha256(json_body).hexdigest(),
    }
    record["status"] = "blocked" if blocked else "openapi_ok_not_stored"
    text = json.dumps(record, ensure_ascii=False)
    if key.strip() and key.strip() in text:
        raise SystemExit("refusing to write the auth key")
    _write(dest, record)
    return record


def _write(dest: Path, record: dict[str, Any]) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    with dest.open("x", encoding="utf-8") as handle:
        handle.write(json.dumps(record, ensure_ascii=False, indent=2) + "\n")


def main() -> None:
    import importlib.util

    spec = importlib.util.spec_from_file_location(
        "historical_amount_top100_pilot",
        ROOT / "docs" / "research" / "historical-amount-top100-pilot.py",
    )
    assert spec is not None and spec.loader is not None
    pilot = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(pilot)
    result = run_access(OUT, pilot.load_auth_key(), pilot.http_get, pilot.http_post)
    print(json.dumps({"status": result["status"], "requests": result["requests"], "bars_written": result["bars_written"]}))


if __name__ == "__main__":
    main()
