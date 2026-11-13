#!/usr/bin/env python3
"""2025-09-18·19·22 KRX Open API와 Yahoo chart 원응답을 연구 폴더에만 저장한다.

운영 일봉·원본·달력·계좌는 쓰지 않는다. 인증키는 출력·저장하지 않는다.
"""
from __future__ import annotations

import importlib.util
import json
import math
import time
import urllib.parse
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
PILOT_PATH = ROOT / "docs" / "research" / "historical-amount-top100-pilot.py"
RAW_ROOT = ROOT / "data" / "raw" / "research" / "sep19-five-20250918-22"
DOCS = ROOT / "docs" / "swing-path-compare-2025h1" / "sep19-five-fetch"
KST = timezone(timedelta(hours=9))
DAYS = ("20250918", "20250919", "20250922")
DAY_ISO = tuple(f"{d[0:4]}-{d[4:6]}-{d[6:8]}" for d in DAYS)
SYMBOLS = (
    ("067310", "kosdaq_equity", "067310.KQ"),
    ("104620", "kosdaq_equity", "104620.KQ"),
    ("128940", "kospi_equity", "128940.KS"),
    ("089890", "kosdaq_equity", "089890.KQ"),
    ("095610", "kosdaq_equity", "095610.KQ"),
)
YAHOO_PERIOD1 = 1757894400
YAHOO_PERIOD2 = 1758672000
YAHOO_INTERVAL = "1d"
PRICE_FIELDS = ("open", "high", "low", "close")
KRX_FIELDS = {
    "open": "TDD_OPNPRC",
    "high": "TDD_HGPRC",
    "low": "TDD_LWPRC",
    "close": "TDD_CLSPRC",
    "volume": "ACC_TRDVOL",
}


def load_pilot():
    spec = importlib.util.spec_from_file_location("hist_top100_pilot", PILOT_PATH)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


def parse_number(value: Any) -> float | None:
    if value is None or isinstance(value, bool):
        return None
    if isinstance(value, (int, float)):
        number = float(value)
        return number if math.isfinite(number) else None
    text = str(value).replace(",", "").strip()
    if text == "":
        return None
    try:
        number = float(text)
    except ValueError:
        return None
    return number if math.isfinite(number) else None


def prices_valid(values: dict[str, float | None]) -> bool:
    nums = [values[name] for name in PRICE_FIELDS]
    if any(n is None or n <= 0 for n in nums):
        return False
    open_, high, low, close = nums
    return low <= min(open_, close) and high >= max(open_, close) and high >= low


def volume_state(present: bool, number: float | None) -> str:
    if not present:
        return "missing"
    if number is None:
        return "bad"
    if number == 0:
        return "zero"
    if number > 0:
        return "positive"
    return "bad"


def classify_krx_row(row: dict[str, Any] | None) -> dict[str, Any]:
    blank = {name: None for name in (*PRICE_FIELDS, "volume")}
    if row is None:
        return {"status": "no_row", "volume_state": "missing", **blank}
    values: dict[str, float | None] = {}
    for name, key in KRX_FIELDS.items():
        if key not in row or str(row.get(key)).strip() == "":
            values[name] = None
            continue
        values[name] = parse_number(row.get(key))
    vol_present = "ACC_TRDVOL" in row and str(row.get("ACC_TRDVOL")).strip() != ""
    vstate = volume_state(vol_present, values["volume"])
    status = "valid_ohlcv" if prices_valid(values) and vstate in {"zero", "positive"} else "bad_price"
    if prices_valid(values) and vstate == "missing":
        status = "bad_price"
    return {"status": status, "volume_state": vstate, **values}


def rows_by_code(block: list[dict[str, Any]]) -> tuple[dict[str, dict[str, Any]], set[str]]:
    found: dict[str, dict[str, Any]] = {}
    dups: set[str] = set()
    for row in block:
        code = str(row.get("ISU_CD") or "").strip()
        if code in found:
            dups.add(code)
        found[code] = row
    return found, dups


def classify_krx_body(body: bytes, expected_date: str, code: str, pilot: Any) -> dict[str, Any]:
    parsed = pilot.validate_openapi_body(body, expected_date)
    kind = parsed["kind"]
    if kind == "auth":
        return {"status": "request_failed", "kind": kind}
    if kind != "ok":
        status = "no_row" if kind == "empty" else "unreadable"
        return {"status": status, "kind": kind, "volume_state": "missing"}
    found, dups = rows_by_code(parsed["block"])
    if code in dups:
        return {"status": "unreadable", "kind": "duplicate_code"}
    row = classify_krx_row(found.get(code))
    row["kind"] = "ok"
    return row


