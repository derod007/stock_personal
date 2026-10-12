#!/usr/bin/env python3
"""Check unmapped KRX codes and Yahoo 404 names without mixing into the frozen dataset.

Does not skip a name only because the code is not six digits.
Does not attach a successor or preferred-share price to another listing.
New probe files go to a separate gitignored version. Frozen hashes are not rewritten.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
DOCS = ROOT / "docs" / "historical-top100-20251008-20261008"
PROBE_ROOT = ROOT / "data" / "raw" / "research" / "historical-amount-top100-20251008-20261008-provider-probe-v1"
KST = timezone(timedelta(hours=9))
CTX = ssl.create_default_context()
UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
)
YAHOO_CHART = "https://query1.finance.yahoo.com/v8/finance/chart/{symbol}?range={range}&interval=1d"
YAHOO_SEARCH = "https://query2.finance.yahoo.com/v1/finance/search?q={q}"
NAVER_DAY = "https://finance.naver.com/item/sise_day.naver?code={code}&page=1"

LETTER = re.compile(r"[A-Za-z]")


def now_kst() -> str:
    return datetime.now(KST).strftime("%Y-%m-%d %H:%M:%S")


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def classify_krx_code(code: str) -> str:
    if re.fullmatch(r"\d{6}", code):
        return "six_digit"
    if re.fullmatch(r"\d{5}[A-Za-z]", code) or re.fullmatch(r"\d{4}[A-Za-z]{2}", code):
        return "preferred_or_class_letter_suffix"
    if re.fullmatch(r"\d{4}[A-Za-z]\d", code) or LETTER.search(code):
        return "alphanumeric_short_code"
    return "other"


def http_get(url: str, timeout: int = 30) -> dict[str, Any]:
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "*/*"})
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=CTX) as resp:
            body = resp.read()
            return {
                "http_code": int(resp.status),
                "body": body,
                "error": None,
                "bytes": len(body),
                "sha256": sha256_bytes(body),
            }
    except urllib.error.HTTPError as exc:
        body = exc.read() or b""
        return {
            "http_code": int(exc.code),
            "body": body,
            "error": f"HTTPError {exc.code}",
            "bytes": len(body),
            "sha256": sha256_bytes(body) if body else None,
        }
    except Exception as exc:  # noqa: BLE001 — probe must record transport failure
        return {
            "http_code": None,
            "body": b"",
            "error": f"{type(exc).__name__}: {exc}",
            "bytes": 0,
            "sha256": None,
        }


def yahoo_chart_ok(body: bytes) -> dict[str, Any]:
    try:
        payload = json.loads(body.decode("utf-8", errors="replace"))
    except json.JSONDecodeError:
        return {"ok": False, "reason": "not_json"}
    err = (payload.get("chart") or {}).get("error")
    result = ((payload.get("chart") or {}).get("result") or [None])[0]
    if err:
        return {"ok": False, "reason": "chart_error", "error": err}
    if not isinstance(result, dict):
        return {"ok": False, "reason": "no_chart_result"}
    meta = result.get("meta") or {}
    ts = result.get("timestamp") or []
    return {
        "ok": True,
        "symbol": meta.get("symbol"),
        "exchange": meta.get("exchangeName"),
        "currency": meta.get("currency"),
        "first_trade": meta.get("firstTradeDate"),
        "instrument": meta.get("instrumentType"),
        "bar_count": len(ts),
    }


def naver_has_table(body: bytes) -> bool:
    text = body.decode("utf-8", errors="replace")
    if "일별" in text and ("종가" in text or "종 가" in text):
        return "데이터가 없습니다" not in text and "페이지가 없습니다" not in text
    return False


def appearance_counts(candidates: dict[str, Any], symbols: set[str]) -> dict[str, Any]:
    by: dict[str, list[str]] = {s: [] for s in symbols}
    for day, rec in (candidates.get("dates") or {}).items():
        for row in rec.get("rows") or []:
            sym = str(row.get("symbol") or "")
            if sym in by:
                by[sym].append(day)
    days = sorted({d for rec in by.values() for d in rec})
    return {
        "unique_symbols": len(symbols),
        "appearances": sum(len(v) for v in by.values()),
        "distinct_days": len(days),
        "by_symbol": {s: {"n": len(v), "first": v[0] if v else None, "last": v[-1] if v else None} for s, v in sorted(by.items())},
    }


def write_probe(name: str, body: bytes) -> str | None:
    if not body:
        return None
    PROBE_ROOT.mkdir(parents=True, exist_ok=True)
    path = PROBE_ROOT / name
    path.write_bytes(body)
    return str(path.relative_to(ROOT)).replace("\\", "/")


def review_symbol(rec: dict[str, Any], kind: str, sleep_s: float, requests: list[dict[str, Any]]) -> dict[str, Any]:
    code = str(rec["code"])
    symbol = str(rec["symbol"])
    name = str(rec["name"])
    out: dict[str, Any] = {
        "kind": kind,
        "code": code,
        "symbol": symbol,
        "name": name,
        "market": rec.get("market"),
        "code_class": classify_krx_code(code),
        "yahoo_six_digit": bool(re.fullmatch(r"\d{6}\.(KS|KQ)", symbol)),
        "successor_not_attached": True,
        "attempts": [],
    }
    chart_url = YAHOO_CHART.format(symbol=urllib.parse.quote(symbol, safe="."), range="1y")
    t0 = time.time()
    chart = http_get(chart_url)
    requests.append({"url": chart_url, "http_code": chart["http_code"], "error": chart["error"], "seconds": round(time.time() - t0, 3)})
    parsed = yahoo_chart_ok(chart["body"]) if chart["body"] else {"ok": False, "reason": "empty"}
    probe = write_probe(f"yahoo-{symbol.replace('.', '_')}.json", chart["body"])
    out["attempts"].append({
        "provider": "yahoo_chart_v8",
        "url": chart_url,
        "http_code": chart["http_code"],
        "error": chart["error"],
        "parsed": {k: v for k, v in parsed.items() if k != "ok"},
        "supported": bool(parsed.get("ok")),
        "probe_file": probe,
        "price_basis": "yahoo_chart_quote_ohlcv_daily_adjustment_unverified" if parsed.get("ok") else None,
    })
    time.sleep(sleep_s)
    search_url = YAHOO_SEARCH.format(q=urllib.parse.quote(code))
    t1 = time.time()
    search = http_get(search_url)
    requests.append({"url": search_url, "http_code": search["http_code"], "error": search["error"], "seconds": round(time.time() - t1, 3)})
    quotes = []
    if search["body"]:
        try:
            payload = json.loads(search["body"].decode("utf-8", errors="replace"))
            for q in (payload.get("quotes") or [])[:5]:
                quotes.append({"symbol": q.get("symbol"), "shortname": q.get("shortname"), "quoteType": q.get("quoteType"), "exch": q.get("exch")})
        except json.JSONDecodeError:
            quotes = []
    write_probe(f"yahoo-search-{code}.json", search["body"])
    out["attempts"].append({
        "provider": "yahoo_search",
        "url": search_url,
        "http_code": search["http_code"],
        "error": search["error"],
        "quotes": quotes,
        "identity_confirmed": False,
        "note": "Search hits are not used as OHLCV. Successor or lookalike symbols are not attached.",
    })
    time.sleep(sleep_s)
    naver_url = NAVER_DAY.format(code=urllib.parse.quote(code, safe=""))
    t2 = time.time()
    naver = http_get(naver_url)
    requests.append({"url": naver_url, "http_code": naver["http_code"], "error": naver["error"], "seconds": round(time.time() - t2, 3)})
    has_table = naver_has_table(naver["body"])
    probe_n = write_probe(f"naver-{code}.html", naver["body"])
    out["attempts"].append({
        "provider": "naver_sise_day_page1",
        "url": naver_url,
        "http_code": naver["http_code"],
        "error": naver["error"],
        "html_table_present": has_table,
        "probe_file": probe_n,
        "price_basis": "naver_sise_day_html_ohlcv_adjustment_unverified_recent_pages_only" if has_table else None,
        "not_mixed_into_yahoo_dataset": True,
        "note": "Naver sise_day is a different source and only a recent page was fetched. It is not stitched onto Yahoo bars.",
    })
    time.sleep(sleep_s)
    yahoo_ok = bool(parsed.get("ok"))
    if yahoo_ok:
        out["outcome"] = "yahoo_chart_supported_not_imported"
        out["reason"] = "Yahoo chart returned a result. Not written into the frozen 5y dataset in this step."
    elif has_table:
        out["outcome"] = "alternative_provider_seen_not_imported"
        out["reason"] = "Naver page has a daily table. OHLCV/adjustment/volume basis differs from Yahoo chart; not mixed."
    else:
        out["outcome"] = "not_secured"
        out["reason"] = "No Yahoo chart result and no Naver daily table on page 1. Not filled with rank 101."
    return out


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--probe", action="store_true")
    parser.add_argument("--sleep", type=float, default=0.8)
    args = parser.parse_args()
    uni = json.loads((DOCS / "universe.json").read_text(encoding="utf-8"))
    cand = json.loads((DOCS / "candidates.json").read_text(encoding="utf-8"))
    bars = json.loads((DOCS / "bars-summary.json").read_text(encoding="utf-8"))
    unmapped = [u for u in uni["rows"].values() if u.get("status") == "provider_symbol_unmapped"]
    failed = list(bars.get("failed") or [])
    unmapped_apps = appearance_counts(cand, {u["symbol"] for u in unmapped})
    failed_apps = appearance_counts(cand, {f["symbol"] for f in failed})
    payload: dict[str, Any] = {
        "schema": 1,
        "kind": "dated_top100_provider_review_v1",
        "label": "KRX 일별 거래대금 TOP100 연구",
        "generated_at": now_kst(),
        "probe_root_gitignored": str(PROBE_ROOT.relative_to(ROOT)).replace("\\", "/"),
        "existing_dataset_not_overwritten": True,
        "frozen_hashes_not_rewritten": True,
        "no_rank_101_fill": True,
        "unmapped_appearances": unmapped_apps,
        "yahoo_404_appearances": failed_apps,
        "code_classes": {},
        "symbols": [],
        "requests": [],
        "note": [
            "숫자 6자리가 아니라고 수집을 생략하지 않았다.",
            "검색으로 나온 다른 심볼 가격을 연결하지 않았다.",
            "네이버 일별 시세와 Yahoo chart를 한 시계열로 붙이지 않았다.",
        ],
    }
    classes: dict[str, int] = {}
    for u in unmapped:
        classes[classify_krx_code(u["code"])] = classes.get(classify_krx_code(u["code"]), 0) + 1
    payload["code_classes"] = classes
    if not args.probe:
        payload["probe_ran"] = False
        (DOCS / "provider-review.json").write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(json.dumps({"ok": True, "probe_ran": False, "unmapped": len(unmapped), "yahoo_404": len(failed)}, ensure_ascii=False))
        return 0
    requests: list[dict[str, Any]] = []
    started = now_kst()
    for u in unmapped:
        payload["symbols"].append(review_symbol(u, "unmapped", args.sleep, requests))
    for f in failed:
        rec = {"code": f["symbol"].split(".")[0], "symbol": f["symbol"], "name": f.get("name"), "market": f.get("market")}
        payload["symbols"].append(review_symbol(rec, "yahoo_404", args.sleep, requests))
    payload["requests"] = [{"url": r["url"], "http_code": r["http_code"], "error": r["error"]} for r in requests]
    payload["request_count"] = len(requests)
    payload["started"] = started
    payload["finished"] = now_kst()
    payload["probe_ran"] = True
    payload["outcomes"] = {}
    for s in payload["symbols"]:
        payload["outcomes"][s["outcome"]] = payload["outcomes"].get(s["outcome"], 0) + 1
    (DOCS / "provider-review.json").write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({
        "ok": True,
        "probe_ran": True,
        "unmapped": len(unmapped),
        "yahoo_404": len(failed),
        "request_count": len(requests),
        "outcomes": payload["outcomes"],
        "appearances": unmapped_apps["appearances"],
        "distinct_days": unmapped_apps["distinct_days"],
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
