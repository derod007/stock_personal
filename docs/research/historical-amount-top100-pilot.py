#!/usr/bin/env python3
"""2025-10 KR 거래대금 TOP100 복원 파일럿 (읽기 전용).

운영 캐시·예약·계좌·전략을 바꾸지 않습니다.
종가×거래량으로 거래대금을 만들지 않습니다.
인증키가 없으면 수집을 실행하지 않고 skipped_no_auth_key로 표시합니다.
KOSPI·KOSDAQ가 모두 정상 검증된 날짜만 TOP100을 저장합니다.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
RAW_ROOT = ROOT / "data" / "raw" / "research" / "historical-amount-top100-202510"
KEY_FILE = RAW_ROOT / ".krx-auth-key"
DOCS = ROOT / "docs" / "historical-top100-pilot"
LEADERS_PHP = ROOT / "src" / "KrAmountLeadersClient.php"
KST = timezone(timedelta(hours=9))
CTX = ssl.create_default_context()
UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
)
SAMPLE_KEY_PUBLIC = "74D1B99DFBF345BBA3FB4476510A4BED4C78D13A"
OPENAPI = "https://data-dbg.krx.co.kr"
SAMPLE_PATHS = {
    "kospi_equity": "/svc/sample/apis/sto/stk_bydd_trd",
    "kosdaq_equity": "/svc/sample/apis/sto/ksq_bydd_trd",
    "konex_equity": "/svc/sample/apis/sto/knx_bydd_trd",
    "etf": "/svc/sample/apis/etp/etf_bydd_trd",
    "etn": "/svc/sample/apis/etp/etn_bydd_trd",
}
PROD_PATHS = {
    "kospi_equity": "/svc/apis/sto/stk_bydd_trd",
    "kosdaq_equity": "/svc/apis/sto/ksq_bydd_trd",
    "etf": "/svc/apis/etp/etf_bydd_trd",
    "etn": "/svc/apis/etp/etn_bydd_trd",
}
EQUITY_MARKETS = ("kospi_equity", "kosdaq_equity")
AUTH_HTTP = {401, 403}
REQUIRED_ROW_FIELDS = ("BAS_DD", "ISU_CD", "ISU_NM", "ACC_TRDVAL")
# 행정안전부 2025년 공휴일 후보. 빈 API 응답만으로 휴장이라고 확정하지 않는다.
HOLIDAYS_2025_10 = {"20251003", "20251005", "20251006", "20251007", "20251008", "20251009"}
CALENDAR_FILE = ROOT / "docs" / "historical-top100-20251008-20261008" / "trading-calendar.json"
PILOT_EVIDENCE = ROOT / "docs" / "historical-top100-pilot" / "evidence.json"
RANGE_FROM = date(2025, 10, 8)
RANGE_TO = date(2026, 10, 8)
RANGE_RAW_ROOT = ROOT / "data" / "raw" / "research" / "historical-amount-top100-20251008-20261008"
RANGE_DOCS = ROOT / "docs" / "historical-top100-20251008-20261008"
ETF_PREFIXES = [
    "KODEX", "TIGER", "KOSEF", "KINDEX", "KOACT", "ACE", "SOL ", "PLUS",
    "RISE", "HANARO", "ARIRANG", "KBSTAR", "TIMEFOLIO", "WON ", "1Q ",
    "KIWOOM", "TREX", "FOCUS", "UNICORN", "HK ", "MASTER", "TIME ",
]


def now_kst() -> str:
    return datetime.now(KST).strftime("%Y-%m-%d %H:%M:%S")


def load_auth_key() -> str:
    env = (os.environ.get("KRX_AUTH_KEY") or os.environ.get("KRX_API_KEY") or "").strip()
    if env:
        return env
    if KEY_FILE.is_file():
        return KEY_FILE.read_text(encoding="utf-8").strip()
    return ""


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str | None:
    if not path.is_file():
        return None
    return sha256_bytes(path.read_bytes())


def excluded_from_amount_rank(name: str) -> bool:
    """src/KrAmountLeadersClient.php excludedFromAmountRank 와 동일."""
    name = name.strip()
    if name == "":
        return False
    if re.search(r"스팩|기업인수목적|단일종목|ETN", name, re.I) is not None:
        return True
    upper = name.upper()
    for prefix in ETF_PREFIXES:
        if upper.startswith(prefix):
            return True
    for prefix in ("히어로즈", "마이티", "에셋플러스"):
        if name.startswith(prefix):
            return True
    return re.search(r"인버스|레버리지|커버드콜|(?<![A-Za-z])ETF(?![A-Za-z])", name) is not None


def exclusion_kind(name: str) -> str | None:
    if not excluded_from_amount_rank(name):
        return None
    if re.search(r"스팩|기업인수목적", name) is not None:
        return "spac"
    if re.search(r"ETN", name, re.I) is not None:
        return "etn"
    if "단일종목" in name:
        return "single_stock_leverage"
    if re.search(r"인버스|레버리지|커버드콜|(?<![A-Za-z])ETF(?![A-Za-z])", name) is not None:
        return "etf_or_leverage_name"
    return "etf_prefix"


def parse_amount_won(row: dict[str, Any]) -> float | None:
    raw = row.get("ACC_TRDVAL")
    if raw is None or raw == "":
        return None
    text = str(raw).replace(",", "").strip()
    try:
        value = float(text)
    except ValueError:
        return None
    if value <= 0:
        return None
    return value


def yahoo_symbol(code: str, market: str) -> str:
    suffix = ".KQ" if market.upper() == "KOSDAQ" else ".KS"
    return code + suffix


def assumed_trading_days(year: int = 2025, month: int = 10) -> list[str]:
    days = []
    d = date(year, month, 1)
    while d.month == month:
        key = d.strftime("%Y%m%d")
        if d.weekday() < 5 and key not in HOLIDAYS_2025_10:
            days.append(key)
        d += timedelta(days=1)
    return days


def ymd_compact(d: date) -> str:
    return d.strftime("%Y%m%d")


def parse_ymd(text: str) -> date:
    raw = str(text).replace("-", "").replace(".", "").strip()
    if len(raw) != 8 or not raw.isdigit():
        raise SystemExit("invalid date " + text)
    return date(int(raw[0:4]), int(raw[4:6]), int(raw[6:8]))


def load_trading_calendar(path: Path | None = None) -> dict[str, Any]:
    p = path or CALENDAR_FILE
    if not p.is_file():
        raise SystemExit("trading calendar missing: " + str(p))
    return json.loads(p.read_text(encoding="utf-8"))


def closed_weekdays(calendar: dict[str, Any] | None = None) -> dict[str, dict[str, Any]]:
    cal = calendar if calendar is not None else load_trading_calendar()
    closed = cal.get("closed_weekdays") or {}
    return {str(k): v for k, v in closed.items()}


def candidate_trading_days(
    start: date,
    end: date,
    calendar: dict[str, Any] | None = None,
) -> list[str]:
    """Weekdays in [start, end] minus documented exchange closures. Empty API is not a holiday."""
    if end < start:
        raise SystemExit("range end before start")
    closed = closed_weekdays(calendar)
    days: list[str] = []
    d = start
    while d <= end:
        key = ymd_compact(d)
        if d.weekday() < 5 and key not in closed:
            days.append(key)
        d += timedelta(days=1)
    return days


def oct2025_overlap_days(days: list[str]) -> list[str]:
    return [d for d in days if d.startswith("202510") and d in assumed_trading_days(2025, 10)]


def frozen_pilot_hashes(pilot: dict[str, Any]) -> tuple[dict[str, str], dict[str, str]]:
    selected: dict[str, str] = {}
    raw: dict[str, str] = {}
    for rec in pilot.get("selected_files") or []:
        name = rec.get("file")
        sha = rec.get("sha256")
        if name and sha:
            selected[str(name)] = str(sha)
    for day in (pilot.get("collect") or {}).get("days") or []:
        d = day.get("date")
        sel_sha = day.get("selected_sha256")
        sel_file = day.get("selected_file") or (f"{d}_top100.json" if d else None)
        if sel_file and sel_sha:
            selected[str(sel_file)] = str(sel_sha)
        for rec in day.get("files") or []:
            market = rec.get("market")
            sha = rec.get("sha256")
            if d and market and sha:
                raw[f"{d}_{market}.json"] = str(sha)
    return selected, raw


def copy_if_hash_matches(src: Path, dest: Path, expected: str) -> str:
    """Copy src to dest only when sha256 matches expected. Never writes src."""
    if not expected:
        return "missing_baseline"
    if not src.is_file():
        return "source_missing"
    got = sha256_file(src)
    if got != expected:
        return "source_mismatch"
    if dest.is_file():
        if sha256_file(dest) == expected:
            return "reused"
        return "dest_mismatch"
    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_bytes(src.read_bytes())
    if sha256_file(dest) != expected:
        return "copy_mismatch"
    return "copied"


def reuse_verified_oct(
    days: list[str],
    dest_raw: Path,
    dest_selected: Path,
    src_root: Path | None = None,
    pilot_path: Path | None = None,
) -> dict[str, Any]:
    """Hash-check Oct 2025 files from PR #73 and copy into the new research folder. Source is read-only."""
    src_root = src_root or RAW_ROOT
    pilot_path = pilot_path or PILOT_EVIDENCE
    if not pilot_path.is_file():
        raise SystemExit("pilot evidence.json missing")
    pilot = json.loads(pilot_path.read_text(encoding="utf-8"))
    expected_selected, expected_raw = frozen_pilot_hashes(pilot)
    overlap = oct2025_overlap_days(days)
    out: dict[str, Any] = {
        "source_raw": str(src_root / "raw"),
        "source_selected": str(src_root / "selected"),
        "overlap_days": overlap,
        "copied": [],
        "reused": [],
        "issues": [],
        "source_untouched": True,
    }
    src_raw = src_root / "raw"
    src_sel = src_root / "selected"
    before = {}
    for p in list(src_raw.glob("*")) + list(src_sel.glob("*")):
        if p.is_file():
            before[str(p)] = (p.stat().st_mtime_ns, p.stat().st_size, sha256_file(p))
    for day in overlap:
        for market in EQUITY_MARKETS:
            name = f"{day}_{market}.json"
            status = copy_if_hash_matches(src_raw / name, dest_raw / name, expected_raw.get(name, ""))
            rec = {"file": name, "kind": "raw", "status": status}
            if status in {"copied", "reused"}:
                out[status if status == "copied" else "reused"].append(rec)
            else:
                out["issues"].append(rec)
        sel_name = f"{day}_top100.json"
        status = copy_if_hash_matches(src_sel / sel_name, dest_selected / sel_name, expected_selected.get(sel_name, ""))
        rec = {"file": sel_name, "kind": "selected", "status": status}
        if status in {"copied", "reused"}:
            out[status if status == "copied" else "reused"].append(rec)
        else:
            out["issues"].append(rec)
    after = {}
    for p in list(src_raw.glob("*")) + list(src_sel.glob("*")):
        if p.is_file():
            after[str(p)] = (p.stat().st_mtime_ns, p.stat().st_size, sha256_file(p))
    if before != after:
        out["source_untouched"] = False
        out["issues"].append({"issue": "oct2025_source_changed"})
    return out


