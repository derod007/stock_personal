#!/usr/bin/env python3
"""Build a research dataset from stored KRX TDD originals.

Keeps original values. Does not interpolate, mix Yahoo, scale prices, drop
0-price rows, or exclude a name's past because of later anomalies.
"""
from __future__ import annotations

import argparse
import json
from collections import Counter
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
RECOUNT = ROOT / "docs" / "research" / "krx_prep_ohlcv_recount.py"
COMPARE = ROOT / "docs" / "research" / "krx_yahoo_ohlcv_compare.py"
UNIVERSE = ROOT / "docs" / "historical-top100-20241008-20251002" / "universe-v2.json"
CANDIDATES = ROOT / "docs" / "historical-top100-20241008-20251002" / "candidates.json"
PREP_DOCS = ROOT / "docs" / "krx-prep-bars-before-20241008"
OLD_SUMMARY = ROOT / "docs" / "krx-prep-ohlcv-recount-20241008" / "summary.json"
OLD_BREAKS = ROOT / "docs" / "krx-prep-ohlcv-recount-20241008" / "price-breaks.json"
DOCS_OUT = ROOT / "docs" / "krx-tdd-dataset-20231013-20251002"
RAW_OUT = ROOT / "data" / "raw" / "research" / "krx-tdd-dataset-20231013-20251002"
PRICE_BASIS = "KRX TDD 원본, 수정 여부 미확정"
OTHER = {"kospi_equity": "kosdaq_equity", "kosdaq_equity": "kospi_equity"}

# Confirmed only. Identity is ISU_CD, not name.
TRANSFERS = {
    "022100.KS": {
        "code": "022100",
        "name": "포스코DX",
        "from_market": "KOSDAQ",
        "from_suffix": "kosdaq_equity",
        "to_market": "KOSPI",
        "to_suffix": "kospi_equity",
        "first_to_market_day": "2024-01-02",
        "identity": "ISU_CD=022100. 이름 문자열은 조인 키가 아니다.",
        "confirmed": True,
        "sources": [
            "KIND 사업보고서(2024-03-13) 주권상장: 유가증권시장 상장 2024년 01월 02일. https://kind.krx.co.kr/external/2024/03/13/001279/20240313003384/11011.htm",
        ],
    },
    "034230.KS": {
        "code": "034230",
        "name": "파라다이스",
        "from_market": "KOSDAQ",
        "from_suffix": "kosdaq_equity",
        "to_market": "KOSPI",
        "to_suffix": "kospi_equity",
        "first_to_market_day": "2024-06-24",
        "identity": "ISU_CD=034230. 회사 IR이 같은 코드를 이전상장 전후 종목으로 적는다.",
        "confirmed": True,
        "sources": [
            "파라다이스 IR FAQ: 종목코드 034230. 2024년 6월 24일 유가증권시장(KOSPI)로 이전상장. https://www.paradise.co.kr/ko/invest/ir/irfaq",
            "한국거래소 유가증권시장본부 안내를 인용한 보도: 2024-06-24 코스피 첫 거래. http://www.newswell.co.kr/news/articleView.html?idxno=9922",
        ],
    },
    "066970.KS": {
        "code": "066970",
        "name": "엘앤에프",
        "from_market": "KOSDAQ",
        "from_suffix": "kosdaq_equity",
        "to_market": "KOSPI",
        "to_suffix": "kospi_equity",
        "first_to_market_day": "2024-01-29",
        "identity": "ISU_CD=066970. 이름 문자열은 조인 키가 아니다.",
        "confirmed": True,
        "sources": [
            "KIND 분기보고서(2024-05-16): 2024년 1월 29일 코스닥시장에서 유가증권시장으로 이전상장. 유가증권시장 상장 2024년 01월 29일. https://kind.krx.co.kr/external/2024/05/16/001206/20240516002828/11013.htm",
            "KIND 신규상장 공시 접수번호 20240125000543. https://kind.krx.co.kr/common/disclsviewer.do?acptNo=20240125000543&docno=&method=searchInitInfo",
        ],
    },
}