def kst_day(timestamp: int) -> str:
    return datetime.fromtimestamp(int(timestamp), KST).strftime("%Y-%m-%d")


def _at(series: Any, index: int) -> tuple[Any, bool]:
    if not isinstance(series, list) or index >= len(series):
        return None, False
    return series[index], True


def classify_yahoo_payload(payload: Any, days: tuple[str, ...] = DAY_ISO) -> dict[str, Any]:
    empty = {day: {"status": "unreadable", "volume_state": "missing"} for day in days}
    if not isinstance(payload, dict):
        return {"status": "unreadable", "days": empty}
    chart = payload.get("chart")
    if not isinstance(chart, dict):
        return {"status": "unreadable", "days": empty}
    result = chart.get("result")
    if not isinstance(result, list) or not result or not isinstance(result[0], dict):
        return {"status": "unreadable", "days": empty}
    result = result[0]
    timestamps = result.get("timestamp")
    indicators = result.get("indicators") if isinstance(result.get("indicators"), dict) else {}
    quotes = indicators.get("quote")
    quote = quotes[0] if isinstance(quotes, list) and quotes else None
    if not isinstance(timestamps, list) or not isinstance(quote, dict):
        return {"status": "unreadable", "days": empty}
    adj_block = indicators.get("adjclose")
    adj = adj_block[0].get("adjclose") if isinstance(adj_block, list) and adj_block and isinstance(adj_block[0], dict) else None
    by_day: dict[str, list[int]] = {}
    for index, ts in enumerate(timestamps):
        if isinstance(ts, bool) or not isinstance(ts, (int, float)):
            continue
        by_day.setdefault(kst_day(int(ts)), []).append(index)
    days_out: dict[str, dict[str, Any]] = {}
    for day in days:
        idxs = by_day.get(day) or []
        if not idxs:
            days_out[day] = {"status": "no_date", "volume_state": "missing", "timestamp": None, **{n: None for n in (*PRICE_FIELDS, "volume", "adjclose")}, "adjclose_state": "missing"}
            continue
        if len(idxs) != 1:
            days_out[day] = {"status": "unreadable", "timestamp_count": len(idxs)}
            continue
        index = idxs[0]
        values: dict[str, float | None] = {}
        price_missing = False
        for name in PRICE_FIELDS:
            raw, present = _at(quote.get(name), index)
            if not present or raw is None:
                price_missing = True
                values[name] = None
            else:
                values[name] = parse_number(raw)
                price_missing = price_missing or values[name] is None
        vol_raw, vol_present = _at(quote.get("volume"), index)
        if not vol_present or vol_raw is None:
            vol_num, vstate = None, "missing"
        else:
            vol_num = parse_number(vol_raw)
            vstate = volume_state(True, vol_num)
        adj_raw, adj_present = _at(adj, index)
        if not adj_present:
            adj_num, adj_state = None, "missing"
        elif adj_raw is None:
            adj_num, adj_state = None, "null"
        else:
            adj_num = parse_number(adj_raw)
            adj_state = "number" if adj_num is not None else "bad"
        status = "valid_ohlcv" if (not price_missing and prices_valid(values) and vstate in {"zero", "positive"}) else "bad_price"
        days_out[day] = {
            "status": status,
            "timestamp": int(timestamps[index]),
            "volume_state": vstate,
            "adjclose": adj_num,
            "adjclose_state": adj_state,
            **values,
            "volume": vol_num if vstate in {"zero", "positive"} else None,
        }
    return {"status": "ok", "days": days_out}


def classify_yahoo_response(http_status: int, body: bytes, days: tuple[str, ...] = DAY_ISO) -> dict[str, Any]:
    failed = {day: {"status": "request_failed", "volume_state": "missing"} for day in days}
    if http_status != 200:
        return {"status": "request_failed", "http_status": http_status, "days": failed}
    try:
        payload = json.loads(body)
    except json.JSONDecodeError:
        return {"status": "unreadable", "http_status": http_status, "days": {day: {"status": "unreadable"} for day in days}}
    parsed = classify_yahoo_payload(payload, days)
    parsed["http_status"] = http_status
    return parsed