def rerank_from_raw_files(day: str, raw_dir: Path) -> dict[str, Any]:
    rows: list[dict[str, Any]] = []
    files: dict[str, str] = {}
    for market in EQUITY_MARKETS:
        path = raw_dir / f"{day}_{market}.json"
        body = path.read_bytes()
        parsed = validate_openapi_body(body, day)
        if not parsed["ok"]:
            raise SystemExit(f"independent rerank blocked {day} {market} {parsed['kind']}")
        files[path.name] = parsed["sha256"]
        rows.extend(rows_from_openapi_block(parsed["block"], product_source=f"krx_openapi_{market}"))
    selected = select_top100(rows)
    selected["date"] = day
    selected["source"] = "independent_rerank_from_raw"
    selected["markets_validated"] = list(EQUITY_MARKETS)
    selected["raw_files"] = files
    return selected


def top100_signature(payload: dict[str, Any]) -> list[tuple[str, float]]:
    return [(str(r.get("code")), float(r.get("amount_won"))) for r in payload.get("top100") or []]


def http_get(url: str, headers: dict[str, str], timeout: int = 60) -> tuple[int, dict[str, str], bytes]:
    req = urllib.request.Request(url, headers={"User-Agent": UA, **headers})
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=CTX) as resp:
            return resp.status, {k.lower(): v for k, v in resp.headers.items()}, resp.read()
    except urllib.error.HTTPError as e:
        body = e.read() if e.fp else b""
        hdrs = {k.lower(): v for k, v in (e.headers.items() if e.headers else [])}
        return e.code, hdrs, body


