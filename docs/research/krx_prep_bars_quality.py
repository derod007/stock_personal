#!/usr/bin/env python3
"""Prep-bar quality for the 1248-name union. Does not fill gaps or scale prices."""
from __future__ import annotations

import argparse
import json
from collections import Counter
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
COMPARE = ROOT / "docs" / "research" / "krx_yahoo_ohlcv_compare.py"
DOCS = ROOT / "docs" / "krx-prep-bars-before-20241008"
UNIVERSE = ROOT / "docs" / "historical-top100-20241008-20251002" / "universe-v2.json"
CANDIDATES = ROOT / "docs" / "historical-top100-20241008-20251002" / "candidates.json"
PRICE_BASIS = "KRX TDD 원본, 수정 여부 미확정"


def load_compare():
    import importlib.util
    spec = importlib.util.spec_from_file_location("krx_yahoo_ohlcv_compare", COMPARE)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


def ohlc_issues(row: dict[str, Any]) -> list[str]:
    issues = []
    o, h, l, c = row.get("open"), row.get("high"), row.get("low"), row.get("close")
    if o is None or h is None or l is None or c is None:
        issues.append("ohlc_null")
        return issues
    if any(x <= 0 for x in (o, h, l, c)):
        issues.append("ohlc_non_positive")
    if h < l:
        issues.append("high_lt_low")
    if o < l or o > h:
        issues.append("open_outside_hl")
    if c < l or c > h:
        issues.append("close_outside_hl")
    return issues