# Official corporate-action matches for the 96 suspects. Empty until filled after scan.
# Do not invent ratio-adjust or drop rules.
CONFIRMED_ACTIONS: list[dict[str, Any]] = [
    {
        "symbol": "083450.KQ",
        "from": "2024-06-25",
        "to": "2024-06-26",
        "action": "무상증자 1주당 1주. 배정기준일 2024-06-27, 권리락일 2024-06-26",
        "source": "KIND 무상증자결정(2024-06-11) GST 083450 acptno=20240611000204. https://kind.krx.co.kr/common/disclsviewer.do?acptno=20240611000204",
    },
    {
        "symbol": "086280.KS",
        "from": "2024-07-11",
        "to": "2024-07-12",
        "action": "무상증자 1주당 1주. 배정기준일 2024-07-15(월). 직전 거래일 2024-07-12와 단절일이 같음",
        "source": "KIND 무상증자결정(2024-06-28) 현대글로비스 086280 acptno=20240628000276. KIND 사업보고서: 신주배정기준일 2024-07-15, 추가상장 2024-08-02. https://kind.krx.co.kr/common/disclsviewer.do?acptno=20240628000276",
    },
]


def load_recount():
    import importlib.util
    spec = importlib.util.spec_from_file_location("krx_prep_ohlcv_recount", RECOUNT)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


def dataset_status(cls: dict[str, Any] | None, has_row: bool) -> str:
    if not has_row:
        return "no_row"
    ohlc = cls["ohlc"] if cls else "ohlc_null"
    if ohlc == "ohl_zero_close_positive":
        return "ohl_zero"
    if cls and cls.get("normal_ohlcv"):
        return "normal_ohlcv"
    if cls and cls.get("normal_ohlc"):
        return "normal_ohlc_not_ohlcv"
    return "other_abnormal"


def load_top100(selected_dir: Path, eval_days: list[str]) -> dict[str, set[str]]:
    out: dict[str, set[str]] = {}
    if not selected_dir.is_dir():
        return out
    for day in eval_days:
        compact = day.replace("-", "")
        path = selected_dir / f"{compact}_top100.json"
        if not path.is_file():
            continue
        payload = json.loads(path.read_text(encoding="utf-8"))
        codes = {str(r.get("code") or "") for r in (payload.get("top100") or []) if r.get("code")}
        out[day] = codes
    return out


def pick_row(
    spec: dict[str, Any],
    tables: dict[str, dict[str, dict]],
    day: str,
    link: bool,
) -> tuple[dict[str, Any] | None, str]:
    assigned = spec["suffix"]
    rec_a = tables.get(assigned, {}).get(spec["code"])
    if rec_a is not None:
        return rec_a, "assigned"
    if not link:
        return None, "missing"
    xfer = TRANSFERS.get(spec["symbol"])
    if not xfer or not xfer.get("confirmed"):
        rec_o = tables.get(OTHER[assigned], {}).get(spec["code"])
        return rec_o, "other_unlinked" if rec_o is not None else "missing"
    if day >= xfer["first_to_market_day"]:
        return None, "missing"
    rec_o = tables.get(xfer["from_suffix"], {}).get(spec["code"])
    if rec_o is None:
        return None, "missing"
    if str(rec_o.get("MKT_NM") or "").upper() != xfer["from_market"]:
        return rec_o, "other_unlinked"
    return rec_o, "linked_prior_market"