def http_post(url: str, data: dict[str, str], headers: dict[str, str], timeout: int = 60) -> tuple[int, bytes]:
    body = urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(
        url,
        data=body,
        headers={
            "User-Agent": UA,
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
            **headers,
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=CTX) as resp:
            return resp.status, resp.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read() if e.fp else b""


def redact_secrets(text: str, extra: str | None = None) -> str:
    out = text
    for secret in (SAMPLE_KEY_PUBLIC, extra, os.environ.get("KRX_AUTH_KEY"), os.environ.get("KRX_API_KEY")):
        if secret:
            out = out.replace(secret, "[redacted]")
    return out


def normalize_bas_dd(value: Any) -> str:
    return str(value or "").replace("-", "").replace(".", "").strip()


def classify_http_status(status: int, body: bytes = b"") -> str:
    if status in AUTH_HTTP:
        return "auth"
    head = body[:200].decode("utf-8", "replace")
    if "Unauthorized" in head and status in (400, 401, 403):
        return "auth"
    if status != 200:
        return "http"
    return "ok_http"


def validate_openapi_body(body: bytes, expected_date: str) -> dict[str, Any]:
    """HTTP 200 본문을 검사. 빈 목록은 empty이며 휴장이 아니다."""
    result: dict[str, Any] = {
        "ok": False,
        "kind": "structure",
        "n": 0,
        "block": [],
        "found_dates": [],
        "sha256": sha256_bytes(body),
        "bytes": len(body),
    }
    try:
        payload = json.loads(body)
    except json.JSONDecodeError:
        result["kind"] = "json"
        return result
    if not isinstance(payload, dict):
        result["kind"] = "structure"
        return result
    resp_code = str(payload.get("respCode") or "")
    resp_msg = str(payload.get("respMsg") or "")
    if resp_code in {"401", "403"} or "Unauthorized" in resp_msg:
        result["kind"] = "auth"
        return result
    block = payload.get("OutBlock_1")
    if not isinstance(block, list):
        result["kind"] = "structure"
        return result
    if block == []:
        result["kind"] = "empty"
        return result
    dates: set[str] = set()
    for row in block:
        if not isinstance(row, dict):
            result["kind"] = "structure"
            return result
        missing = [k for k in REQUIRED_ROW_FIELDS if k not in row]
        if missing:
            result["kind"] = "structure"
            result["missing_fields"] = missing
            return result
        dates.add(normalize_bas_dd(row.get("BAS_DD")))
    dates.discard("")
    result["found_dates"] = sorted(dates)
    result["n"] = len(block)
    result["block"] = block
    if dates != {expected_date}:
        result["kind"] = "date_mismatch"
        return result
    result["ok"] = True
    result["kind"] = "ok"
    return result


def decide_day_selection(market_results: dict[str, dict[str, Any]]) -> dict[str, Any]:
    """양쪽 시장이 모두 kind=ok 일 때만 선정."""
    kinds = []
    for market in EQUITY_MARKETS:
        rec = market_results.get(market) or {"ok": False, "kind": "missing"}
        kinds.append(str(rec.get("kind") or "missing"))
    select = all((market_results.get(m) or {}).get("ok") for m in EQUITY_MARKETS)
    unique_kinds = sorted(set(kinds))
    if select:
        status = "ok"
    elif unique_kinds == ["empty"]:
        status = "empty_unconfirmed"
    elif "auth" in unique_kinds:
        status = "auth"
    elif "date_mismatch" in unique_kinds:
        status = "date_mismatch"
    elif "json" in unique_kinds:
        status = "json"
    elif "corrupt_cache" in unique_kinds:
        status = "corrupt_cache"
    elif "http" in unique_kinds:
        status = "http"
    elif "missing" in unique_kinds or "structure" in unique_kinds:
        status = "structure"
    elif len(set(kinds)) > 1:
        status = "partial"
    else:
        status = "blocked"
    return {
        "select": select,
        "status": status,
        "market_kinds": {m: (market_results.get(m) or {}).get("kind", "missing") for m in EQUITY_MARKETS},
        "holiday_confirmed": False,
    }


def skipped_collect(reason: str = "KRX_AUTH_KEY / KRX_API_KEY 없음") -> dict[str, Any]:
    return {
        "ran": False,
        "status": "skipped_no_auth_key",
        "reason": reason,
        "requests": 0,
        "retries": 0,
        "errors": [],
        "days": [],
        "ok_dates": [],
        "blocked_dates": [],
        "selected_files_counted": [],
        "stale_selected_ignored": [],
    }


def compact_rows(block: list[dict[str, Any]]) -> list[dict[str, Any]]:
    out = []
    for row in block:
        amount = parse_amount_won(row)
        close = str(row.get("TDD_CLSPRC", "")).replace(",", "")
        volume = str(row.get("ACC_TRDVOL", "")).replace(",", "")
        approx = None
        try:
            if close and volume:
                approx = float(close) * float(volume)
        except ValueError:
            approx = None
        out.append({
            "code": row.get("ISU_CD"),
            "name": row.get("ISU_NM"),
            "market": row.get("MKT_NM"),
            "sector_part": row.get("SECT_TP_NM"),
            "acc_trdval": amount,
            "close_times_volume": approx,
            "amount_equals_close_times_volume": (
                amount is not None and approx is not None and abs(amount - approx) < 0.5
            ),
        })
    return out


def select_top100(rows: list[dict[str, Any]]) -> dict[str, Any]:
    """운영과 같이 이름 제외 후 거래대금 내림차순. 동률은 코드 오름차순으로만 고정한다."""
    before = []
    excluded = []
    dropped_no_amount = []
    seen: set[str] = set()
    for row in rows:
        code = str(row.get("code") or "")
        name = str(row.get("name") or "")
        market = str(row.get("market") or "")
        if market.upper() == "KONEX":
            excluded.append({"code": code, "name": name, "reason": "konex_out_of_scanner_scope"})
            continue
        amount = row.get("amount_won")
        if amount is None:
            dropped_no_amount.append({"code": code, "name": name})
            continue
        kind = exclusion_kind(name)
        item = {
            "code": code,
            "name": name,
            "market": market,
            "yahoo": yahoo_symbol(code, market),
            "amount_won": amount,
            "product_source": row.get("product_source", "unconfirmed"),
            "exclusion_kind": kind,
        }
        before.append(item)
        if kind:
            excluded.append({**item, "reason": kind})
    eligible = []
    for item in before:
        if item["exclusion_kind"]:
            continue
        if item["code"] in seen:
            continue
        seen.add(item["code"])
        eligible.append(item)
    eligible.sort(key=lambda r: (-r["amount_won"], r["code"]))
    ranked = []
    for i, item in enumerate(eligible[:100], start=1):
        ranked.append({**item, "rank": i})
    ties_at_cut = 0
    if len(eligible) > 100:
        cut = eligible[99]["amount_won"]
        ties_at_cut = sum(1 for r in eligible if r["amount_won"] == cut)
    return {
        "input_rows": len(rows),
        "after_market_filter": len(before) + len([e for e in excluded if e.get("reason") == "konex_out_of_scanner_scope"]),
        "excluded_count": len(excluded),
        "excluded": excluded,
        "no_amount_count": len(dropped_no_amount),
        "eligible_count": len(eligible),
        "top100": ranked,
        "ties_at_rank_100_amount": ties_at_cut,
        "unique_codes_in_top100": len({r["code"] for r in ranked}),
    }


def rows_from_openapi_block(block: list[dict[str, Any]], product_source: str) -> list[dict[str, Any]]:
    rows = []
    for raw in block:
        amount = parse_amount_won(raw)
        rows.append({
            "code": str(raw.get("ISU_CD") or ""),
            "name": str(raw.get("ISU_NM") or ""),
            "market": str(raw.get("MKT_NM") or ""),
            "amount_won": amount,
            "product_source": product_source,
        })
    return rows


def probe_access() -> dict[str, Any]:
    stats: dict[str, Any] = {
        "at": now_kst(),
        "steps": [],
        "sample_sets": {},
        "facts": [],
        "assumptions": [],
    }
    status, _, body = http_get(
        "https://data.krx.co.kr/contents/MDC/MDI/outerLoader/index.cmd?screenId=MDCSTAT015",
        {},
    )
    stats["steps"].append({
        "id": "krx_mdcstat015_page",
        "status": status,
        "bytes": len(body),
        "note": "전종목 시세 화면 HTML. 조회 JSON은 별도.",
    })
    status, body = http_post(
        "https://data.krx.co.kr/comm/bldAttendant/getJsonData.cmd",
        {
            "bld": "dbms/MDC/STAT/standard/MDCSTAT01501",
            "locale": "ko_KR",
            "mktId": "STK",
            "trdDd": "20251002",
            "share": "1",
            "money": "1",
            "csvxls_isNo": "false",
        },
        {
            "Referer": "https://data.krx.co.kr/contents/MDC/MDI/outerLoader/index.cmd?screenId=MDCSTAT015",
            "Origin": "https://data.krx.co.kr",
        },
    )
    stats["steps"].append({
        "id": "krx_getJsonData_mdcstat01501",
        "status": status,
        "bytes": len(body),
        "head": body[:80].decode("utf-8", "replace"),
        "note": "로그인 세션 없이 호출. LOGOUT이면 비회원 조회 불가.",
    })
    status, _, body = http_get(f"{OPENAPI}/svc/apis/sto/stk_bydd_trd?basDd=20251002", {"Accept": "application/json"})
    stats["steps"].append({
        "id": "openapi_prod_no_key",
        "status": status,
        "bytes": len(body),
        "head": body[:120].decode("utf-8", "replace"),
    })
    raw_dir = RAW_ROOT / "raw"
    raw_dir.mkdir(parents=True, exist_ok=True)
    for name, path in SAMPLE_PATHS.items():
        url = f"{OPENAPI}{path}?basDd=20251002"
        status, _, body = http_get(url, {"Accept": "application/json", "AUTH_KEY": SAMPLE_KEY_PUBLIC})
        rec: dict[str, Any] = {"url": url, "status": status, "bytes": len(body), "sha256": sha256_bytes(body)}
        try:
            payload = json.loads(body)
            block = payload.get("OutBlock_1") if isinstance(payload, dict) else None
            if isinstance(block, list):
                rec["n"] = len(block)
                rec["rows"] = compact_rows(block)
                dest = raw_dir / f"sample_{name}_20251002.json"
                dest.write_bytes(body)
                rec["saved"] = str(dest)
            else:
                rec["head"] = body[:180].decode("utf-8", "replace")
        except json.JSONDecodeError:
            rec["head"] = body[:180].decode("utf-8", "replace")
        stats["sample_sets"][name] = rec
        stats["steps"].append({"id": f"sample_{name}", "status": status, "bytes": len(body), "n": rec.get("n")})
        time.sleep(0.4)

    equity_n = [
        stats["sample_sets"].get("kospi_equity", {}).get("n"),
        stats["sample_sets"].get("kosdaq_equity", {}).get("n"),
    ]
    stats["facts"].extend([
        "운영 스캐너는 finance.daum.net accTradePrice 실시간 순위이며 날짜 파라미터가 없다.",
        "KRX Open API 샘플은 ACC_TRDVAL(원) 필드를 제공한다.",
        "정보데이터시스템 getJsonData는 이 환경에서 LOGOUT/400 이었다.",
        "Open API 본호출은 인증키 없이 401 Unauthorized Key 이었다.",
        "공개 샘플 키는 본호출에서 Unauthorized API Call 이었다.",
    ])
    if all(n == 10 for n in equity_n):
        stats["facts"].append("KOSPI·KOSDAQ 샘플 응답은 각 10행이며 전 종목이 아니다.")
    mismatches = []
    for name in ("kospi_equity", "kosdaq_equity"):
        for row in stats["sample_sets"].get(name, {}).get("rows") or []:
            if row.get("amount_equals_close_times_volume") is False:
                mismatches.append(row)
    stats["close_times_volume_mismatches_in_sample"] = mismatches[:8]
    stats["assumptions"].extend([
        "2025-10 거래일 후보는 주말·공휴일을 뺀 목록이다. 빈 응답만으로 휴장이라고 보지 않는다.",
        "Open API 주식 일별매매정보는 주권만 포함한다고 안내되어 있다. ETF/ETN은 증권상품 API가 따로 있다.",
        "ACC_TRDVAL이 정규장만인지, 시간외·NXT를 합친 것인지는 이번 명세서 전문을 받지 못해 단정하지 않는다.",
    ])
    return stats


def _get_with_retry(
    url: str,
    headers: dict[str, str],
    summary: dict[str, Any],
    tries: int = 3,
    sleep_s: float = 1.2,
) -> tuple[int, bytes]:
    last_status, last_body = 0, b""
    for i in range(tries):
        status, _, body = http_get(url, headers)
        last_status, last_body = status, body
        if status == 200:
            return status, body
        if classify_http_status(status, body) == "auth":
            summary["retries"] += 0
            return status, body
        summary["retries"] += 1
        if i + 1 < tries:
            time.sleep(max(sleep_s, 2 * (i + 1)))
    return last_status, last_body


def resolve_market_response(
    *,
    dest: Path,
    expected_date: str,
    market: str,
    auth_key: str | None,
    summary: dict[str, Any],
    sleep_s: float,
    fetch_market: Any = None,
) -> dict[str, Any]:
    """재개 시 기존 원본을 해시·구조·기준일로 검사하고, 통과한 것만 재사용한다."""
    rec: dict[str, Any] = {"market": market, "reuse": False, "ok": False, "kind": "missing"}
    cached = dest.is_file() and dest.stat().st_size > 0
    if cached:
        body = dest.read_bytes()
        parsed = validate_openapi_body(body, expected_date)
        rec.update({
            "sha256": parsed["sha256"],
            "bytes": parsed["bytes"],
            "n": parsed["n"],
            "found_dates": parsed.get("found_dates") or [],
        })
        if parsed["ok"]:
            rec.update({"ok": True, "kind": "ok", "reuse": True, "block": parsed["block"]})
            return rec
        rec["cache_rejected"] = parsed["kind"]
        if parsed["kind"] == "empty":
            rec.update({"ok": False, "kind": "empty", "reuse": True, "block": []})
            return rec
        rec["kind"] = "corrupt_cache" if parsed["kind"] in {"json", "structure"} else parsed["kind"]

    if fetch_market is None and not auth_key:
        rec["kind"] = rec.get("cache_rejected") or rec.get("kind") or "missing"
        return rec

    if fetch_market is not None:
        status, body = fetch_market(expected_date, market)
        summary["requests"] += 1
        summary["bytes"] += len(body)
    else:
        path = PROD_PATHS[market]
        status, body = _get_with_retry(
            f"{OPENAPI}{path}?basDd={expected_date}",
            {"Accept": "application/json", "AUTH_KEY": auth_key or ""},
            summary,
            sleep_s=sleep_s,
        )
        summary["requests"] += 1
        summary["bytes"] += len(body)
        time.sleep(sleep_s)

    http_kind = classify_http_status(status, body)
    rec["http_status"] = status
    rec["bytes"] = len(body)
    rec["sha256"] = sha256_bytes(body)
    rec["head"] = redact_secrets(body[:160].decode("utf-8", "replace"), extra=auth_key)
    if http_kind != "ok_http":
        rec["ok"] = False
        rec["kind"] = http_kind
        rec["reuse"] = False
        summary["errors"].append({
            "date": expected_date,
            "market": market,
            "kind": http_kind,
            "status": status,
            "head": rec["head"],
        })
        return rec

    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_bytes(body)
    parsed = validate_openapi_body(body, expected_date)
    rec.update({
        "ok": parsed["ok"],
        "kind": parsed["kind"],
        "n": parsed["n"],
        "found_dates": parsed.get("found_dates") or [],
        "reuse": False,
        "sha256": parsed["sha256"],
        "block": parsed.get("block") or [],
    })
    if not parsed["ok"]:
        summary["errors"].append({
            "date": expected_date,
            "market": market,
            "kind": parsed["kind"],
            "found_dates": parsed.get("found_dates") or [],
        })
    return rec


def collect_month(
    auth_key: str,
    sleep_s: float = 1.2,
    *,
    days: list[str] | None = None,
    raw_dir: Path | None = None,
    selected_dir: Path | None = None,
    fetch_market: Any = None,
) -> dict[str, Any]:
    raw_dir = raw_dir or (RAW_ROOT / "raw")
    selected_dir = selected_dir or (RAW_ROOT / "selected")
    raw_dir.mkdir(parents=True, exist_ok=True)
    selected_dir.mkdir(parents=True, exist_ok=True)
    summary: dict[str, Any] = {
        "ran": True,
        "status": "ran",
        "started": now_kst(),
        "requests": 0,
        "retries": 0,
        "errors": [],
        "days": [],
        "bytes": 0,
        "ok_dates": [],
        "blocked_dates": [],
        "selected_files_counted": [],
        "stale_selected_ignored": [],
    }
    for day in (days if days is not None else assumed_trading_days()):
        market_results: dict[str, dict[str, Any]] = {}
        files = []
        for market in EQUITY_MARKETS:
            rec = resolve_market_response(
                dest=raw_dir / f"{day}_{market}.json",
                expected_date=day,
                market=market,
                auth_key=auth_key,
                summary=summary,
                sleep_s=sleep_s,
                fetch_market=fetch_market,
            )
            market_results[market] = rec
            files.append({k: rec.get(k) for k in (
                "market", "ok", "kind", "reuse", "sha256", "bytes", "n",
                "found_dates", "cache_rejected", "http_status",
            ) if k in rec or rec.get(k) is not None})
        decision = decide_day_selection(market_results)
        selected_path = selected_dir / f"{day}_top100.json"
        day_rec: dict[str, Any] = {
            "date": day,
            "files": files,
            "select": decision["select"],
            "status": decision["status"],
            "market_kinds": decision["market_kinds"],
            "holiday_confirmed": False,
        }
        if not decision["select"]:
            if selected_path.is_file():
                day_rec["stale_selected"] = selected_path.name
                summary["stale_selected_ignored"].append(selected_path.name)
            summary["blocked_dates"].append(day)
            summary["days"].append(day_rec)
            if decision["status"] == "auth" and fetch_market is None:
                summary["status"] = "stopped_auth"
                break
            continue
        day_rows: list[dict[str, Any]] = []
        for market in EQUITY_MARKETS:
            block = market_results[market].get("block") or []
            day_rows.extend(rows_from_openapi_block(block, product_source=f"krx_openapi_{market}"))
        selected = select_top100(day_rows)
        selected["date"] = day
        selected["source"] = "krx_openapi_equity_kospi_kosdaq"
        selected["markets_validated"] = list(EQUITY_MARKETS)
        encoded = json.dumps(selected, ensure_ascii=False, indent=2).encode("utf-8")
        selected_path.write_bytes(encoded)
        day_rec.update({
            "eligible": selected["eligible_count"],
            "excluded": selected["excluded_count"],
            "top100": len(selected["top100"]),
            "selected_sha256": sha256_bytes(encoded),
            "selected_file": selected_path.name,
        })
        summary["ok_dates"].append(day)
        summary["selected_files_counted"].append(selected_path.name)
        summary["days"].append(day_rec)
    summary["finished"] = now_kst()
    return summary


def scanner_rules() -> dict[str, Any]:
    return {
        "code": "src/KrAmountLeadersClient.php",
        "sha256": sha256_file(LEADERS_PHP),
        "primary_source": "https://finance.daum.net/api/trend/trade_volume fieldName=accTradePrice",
        "fallback_source": "https://finance.naver.com/sise/sise_quant.naver amount column",
        "markets": ["KOSPI", "KOSDAQ"],
        "not_included": ["KONEX", "date_parameter"],
        "amount_field": "accTradePrice (won). Daum path does not use close*volume.",
        "exclude_when": "fetch row mapping, before final rank",
        "exclude_rule": "KrAmountLeadersClient::excludedFromAmountRank",
        "pagination": "up to 100 non-excluded rows per market, max 10 pages of 100, then merge and sort",
        "sort": "amount_million desc, PHP usort. Equal amounts keep relative order (PHP 8 stable).",
        "limit": 100,
        "session": "scheduled 20:20 KST after regular close; live ranking not a dated archive",
        "nxt_afterhours": "not specified in scanner code",
    }


def year_estimate(
    collect: dict[str, Any] | None = None,
    union_n: int = 0,
) -> dict[str, Any]:
    month_days = assumed_trading_days()
    n_month = len(month_days)
    year_days = 247
    measured_req = (collect or {}).get("requests")
    measured_bytes = (collect or {}).get("bytes")
    started = (collect or {}).get("started")
    finished = (collect or {}).get("finished")
    measured_seconds: float | None = None
    if collect and collect.get("ran") and started and finished:
        fmt = "%Y-%m-%d %H:%M:%S"
        try:
            measured_seconds = (
                datetime.strptime(str(finished), fmt) - datetime.strptime(str(started), fmt)
            ).total_seconds()
        except ValueError:
            measured_seconds = None
    scale = year_days / n_month if n_month else 0
    if measured_req:
        year_equity_requests = round(measured_req * scale)
        year_sleep = round((measured_seconds or 0) * scale, 1) if measured_seconds else round(year_equity_requests * 1.2, 1)
        mb = (measured_bytes or 0) / 1_000_000
        year_mb = round(mb * scale, 1)
        assumptions = [
            "1년 거래일 247일(근사).",
            "하루 주권 2호출(KOSPI+KOSDAQ). ETF/ETN 확인 시 +2.",
            f"응답 크기: 2025-10 실측 {n_month}일 {measured_req}요청 약 {mb:.1f}MB. 1년은 이 비율로 환산.",
        ]
        out_size = {
            "year_equity_requests": year_equity_requests,
            "year_equity_plus_etp_requests": year_equity_requests * 2,
            "year_seconds_equity": year_sleep,
            "year_raw_size_mb": year_mb,
            "measured_month_requests": measured_req,
            "measured_month_bytes": measured_bytes,
            "measured_month_seconds": measured_seconds,
            "measured_month_mb": round(mb, 1),
        }
    else:
        assumptions = [
            "1년 거래일 247일(근사).",
            "하루 주권 2호출(KOSPI+KOSDAQ). ETF/ETN 확인 시 +2.",
            "호출 간격 1.2초, 오류 재시도 여유 포함.",
            "응답 크기: 샘플 10행≈3KB. 전 종목은 시장당 수백~2천행으로 가정해 하루 0.5–1.5MB.",
        ]
        out_size = {
            "year_equity_requests": year_days * 2,
            "year_equity_plus_etp_requests": year_days * 4,
            "year_sleep_seconds_equity": round(year_days * 2 * 1.2, 1),
            "year_raw_size_mb_low": year_days * 0.5,
            "year_raw_size_mb_high": year_days * 1.5,
        }
    daily_bar_estimate: dict[str, Any] = {
        "note": "이번 파일럿은 일봉을 받지 않는다.",
        "if_year_unique_600_to_1200": "600–1200회. 운영 캐시에 쓰지 말 것.",
    }
    if union_n:
        daily_bar_estimate["month_union_unique"] = union_n
        daily_bar_estimate["if_month_union_one_range_each"] = (
            f"{union_n}회. 1년 합집합은 더 클 수 있어 미확정. 운영 캐시에 쓰지 말 것."
        )
    else:
        daily_bar_estimate["note"] = (
            "이번 파일럿은 일봉을 받지 않는다. TOP100 합집합을 세기 전에는 종목 수를 확정하지 않는다."
        )
        daily_bar_estimate["if_month_unique_200_to_400"] = "Yahoo 구간 1요청/종목 가정 시 200–400회."
    return {
        "month_candidate_days": month_days,
        "month_candidate_day_count": n_month,
        "assumptions": assumptions,
        **out_size,
        "daily_bars_not_collected": True,
        "daily_bar_estimate": daily_bar_estimate,
        "uncertainties": [
            "Open API 일 한도·버스트 403.",
            (
                "주식 일별매매정보는 승인됨. ETF/ETN 카테고리는 이번 수집에 쓰지 않음."
                if collect and collect.get("ran") and collect.get("ok_dates")
                else "카테고리별 활용 승인 대기."
            ),
            "NXT·시간외 포함 여부.",
            "주권 API만 쓰면 ETF는 목록에 없지만, 운영 다음은 ETF를 받은 뒤 이름으로 뺀다.",
        ],
    }


def counted_selected_files(collect: dict[str, Any] | None, selected_dir: Path) -> tuple[list[dict[str, Any]], set[str]]:
    """이번 수집에서 양쪽 시장이 통과한 날짜의 선정 파일만 집계한다."""
    selected_files: list[dict[str, Any]] = []
    union: set[str] = set()
    if not collect or not collect.get("ran"):
        return selected_files, union
    allowed = set(collect.get("selected_files_counted") or [])
    if not allowed or not selected_dir.is_dir():
        return selected_files, union
    for name in sorted(allowed):
        path = selected_dir / name
        if not path.is_file():
            continue
        payload = json.loads(path.read_text(encoding="utf-8"))
        codes = [r["code"] for r in payload.get("top100", [])]
        union.update(codes)
        selected_files.append({
            "file": path.name,
            "sha256": sha256_file(path),
            "n": len(codes),
            "eligible": payload.get("eligible_count"),
            "excluded": payload.get("excluded_count"),
        })
    return selected_files, union


STALE_UNAPPROVED_FACT = (
    "개인 인증키로 본호출을 시도했다. 응답은 401 Unauthorized API Call이며 "
    "해당 API 활용 승인이 없는 상태로 본다."
)
PRIOR_UNAPPROVED_FACT = (
    "활용 승인 전 개인 인증키 본호출은 401 Unauthorized API Call 이었다."
)
COLLECT_OK_FACT = (
    "활용 승인 후 개인 인증키로 주권 본호출이 200을 반환했고, "
    "양쪽 시장이 검증된 날만 TOP100을 저장했다."
)


def refresh_probe_facts(probe: dict[str, Any], collect: dict[str, Any] | None) -> None:
    facts = list(probe.get("facts") or [])
    if collect and collect.get("ran") and collect.get("ok_dates"):
        facts = [f for f in facts if f != STALE_UNAPPROVED_FACT]
        if PRIOR_UNAPPROVED_FACT not in facts:
            facts.append(PRIOR_UNAPPROVED_FACT)
        if not any(f.startswith(COLLECT_OK_FACT) for f in facts):
            n = len(collect.get("ok_dates") or [])
            facts.append(COLLECT_OK_FACT + f" 후보 {n}거래일.")
    probe["facts"] = facts


def build_evidence(probe: dict[str, Any], collect: dict[str, Any] | None) -> dict[str, Any]:
    selected_files, union = counted_selected_files(collect, RAW_ROOT / "selected")
    refresh_probe_facts(probe, collect)
    sample_not_universe = True
    for name in ("kospi_equity", "kosdaq_equity"):
        n = probe.get("sample_sets", {}).get(name, {}).get("n")
        if n not in (None, 10):
            sample_not_universe = False
    conclusion = "일부 제한"
    conclusion_why = [
        "공식 거래대금 필드(ACC_TRDVAL)와 일별 전 종목 API는 확인했다.",
        "본호출은 인증키·활용 승인이 필요하고, 정보데이터시스템 JSON은 로그인이 필요하다. 우회하지 않았다.",
        "샘플 10행으로 TOP100을 만들지 않았다.",
        "운영 다음은 실시간 accTradePrice라, 키를 받아도 동일 TOP100이라고 단정할 수 없다.",
    ]
    if collect and collect.get("ran") and collect.get("ok_dates"):
        n = len(collect.get("ok_dates") or [])
        conclusion_why.append(
            f"인증키로 {n}거래일 모두 양쪽 시장이 검증된 뒤에만 TOP100을 저장했다. "
            f"합집합 고유 {len(union)}종목. 운영과 동일인지는 다음·시간외·NXT 정의 대조가 남는다."
        )
    if collect and collect.get("status") == "skipped_no_auth_key":
        conclusion_why.append("인증키가 없어 수집은 실행하지 않았다.")
    if collect and collect.get("status") == "stopped_auth":
        conclusion_why.append("인증키는 있었으나 Open API가 Unauthorized API Call이라 나머지 날짜를 호출하지 않았다.")
    ye = year_estimate(collect, len(union))
    return {
        "generated_at": now_kst(),
        "inputs": {
            "leaders_php": str(LEADERS_PHP),
            "leaders_php_sha256": sha256_file(LEADERS_PHP),
            "amount_exclude_test": str(ROOT / "tests" / "amount_exclude.php"),
            "raw_root": str(RAW_ROOT),
            "raw_root_gitignored": True,
        },
        "aggregation": {
            "amount_field": "ACC_TRDVAL only",
            "close_times_volume": "recorded as mismatch check, never used for rank",
            "markets": "KOSPI+KOSDAQ. KONEX dropped.",
            "exclude": "KrAmountLeadersClient::excludedFromAmountRank name rule",
            "rank": "amount_won desc, code asc for ties",
            "topn": 100,
            "listing_basis": "that day's Open API rows, not today's ticker list",
            "select_requires": "both KOSPI and KOSDAQ validate: http 200, json, non-empty OutBlock_1, BAS_DD=request date",
            "empty_is_not_holiday": True,
        },
        "scanner": scanner_rules(),
        "probe": probe,
        "collect": collect,
        "selected_files": selected_files,
        "union_unique_codes": len(union),
        "union_codes_preview": sorted(union)[:30],
        "year_estimate": ye,
        "sample_is_not_universe": sample_not_universe,
        "facts_vs_assumptions": {
            "facts": probe.get("facts", []),
            "assumptions": probe.get("assumptions", []) + ye["assumptions"],
        },
        "conclusion": conclusion,
        "conclusion_why": conclusion_why,
        "replay": {
            "probe": "python docs/research/historical-amount-top100-pilot.py --probe",
            "tests": "python -m unittest tests.test_historical_amount_top100_pilot",
            "collect_powershell": [
                "$env:KRX_AUTH_KEY = Read-Host 'KRX AUTH KEY'",
                "python docs\\research\\historical-amount-top100-pilot.py --collect",
                "Remove-Item Env:KRX_AUTH_KEY",
            ],
            "do_not_put_key_in_git_or_logs": True,
        },
    }


def public_evidence(evidence: dict[str, Any]) -> dict[str, Any]:
    """Git용: 샘플 원본 행을 빼고 해시·건수만 남긴다."""
    out = json.loads(json.dumps(evidence))
    probe = out.get("probe") or {}
    slim_sets = {}
    for name, rec in (probe.get("sample_sets") or {}).items():
        slim_sets[name] = {
            "url": rec.get("url"),
            "status": rec.get("status"),
            "bytes": rec.get("bytes"),
            "sha256": rec.get("sha256"),
            "n": rec.get("n"),
        }
    probe["sample_sets"] = slim_sets
    mismatches = probe.get("close_times_volume_mismatches_in_sample") or []
    probe["close_times_volume_mismatches_in_sample"] = mismatches[:3]
    probe["close_times_volume_mismatch_count"] = len(mismatches)
    out["probe"] = probe
    return out


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(
        description="KRX amount TOP100 collect. Default is the 2025-10 pilot. Does not write operational caches.",
        epilog=(
            "PowerShell collect: $env:KRX_AUTH_KEY = Read-Host 'KRX AUTH KEY'; "
            "python docs\\research\\historical-amount-top100-pilot.py --collect; "
            "Remove-Item Env:KRX_AUTH_KEY. Never commit or log the key."
        ),
    )
    parser.add_argument("--probe", action="store_true")
    parser.add_argument("--collect", action="store_true")
    parser.add_argument("--from", dest="date_from", default=None, help="YYYY-MM-DD or YYYYMMDD")
    parser.add_argument("--to", dest="date_to", default=None, help="YYYY-MM-DD or YYYYMMDD")
    parser.add_argument("--raw-root", default=None)
    parser.add_argument("--docs", default=None)
    parser.add_argument("--reuse-from", default=None, help="Oct 2025 gitignored raw root to copy after hash check")
    parser.add_argument("--sleep", type=float, default=1.2)
    args = parser.parse_args()
    range_mode = args.date_from is not None or args.date_to is not None
    if range_mode and (not args.date_from or not args.date_to):
        raise SystemExit("--from and --to are required together")
    if not args.probe and not args.collect:
        args.probe = not range_mode
    if range_mode and args.probe:
        raise SystemExit("range collect does not re-run the 2025-10 probe (that writes the Oct raw folder)")

    start = parse_ymd(args.date_from) if args.date_from else date(2025, 10, 1)
    end = parse_ymd(args.date_to) if args.date_to else date(2025, 10, 31)
    days = candidate_trading_days(start, end) if range_mode else assumed_trading_days()
    dest_root = Path(args.raw_root) if args.raw_root else (RANGE_RAW_ROOT if range_mode else RAW_ROOT)
    dest_docs = Path(args.docs) if args.docs else (RANGE_DOCS if range_mode else DOCS)
    if dest_root.resolve() == RAW_ROOT.resolve() and range_mode:
        raise SystemExit("refusing to write a range collect into the Oct 2025 raw root")
    if dest_docs.resolve() == DOCS.resolve() and range_mode:
        raise SystemExit("refusing to overwrite the Oct 2025 pilot evidence")

    dest_root.mkdir(parents=True, exist_ok=True)
    dest_docs.mkdir(parents=True, exist_ok=True)
    if not range_mode:
        RAW_ROOT.mkdir(parents=True, exist_ok=True)
        DOCS.mkdir(parents=True, exist_ok=True)

    probe: dict[str, Any] = {}
    if args.probe:
        probe = probe_access()
    elif (dest_docs / "evidence.json").is_file():
        try:
            prev = json.loads((dest_docs / "evidence.json").read_text(encoding="utf-8"))
            if isinstance(prev.get("probe"), dict):
                probe = prev["probe"]
        except (OSError, json.JSONDecodeError):
            probe = {}
    if range_mode:
        probe.setdefault("facts", [])
        probe["facts"] = list(probe.get("facts") or [])
        probe["facts"].append(
            f"기간 {start.isoformat()}~{end.isoformat()} 거래일 후보는 달력 파일에서 확정했다. "
            "빈 응답만으로 휴장이라고 보지 않는다."
        )

    reuse_report: dict[str, Any] | None = None
    if args.collect and range_mode:
        reuse_report = reuse_verified_oct(
            days,
            dest_root / "raw",
            dest_root / "selected",
            src_root=Path(args.reuse_from) if args.reuse_from else RAW_ROOT,
        )
        if reuse_report["issues"] or not reuse_report["source_untouched"]:
            raise SystemExit(json.dumps({"ok": False, "reuse": reuse_report}, ensure_ascii=False, indent=2))

    key = load_auth_key()
    if args.collect:
        if not key:
            collect = skipped_collect()
            probe.setdefault("facts", []).append(
                "인증키 없음. 수집 미실행(skipped_no_auth_key). 근사 TOP100을 만들지 않았다."
            )
        else:
            collect = collect_month(
                key,
                sleep_s=args.sleep,
                days=days,
                raw_dir=dest_root / "raw",
                selected_dir=dest_root / "selected",
            )
    else:
        collect = skipped_collect("이번 실행은 --collect가 아님. 수집 미실행.")
    if reuse_report is not None:
        collect["oct2025_reuse"] = reuse_report

    if range_mode:
        write_json(dest_root / "collect-summary.json", {
            "generated_at": now_kst(),
            "from": start.isoformat(),
            "to": end.isoformat(),
            "candidate_days": days,
            "candidate_day_count": len(days),
            "collect": collect,
            "reuse": reuse_report,
        })
        write_json(dest_docs / "collect-summary.json", {
            "generated_at": now_kst(),
            "from": start.isoformat(),
            "to": end.isoformat(),
            "candidate_day_count": len(days),
            "ok_dates": collect.get("ok_dates") or [],
            "blocked_dates": collect.get("blocked_dates") or [],
            "requests": collect.get("requests"),
            "retries": collect.get("retries"),
            "bytes": collect.get("bytes"),
            "started": collect.get("started"),
            "finished": collect.get("finished"),
            "status": collect.get("status"),
            "reuse": {
                "overlap_days": (reuse_report or {}).get("overlap_days"),
                "copied": len((reuse_report or {}).get("copied") or []),
                "reused": len((reuse_report or {}).get("reused") or []),
                "source_untouched": (reuse_report or {}).get("source_untouched"),
            } if reuse_report else None,
            "raw_root_gitignored": True,
        })
        print(json.dumps({
            "ok": collect.get("status") != "stopped_auth",
            "range": [start.isoformat(), end.isoformat()],
            "candidate_days": len(days),
            "ok_dates": len(collect.get("ok_dates") or []),
            "blocked_dates": collect.get("blocked_dates") or [],
            "requests": collect.get("requests"),
            "retries": collect.get("retries"),
            "reuse_copied": len((reuse_report or {}).get("copied") or []),
            "docs": str(dest_docs / "collect-summary.json"),
        }, ensure_ascii=False))
        return 0 if collect.get("ran") and collect.get("status") != "stopped_auth" else 2

    evidence = build_evidence(probe, collect)
    write_json(RAW_ROOT / "evidence-local.json", evidence)
    write_json(DOCS / "evidence.json", public_evidence(evidence))
    print(json.dumps({
        "ok": True,
        "conclusion": evidence["conclusion"],
        "union_unique_codes": evidence["union_unique_codes"],
        "sample_is_not_universe": evidence["sample_is_not_universe"],
        "collect_ran": bool(collect.get("ran")),
        "collect_status": collect.get("status"),
        "ok_dates": collect.get("ok_dates") or [],
        "docs": str(DOCS / "evidence.json"),
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
