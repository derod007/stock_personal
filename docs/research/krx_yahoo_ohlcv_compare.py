#!/usr/bin/env python3
"""Read-only KRX vs Yahoo original OHLCV compare for 2024-10-08..2025-10-02.

Does not fetch, insert bars, rewrite datasets, or run accounts.
Yahoo close and adjclose are compared separately.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import math
from collections import Counter, defaultdict
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

KST = timezone(timedelta(hours=9))
PRICE_EPS = 1e-6
VOL_EPS = 0.5
FOCUS = ("298380.KQ", "326030.KS", "005930.KS", "000660.KS")
FOCUS_DAYS = ("2025-09-18", "2025-09-19", "2025-09-22")
FOCUS_WINDOW = ("2025-09-18", "2025-09-19")


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def sha256_text(s: str) -> str:
    return hashlib.sha256(s.encode("utf-8")).hexdigest()


def num(v: Any) -> float | None:
    if v is None:
        return None
    if isinstance(v, bool):
        return None
    if isinstance(v, (int, float)):
        if isinstance(v, float) and not math.isfinite(v):
            return None
        return float(v)
    s = str(v).replace(",", "").strip()
    if s in ("", "-"):
        return None
    try:
        x = float(s)
    except ValueError:
        return None
    if not math.isfinite(x):
        return None
    return x


def eq(a: float | None, b: float | None, eps: float) -> bool:
    if a is None or b is None:
        return False
    return abs(a - b) <= eps


def day_kst(ts: int) -> str:
    return datetime.fromtimestamp(int(ts), tz=KST).strftime("%Y-%m-%d")


def kst_str(ts: int) -> str:
    return datetime.fromtimestamp(int(ts), tz=KST).strftime("%Y-%m-%d %H:%M:%S")


def ymd(day: str) -> str:
    return day.replace("-", "")


def load_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def universe_rows(uni: dict) -> list[dict]:
    rows = uni.get("rows")
    if isinstance(rows, dict):
        values = list(rows.values())
    elif isinstance(rows, list):
        values = rows
    else:
        raise SystemExit("universe-v2.json rows missing")
    out = []
    for r in values:
        symbol = str(r.get("symbol") or "")
        code = str(r.get("code") or "")
        if not symbol or not code:
            continue
        suffix = "kospi_equity" if symbol.endswith(".KS") else "kosdaq_equity"
        out.append({
            "code": code,
            "symbol": symbol,
            "name": r.get("name"),
            "market": r.get("market"),
            "status": r.get("status"),
            "suffix": suffix,
        })
    out.sort(key=lambda x: x["symbol"])
    return out


def parse_yahoo(body: bytes, expect_symbol: str) -> dict[str, Any]:
    j = json.loads(body.decode("utf-8"))
    res = ((j.get("chart") or {}).get("result") or [None])[0]
    if not isinstance(res, dict):
        return {"ok": False, "error": "no_result", "meta": {}, "days": {}}
    meta = res.get("meta") or {}
    ts = res.get("timestamp") or []
    q = ((res.get("indicators") or {}).get("quote") or [{}])[0] or {}
    adj = (((res.get("indicators") or {}).get("adjclose") or [{}])[0] or {}).get("adjclose") or []
    days: dict[str, dict[str, Any]] = {}
    identity_ok = str(meta.get("symbol") or "").upper() == expect_symbol.upper()
    for i, t in enumerate(ts):
        if not isinstance(t, int):
            continue
        d = day_kst(t)
        days[d] = {
            "timestamp": t,
            "timestamp_kst": kst_str(t),
            "open": (q.get("open") or [None])[i] if i < len(q.get("open") or []) else None,
            "high": (q.get("high") or [None])[i] if i < len(q.get("high") or []) else None,
            "low": (q.get("low") or [None])[i] if i < len(q.get("low") or []) else None,
            "close": (q.get("close") or [None])[i] if i < len(q.get("close") or []) else None,
            "volume": (q.get("volume") or [None])[i] if i < len(q.get("volume") or []) else None,
            "adjclose": adj[i] if i < len(adj) else None,
        }
    return {
        "ok": True,
        "error": None,
        "meta_symbol": meta.get("symbol"),
        "currency": meta.get("currency"),
        "exchange": meta.get("exchangeName"),
        "identity_ok": identity_ok,
        "timestamp_count": len(ts),
        "days": days,
    }


def load_yahoo_v2(ds: Path, symbols: list[dict]) -> tuple[dict[str, dict], dict[str, Any]]:
    by_sym: dict[str, dict] = {}
    missing_index = 0
    missing_raw = 0
    hash_mismatch = 0
    identity_fail = 0
    parse_fail = 0
    raw_shas: dict[str, str] = {}
    for spec in symbols:
        symbol = spec["symbol"]
        idx_path = ds / "raw" / "yahoo" / "index" / f"{symbol}.5y.json"
        if not idx_path.is_file():
            missing_index += 1
            by_sym[symbol] = {"ok": False, "error": "index_missing", "days": {}}
            continue
        idx = load_json(idx_path)
        sha = str(idx.get("sha256") or "")
        raw_path = ds / "raw" / "yahoo" / f"{sha}.json"
        if not raw_path.is_file():
            missing_raw += 1
            by_sym[symbol] = {"ok": False, "error": "raw_missing", "days": {}}
            continue
        body = raw_path.read_bytes()
        got = hashlib.sha256(body).hexdigest()
        if sha and got != sha:
            hash_mismatch += 1
        parsed = parse_yahoo(body, symbol)
        if not parsed.get("ok"):
            parse_fail += 1
        elif not parsed.get("identity_ok"):
            identity_fail += 1
        parsed["raw_sha256"] = got
        parsed["index_sha256"] = sha
        by_sym[symbol] = parsed
        raw_shas[symbol] = got
    return by_sym, {
        "symbols": len(symbols),
        "missing_index": missing_index,
        "missing_raw": missing_raw,
        "raw_sha_mismatch": hash_mismatch,
        "meta_symbol_mismatch": identity_fail,
        "parse_fail": parse_fail,
        "loaded": len(symbols) - missing_index - missing_raw,
    }


def empty_day() -> dict[str, int]:
    return {
        "universe": 0,
        "comparable": 0,
        "exact_ohlcv_close": 0,
        "volume_only_close": 0,
        "price_diff_close": 0,
        "price_diff_close_only": 0,
        "price_diff_ohl_also": 0,
        "close_equal_adj_equal_krx": 0,
        "close_equal_adj_differs_krx": 0,
        "adjclose_equals_krx_close": 0,
        "yahoo_timestamp_null_ohlcv": 0,
        "yahoo_absent": 0,
        "krx_absent": 0,
        "both_absent": 0,
        "krx_bas_dd_mismatch": 0,
        "shift_yahoo_equals_krx_prev": 0,
        "shift_yahoo_equals_krx_next": 0,
        "shift_krx_equals_yahoo_prev": 0,
        "shift_krx_equals_yahoo_next": 0,
        "yahoo_next_close_equals_krx_today": 0,
    }


def classify_pair(krx: dict | None, y: dict | None) -> str:
    if krx is None and y is None:
        return "both_absent"
    if krx is None:
        if y and y.get("close") is None and y.get("open") is None:
            return "yahoo_timestamp_null_ohlcv" if y.get("timestamp") else "yahoo_absent"
        return "krx_absent"
    if y is None:
        return "yahoo_absent"
    if y.get("close") is None and y.get("open") is None and y.get("high") is None and y.get("low") is None:
        return "yahoo_timestamp_null_ohlcv"
    close_ok = eq(num(krx["close"]), num(y.get("close")), PRICE_EPS)
    ohl_ok = all(eq(num(krx[k]), num(y.get(k)), PRICE_EPS) for k in ("open", "high", "low"))
    price_ok = close_ok and ohl_ok
    vol_ok = eq(num(krx.get("volume")), num(y.get("volume")), VOL_EPS)
    if price_ok and vol_ok:
        return "exact_ohlcv_close"
    if price_ok and not vol_ok:
        return "volume_only_close"
    if (not close_ok) and ohl_ok:
        return "price_diff_close_only"
    return "price_diff_ohl_also"


def krx_fields(row: dict) -> dict[str, Any]:
    return {
        "BAS_DD": row.get("BAS_DD"),
        "ISU_CD": row.get("ISU_CD"),
        "ISU_NM": row.get("ISU_NM"),
        "MKT_NM": row.get("MKT_NM"),
        "open": num(row.get("TDD_OPNPRC")),
        "high": num(row.get("TDD_HGPRC")),
        "low": num(row.get("TDD_LWPRC")),
        "close": num(row.get("TDD_CLSPRC")),
        "volume": num(row.get("ACC_TRDVOL")),
        "amount_won": num(row.get("ACC_TRDVAL")),
        "cmpprevdd": num(row.get("CMPPREVDD_PRC")),
        "fluc_rt": row.get("FLUC_RT"),
    }


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--universe", required=True)
    p.add_argument("--candidates", required=True)
    p.add_argument("--file-hashes", required=True)
    p.add_argument("--krx-raw", required=True)
    p.add_argument("--yahoo-v2", required=True)
    p.add_argument("--out", required=True)
    args = p.parse_args()

    uni_path = Path(args.universe)
    cand_path = Path(args.candidates)
    hash_path = Path(args.file_hashes)
    krx_dir = Path(args.krx_raw)
    yahoo_dir = Path(args.yahoo_v2)
    out_dir = Path(args.out)

    uni = load_json(uni_path)
    cand = load_json(cand_path)
    frozen = load_json(hash_path)
    days: list[str] = list(cand["days"])
    symbols = universe_rows(uni)
    if len(symbols) != 1248:
        raise SystemExit(f"expected 1248 universe symbols, got {len(symbols)}")
    if len(days) != 243:
        raise SystemExit(f"expected 243 days, got {len(days)}")

    yahoo_by, yahoo_stats = load_yahoo_v2(yahoo_dir, symbols)
    prev_of = {days[i]: days[i - 1] if i else None for i in range(len(days))}
    next_of = {days[i]: days[i + 1] if i + 1 < len(days) else None for i in range(len(days))}

    daily: dict[str, dict[str, int]] = {}
    totals = empty_day()
    ratio_by_symbol: dict[str, list[float]] = defaultdict(list)
    hash_ok = 0
    hash_bad = 0
    hash_missing = 0
    krx_file_hashes: dict[str, str] = {}
    focus: dict[str, dict[str, Any]] = {s: {} for s in FOCUS}

    # Precompute yahoo closes by symbol+day for shift checks
    y_close = {spec["symbol"]: {} for spec in symbols}
    for spec in symbols:
        parsed = yahoo_by.get(spec["symbol"]) or {}
        for d, row in (parsed.get("days") or {}).items():
            y_close[spec["symbol"]][d] = num(row.get("close"))

    for day in days:
        compact = ymd(day)
        counts = empty_day()
        counts["universe"] = len(symbols)
        krx_by_code: dict[str, dict] = {}
        bas_mismatch = 0
        for suffix in ("kospi_equity", "kosdaq_equity"):
            name = f"{compact}_{suffix}.json"
            path = krx_dir / name
            expected = (frozen.get("raw") or {}).get(name)
            if not path.is_file():
                hash_missing += 1
                continue
            digest = sha256_file(path)
            krx_file_hashes[name] = digest
            if expected:
                if digest == expected:
                    hash_ok += 1
                else:
                    hash_bad += 1
            payload = load_json(path)
            block = payload.get("OutBlock_1") if isinstance(payload, dict) else None
            if not isinstance(block, list):
                continue
            for row in block:
                code = str(row.get("ISU_CD") or "")
                if not code:
                    continue
                if str(row.get("BAS_DD") or "") != compact:
                    bas_mismatch += 1
                krx_by_code[f"{suffix}:{code}"] = row
        counts["krx_bas_dd_mismatch"] = bas_mismatch

        for spec in symbols:
            key = f"{spec['suffix']}:{spec['code']}"
            raw_krx = krx_by_code.get(key)
            krx = krx_fields(raw_krx) if raw_krx else None
            yrow = ((yahoo_by.get(spec["symbol"]) or {}).get("days") or {}).get(day)
            kind = classify_pair(krx, yrow)
            if kind in ("exact_ohlcv_close", "volume_only_close", "price_diff_close_only", "price_diff_ohl_also"):
                counts["comparable"] += 1
            if kind in ("price_diff_close_only", "price_diff_ohl_also"):
                counts["price_diff_close"] += 1
            counts[kind] += 1

            if krx and yrow and num(yrow.get("close")) is not None:
                yc = num(yrow.get("close"))
                ya = num(yrow.get("adjclose"))
                kc = krx.get("close")
                if eq(yc, kc, PRICE_EPS) and eq(ya, kc, PRICE_EPS):
                    counts["close_equal_adj_equal_krx"] += 1
                elif eq(yc, kc, PRICE_EPS) and not eq(ya, kc, PRICE_EPS):
                    counts["close_equal_adj_differs_krx"] += 1
                if eq(ya, kc, PRICE_EPS):
                    counts["adjclose_equals_krx_close"] += 1
                if yc and kc and yc > 0 and kc > 0:
                    ratio_by_symbol[spec["symbol"]].append(yc / kc)

            if spec["symbol"] in FOCUS and day in FOCUS_DAYS:
                focus[spec["symbol"]][day] = {
                    "identity": {
                        "request_day": day,
                        "request_yyyymmdd": compact,
                        "krx_file": f"{compact}_{spec['suffix']}.json",
                        "krx_file_sha256": krx_file_hashes.get(f"{compact}_{spec['suffix']}.json"),
                        "krx_BAS_DD": None if krx is None else krx.get("BAS_DD"),
                        "krx_BAS_DD_matches_request": None if krx is None else krx.get("BAS_DD") == compact,
                        "krx_ISU_CD": None if krx is None else krx.get("ISU_CD"),
                        "universe_code": spec["code"],
                        "universe_symbol": spec["symbol"],
                        "yahoo_meta_symbol": (yahoo_by.get(spec["symbol"]) or {}).get("meta_symbol"),
                        "yahoo_identity_ok": (yahoo_by.get(spec["symbol"]) or {}).get("identity_ok"),
                        "yahoo_timestamp": None if yrow is None else yrow.get("timestamp"),
                        "yahoo_timestamp_kst": None if yrow is None else yrow.get("timestamp_kst"),
                        "yahoo_kst_day": None if yrow is None else day_kst(yrow["timestamp"]) if yrow.get("timestamp") else None,
                    },
                    "krx": krx,
                    "yahoo": yrow,
                    "class": kind,
                    "yahoo_raw_sha256": (yahoo_by.get(spec["symbol"]) or {}).get("raw_sha256"),
                }

        daily[day] = counts
        for k, v in counts.items():
            totals[k] += v

    krx_close: dict[str, dict[str, float | None]] = {spec["symbol"]: {} for spec in symbols}
    for day in days:
        compact = ymd(day)
        tables: dict[str, dict[str, float | None]] = {"kospi_equity": {}, "kosdaq_equity": {}}
        for suffix in tables:
            path = krx_dir / f"{compact}_{suffix}.json"
            if not path.is_file():
                continue
            payload = load_json(path)
            block = payload.get("OutBlock_1") if isinstance(payload, dict) else None
            if not isinstance(block, list):
                continue
            for row in block:
                tables[suffix][str(row.get("ISU_CD") or "")] = num(row.get("TDD_CLSPRC"))
        for spec in symbols:
            krx_close[spec["symbol"]][day] = tables[spec["suffix"]].get(spec["code"])

    shift_tot = Counter()
    shift_daily: dict[str, dict[str, int]] = {}
    for day in days:
        c = {
            "shift_yahoo_equals_krx_prev": 0,
            "shift_yahoo_equals_krx_next": 0,
            "shift_krx_equals_yahoo_prev": 0,
            "shift_krx_equals_yahoo_next": 0,
            "yahoo_next_close_equals_krx_today": 0,
        }
        pd, nd = prev_of[day], next_of[day]
        for spec in symbols:
            kc = krx_close[spec["symbol"]].get(day)
            yc = y_close[spec["symbol"]].get(day)
            if yc is not None and kc is not None and not eq(yc, kc, PRICE_EPS):
                if pd and eq(yc, krx_close[spec["symbol"]].get(pd), PRICE_EPS):
                    c["shift_yahoo_equals_krx_prev"] += 1
                if nd and eq(yc, krx_close[spec["symbol"]].get(nd), PRICE_EPS):
                    c["shift_yahoo_equals_krx_next"] += 1
                if pd and eq(kc, y_close[spec["symbol"]].get(pd), PRICE_EPS):
                    c["shift_krx_equals_yahoo_prev"] += 1
                if nd and eq(kc, y_close[spec["symbol"]].get(nd), PRICE_EPS):
                    c["shift_krx_equals_yahoo_next"] += 1
            if kc is not None and nd and eq(y_close[spec["symbol"]].get(nd), kc, PRICE_EPS) and (yc is None or not eq(yc, kc, PRICE_EPS)):
                c["yahoo_next_close_equals_krx_today"] += 1
        shift_daily[day] = c
        daily[day].update(c)
        for k, v in c.items():
            shift_tot[k] += v
            totals[k] = totals.get(k, 0) + v if k not in totals else totals[k]
    # totals already had placeholder zeros from first pass; overwrite with real shift totals
    for k, v in shift_tot.items():
        totals[k] = v

    constant_ratio = []
    mixed_ratio = 0
    near_one = 0
    for symbol, ratios in ratio_by_symbol.items():
        if len(ratios) < 20:
            continue
        ratios_sorted = sorted(ratios)
        mid = ratios_sorted[len(ratios_sorted) // 2]
        span = ratios_sorted[-1] - ratios_sorted[0]
        if abs(mid - 1.0) <= 0.002 and span <= 0.01:
            near_one += 1
        elif span <= 0.01 and abs(mid - 1.0) > 0.002:
            constant_ratio.append({
                "symbol": symbol,
                "n": len(ratios),
                "median_yahoo_over_krx": mid,
                "min": ratios_sorted[0],
                "max": ratios_sorted[-1],
            })
        else:
            mixed_ratio += 1
    constant_ratio.sort(key=lambda x: x["symbol"])

    def slice_days(keys: tuple[str, ...]) -> dict[str, int]:
        acc = empty_day()
        for d in keys:
            if d not in daily:
                continue
            for k, v in daily[d].items():
                acc[k] = acc.get(k, 0) + v
        return acc

    window = slice_days(FOCUS_WINDOW)
    rest_days = tuple(d for d in days if d not in FOCUS_WINDOW)
    rest = slice_days(rest_days)

    def rate(block: dict[str, int], key: str) -> float | None:
        n = block.get("universe") or 0
        if not n:
            return None
        return block.get(key, 0) / n

    top_null = sorted(
        ((d, daily[d]["yahoo_timestamp_null_ohlcv"]) for d in days),
        key=lambda x: (-x[1], x[0]),
    )[:10]
    top_price = sorted(
        ((d, daily[d]["price_diff_close"]) for d in days),
        key=lambda x: (-x[1], x[0]),
    )[:10]

    input_hashes = {
        "universe_v2": sha256_file(uni_path),
        "candidates": sha256_file(cand_path),
        "file_hashes": sha256_file(hash_path),
        "v2_dataset_json": sha256_file(yahoo_dir / "dataset.json") if (yahoo_dir / "dataset.json").is_file() else None,
        "v2_universe_json": sha256_file(yahoo_dir / "universe.json") if (yahoo_dir / "universe.json").is_file() else None,
        "krx_raw_manifest_sha256": sha256_text("\n".join(f"{k}={v}" for k, v in sorted(krx_file_hashes.items()))),
        "krx_raw_files_hashed": len(krx_file_hashes),
        "frozen_raw_hash_ok": hash_ok,
        "frozen_raw_hash_mismatch": hash_bad,
        "frozen_raw_file_missing": hash_missing,
    }

    reproduce = {
        "command": (
            "python docs/research/krx_yahoo_ohlcv_compare.py "
            "--universe docs/historical-top100-20241008-20251002/universe-v2.json "
            "--candidates docs/historical-top100-20241008-20251002/candidates.json "
            "--file-hashes docs/historical-top100-20241008-20251002/file-hashes.json "
            "--krx-raw <GITIGNORED_KRX_RAW_DIR> "
            "--yahoo-v2 <LOCAL_V2_DATASET_DIR> "
            "--out docs/krx-yahoo-ohlcv-compare-20241008-20251002"
        ),
        "krx_raw_dir_used": str(krx_dir),
        "yahoo_v2_dir_used": str(yahoo_dir),
        "no_http": True,
    }

    summary = {
        "schema": 2,
        "kind": "krx_yahoo_ohlcv_compare_v2",
        "price_diff_split": {
            "note": "price_diff_close is the parent of close-only vs OHL-also. No prices were scaled.",
            "close_only": "open/high/low match, close differs",
            "ohl_also": "at least one of open/high/low differs",
        },
        "period": {
            "from": days[0],
            "to": days[-1],
            "sessions": len(days),
            "symbols": len(symbols),
            "included": sum(1 for s in symbols if s.get("status") == "included"),
            "not_included": [s for s in symbols if s.get("status") != "included"],
        },
        "writes": False,
        "no_recovery_decision": True,
        "no_provider_truth_claim": True,
        "identity": {
            "request_date": "filename YYYYMMDD of KRX both-market originals",
            "krx_date_field": "BAS_DD",
            "yahoo_date": "unix timestamp converted with Asia/Seoul",
            "symbol": "universe code+suffix vs KRX ISU_CD vs Yahoo meta.symbol",
            "yahoo": yahoo_stats,
            "krx_bas_dd_mismatch_total": totals.get("krx_bas_dd_mismatch", 0),
        },
        "totals": totals,
        "sep18_19": window,
        "outside_sep18_19": rest,
        "concentration": {
            "null_rate_sep18_19": rate(window, "yahoo_timestamp_null_ohlcv"),
            "null_rate_other_days": rate(rest, "yahoo_timestamp_null_ohlcv"),
            "price_diff_rate_sep18_19": rate(window, "price_diff_close"),
            "price_diff_rate_other_days": rate(rest, "price_diff_close"),
            "close_only_rate_sep18_19": rate(window, "price_diff_close_only"),
            "close_only_rate_other_days": rate(rest, "price_diff_close_only"),
            "ohl_also_rate_sep18_19": rate(window, "price_diff_ohl_also"),
            "ohl_also_rate_other_days": rate(rest, "price_diff_ohl_also"),
            "volume_only_rate_sep18_19": rate(window, "volume_only_close"),
            "volume_only_rate_other_days": rate(rest, "volume_only_close"),
            "top_days_yahoo_null": [{"date": d, "n": n} for d, n in top_null],
            "top_days_price_diff": [{"date": d, "n": n} for d, n in top_price],
            "null_concentrated_on_sep18_19": (window["yahoo_timestamp_null_ohlcv"] > 0)
            and (window["yahoo_timestamp_null_ohlcv"] >= rest["yahoo_timestamp_null_ohlcv"]),
            "price_diff_only_on_sep18_19": rest["price_diff_close"] == 0 and window["price_diff_close"] > 0,
        },
        "shift_diagnosis_not_applied": {
            "note": "Counts only. Dates and prices were not moved or scaled.",
            "totals": dict(shift_tot),
            "sep19": shift_daily.get("2025-09-19"),
            "sep18": shift_daily.get("2025-09-18"),
            "sep22": shift_daily.get("2025-09-22"),
        },
        "ratio_diagnosis_not_applied": {
            "note": "yahoo close / krx close on comparable numeric pairs. No scaling applied.",
            "symbols_with_ge20_ratios": near_one + len(constant_ratio) + mixed_ratio,
            "near_one": near_one,
            "constant_ratio_not_one": constant_ratio[:30],
            "constant_ratio_not_one_count": len(constant_ratio),
            "mixed_ratio": mixed_ratio,
        },
        "focus": focus,
        "daily": daily,
        "input_hashes": input_hashes,
        "reproduce": reproduce,
        "price_fields": {
            "krx": ["TDD_OPNPRC", "TDD_HGPRC", "TDD_LWPRC", "TDD_CLSPRC", "ACC_TRDVOL"],
            "yahoo_close_path": "chart.result[0].indicators.quote[0]",
            "yahoo_adjclose_path": "chart.result[0].indicators.adjclose[0].adjclose",
            "not_used": "TOP100 ACC_TRDVAL-only price inference",
        },
    }
    write_json(out_dir / "summary.json", summary)
    print(json.dumps({
        "out": str(out_dir / "summary.json"),
        "comparable": totals["comparable"],
        "exact": totals["exact_ohlcv_close"],
        "volume_only": totals["volume_only_close"],
        "price_diff": totals["price_diff_close"],
        "close_only": totals["price_diff_close_only"],
        "ohl_also": totals["price_diff_ohl_also"],
        "yahoo_null": totals["yahoo_timestamp_null_ohlcv"],
        "yahoo_absent": totals["yahoo_absent"],
        "krx_absent": totals["krx_absent"],
        "hash_ok": hash_ok,
        "hash_bad": hash_bad,
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