def load_day_index(cmpmod: Any, raw_dir: Path, day: str) -> tuple[dict[str, dict], list[dict[str, Any]]]:
    compact = day.replace("-", "")
    by_code: dict[str, dict] = {}
    dups: list[dict[str, Any]] = []
    seen: dict[str, int] = {}
    for suffix in ("kospi_equity", "kosdaq_equity"):
        path = raw_dir / f"{compact}_{suffix}.json"
        if not path.is_file():
            continue
        payload = cmpmod.load_json(path)
        block = payload.get("OutBlock_1") if isinstance(payload, dict) else None
        if not isinstance(block, list):
            continue
        for raw in block:
            code = str(raw.get("ISU_CD") or "")
            if not code:
                continue
            key = f"{suffix}:{code}"
            seen[key] = seen.get(key, 0) + 1
            if seen[key] == 1:
                rec = cmpmod.krx_fields(raw)
                rec["suffix"] = suffix
                rec["day"] = day
                by_code[key] = rec
            elif seen[key] == 2:
                dups.append({"day": day, "suffix": suffix, "code": code})
    return by_code, dups


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--prep-raw", required=True)
    p.add_argument("--eval-raw", required=True)
    p.add_argument("--out", default=str(DOCS))
    args = p.parse_args()
    cmpmod = load_compare()
    days_payload = cmpmod.load_json(DOCS / "trading-days.json")
    prep_days = [f"{d[0:4]}-{d[4:6]}-{d[6:8]}" for d in days_payload["days"]]
    eval_days = list(cmpmod.load_json(CANDIDATES)["days"])
    symbols = cmpmod.universe_rows(cmpmod.load_json(UNIVERSE))
    if len(symbols) != 1248:
        raise SystemExit(f"expected 1248 symbols, got {len(symbols)}")
    prep_raw = Path(args.prep_raw)
    eval_raw = Path(args.eval_raw)

    prep_missing_files = []
    eval_missing_files = []
    dup_rows: list[dict[str, Any]] = []
    per = {
        spec["symbol"]: {
            "symbol": spec["symbol"],
            "code": spec["code"],
            "name": spec["name"],
            "market": spec["market"],
            "suffix": spec["suffix"],
            "prep_bars": 0,
            "prep_missing": 0,
            "eval_bars": 0,
            "eval_missing": 0,
            "prep_ohlc_issues": 0,
            "eval_ohlc_issues": 0,
            "prep_duplicate_days": 0,
        }
        for spec in symbols
    }
    issue_kinds = Counter()

    for day in prep_days:
        idx, dups = load_day_index(cmpmod, prep_raw, day)
        dup_rows.extend(dups)
        compact = day.replace("-", "")
        for suffix in ("kospi_equity", "kosdaq_equity"):
            if not (prep_raw / f"{compact}_{suffix}.json").is_file():
                prep_missing_files.append(f"{compact}_{suffix}.json")
        dup_keys = {f"{d['suffix']}:{d['code']}" for d in dups}
        for spec in symbols:
            row = per[spec["symbol"]]
            rec = idx.get(f"{spec['suffix']}:{spec['code']}")
            if rec is None:
                row["prep_missing"] += 1
                continue
            row["prep_bars"] += 1
            if f"{spec['suffix']}:{spec['code']}" in dup_keys:
                row["prep_duplicate_days"] += 1
            for kind in ohlc_issues(rec):
                row["prep_ohlc_issues"] += 1
                issue_kinds[kind] += 1

    for day in eval_days:
        idx, dups = load_day_index(cmpmod, eval_raw, day)
        dup_rows.extend({"scope": "eval", **d} for d in dups)
        compact = day.replace("-", "")
        for suffix in ("kospi_equity", "kosdaq_equity"):
            if not (eval_raw / f"{compact}_{suffix}.json").is_file():
                eval_missing_files.append(f"{compact}_{suffix}.json")
        for spec in symbols:
            row = per[spec["symbol"]]
            rec = idx.get(f"{spec['suffix']}:{spec['code']}")
            if rec is None:
                row["eval_missing"] += 1
                continue
            row["eval_bars"] += 1
            for kind in ohlc_issues(rec):
                row["eval_ohlc_issues"] += 1
                issue_kinds[f"eval_{kind}"] += 1

    rows = sorted(per.values(), key=lambda r: r["symbol"])
    short = [r for r in rows if r["prep_bars"] < 240]
    hist = Counter(r["prep_bars"] for r in rows)
    join_ok = ("20241007_kospi_equity.json" not in prep_missing_files) and (
        "20241008_kospi_equity.json" not in eval_missing_files
    )
    summary = {
        "schema": 1,
        "kind": "krx_prep_bars_quality_v1",
        "price_basis": PRICE_BASIS,
        "no_fill": True,
        "no_ratio_adjust": True,
        "no_yahoo_mix": True,
        "universe": len(symbols),
        "prep_days": len(prep_days),
        "eval_days": len(eval_days),
        "prep_missing_files": prep_missing_files,
        "eval_missing_files": eval_missing_files,
        "duplicate_row_events": len(dup_rows),
        "join_prep_last_and_eval_first_files_present": join_ok,
        "prep_bars_histogram": {str(k): hist[k] for k in sorted(hist)},
        "symbols_short_of_240": len(short),
        "symbols_with_prep_ohlc_issues": sum(1 for r in rows if r["prep_ohlc_issues"]),
        "symbols_with_eval_ohlc_issues": sum(1 for r in rows if r["eval_ohlc_issues"]),
        "ohlc_issue_kinds": dict(issue_kinds),
        "short_preview": short[:40],
        "rows": rows,
    }
    out = Path(args.out)
    cmpmod.write_json(out / "quality.json", summary)
    public = {k: v for k, v in summary.items() if k != "rows"}
    public["note"] = "종목별 전체 행은 quality.json. 신규 상장·정지로 부족한 종목을 채우지 않았다."
    cmpmod.write_json(out / "quality-summary.json", public)
    print(json.dumps({
        "universe": len(symbols),
        "prep_days": len(prep_days),
        "short_of_240": len(short),
        "prep_ohlc_symbols": public["symbols_with_prep_ohlc_issues"],
        "dup_events": len(dup_rows),
        "join_files": join_ok,
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
