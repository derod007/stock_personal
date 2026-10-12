#!/usr/bin/env python3
"""2025-10-08~2026-10-08 날짜별 KRX 거래대금 TOP100 입력 고정.

계좌 재현·수익률은 하지 않는다.
운영 캐시·전략 지문·full-v2·2025-10 원본/결과는 쓰지 않고 바꾸지도 않는다.
누락 날짜를 다른 날 목록으로 대체하지 않는다.
인증키·원본 JSON은 Git에 넣지 않는다.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import re
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
PILOT_PATH = ROOT / "docs" / "research" / "historical-amount-top100-pilot.py"
DOCS = ROOT / "docs" / "historical-top100-20251008-20261008"
KST = timezone(timedelta(hours=9))
YAHOO_SIX = re.compile(r"^\d{6}\.(KS|KQ)$")


def load_pilot():
    spec = importlib.util.spec_from_file_location("hist_top100_pilot", PILOT_PATH)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


def now_kst() -> str:
    return datetime.now(KST).strftime("%Y-%m-%d %H:%M:%S")


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def ymd(day: str) -> str:
    return f"{day[0:4]}-{day[4:6]}-{day[6:8]}"


def required_hash_issues(
    got: dict[str, str],
    expected: dict[str, str],
    required: list[str],
    missing_issue: str,
    mismatch_issue: str,
) -> list[dict[str, Any]]:
    issues: list[dict[str, Any]] = []
    for name in required:
        if name not in expected or not expected[name]:
            issues.append({"file": name, "issue": missing_issue})
        elif name not in got:
            continue
        elif got[name] != expected[name]:
            issues.append({
                "file": name,
                "issue": mismatch_issue,
                "expected": expected[name],
                "got": got[name],
            })
    return issues


def frozen_from_collect(collect: dict[str, Any]) -> tuple[dict[str, str], dict[str, str]]:
    selected: dict[str, str] = {}
    raw: dict[str, str] = {}
    for day in collect.get("days") or []:
        d = day.get("date")
        if day.get("selected_sha256") and day.get("selected_file"):
            selected[str(day["selected_file"])] = str(day["selected_sha256"])
        for rec in day.get("files") or []:
            market = rec.get("market")
            sha = rec.get("sha256")
            if d and market and sha:
                raw[f"{d}_{market}.json"] = str(sha)
    return selected, raw


def validate_inputs(pilot: Any) -> dict[str, Any]:
    days = pilot.candidate_trading_days(pilot.RANGE_FROM, pilot.RANGE_TO)
    raw_root = pilot.RANGE_RAW_ROOT
    selected_dir = raw_root / "selected"
    raw_dir = raw_root / "raw"
    issues: list[dict[str, Any]] = []
    dates: dict[str, Any] = {}
    union: dict[str, dict[str, str]] = {}
    name_changes: list[dict[str, str]] = []
    selected_hashes: list[dict[str, Any]] = []
    raw_hashes: list[dict[str, Any]] = []
    rerank_ok: list[str] = []
    summary_path = DOCS / "collect-summary.json"
    local_summary = raw_root / "collect-summary.json"
    hash_path = DOCS / "file-hashes.json"
    docs_sum = json.loads(summary_path.read_text(encoding="utf-8")) if summary_path.is_file() else {}
    local = json.loads(local_summary.read_text(encoding="utf-8")) if local_summary.is_file() else {}
    collect = local.get("collect") if isinstance(local.get("collect"), dict) else {}
    expected_selected, expected_raw = frozen_from_collect(collect)
    if hash_path.is_file():
        frozen = json.loads(hash_path.read_text(encoding="utf-8"))
        if frozen.get("selected"):
            expected_selected = {str(k): str(v) for k, v in frozen["selected"].items()}
        if frozen.get("raw"):
            expected_raw = {str(k): str(v) for k, v in frozen["raw"].items()}
    ok_dates = list(collect.get("ok_dates") or docs_sum.get("ok_dates") or [])
    blocked = list(collect.get("blocked_dates") or docs_sum.get("blocked_dates") or [])
    if local.get("candidate_days") and local["candidate_days"] != days:
        issues.append({"issue": "candidate_days_mismatch", "expected": days, "got": local.get("candidate_days")})
    for day in days:
        sel_path = selected_dir / f"{day}_top100.json"
        if day in blocked:
            issues.append({"date": day, "issue": "blocked_not_replaced", "status": "listed_in_blocked_dates"})
            continue
        if not sel_path.is_file():
            issues.append({"date": day, "issue": "selected_missing"})
            continue
        payload = json.loads(sel_path.read_text(encoding="utf-8"))
        top = payload.get("top100") or []
        codes = [r["code"] for r in top]
        amounts = [r["amount_won"] for r in top]
        sha = pilot.sha256_file(sel_path)
        selected_hashes.append({"file": sel_path.name, "sha256": sha, "n": len(top)})
        if payload.get("date") != day:
            issues.append({"date": day, "issue": "selected_date_field", "got": payload.get("date")})
        if len(top) != 100:
            issues.append({"date": day, "issue": "n", "n": len(top)})
        if len(set(codes)) != len(codes):
            issues.append({"date": day, "issue": "dup"})
        if amounts != sorted(amounts, reverse=True):
            issues.append({"date": day, "issue": "order"})
        for market in ("kospi_equity", "kosdaq_equity"):
            raw_path = raw_dir / f"{day}_{market}.json"
            if not raw_path.is_file():
                issues.append({"date": day, "market": market, "issue": "raw_missing"})
                continue
            raw_hashes.append({"file": raw_path.name, "sha256": pilot.sha256_file(raw_path)})
        try:
            rerank = pilot.rerank_from_raw_files(day, raw_dir)
            if pilot.top100_signature(rerank) != [(str(r["code"]), float(r["amount_won"])) for r in top]:
                issues.append({"date": day, "issue": "independent_rerank_mismatch"})
            else:
                rerank_ok.append(day)
        except SystemExit as exc:
            issues.append({"date": day, "issue": "independent_rerank_blocked", "detail": str(exc)})
        rows = []
        for r in top:
            market = str(r.get("market") or "")
            symbol = str(r.get("yahoo") or pilot.yahoo_symbol(str(r["code"]), market))
            rows.append({
                "rank": r["rank"],
                "code": r["code"],
                "symbol": symbol,
                "name": r["name"],
                "market": market,
                "amount_won": r["amount_won"],
            })
            prev = union.get(symbol)
            if prev and prev["name"] != r["name"]:
                name_changes.append({"symbol": symbol, "from": prev["name"], "to": r["name"], "date": day})
            union[symbol] = {"code": r["code"], "name": r["name"], "market": market}
        dates[day] = {"n": len(rows), "sha256": sha, "rows": rows}
    got_selected = {x["file"]: x["sha256"] for x in selected_hashes}
    got_raw = {x["file"]: x["sha256"] for x in raw_hashes}
    required_selected = [f"{day}_top100.json" for day in (ok_dates or [])]
    required_raw = [f"{day}_{m}.json" for day in (ok_dates or []) for m in ("kospi_equity", "kosdaq_equity")]
    issues.extend(required_hash_issues(
        got_selected, expected_selected, required_selected,
        "selected_hash_missing", "selected_hash_mismatch",
    ))
    issues.extend(required_hash_issues(
        got_raw, expected_raw, required_raw,
        "raw_hash_missing", "raw_hash_mismatch",
    ))
    symbols = []
    for symbol, info in sorted(union.items()):
        yahoo_ok = bool(YAHOO_SIX.fullmatch(symbol))
        symbols.append({
            "code": info["code"],
            "symbol": symbol,
            "name": info["name"],
            "market": info["market"],
            "status": "included" if yahoo_ok else "provider_symbol_unmapped",
            "hold_reason": None if yahoo_ok else "yahoo_chart_symbol_pattern_not_six_digits",
            "not_judged_invalid_listing": not yahoo_ok,
        })
    if issues:
        raise SystemExit(json.dumps({"ok": False, "issues": issues[:80], "issue_count": len(issues)}, ensure_ascii=False, indent=2))
    return {
        "schema": 1,
        "kind": "dated_krx_amount_top100_v1",
        "label": "KRX 일별 거래대금 TOP100 연구",
        "generated_at": now_kst(),
        "from": "2025-10-08",
        "to": "2026-10-08",
        "source_selected_gitignored": True,
        "days": days,
        "ok_dates": ok_dates,
        "blocked_dates": blocked,
        "day_count": len(days),
        "ok_day_count": len(ok_dates),
        "union_unique_symbols": len(symbols),
        "provider_unmapped_symbols": [s for s in symbols if s["status"] != "included"],
        "name_changes_in_union": name_changes,
        "selected_files": selected_hashes,
        "raw_files": raw_hashes,
        "independent_rerank_ok_dates": rerank_ok,
        "dates": dates,
        "universe_rows": symbols,
        "note": [
            "신규 진입 후보는 해당 날짜의 TOP100만 쓴다. 합집합을 매일 후보로 쓰지 않는다.",
            "누락·손상된 날짜를 다른 날 목록이나 고정 종목군으로 대체하지 않는다.",
            "운영 다음 실시간 TOP100과 동일하다고 보지 않는다.",
            "숫자 6자리가 아니라고 잘못된 종목이라고 단정하지 않는다. Yahoo chart 심볼 패턴만 구분한다.",
        ],
    }


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
            "sources": [{"kind": "dated_krx_amount_top100", "from": validated["from"], "to": validated["to"]}],
        }
    return {
        "schema": 1,
        "policy": "dated_krx_amount_top100_20251008_20261008_v1",
        "membership": "point_in_time_amount_top100_new_entries_only",
        "note": "Symbols that appeared in a dated KRX equity ACC_TRDVAL TOP100 in 2025-10-08..2026-10-08. Not a live Daum scan.",
        "sources": [{"kind": "selected_top100", "days": validated["ok_dates"], "gitignored": True}],
        "source_errors": [{"date": d, "issue": "blocked_or_missing"} for d in validated["blocked_dates"]],
        "invalid_symbol_rows": [],
        "counts": {
            "source_files": len(validated["ok_dates"]),
            "unique_codes": len(rows),
            "included": sum(1 for r in rows.values() if r["status"] == "included"),
            "excluded": 0,
            "provider_symbol_unmapped": sum(1 for r in rows.values() if r["status"] == "provider_symbol_unmapped"),
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
        "from": validated["from"],
        "to": validated["to"],
        "depends_on_pr": 73,
        "days": [ymd(d) for d in validated["ok_dates"]],
        "union_unique_symbols": validated["union_unique_symbols"],
        "name_changes_in_union": validated["name_changes_in_union"],
        "selected_files": validated["selected_files"],
        "dates": dates,
        "note": "New-entry candidates only. Holdings and pending orders follow existing replay rules after a drop-out. Account replay is not in this step.",
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--validate", action="store_true")
    args = parser.parse_args()
    if not args.validate:
        args.validate = True
    pilot = load_pilot()
    validated = validate_inputs(pilot)
    DOCS.mkdir(parents=True, exist_ok=True)
    write_json(DOCS / "candidates.json", public_candidates(validated))
    write_json(DOCS / "universe.json", universe_payload(validated))
    write_json(DOCS / "file-hashes.json", {
        "schema": 1,
        "kind": "dated_krx_amount_top100_file_hashes_v1",
        "from": validated["from"],
        "to": validated["to"],
        "selected": {x["file"]: x["sha256"] for x in validated["selected_files"]},
        "raw": {x["file"]: x["sha256"] for x in validated["raw_files"]},
        "note": "Raw JSON stays gitignored. Missing or changed hashes fail validation.",
    })
    write_json(DOCS / "input-validation.json", {
        "ok": True,
        "generated_at": validated["generated_at"],
        "from": validated["from"],
        "to": validated["to"],
        "day_count": validated["day_count"],
        "ok_day_count": validated["ok_day_count"],
        "blocked_dates": validated["blocked_dates"],
        "union_unique_symbols": validated["union_unique_symbols"],
        "name_changes_in_union": validated["name_changes_in_union"],
        "provider_unmapped_symbols": validated["provider_unmapped_symbols"],
        "selected_file_count": len(validated["selected_files"]),
        "raw_file_count": len(validated["raw_files"]),
        "independent_rerank_ok_count": len(validated["independent_rerank_ok_dates"]),
        "issues": [],
    })
    print(json.dumps({
        "ok": True,
        "days": validated["day_count"],
        "ok_dates": validated["ok_day_count"],
        "blocked": validated["blocked_dates"],
        "union_unique_symbols": validated["union_unique_symbols"],
        "name_changes": len(validated["name_changes_in_union"]),
        "unmapped": len(validated["provider_unmapped_symbols"]),
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