def compare_day(krx: dict[str, Any], yahoo: dict[str, Any]) -> dict[str, Any]:
    both = krx.get("status") == "valid_ohlcv" and yahoo.get("status") == "valid_ohlcv"
    fields = {}
    for name in (*PRICE_FIELDS, "volume"):
        if not both:
            fields[name] = "not_compared"
            continue
        left, right = krx.get(name), yahoo.get(name)
        if left is None or right is None:
            fields[name] = "missing_side"
        else:
            fields[name] = "equal" if float(left) == float(right) else "differ"
    adj_vs_close = "not_compared"
    if yahoo.get("adjclose_state") == "null":
        adj_vs_close = "adjclose_null"
    elif yahoo.get("status") == "valid_ohlcv" and yahoo.get("adjclose") is not None and yahoo.get("close") is not None:
        adj_vs_close = "equal" if float(yahoo["adjclose"]) == float(yahoo["close"]) else "differ"
    return {"fields": fields, "yahoo_adjclose_vs_close": adj_vs_close, "price_basis": "미확정"}


def _rel(path: Path) -> str:
    return str(path.relative_to(ROOT)).replace("\\", "/")


def _inside_raw(path: Path, raw_root: Path = RAW_ROOT) -> None:
    resolved = path.resolve()
    root = raw_root.resolve()
    if resolved != root and root not in resolved.parents:
        raise SystemExit("refusing write outside research raw")


