#!/usr/bin/env python3
"""2025-10 날짜별 KRX 거래대금 TOP100을 재현 입력으로 고정한다.

운영 캐시·전략 지문·full-v2 결과를 쓰지 않는다.
누락된 날짜를 다른 날 목록이나 고정 종목군으로 채우지 않는다.
인증키·원본 JSON은 Git에 넣지 않는다.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
RAW_ROOT = ROOT / "data" / "raw" / "research" / "historical-amount-top100-202510"
DOCS = ROOT / "docs" / "historical-top100-replay-202510"
SELECTED = RAW_ROOT / "selected"
RAW = RAW_ROOT / "raw"
PILOT_EVIDENCE = ROOT / "docs" / "historical-top100-pilot" / "evidence.json"
KST = timezone(timedelta(hours=9))
EQUITY_MARKETS = ("kospi_equity", "kosdaq_equity")
HOLIDAYS_2025_10 = {"20251003", "20251005", "20251006", "20251007", "20251008", "20251009"}


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


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


def now_kst() -> str:
    return datetime.now(KST).strftime("%Y-%m-%d %H:%M:%S")


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def ymd(day: str) -> str:
    return f"{day[0:4]}-{day[4:6]}-{day[6:8]}"


def validate_inputs() -> dict[str, Any]:
    days = assumed_trading_days()
    issues: list[dict[str, Any]] = []
    dates: dict[str, Any] = {}
    union: dict[str, dict[str, str]] = {}
    name_changes: list[dict[str, str]] = []
    selected_hashes: list[dict[str, Any]] = []
    raw_hashes: list[dict[str, Any]] = []
    if not PILOT_EVIDENCE.is_file():
        raise SystemExit("pilot evidence.json missing")
    pilot = json.loads(PILOT_EVIDENCE.read_text(encoding="utf-8"))
    collect = pilot.get("collect") or {}
    if collect.get("ok_dates") != days:
        issues.append({"issue": "ok_dates_mismatch", "expected": days, "got": collect.get("ok_dates")})
    for day in days:
        sel_path = SELECTED / f"{day}_top100.json"
        if not sel_path.is_file():
            issues.append({"date": day, "issue": "selected_missing"})
            continue
        payload = json.loads(sel_path.read_text(encoding="utf-8"))
        top = payload.get("top100") or []
        codes = [r["code"] for r in top]
        amounts = [r["amount_won"] for r in top]
        sha = sha256_file(sel_path)
        selected_hashes.append({"file": sel_path.name, "sha256": sha, "n": len(top)})
        if payload.get("date") != day:
            issues.append({"date": day, "issue": "selected_date_field", "got": payload.get("date")})
        if len(top) != 100:
            issues.append({"date": day, "issue": "n", "n": len(top)})
        if len(set(codes)) != len(codes):
            issues.append({"date": day, "issue": "dup"})
        if amounts != sorted(amounts, reverse=True):
            issues.append({"date": day, "issue": "order"})
        rows = []
        for r in top:
            market = str(r.get("market") or "")
            symbol = str(r.get("yahoo") or yahoo_symbol(str(r["code"]), market))
            rec = {
                "rank": r["rank"],
                "code": r["code"],
                "symbol": symbol,
                "name": r["name"],
                "market": market,
                "amount_won": r["amount_won"],
            }
            rows.append(rec)
            prev = union.get(symbol)
            if prev and prev["name"] != r["name"]:
                name_changes.append({"symbol": symbol, "from": prev["name"], "to": r["name"], "date": day})
            union[symbol] = {"code": r["code"], "name": r["name"], "market": market}
        dates[day] = {"n": len(rows), "sha256": sha, "rows": rows}
        for market in EQUITY_MARKETS:
            raw_path = RAW / f"{day}_{market}.json"
            if not raw_path.is_file():
                issues.append({"date": day, "market": market, "issue": "raw_missing"})
                continue
            raw = json.loads(raw_path.read_text(encoding="utf-8"))
            block = raw.get("OutBlock_1") or []
            found = sorted({str(x.get("BAS_DD") or "") for x in block})
            raw_hashes.append({
                "file": raw_path.name,
                "sha256": sha256_file(raw_path),
                "n": len(block),
                "found_dates": found,
            })
            if found != [day]:
                issues.append({"date": day, "market": market, "issue": "bas_dd", "found": found})
            if not block:
                issues.append({"date": day, "market": market, "issue": "empty_block"})
        counted = {x["file"]: x["sha256"] for x in (pilot.get("selected_files") or [])}
        if sel_path.name in counted and counted[sel_path.name] != sha:
            issues.append({"date": day, "issue": "selected_hash_mismatch"})
    if issues:
        raise SystemExit(json.dumps({"ok": False, "issues": issues}, ensure_ascii=False, indent=2))
    symbols = []
    for symbol, info in sorted(union.items()):
        yahoo_ok = bool(re.fullmatch(r"\d{6}\.(KS|KQ)", symbol))
        symbols.append({
            "code": info["code"],
            "symbol": symbol,
            "name": info["name"],
            "market": info["market"],
            "status": "included" if yahoo_ok else "identity_hold",
            "hold_reason": None if yahoo_ok else "yahoo_symbol_not_six_digits",
        })
    out = {
        "schema": 1,
        "kind": "dated_krx_amount_top100_v1",
        "label": "KRX 일별 거래대금 TOP100 연구",
        "generated_at": now_kst(),
        "source_selected_dir": str(SELECTED),
        "source_selected_gitignored": True,
        "days": days,
        "day_count": len(days),
        "union_unique_symbols": len(symbols),
        "identity_hold_symbols": [s for s in symbols if s["status"] != "included"],
        "name_changes_in_union": name_changes,
        "selected_files": selected_hashes,
        "raw_files": raw_hashes,
        "dates": dates,
        "universe_rows": symbols,
        "note": [
            "신규 진입 후보는 해당 날짜의 TOP100만 쓴다. 합집합을 매일 후보로 쓰지 않는다.",
            "누락·손상된 날짜를 다른 날 목록이나 고정 종목군으로 대체하지 않는다.",
            "운영 다음 실시간 TOP100과 동일하다고 보지 않는다.",
        ],
    }
    if len(symbols) != 305:
        raise SystemExit(f"union unique symbols {len(symbols)} != 305")
    return out


def universe_payload(validated: dict[str, Any]) -> dict[str, Any]:
    rows: dict[str, Any] = {}
    for rec in validated["universe_rows"]:
        rows[rec["code"]] = {
            "code": rec["code"],
            "symbol": rec["symbol"],
            "name": rec["name"],
            "market": rec["market"],
            "status": rec["status"],
            "hold_reason": rec.get("hold_reason"),
            "product": "equity",
            "exclusion_kind": None,
            "sources": [{"kind": "dated_krx_amount_top100", "dates": validated["days"]}],
        }
    return {
        "schema": 1,
        "policy": "dated_krx_amount_top100_202510_v1",
        "membership": "point_in_time_amount_top100_new_entries_only",
        "note": "Symbols that appeared in a dated KRX equity ACC_TRDVAL TOP100 in 2025-10. Not a live Daum scan.",
        "sources": [{"kind": "selected_top100", "days": validated["days"], "gitignored": True}],
        "source_errors": [],
        "invalid_symbol_rows": [],
        "counts": {
            "source_files": len(validated["days"]),
            "unique_codes": len(rows),
            "included": sum(1 for r in rows.values() if r["status"] == "included"),
            "excluded": 0,
            "identity_hold": sum(1 for r in rows.values() if r["status"] == "identity_hold"),
        },
        "rows": rows,
    }


def public_candidates(validated: dict[str, Any]) -> dict[str, Any]:
    dates = {}
    for day, rec in validated["dates"].items():
        dates[ymd(day)] = {
            "n": rec["n"],
            "source_sha256": rec["sha256"],
            "rows": [
                {k: r[k] for k in ("rank", "code", "symbol", "name", "market", "amount_won")}
                for r in rec["rows"]
            ],
        }
    return {
        "schema": 1,
        "kind": "dated_krx_amount_top100_candidates_v1",
        "label": "KRX 일별 거래대금 TOP100 연구",
        "depends_on_pr": 73,
        "days": [ymd(d) for d in validated["days"]],
        "union_unique_symbols": validated["union_unique_symbols"],
        "name_changes_in_union": validated["name_changes_in_union"],
        "selected_files": validated["selected_files"],
        "dates": dates,
        "note": "New-entry candidates only. Holdings and pending orders follow existing replay rules after a drop-out.",
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--validate", action="store_true")
    args = parser.parse_args()
    if not args.validate:
        args.validate = True
    validated = validate_inputs()
    DOCS.mkdir(parents=True, exist_ok=True)
    write_json(DOCS / "candidates.json", public_candidates(validated))
    write_json(DOCS / "universe.json", universe_payload(validated))
    write_json(DOCS / "input-validation.json", {
        "ok": True,
        "generated_at": validated["generated_at"],
        "day_count": validated["day_count"],
        "union_unique_symbols": validated["union_unique_symbols"],
        "name_changes_in_union": validated["name_changes_in_union"],
        "selected_files": validated["selected_files"],
        "raw_file_count": len(validated["raw_files"]),
        "issues": [],
    })
    print(json.dumps({
        "ok": True,
        "days": validated["day_count"],
        "union_unique_symbols": validated["union_unique_symbols"],
        "name_changes": len(validated["name_changes_in_union"]),
        "written": [str(DOCS / "candidates.json"), str(DOCS / "universe.json")],
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
