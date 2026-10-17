#!/usr/bin/env python3
"""Re-count stored KRX originals: normal OHLCV vs abnormal vs missing.

Does not fetch, fill, mix Yahoo, scale prices, or run accounts.
Does not treat 0-price rows as MA240 bars or as confirmed halts.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from collections import Counter
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
COMPARE = ROOT / "docs" / "research" / "krx_yahoo_ohlcv_compare.py"
QUALITY = ROOT / "docs" / "research" / "krx_prep_bars_quality.py"
UNIVERSE = ROOT / "docs" / "historical-top100-20241008-20251002" / "universe-v2.json"
CANDIDATES = ROOT / "docs" / "historical-top100-20241008-20251002" / "candidates.json"
PREP_DOCS = ROOT / "docs" / "krx-prep-bars-before-20241008"
OUT = ROOT / "docs" / "krx-prep-ohlcv-recount-20241008"
PRICE_BASIS = "KRX TDD 원본, 수정 여부 미확정"
OTHER = {"kospi_equity": "kosdaq_equity", "kosdaq_equity": "kospi_equity"}
SPLIT_HI = 1.9
SPLIT_LO = 1.0 / 1.9
DAILY_LIMIT = 0.301


def load_mod(path: Path, name: str):
    import importlib.util
    spec = importlib.util.spec_from_file_location(name, path)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def znum(v: Any) -> float | None:
    if v is None:
        return None
    if isinstance(v, bool):
        return None
    if isinstance(v, (int, float)):
        return float(v)
    s = str(v).replace(",", "").strip()
    if s in ("", "-"):
        return None
    try:
        return float(s)
    except ValueError:
        return None


def classify_volume(volume: Any) -> str:
    if volume is None:
        return "null"
    if volume < 0:
        return "negative"
    if volume == 0:
        return "zero"
    return "positive"


def classify_ohlc(rec: dict[str, Any]) -> str:
    o, h, l, c = rec.get("open"), rec.get("high"), rec.get("low"), rec.get("close")
    if o is None or h is None or l is None or c is None:
        return "ohlc_null"
    if o == 0 and h == 0 and l == 0:
        if c > 0:
            return "ohl_zero_close_positive"
        if c == 0:
            return "ohlc_all_zero"
        return "ohl_zero_close_non_positive"
    if any(x < 0 for x in (o, h, l, c)):
        return "ohlc_negative"
    if any(x <= 0 for x in (o, h, l, c)):
        return "ohlc_non_positive_other"
    if h < l:
        return "high_lt_low"
    if o < l or o > h:
        return "open_outside_hl"
    if c < l or c > h:
        return "close_outside_hl"
    return "normal_ohlc"


def classify_row(rec: dict[str, Any]) -> dict[str, Any]:
    ohlc = classify_ohlc(rec)
    volume = classify_volume(rec.get("volume"))
    normal_ohlc = ohlc == "normal_ohlc"
    normal_ohlcv = normal_ohlc and volume == "positive"
    if ohlc == "ohl_zero_close_positive":
        kind = f"ohl_zero_close_positive_volume_{volume}"
    elif ohlc == "ohlc_all_zero" and volume == "zero":
        kind = "ohlcv_all_zero"
    elif normal_ohlc:
        kind = "normal_ohlcv" if volume == "positive" else f"normal_ohlc_volume_{volume}"
    else:
        kind = ohlc
    return {
        "ohlc": ohlc,
        "volume": volume,
        "kind": kind,
        "normal_ohlc": normal_ohlc,
        "normal_ohlcv": normal_ohlcv,
    }


def is_abnormal(kind: str) -> bool:
    return kind not in {"normal", "normal_ohlcv"} and not str(kind).startswith("normal_ohlc_volume_")


def empty_symbol(spec: dict[str, Any]) -> dict[str, Any]:
    return {
        "symbol": spec["symbol"],
        "code": spec["code"],
        "name": spec["name"],
        "market": spec["market"],
        "suffix": spec["suffix"],
        "prep_days": 240,
        "eval_days": 243,
        "prep_assigned_rows": 0,
        "prep_normal_ohlc": 0,
        "prep_normal_ohlcv": 0,
        "prep_abnormal_rows": 0,
        "prep_no_row": 0,
        "prep_other_market_only": 0,
        "prep_both_markets": 0,
        "eval_assigned_rows": 0,
        "eval_normal_ohlc": 0,
        "eval_normal_ohlcv": 0,
        "eval_abnormal_rows": 0,
        "eval_no_row": 0,
        "eval_other_market_only": 0,
        "eval_both_markets": 0,
        "prep_kind_counts": {},
        "eval_kind_counts": {},
        "ma240_not_asserted": True,
    }


def bump_kind(store: dict[str, int], kind: str) -> None:
    store[kind] = store.get(kind, 0) + 1


def load_day_tables(cmpmod: Any, raw_dir: Path, compact: str) -> dict[str, dict[str, dict]]:
    tables: dict[str, dict[str, dict]] = {"kospi_equity": {}, "kosdaq_equity": {}}
    for suffix in tables:
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
            rec = cmpmod.krx_fields(raw)
            rec["suffix"] = suffix
            rec["ISU_NM"] = raw.get("ISU_NM")
            rec["MKT_NM"] = raw.get("MKT_NM")
            rec["SECT_TP_NM"] = raw.get("SECT_TP_NM")
            rec["class"] = classify_row(rec)
            rec["kind"] = rec["class"]["kind"]
            tables[suffix][code] = rec
    return tables


def file_hash(hashes: dict[str, str], raw_dir: Path, name: str) -> str | None:
    if name in hashes:
        return hashes[name]
    path = raw_dir / name
    if path.is_file():
        got = sha256_file(path)
        hashes[name] = got
        return got
    return None


def observe_day(
    spec: dict[str, Any],
    tables: dict[str, dict[str, dict]],
    day: str,
    compact: str,
    scope: str,
    per: dict[str, dict],
    kind_tot: Counter,
    abnormal_days: list[dict[str, Any]],
    cross_hits: list[dict[str, Any]],
    issue_syms: set[str],
    hashes: dict[str, str],
    raw_dir: Path,
) -> dict[str, Any] | None:
    row = per[spec["symbol"]]
    assigned = spec["suffix"]
    other = OTHER[assigned]
    rec_a = tables.get(assigned, {}).get(spec["code"])
    rec_o = tables.get(other, {}).get(spec["code"])
    prefix = "prep" if scope == "prep" else "eval"
    if rec_a is not None and rec_o is not None:
        row[f"{prefix}_both_markets"] += 1
        cross_hits.append({
            "scope": scope,
            "day": day,
            "symbol": spec["symbol"],
            "code": spec["code"],
            "link": "ISU_CD",
            "assigned_suffix": assigned,
            "assigned_mkt": rec_a.get("MKT_NM"),
            "assigned_name": rec_a.get("ISU_NM"),
            "other_suffix": other,
            "other_mkt": rec_o.get("MKT_NM"),
            "other_name": rec_o.get("ISU_NM"),
            "names_equal": rec_a.get("ISU_NM") == rec_o.get("ISU_NM"),
        })
    if rec_a is None and rec_o is not None:
        row[f"{prefix}_other_market_only"] += 1
        cross_hits.append({
            "scope": scope,
            "day": day,
            "symbol": spec["symbol"],
            "code": spec["code"],
            "link": "ISU_CD",
            "kind": "assigned_missing_other_present",
            "assigned_suffix": assigned,
            "other_suffix": other,
            "other_mkt": rec_o.get("MKT_NM"),
            "other_name": rec_o.get("ISU_NM"),
            "other_kind": rec_o.get("kind"),
            "other_close": rec_o.get("close"),
        })
    if rec_a is None:
        row[f"{prefix}_no_row"] += 1
        bump_kind(row[f"{prefix}_kind_counts"], "no_row")
        kind_tot[f"{prefix}_no_row"] += 1
        return None
    row[f"{prefix}_assigned_rows"] += 1
    kind = rec_a["kind"]
    cls = rec_a.get("class") or classify_row(rec_a)
    bump_kind(row[f"{prefix}_kind_counts"], kind)
    kind_tot[f"{prefix}_{kind}"] += 1
    if cls.get("normal_ohlc"):
        row[f"{prefix}_normal_ohlc"] += 1
    if cls.get("normal_ohlcv"):
        row[f"{prefix}_normal_ohlcv"] += 1
    if is_abnormal(kind):
        row[f"{prefix}_abnormal_rows"] += 1
        if spec["symbol"] in issue_syms:
            fname = f"{compact}_{assigned}.json"
            abnormal_days.append({
                "scope": scope,
                "day": day,
                "symbol": spec["symbol"],
                "code": spec["code"],
                "name": spec["name"],
                "suffix": assigned,
                "kind": kind,
                "halt_status": "미확정",
                "halt_evidence": "TDD 행에 거래정지 플래그가 없다. 0-OHL 패턴만으로 정지라고 보지 않는다.",
                "open": rec_a.get("open"),
                "high": rec_a.get("high"),
                "low": rec_a.get("low"),
                "close": rec_a.get("close"),
                "volume": rec_a.get("volume"),
                "amount_won": rec_a.get("amount_won"),
                "cmpprevdd": rec_a.get("cmpprevdd"),
                "fluc_rt": rec_a.get("fluc_rt"),
                "mkt_nm": rec_a.get("MKT_NM"),
                "isu_nm": rec_a.get("ISU_NM"),
                "sect_tp_nm": rec_a.get("SECT_TP_NM"),
                "file": fname,
                "file_sha256": file_hash(hashes, raw_dir, fname),
            })
    return rec_a


def scan_breaks(series: list[tuple[str, float, str]], calendar: list[str]) -> list[dict[str, Any]]:
    """Flag close-to-close jumps. Does not attribute split/merge without confirmation."""
    idx = {d: i for i, d in enumerate(calendar)}
    out = []
    for i in range(1, len(series)):
        d0, c0, k0 = series[i - 1]
        d1, c1, k1 = series[i]
        if c0 <= 0 or c1 <= 0:
            continue
        ratio = c1 / c0
        cal_gap = idx[d1] - idx[d0]
        rec = {
            "from": d0,
            "to": d1,
            "from_close": c0,
            "to_close": c1,
            "from_kind": k0,
            "to_kind": k1,
            "ratio": ratio,
            "calendar_steps": cal_gap,
            "cause": "미확정",
            "adjusted": False,
        }
        if ratio >= SPLIT_HI or ratio <= SPLIT_LO:
            rec["rule"] = "close_ratio_ge_1.9_or_le_1_over_1.9"
            rec["note"] = "분할·병합 후보라 단정하지 않는다. 자동 보정 없음."
            out.append(rec)
        elif cal_gap == 1 and abs(ratio - 1.0) > DAILY_LIMIT:
            rec["rule"] = "adjacent_days_abs_return_gt_0.301"
            rec["note"] = "일일 가격제한 30%를 넘는 의심. 제한폭 예외·재개·기업행사는 확인하지 않으면 원인 미확정."
            out.append(rec)
    return out


def request_482_note() -> dict[str, Any]:
    return {
        "schema": 1,
        "kind": "krx_prep_bars_request_482_breakdown_v1",
        "total_http_requests": 482,
        "auto_retries": 0,
        "auto_retry_definition": (
            "historical-amount-top100-pilot._get_with_retry: HTTP가 200이 아니고 auth가 아니면 "
            "summary['retries']를 올리고 최대 3회 반복. collect.json의 retries 필드."
        ),
        "empty_http_200_is_not_auto_retry": True,
        "parts": [
            {
                "id": "first_sequential",
                "requests": 478,
                "kind": "new_http",
                "note": "1차 --collect. PR #89 20241007 2파일은 복사(HTTP 아님). 나머지 239일×2 - 재사용 2 + 20241001 빈 응답 2 = 478.",
            },
            {
                "id": "manual_rerequest_20241001",
                "requests": 2,
                "kind": "manual_rerequest",
                "note": "2차 --collect. 자동 재시도가 아니다. 빈 캐시를 지운 뒤 같은 날짜를 사람이 다시 실행했다. HTTP 200 빈 본문은 _get_with_retry 조건(비-200)에 해당하지 않는다.",
            },
            {
                "id": "calendar_revision_20231013",
                "requests": 2,
                "kind": "new_http",
                "note": "3차 --collect. 20241001을 공식 임시공휴일로 달력에서 뺀 뒤 20231013을 새로 받았다. 재시도가 아니다.",
            },
        ],
        "not_counted_as_http": {
            "pr89_reuse_copy": 2,
            "files": ["20241007_kospi_equity.json", "20241007_kosdaq_equity.json"],
        },
    }


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--prep-raw", required=True)
    p.add_argument("--eval-raw", required=True)
    p.add_argument("--out", default=str(OUT))
    args = p.parse_args()
    cmpmod = load_mod(COMPARE, "krx_yahoo_ohlcv_compare")
    days_payload = cmpmod.load_json(PREP_DOCS / "trading-days.json")
    prep_days = [f"{d[0:4]}-{d[4:6]}-{d[6:8]}" for d in days_payload["days"]]
    eval_days = list(cmpmod.load_json(CANDIDATES)["days"])
    symbols = cmpmod.universe_rows(cmpmod.load_json(UNIVERSE))
    if len(symbols) != 1248:
        raise SystemExit(f"expected 1248 symbols, got {len(symbols)}")
    if len(prep_days) != 240 or len(eval_days) != 243:
        raise SystemExit("unexpected day counts")
    q = cmpmod.load_json(PREP_DOCS / "quality.json")
    prep_issue = {r["symbol"] for r in q["rows"] if r["prep_ohlc_issues"]}
    eval_issue = {r["symbol"] for r in q["rows"] if r["eval_ohlc_issues"]}
    issue_syms = prep_issue | eval_issue

    prep_raw = Path(args.prep_raw)
    eval_raw = Path(args.eval_raw)
    prep_hashes = dict((cmpmod.load_json(PREP_DOCS / "file-hashes.json") or {}).get("raw") or {})
    eval_hash_path = ROOT / "docs" / "historical-top100-20241008-20251002" / "file-hashes.json"
    eval_hashes: dict[str, str] = {}
    if eval_hash_path.is_file():
        eh = cmpmod.load_json(eval_hash_path)
        raw = eh.get("raw") if isinstance(eh, dict) else None
        if isinstance(raw, dict):
            eval_hashes = {k: (v if isinstance(v, str) else (v or {}).get("sha256")) for k, v in raw.items()}
            eval_hashes = {k: v for k, v in eval_hashes.items() if v}

    per = {spec["symbol"]: empty_symbol(spec) for spec in symbols}
    kind_tot: Counter = Counter()
    abnormal_all: list[dict[str, Any]] = []
    cross_hits: list[dict[str, Any]] = []
    assigned_closes: dict[str, list[tuple[str, float, str]]] = {s["symbol"]: [] for s in symbols}
    either_closes: dict[str, list[tuple[str, float, str]]] = {s["symbol"]: [] for s in symbols}

    def walk(days: list[str], raw_dir: Path, hashes: dict[str, str], scope: str) -> None:
        for day in days:
            compact = day.replace("-", "")
            tables = load_day_tables(cmpmod, raw_dir, compact)
            for spec in symbols:
                rec_a = observe_day(
                    spec, tables, day, compact, scope, per, kind_tot, abnormal_all,
                    cross_hits, issue_syms, hashes, raw_dir,
                )
                rec_o = tables.get(OTHER[spec["suffix"]], {}).get(spec["code"])
                chosen = rec_a if rec_a is not None else rec_o
                if rec_a is not None:
                    c = rec_a.get("close")
                    if c is not None and c > 0:
                        assigned_closes[spec["symbol"]].append((day, c, rec_a["kind"]))
                if chosen is not None:
                    c = chosen.get("close")
                    if c is not None and c > 0:
                        src = spec["suffix"] if rec_a is not None else OTHER[spec["suffix"]]
                        either_closes[spec["symbol"]].append((day, c, f"{chosen['kind']}:{src}"))

    walk(prep_days, prep_raw, prep_hashes, "prep")
    walk(eval_days, eval_raw, eval_hashes, "eval")

    calendar = prep_days + eval_days
    breaks_assigned = []
    breaks_either = []
    for spec in symbols:
        for rec in scan_breaks(assigned_closes[spec["symbol"]], calendar):
            rec["symbol"] = spec["symbol"]
            rec["code"] = spec["code"]
            rec["name"] = spec["name"]
            rec["lookup"] = "assigned_suffix"
            breaks_assigned.append(rec)
        for rec in scan_breaks(either_closes[spec["symbol"]], calendar):
            rec["symbol"] = spec["symbol"]
            rec["code"] = spec["code"]
            rec["name"] = spec["name"]
            rec["lookup"] = "either_market_same_ISU_CD"
            breaks_either.append(rec)

    issue_abnormal = [r for r in abnormal_all if r["symbol"] in issue_syms]
    type_counts = Counter(r["kind"] for r in issue_abnormal)
    other_only = [h for h in cross_hits if h.get("kind") == "assigned_missing_other_present"]
    both = [h for h in cross_hits if "assigned_mkt" in h]
    rows = sorted(per.values(), key=lambda r: r["symbol"])

    hist_normal = Counter(r["prep_normal_ohlcv"] for r in rows)
    n240_old = sum(1 for r in q["rows"] if r["prep_bars"] >= 240)
    n240_normal = sum(1 for r in rows if r["prep_normal_ohlcv"] >= 240)

    summary = {
        "schema": 1,
        "kind": "krx_prep_ohlcv_recount_v1",
        "price_basis": PRICE_BASIS,
        "no_fill": True,
        "no_yahoo_mix": True,
        "no_ratio_adjust": True,
        "no_account_run": True,
        "ma240_prep_not_asserted": True,
        "universe": 1248,
        "prep_days": 240,
        "eval_days": 243,
        "old_quality_prep_bars_counted_zero_price": True,
        "old_quality_symbols_prep_bars_240": n240_old,
        "symbols_prep_normal_ohlcv_240": n240_normal,
        "note_ma240": "정상 OHLCV 240이어도 MA240 준비 완료로 단정하지 않는다. 수정 여부 미확정.",
        "prep_totals": {
            "assigned_rows": sum(r["prep_assigned_rows"] for r in rows),
            "normal_ohlcv": sum(r["prep_normal_ohlcv"] for r in rows),
            "abnormal_rows": sum(r["prep_abnormal_rows"] for r in rows),
            "no_row": sum(r["prep_no_row"] for r in rows),
            "other_market_only": sum(r["prep_other_market_only"] for r in rows),
            "both_markets": sum(r["prep_both_markets"] for r in rows),
        },
        "eval_totals": {
            "assigned_rows": sum(r["eval_assigned_rows"] for r in rows),
            "normal_ohlcv": sum(r["eval_normal_ohlcv"] for r in rows),
            "abnormal_rows": sum(r["eval_abnormal_rows"] for r in rows),
            "no_row": sum(r["eval_no_row"] for r in rows),
            "other_market_only": sum(r["eval_other_market_only"] for r in rows),
            "both_markets": sum(r["eval_both_markets"] for r in rows),
        },
        "kind_totals": dict(kind_tot),
        "old_issue_symbols": {"prep": sorted(prep_issue), "eval": sorted(eval_issue)},
        "old_issue_abnormal_type_counts": dict(type_counts),
        "old_issue_abnormal_day_rows": len(issue_abnormal),
        "halt_status": "미확정",
        "cross_market": {
            "link": "ISU_CD only. Names are recorded, not used to join.",
            "assigned_missing_other_present_events": len(other_only),
            "both_markets_same_day_events": len(both),
            "symbols_with_other_market_only_prep": sum(1 for r in rows if r["prep_other_market_only"]),
            "symbols_with_other_market_only_eval": sum(1 for r in rows if r["eval_other_market_only"]),
        },
        "price_breaks": {
            "rules": [
                f"positive close pair, ratio >= {SPLIT_HI} or <= {SPLIT_LO:.6f}",
                f"adjacent calendar days, |ratio-1| > {DAILY_LIMIT}",
            ],
            "assigned_suffix_n": len(breaks_assigned),
            "either_market_n": len(breaks_either),
            "cause_policy": "분할·병합·정지는 별도 근거 없으면 미확정. 자동 보정 없음.",
        },
        "prep_normal_ohlcv_histogram": {str(k): hist_normal[k] for k in sorted(hist_normal)},
        "request_482": request_482_note(),
    }
    out = Path(args.out)
    write_json(out / "summary.json", summary)
    write_json(out / "symbols.json", {"schema": 1, "price_basis": PRICE_BASIS, "rows": rows})
    write_json(out / "abnormal-days-old-issues.json", {
        "schema": 1,
        "price_basis": PRICE_BASIS,
        "halt_status": "미확정",
        "prep_issue_symbols": sorted(prep_issue),
        "eval_issue_symbols": sorted(eval_issue),
        "type_counts": dict(type_counts),
        "rows": issue_abnormal,
    })
    issue_counts: dict[tuple[str, str], dict[str, Any]] = {}
    for rec in issue_abnormal:
        key = (rec["symbol"], rec["scope"])
        if key not in issue_counts:
            issue_counts[key] = {
                "symbol": rec["symbol"],
                "code": rec["code"],
                "name": rec["name"],
                "scope": rec["scope"],
                "kind": rec["kind"],
                "n": 0,
                "first": rec["day"],
                "last": rec["day"],
            }
        issue_counts[key]["n"] += 1
        if rec["day"] < issue_counts[key]["first"]:
            issue_counts[key]["first"] = rec["day"]
        if rec["day"] > issue_counts[key]["last"]:
            issue_counts[key]["last"] = rec["day"]
    write_json(out / "issue-symbol-counts.json", {
        "schema": 1,
        "price_basis": PRICE_BASIS,
        "halt_status": "미확정",
        "n": len(issue_counts),
        "rows": sorted(issue_counts.values(), key=lambda r: (r["scope"], r["symbol"])),
    })
    write_json(out / "cross-market.json", {
        "schema": 1,
        "price_basis": PRICE_BASIS,
        "link": "ISU_CD",
        "name_not_used_to_join": True,
        "assigned_missing_other_present": other_only,
        "both_markets_same_day": both,
    })
    write_json(out / "price-breaks.json", {
        "schema": 1,
        "price_basis": PRICE_BASIS,
        "no_auto_adjust": True,
        "rules": summary["price_breaks"]["rules"],
        "assigned_suffix": breaks_assigned,
        "either_market_same_ISU_CD": breaks_either,
    })
    write_json(out / "request-482.json", request_482_note())
    print(json.dumps({
        "prep_normal": summary["prep_totals"]["normal_ohlcv"],
        "prep_abnormal": summary["prep_totals"]["abnormal_rows"],
        "prep_no_row": summary["prep_totals"]["no_row"],
        "prep_other_only": summary["prep_totals"]["other_market_only"],
        "eval_normal": summary["eval_totals"]["normal_ohlcv"],
        "eval_abnormal": summary["eval_totals"]["abnormal_rows"],
        "issue_abnormal_rows": len(issue_abnormal),
        "other_only_events": len(other_only),
        "breaks_assigned": len(breaks_assigned),
        "normal_240": n240_normal,
        "old_bars_240": n240_old,
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