def yahoo_url(symbol: str) -> str:
    query = urllib.parse.urlencode({
        "period1": YAHOO_PERIOD1,
        "period2": YAHOO_PERIOD2,
        "interval": YAHOO_INTERVAL,
    })
    return "https://query1.finance.yahoo.com/v8/finance/chart/" + urllib.parse.quote(symbol) + "?" + query


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def fetch(
    sleep_s: float = 1.2,
    *,
    raw_root: Path | None = None,
    docs: Path | None = None,
    pilot: Any = None,
) -> dict[str, Any]:
    raw_root = RAW_ROOT if raw_root is None else raw_root
    docs = DOCS if docs is None else docs
    if raw_root.exists() or (docs / "manifest.json").exists() or (docs / "comparison.json").exists():
        raise SystemExit("원본 저장 폴더 또는 manifest.json·comparison.json이 이미 있어 다시 받지 않습니다.")
    pilot = load_pilot() if pilot is None else pilot
    auth = pilot.load_auth_key()
    krx_dir = raw_root / "krx"
    yahoo_dir = raw_root / "yahoo"
    _inside_raw(krx_dir, raw_root)
    _inside_raw(yahoo_dir, raw_root)
    krx_dir.mkdir(parents=True, exist_ok=True)
    yahoo_dir.mkdir(parents=True, exist_ok=True)
    summary: dict[str, Any] = {"requests": 0, "retries": 0, "errors": [], "bytes": 0}
    started = pilot.now_kst()
    krx_files = []
    krx_rows: dict[tuple[str, str], dict[str, Any]] = {}
    if not auth:
        for day in DAYS:
            for market in ("kospi_equity", "kosdaq_equity"):
                krx_files.append({"date": day, "market": market, "status": "request_failed", "kind": "no_auth_key"})
                for code, need, _symbol in SYMBOLS:
                    if need == market:
                        krx_rows[(code, day)] = {"status": "request_failed", "kind": "no_auth_key", "volume_state": "missing"}
    else:
        stopped = False
        for day in DAYS:
            for market in ("kospi_equity", "kosdaq_equity"):
                dest = krx_dir / f"{day}_{market}.json"
                if stopped:
                    krx_files.append({"date": day, "market": market, "status": "request_failed", "kind": "not_requested_after_auth"})
                    for code, need, _symbol in SYMBOLS:
                        if need == market:
                            krx_rows[(code, day)] = {"status": "request_failed", "kind": "not_requested_after_auth", "volume_state": "missing"}
                    continue
                rec = pilot.resolve_market_response(
                    dest=dest,
                    expected_date=day,
                    market=market,
                    auth_key=auth,
                    summary=summary,
                    sleep_s=sleep_s,
                )
                rec.pop("block", None)
                file_rec = {
                    "date": day,
                    "market": market,
                    "path": _rel(dest) if dest.is_file() else None,
                    "http_status": rec.get("http_status"),
                    "kind": rec.get("kind"),
                    "bytes": rec.get("bytes"),
                    "sha256": rec.get("sha256"),
                    "n": rec.get("n"),
                    "found_dates": rec.get("found_dates"),
                }
                if rec.get("kind") == "auth":
                    stopped = True
                    file_rec["status"] = "request_failed"
                    head = rec.get("head")
                    if isinstance(head, str):
                        file_rec["head"] = pilot.redact_secrets(head, extra=auth)[:160]
                elif dest.is_file() and rec.get("kind") == "ok":
                    file_rec["status"] = "saved"
                    body = dest.read_bytes()
                    file_rec["bytes"] = len(body)
                    file_rec["sha256"] = pilot.sha256_bytes(body)
                else:
                    file_rec["status"] = "request_failed" if rec.get("kind") in {"http", "auth", "missing"} else "unreadable"
                krx_files.append(file_rec)
                for code, need, _symbol in SYMBOLS:
                    if need != market:
                        continue
                    if dest.is_file() and rec.get("kind") == "ok":
                        krx_rows[(code, day)] = classify_krx_body(dest.read_bytes(), day, code, pilot)
                    elif rec.get("kind") == "empty" and dest.is_file():
                        krx_rows[(code, day)] = classify_krx_body(dest.read_bytes(), day, code, pilot)
                    else:
                        status = "unreadable" if file_rec["status"] == "unreadable" else "request_failed"
                        krx_rows[(code, day)] = {"status": status, "kind": rec.get("kind"), "volume_state": "missing"}
    yahoo_files = []
    yahoo_rows: dict[tuple[str, str], dict[str, Any]] = {}
    for code, _market, symbol in SYMBOLS:
        url = yahoo_url(symbol)
        status, _headers, body = pilot.http_get(url, {"Accept": "application/json"})
        dest = yahoo_dir / f"{symbol}_1d.json"
        _inside_raw(dest, raw_root)
        dest.write_bytes(body)
        parsed = classify_yahoo_response(status, body)
        yahoo_files.append({
            "symbol": symbol,
            "url": url,
            "http_status": status,
            "path": _rel(dest),
            "bytes": len(body),
            "sha256": pilot.sha256_bytes(body),
            "status": parsed["status"],
            "fetched_at_kst": pilot.now_kst(),
        })
        for day in DAY_ISO:
            yahoo_rows[(symbol, day)] = parsed["days"][day]
        time.sleep(sleep_s)
    comparison_symbols = []
    for code, market, symbol in SYMBOLS:
        day_rows = []
        for compact, iso in zip(DAYS, DAY_ISO):
            krx = krx_rows[(code, compact)]
            yahoo = yahoo_rows[(symbol, iso)]
            day_rows.append({
                "day": iso,
                "krx": krx,
                "yahoo": yahoo,
                "compare": compare_day(krx, yahoo),
            })
        comparison_symbols.append({"code": code, "market": market, "symbol": symbol, "days": day_rows})
    manifest = {
        "schema": 1,
        "kind": "sep19_five_fetch_v1",
        "timezone": "Asia/Seoul",
        "started_at_kst": started,
        "finished_at_kst": pilot.now_kst(),
        "current_supply_only": True,
        "does_not_prove_past_response": True,
        "symbols": [s[2] for s in SYMBOLS],
        "days": list(DAY_ISO),
        "auth_key_recorded": False,
        "raw_committed": False,
        "raw_dir": _rel(RAW_ROOT),
        "raw_not_in_git": "KRX Open API 이용약관은 제3자 제공을 금지한다. Yahoo 원응답도 이 폴더에만 둔다. /data/raw/ 는 Git 무시.",
        "reverify": "로컬 파일 sha256이 이 manifest와 같은지 본다. 저장소 클론만으로는 원본 바이트가 없다.",
        "krx": {
            "source": "https://data-dbg.krx.co.kr",
            "paths": {"kospi_equity": "/svc/apis/sto/stk_bydd_trd", "kosdaq_equity": "/svc/apis/sto/ksq_bydd_trd"},
            "query": "basDd=요청일",
            "auth_key_present": bool(auth),
            "files": krx_files,
        },
        "yahoo": {
            "source": "https://query1.finance.yahoo.com/v8/finance/chart/",
            "period1": YAHOO_PERIOD1,
            "period2": YAHOO_PERIOD2,
            "period1_utc": "2025-09-15T00:00:00+00:00",
            "period2_utc": "2025-09-24T00:00:00+00:00",
            "interval": YAHOO_INTERVAL,
            "files": yahoo_files,
        },
    }
    comparison = {
        "schema": 1,
        "kind": "sep19_five_compare_v1",
        "timezone": "Asia/Seoul",
        "finished_at_kst": manifest["finished_at_kst"],
        "price_basis": "미확정",
        "current_supply_only": True,
        "symbols": comparison_symbols,
    }
    write_json(docs / "manifest.json", manifest)
    write_json(docs / "comparison.json", comparison)
    return {"manifest": manifest, "comparison": comparison}


if __name__ == "__main__":
    fetch()
