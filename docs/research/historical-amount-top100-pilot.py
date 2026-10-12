#!/usr/bin/env python3
"""2025-10 KR 거래대금 TOP100 복원 파일럿 (읽기 전용).

운영 캐시·예약·계좌·전략을 바꾸지 않습니다.
종가×거래량으로 거래대금을 만들지 않습니다.
인증키가 없으면 전 종목 TOP100을 추정하지 않습니다.
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
# 행정안전부 2025년 공휴일. 거래일은 수집 시 빈 응답으로 재확인한다.
HOLIDAYS_2025_10 = {"20251003", "20251005", "20251006", "20251007", "20251008", "20251009"}
ETF_PREFIXES = [
    "KODEX", "TIGER", "KOSEF", "KINDEX", "KOACT", "ACE", "SOL ", "PLUS",
    "RISE", "HANARO", "ARIRANG", "KBSTAR", "TIMEFOLIO", "WON ", "1Q ",
    "KIWOOM", "TREX", "FOCUS", "UNICORN", "HK ", "MASTER", "TIME ",
]


def now_kst() -> str:
    return datetime.now(KST).strftime("%Y-%m-%d %H:%M:%S")


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
        "2025-10 거래일 후보는 주말·공휴일을 뺀 목록이며, 키 확보 후 빈 응답으로 휴장일을 재확인해야 한다.",
        "Open API 주식 일별매매정보는 주권만 포함한다고 안내되어 있다. ETF/ETN은 증권상품 API가 따로 있다.",
        "ACC_TRDVAL이 정규장만인지, 시간외·NXT를 합친 것인지는 이번 명세서 전문을 받지 못해 단정하지 않는다.",
    ])
    return stats


def collect_month(auth_key: str, sleep_s: float = 1.2) -> dict[str, Any]:
    raw_dir = RAW_ROOT / "raw"
    raw_dir.mkdir(parents=True, exist_ok=True)
    summary: dict[str, Any] = {
        "started": now_kst(),
        "requests": 0,
        "retries": 0,
        "errors": [],
        "days": [],
        "bytes": 0,
    }
    days = assumed_trading_days()
    for day in days:
        day_rows: list[dict[str, Any]] = []
        day_rec: dict[str, Any] = {"date": day, "files": []}
        empty_day = True
        for market, path in (("kospi_equity", PROD_PATHS["kospi_equity"]), ("kosdaq_equity", PROD_PATHS["kosdaq_equity"])):
            dest = raw_dir / f"{day}_{market}.json"
            if dest.is_file() and dest.stat().st_size > 20:
                body = dest.read_bytes()
                day_rec["files"].append({"market": market, "reuse": True, "sha256": sha256_bytes(body), "bytes": len(body)})
            else:
                status, _, body = _get_with_retry(
                    f"{OPENAPI}{path}?basDd={day}",
                    {"Accept": "application/json", "AUTH_KEY": auth_key},
                    summary,
                )
                summary["requests"] += 1
                summary["bytes"] += len(body)
                if status != 200:
                    summary["errors"].append({"date": day, "market": market, "status": status, "head": body[:160].decode("utf-8", "replace")})
                    day_rec["files"].append({"market": market, "status": status})
                    time.sleep(sleep_s)
                    continue
                dest.write_bytes(body)
                day_rec["files"].append({"market": market, "reuse": False, "sha256": sha256_bytes(body), "bytes": len(body)})
                time.sleep(sleep_s)
            try:
                payload = json.loads(body)
            except json.JSONDecodeError:
                summary["errors"].append({"date": day, "market": market, "error": "json"})
                continue
            block = payload.get("OutBlock_1") if isinstance(payload, dict) else None
            if not isinstance(block, list) or block == []:
                continue
            empty_day = False
            day_rows.extend(rows_from_openapi_block(block, product_source=f"krx_openapi_{market}"))
        if empty_day:
            day_rec["status"] = "empty_or_holiday"
            summary["days"].append(day_rec)
            continue
        selected = select_top100(day_rows)
        selected["date"] = day
        selected["source"] = "krx_openapi_equity_kospi_kosdaq"
        out = RAW_ROOT / "selected" / f"{day}_top100.json"
        out.parent.mkdir(parents=True, exist_ok=True)
        encoded = json.dumps(selected, ensure_ascii=False, indent=2).encode("utf-8")
        out.write_bytes(encoded)
        day_rec["status"] = "ok"
        day_rec["eligible"] = selected["eligible_count"]
        day_rec["excluded"] = selected["excluded_count"]
        day_rec["top100"] = len(selected["top100"])
        day_rec["selected_sha256"] = sha256_bytes(encoded)
        summary["days"].append(day_rec)
    summary["finished"] = now_kst()
    return summary


def _get_with_retry(url: str, headers: dict[str, str], summary: dict[str, Any], tries: int = 3) -> tuple[int, dict[str, str], bytes]:
    last = (0, {}, b"")
    for i in range(tries):
        last = http_get(url, headers)
        if last[0] == 200:
            return last
        summary["retries"] += 1
        time.sleep(2 * (i + 1))
    return last


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


def year_estimate() -> dict[str, Any]:
    month_days = assumed_trading_days()
    return {
        "month_candidate_days": month_days,
        "month_candidate_day_count": len(month_days),
        "assumptions": [
            "1년 거래일 247일(근사).",
            "하루 주권 2호출(KOSPI+KOSDAQ). ETF/ETN 확인 시 +2.",
            "호출 간격 1.2초, 오류 재시도 여유 포함.",
            "응답 크기: 샘플 10행≈3KB. 전 종목은 시장당 수백~2천행으로 가정해 하루 0.5–1.5MB.",
        ],
        "year_equity_requests": 247 * 2,
        "year_equity_plus_etp_requests": 247 * 4,
        "year_sleep_seconds_equity": round(247 * 2 * 1.2, 1),
        "year_raw_size_mb_low": 247 * 0.5,
        "year_raw_size_mb_high": 247 * 1.5,
        "daily_bars_not_collected": True,
        "daily_bar_estimate": {
            "note": "이번 파일럿은 일봉을 받지 않는다. TOP100 합집합을 재기 전에는 종목 수를 확정하지 않는다.",
            "if_month_unique_200_to_400": "Yahoo 구간 1요청/종목 가정 시 200–400회.",
            "if_year_unique_600_to_1200": "600–1200회. 운영 캐시에 쓰지 말 것.",
        },
        "uncertainties": [
            "Open API 일 한도·버스트 403.",
            "카테고리별 활용 승인 대기.",
            "NXT·시간외 포함 여부.",
            "주권 API만 쓰면 ETF는 목록에 없지만, 운영 다음은 ETF를 받은 뒤 이름으로 뺀다.",
        ],
    }


def build_evidence(probe: dict[str, Any], collect: dict[str, Any] | None) -> dict[str, Any]:
    union: set[str] = set()
    selected_files = []
    selected_dir = RAW_ROOT / "selected"
    if selected_dir.is_dir():
        for path in sorted(selected_dir.glob("*_top100.json")):
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
    if collect and any(d.get("status") == "ok" for d in collect.get("days", [])):
        conclusion_why.append("인증키로 일부 거래일을 받았다. 운영과 동일인지는 다음·시간외·NXT 정의 대조가 남는다.")
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
        },
        "scanner": scanner_rules(),
        "probe": probe,
        "collect": collect,
        "selected_files": selected_files,
        "union_unique_codes": len(union),
        "union_codes_preview": sorted(union)[:30],
        "year_estimate": year_estimate(),
        "sample_is_not_universe": sample_not_universe,
        "facts_vs_assumptions": {
            "facts": probe.get("facts", []),
            "assumptions": probe.get("assumptions", []) + year_estimate()["assumptions"],
        },
        "conclusion": conclusion,
        "conclusion_why": conclusion_why,
        "replay": {
            "probe": "python docs/research/historical-amount-top100-pilot.py --probe",
            "collect": "KRX_AUTH_KEY=... python docs/research/historical-amount-top100-pilot.py --collect",
            "tests": "python -m unittest tests.test_historical_amount_top100_pilot",
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
    parser = argparse.ArgumentParser()
    parser.add_argument("--probe", action="store_true")
    parser.add_argument("--collect", action="store_true")
    args = parser.parse_args()
    if not args.probe and not args.collect:
        args.probe = True

    RAW_ROOT.mkdir(parents=True, exist_ok=True)
    DOCS.mkdir(parents=True, exist_ok=True)

    probe = probe_access() if args.probe or args.collect else {}
    collect = None
    key = (os.environ.get("KRX_AUTH_KEY") or os.environ.get("KRX_API_KEY") or "").strip()
    if args.collect:
        if not key:
            probe.setdefault("facts", []).append("KRX_AUTH_KEY 없음. --collect를 건너뛰고 근사 TOP100을 만들지 않았다.")
        else:
            collect = collect_month(key)

    evidence = build_evidence(probe, collect)
    write_json(RAW_ROOT / "evidence-local.json", evidence)
    write_json(DOCS / "evidence.json", public_evidence(evidence))
    print(json.dumps({
        "ok": True,
        "conclusion": evidence["conclusion"],
        "union_unique_codes": evidence["union_unique_codes"],
        "sample_is_not_universe": evidence["sample_is_not_universe"],
        "collect_ran": collect is not None,
        "docs": str(DOCS / "evidence.json"),
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