def bar_record(
    spec: dict[str, Any],
    day: str,
    compact: str,
    scope: str,
    rec: dict[str, Any] | None,
    lookup: str,
    hashes: dict[str, str],
    raw_dir: Path,
    rc,
) -> dict[str, Any]:
    cls = rec.get("class") if rec else None
    suffix = rec.get("suffix") if rec else spec["suffix"]
    fname = f"{compact}_{suffix}.json" if rec else None
    return {
        "day": day,
        "scope": scope,
        "symbol": spec["symbol"],
        "code": spec["code"],
        "lookup": lookup,
        "status": dataset_status(cls, rec is not None),
        "ohlc_class": None if not cls else cls["ohlc"],
        "volume_class": None if not cls else cls["volume"],
        "kind": None if not rec else rec.get("kind"),
        "open": None if not rec else rec.get("open"),
        "high": None if not rec else rec.get("high"),
        "low": None if not rec else rec.get("low"),
        "close": None if not rec else rec.get("close"),
        "volume": None if not rec else rec.get("volume"),
        "amount_won": None if not rec else rec.get("amount_won"),
        "cmpprevdd": None if not rec else rec.get("cmpprevdd"),
        "fluc_rt": None if not rec else rec.get("fluc_rt"),
        "market_observed": None if not rec else rec.get("MKT_NM"),
        "isu_nm_observed": None if not rec else rec.get("ISU_NM"),
        "suffix_observed": None if not rec else rec.get("suffix"),
        "file": fname,
        "file_sha256": None if not fname else rc.file_hash(hashes, raw_dir, fname),
        "kept_zero_price_row": bool(rec) and (cls or {}).get("ohlc") == "ohl_zero_close_positive",
        "excluded_for_later_anomaly": False,
    }


def attach_confirmed(break_rec: dict[str, Any]) -> dict[str, Any]:
    rec = dict(break_rec)
    rec["corporate_action"] = "미확정"
    rec["corporate_action_source"] = None
    rec["ratio_adjusted"] = False
    rec["excluded_by_rule"] = False
    for rule in CONFIRMED_ACTIONS:
        if rule["symbol"] == rec["symbol"] and rule["from"] == rec["from"] and rule["to"] == rec["to"]:
            rec["corporate_action"] = rule["action"]
            rec["corporate_action_source"] = rule["source"]
            break
    return rec


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--prep-raw", required=True)
    p.add_argument("--eval-raw", required=True)
    p.add_argument("--eval-selected", default="")
    p.add_argument("--docs-out", default=str(DOCS_OUT))
    p.add_argument("--raw-out", default=str(RAW_OUT))
    args = p.parse_args()
    rc = load_recount()
    cmpmod = rc.load_mod(COMPARE, "krx_yahoo_ohlcv_compare")
    days_payload = cmpmod.load_json(PREP_DOCS / "trading-days.json")
    prep_days = [f"{d[0:4]}-{d[4:6]}-{d[6:8]}" for d in days_payload["days"]]
    eval_days = list(cmpmod.load_json(CANDIDATES)["days"])
    calendar = prep_days + eval_days
    symbols = cmpmod.universe_rows(cmpmod.load_json(UNIVERSE))
    if len(symbols) != 1248:
        raise SystemExit(f"expected 1248, got {len(symbols)}")
    prep_raw = Path(args.prep_raw)
    eval_raw = Path(args.eval_raw)
    selected = Path(args.eval_selected) if args.eval_selected else eval_raw.parent / "selected"
    top100 = load_top100(selected, eval_days)
    prep_hashes = dict((cmpmod.load_json(PREP_DOCS / "file-hashes.json") or {}).get("raw") or {})
    eval_hash_path = ROOT / "docs" / "historical-top100-20241008-20251002" / "file-hashes.json"
    eval_hashes: dict[str, str] = {}
    if eval_hash_path.is_file():
        raw = (cmpmod.load_json(eval_hash_path) or {}).get("raw") or {}
        if isinstance(raw, dict):
            eval_hashes = {k: v for k, v in raw.items() if isinstance(v, str)}

    bars_dir = Path(args.raw_out) / "bars"
    bars_dir.mkdir(parents=True, exist_ok=True)
    docs = Path(args.docs_out)
    docs.mkdir(parents=True, exist_ok=True)

    kind_tot: Counter = Counter()
    status_tot: Counter = Counter()
    assigned_status_tot: Counter = Counter()
    per: dict[str, dict[str, Any]] = {}
    transfer_counts = {
        sym: {
            "assigned_rows": 0,
            "linked_rows": 0,
            "no_row_before_link": 0,
            "no_row_after_link": 0,
        }
        for sym in TRANSFERS
    }
    series_assigned: dict[str, list[dict[str, Any]]] = {s["symbol"]: [] for s in symbols}
    series_linked: dict[str, list[dict[str, Any]]] = {s["symbol"]: [] for s in symbols}

    handles = {spec["symbol"]: (bars_dir / f"{spec['symbol']}.jsonl").open("w", encoding="utf-8") for spec in symbols}
    try:
        def walk(days: list[str], raw_dir: Path, hashes: dict[str, str], scope: str) -> None:
            for day in days:
                compact = day.replace("-", "")
                tables = rc.load_day_tables(cmpmod, raw_dir, compact)
                for spec in symbols:
                    rec_a, _ = pick_row(spec, tables, day, link=False)
                    rec_l, lookup = pick_row(spec, tables, day, link=True)
                    bar_a = bar_record(spec, day, compact, scope, rec_a, "assigned" if rec_a else "missing", hashes, raw_dir, rc)
                    bar_l = bar_record(spec, day, compact, scope, rec_l, lookup, hashes, raw_dir, rc)
                    handles[spec["symbol"]].write(json.dumps(bar_l, ensure_ascii=False) + "\n")
                    st = bar_l["status"]
                    status_tot[f"{scope}_{st}"] += 1
                    assigned_status_tot[f"{scope}_{bar_a['status']}"] += 1
                    if rec_l is not None:
                        kind_tot[f"{scope}_{rec_l['kind']}"] += 1
                        kind_tot[f"{scope}_volume_{rec_l['class']['volume']}"] += 1
                        kind_tot[f"{scope}_ohlc_{rec_l['class']['ohlc']}"] += 1
                    else:
                        kind_tot[f"{scope}_no_row"] += 1
                    row = per.setdefault(spec["symbol"], {
                        "symbol": spec["symbol"],
                        "code": spec["code"],
                        "name": spec["name"],
                        "prep_normal_ohlc": 0,
                        "prep_normal_ohlcv": 0,
                        "prep_ohl_zero": 0,
                        "prep_no_row": 0,
                        "eval_normal_ohlc": 0,
                        "eval_normal_ohlcv": 0,
                        "eval_ohl_zero": 0,
                        "eval_no_row": 0,
                    })
                    prefix = "prep" if scope == "prep" else "eval"
                    if st == "normal_ohlcv":
                        row[f"{prefix}_normal_ohlcv"] += 1
                        row[f"{prefix}_normal_ohlc"] += 1
                    elif st == "normal_ohlc_not_ohlcv":
                        row[f"{prefix}_normal_ohlc"] += 1
                    elif st == "ohl_zero":
                        row[f"{prefix}_ohl_zero"] += 1
                    elif st == "no_row":
                        row[f"{prefix}_no_row"] += 1
                    if spec["symbol"] in TRANSFERS:
                        tc = transfer_counts[spec["symbol"]]
                        if rec_a is not None:
                            tc["assigned_rows"] += 1
                        if lookup == "linked_prior_market":
                            tc["linked_rows"] += 1
                        if rec_a is None:
                            tc["no_row_before_link"] += 1
                        if rec_l is None:
                            tc["no_row_after_link"] += 1
                    c_a = None if rec_a is None else rec_a.get("close")
                    if rec_a is not None and c_a is not None and c_a > 0:
                        series_assigned[spec["symbol"]].append(bar_a)
                    c_l = None if rec_l is None else rec_l.get("close")
                    if rec_l is not None and c_l is not None and c_l > 0:
                        series_linked[spec["symbol"]].append(bar_l)

        walk(prep_days, prep_raw, prep_hashes, "prep")
        walk(eval_days, eval_raw, eval_hashes, "eval")
    finally:
        for fh in handles.values():
            fh.close()

    idx = {d: i for i, d in enumerate(calendar)}

    def enrich_breaks(src: list[dict[str, Any]], series_map: dict[str, list[dict[str, Any]]], lookup: str) -> list[dict[str, Any]]:
        by_sym_day = {
            spec["symbol"]: {b["day"]: b for b in series_map[spec["symbol"]]}
            for spec in symbols
        }
        out = []
        for raw in src:
            rec = attach_confirmed(raw)
            rec["lookup"] = lookup
            prev = by_sym_day.get(raw["symbol"], {}).get(raw["from"])
            nxt = by_sym_day.get(raw["symbol"], {}).get(raw["to"])
            rec["from_ohlcv"] = None if not prev else {
                k: prev.get(k) for k in ("open", "high", "low", "close", "volume", "amount_won", "kind", "status", "market_observed", "file", "file_sha256")
            }
            rec["to_ohlcv"] = None if not nxt else {
                k: nxt.get(k) for k in ("open", "high", "low", "close", "volume", "amount_won", "kind", "status", "market_observed", "file", "file_sha256")
            }
            rec["calendar_gap_days"] = idx[raw["to"]] - idx[raw["from"]]
            rec["intervening_calendar_days"] = rec["calendar_gap_days"] - 1
            day = raw["to"]
            if day in top100:
                rec["to_in_eval_top100"] = raw["code"] in top100[day]
            else:
                rec["to_in_eval_top100"] = None
                rec["top100_note"] = "평가 기간 TOP100 원본에 없는 날짜"
            out.append(rec)
        return out

    old_breaks = []
    if OLD_BREAKS.is_file():
        old_breaks = list((cmpmod.load_json(OLD_BREAKS) or {}).get("assigned_suffix") or [])
    assigned_break_src = []
    for spec in symbols:
        closes = [(b["day"], b["close"], b["kind"]) for b in series_assigned[spec["symbol"]]]
        for rec in rc.scan_breaks(closes, calendar):
            rec["symbol"] = spec["symbol"]
            rec["code"] = spec["code"]
            rec["name"] = spec["name"]
            assigned_break_src.append(rec)
    linked_break_src = []
    for spec in symbols:
        closes = [(b["day"], b["close"], b["kind"]) for b in series_linked[spec["symbol"]]]
        for rec in rc.scan_breaks(closes, calendar):
            rec["symbol"] = spec["symbol"]
            rec["code"] = spec["code"]
            rec["name"] = spec["name"]
            linked_break_src.append(rec)

    breaks_assigned = enrich_breaks(assigned_break_src, series_assigned, "assigned_suffix")
    breaks_linked = enrich_breaks(linked_break_src, series_linked, "linked_confirmed_transfer")

    old = cmpmod.load_json(OLD_SUMMARY) if OLD_SUMMARY.is_file() else {}
    old_prep_n = ((old.get("prep_totals") or {}).get("normal_ohlcv"))
    assigned_prep_ohlcv = assigned_status_tot["prep_normal_ohlcv"]
    assigned_prep_ohlc = assigned_prep_ohlcv + assigned_status_tot["prep_normal_ohlc_not_ohlcv"]
    new_prep_ohlcv = status_tot["prep_normal_ohlcv"]
    new_prep_ohlc = new_prep_ohlcv + status_tot["prep_normal_ohlc_not_ohlcv"]
    rows = sorted(per.values(), key=lambda r: r["symbol"])
    n240_ohlcv = sum(1 for r in rows if r["prep_normal_ohlcv"] >= 240)
    n240_ohlc = sum(1 for r in rows if r["prep_normal_ohlc"] >= 240)

    comparison = {
        "pr91_prep_kind_normal_counted_as_normal_ohlcv": old_prep_n,
        "assigned_only_prep_normal_ohlc": assigned_prep_ohlc,
        "assigned_only_prep_normal_ohlcv": assigned_prep_ohlcv,
        "linked_prep_normal_ohlc": new_prep_ohlc,
        "linked_prep_normal_ohlcv": new_prep_ohlcv,
        "assigned_equals_pr91_normal": old_prep_n == assigned_prep_ohlcv,
        "prep_normal_ohlc_equals_ohlcv": assigned_prep_ohlc == assigned_prep_ohlcv,
        "note": (
            "PR #91 normal은 지정 suffix·OHLC 정상이면 거래량 0도 포함. "
            "지정 suffix만 보면 정상 OHLC와 정상 OHLCV 건수가 같은지 비교한다. "
            "연결 시계열은 확인된 이전상장 3종의 반대 시장 행을 더한 값이다."
        ),
    }

    xfer_out = []
    for sym, meta in TRANSFERS.items():
        tc = transfer_counts[sym]
        xfer_out.append({
            **meta,
            "counts": {
                **tc,
                "rows_after_link": tc["assigned_rows"] + tc["linked_rows"],
            },
        })

    summary = {
        "schema": 1,
        "kind": "krx_tdd_dataset_v1",
        "price_basis": PRICE_BASIS,
        "no_fill": True,
        "no_yahoo_mix": True,
        "no_ratio_adjust": True,
        "no_drop_zero_price_rows": True,
        "no_exclude_past_for_future_anomaly": True,
        "ma240_not_asserted": True,
        "universe": 1248,
        "prep_days": 240,
        "eval_days": 243,
        "bars_gitignored": str(bars_dir.relative_to(ROOT)).replace("\\", "/"),
        "status_totals": dict(status_tot),
        "assigned_status_totals": dict(assigned_status_tot),
        "kind_totals": dict(kind_tot),
        "symbols_prep_normal_ohlc_240": n240_ohlc,
        "symbols_prep_normal_ohlcv_240": n240_ohlcv,
        "comparison_with_pr91": comparison,
        "transfers": xfer_out,
        "price_breaks_assigned_n": len(breaks_assigned),
        "price_breaks_linked_n": len(breaks_linked),
        "price_breaks_pr91_n": len(old_breaks),
        "confirmed_corporate_actions": len(CONFIRMED_ACTIONS),
        "corporate_action_policy": "확인된 공시만 적는다. 배율 보정·임의 제외 규칙 없음.",
    }
    rc.write_json(docs / "summary.json", summary)
    rc.write_json(docs / "symbol-index.json", {"schema": 1, "price_basis": PRICE_BASIS, "rows": rows})
    rc.write_json(docs / "transfers.json", {"schema": 1, "link": "ISU_CD", "name_not_used_to_join": True, "rows": xfer_out})
    rc.write_json(docs / "price-breaks.json", {
        "schema": 1,
        "price_basis": PRICE_BASIS,
        "no_auto_adjust": True,
        "no_exclusion_rule": True,
        "pr91_assigned_n": len(old_breaks),
        "assigned_suffix": breaks_assigned,
        "linked_confirmed_transfer": breaks_linked,
    })
    rc.write_json(docs / "manifest.json", {
        "schema": 1,
        "price_basis": PRICE_BASIS,
        "prep_raw": str(prep_raw),
        "eval_raw": str(eval_raw),
        "bars": str(bars_dir),
        "calendar": {"prep": [prep_days[0], prep_days[-1], len(prep_days)], "eval": [eval_days[0], eval_days[-1], len(eval_days)]},
        "provenance_fields": ["file", "file_sha256", "day", "symbol", "code", "market_observed", "suffix_observed", "lookup"],
    })
    print(json.dumps({
        "prep_normal_ohlc": new_prep_ohlc,
        "prep_normal_ohlcv": new_prep_ohlcv,
        "assigned_prep_normal_ohlcv": assigned_prep_ohlcv,
        "pr91_prep_normal": old_prep_n,
        "assigned_equals_pr91": comparison["assigned_equals_pr91_normal"],
        "ohlc_equals_ohlcv": comparison["prep_normal_ohlc_equals_ohlcv"],
        "prep_ohl_zero": status_tot["prep_ohl_zero"],
        "prep_no_row": status_tot["prep_no_row"],
        "n240_ohlcv": n240_ohlcv,
        "breaks_assigned": len(breaks_assigned),
        "breaks_linked": len(breaks_linked),
        "linked_rows": {s: transfer_counts[s]["linked_rows"] for s in TRANSFERS},
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
