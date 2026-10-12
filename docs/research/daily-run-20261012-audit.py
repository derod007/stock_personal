"""Read-only audit of the 2026-10-12 KR scheduled run. Does not write operational files."""
from __future__ import annotations

import hashlib
import json
from collections import Counter
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
STATE = Path(r"C:\Users\acdun\Desktop\dev\stock-personal-paper")
OUT = ROOT / "docs/daily-run-20261012"
KST = timezone(timedelta(hours=9))
RUN_ID = "98d402ee2462459e86601c7ec15b8594"
RR_NAME = "20261012-112244-6e8bd47b342f.json"
OCT8_LEDGER = "20261008-144016-66da152dba51.json"
ACCOUNT = "paper-kr-recovery-v1"
SECTOR_DIR = ROOT / "data/raw/cache/sector"
OTHER_SECTOR_DIR = ROOT / "data/cache/sector"
SCAN_CACHE = ROOT / "data/raw/cache/kr_amount_scan_v20_all_account1_100.json"
JUDGE_LINE = "bin/paper/EnvelopeResearch.php:inspect"
QUALITY_LINE = "src/PaperQuality.php:inspect"


def sha256_file(path: Path) -> str | None:
    if not path.is_file():
        return None
    return hashlib.sha256(path.read_bytes()).hexdigest()


def kst(ts: int | float | None) -> str | None:
    if ts is None:
        return None
    return datetime.fromtimestamp(int(ts), KST).strftime("%Y-%m-%d %H:%M:%S %z")


def kst_day(ts: int | float | None) -> str | None:
    if ts is None:
        return None
    return datetime.fromtimestamp(int(ts), KST).strftime("%Y-%m-%d")


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def close_time(bar: dict, symbol: str) -> int | None:
    if "available_at" in bar:
        v = bar["available_at"]
        return int(v) if isinstance(v, (int, float)) else None
    stamp = bar.get("time")
    if stamp is None:
        return None
    kr = symbol.endswith(".KS") or symbol.endswith(".KQ")
    tz = timezone(timedelta(hours=9)) if kr else timezone(timedelta(hours=-4))
    day = datetime.fromtimestamp(int(stamp), tz).strftime("%Y-%m-%d")
    hm = "15:30:00" if kr else "16:00:00"
    return int(datetime.strptime(f"{day} {hm}", "%Y-%m-%d %H:%M:%S").replace(tzinfo=tz).timestamp())


def ohlc_ok(bar: dict) -> bool:
    try:
        o, h, l, c = (float(bar[k]) for k in ("open", "high", "low", "close"))
    except (KeyError, TypeError, ValueError):
        return False
    if not all(x == x and x > 0 for x in (o, h, l, c)):
        return False
    if l > min(o, c) or h < max(o, c) or h < l:
        return False
    return True


def diagnose_invalid(bar: dict | None, symbol: str, session: int) -> dict:
    if bar is None:
        return {"kind": "bar_not_found", "detail": "invalid_bars 시각에 해당하는 원본 봉이 없음"}
    reasons = []
    if bar.get("synthetic"):
        reasons.append("synthetic")
    if bar.get("is_complete", True) is False:
        reasons.append("incomplete")
    t = close_time(bar, symbol)
    if t is None:
        reasons.append("missing_close_time")
    elif t > session:
        reasons.append("after_session")
    fields = {}
    for key in ("open", "high", "low", "close", "volume"):
        fields[key] = bar.get(key)
    if not ohlc_ok(bar):
        reasons.append("invalid_ohlc")
    vol = bar.get("volume")
    try:
        vf = float(vol)
        if vf != vf or vf < 0:
            reasons.append("invalid_volume")
    except (TypeError, ValueError):
        reasons.append("invalid_volume")
    return {
        "kind": "+".join(reasons) if reasons else "unknown",
        "close_time": t,
        "close_day": kst_day(t) if t else None,
        "fields": fields,
        "detail": None,
    }


def classify_sector_name(name: str | None) -> str:
    s = (name or "").strip()
    if s == "":
        return "empty"
    if s == "기타":
        return "placeholder_기타"
    return "real_industry"


def mtime_iso(path: Path) -> str | None:
    if not path.is_file():
        return None
    return kst(path.stat().st_mtime)


def cache_file(code: str) -> Path:
    return SECTOR_DIR / f"sector_{code}.json"


def read_sector_cache(code: str) -> dict:
    path = cache_file(code)
    out = {
        "path": str(path),
        "exists": path.is_file(),
        "mtime": mtime_iso(path),
        "mtime_ts": int(path.stat().st_mtime) if path.is_file() else None,
        "read_error": None,
        "sector": None,
        "sector_bucket": None,
        "sector_label": None,
        "name": None,
    }
    if not path.is_file():
        return out
    try:
        d = load_json(path)
        out.update({
            "sector": d.get("sector"),
            "sector_bucket": d.get("sector_bucket"),
            "sector_label": d.get("sector_label"),
            "name": d.get("name"),
        })
    except (OSError, json.JSONDecodeError) as e:
        out["read_error"] = type(e).__name__
    return out


def in_window(ts: int | None, start: int, end: int) -> bool:
    return ts is not None and start <= ts <= end


def main() -> None:
    run_path = STATE / "runs" / f"{ACCOUNT}-forward" / f"{RUN_ID}.json"
    rr_path = STATE / "rr-audit" / ACCOUNT / RR_NAME
    env_latest = STATE / "envelope-research" / ACCOUNT / "latest.json"
    env_oct8 = STATE / "envelope-research" / ACCOUNT / OCT8_LEDGER
    frozen_dir = STATE / "envelope-research" / ACCOUNT / "frozen"

    run = load_json(run_path)
    rr = load_json(rr_path)
    scan = load_json(SCAN_CACHE)
    latest = load_json(env_latest)
    oct8 = load_json(env_oct8)

    start = int(run["started_at"])
    end = int(run["finished_at"])
    scan_mtime = int(SCAN_CACHE.stat().st_mtime)
    scan_is_this_run = in_window(scan_mtime, start, end)

    rr_by_symbol = {r["symbol"]: r for r in rr.get("records", [])}
    rows = scan.get("rows") or []
    symbols = [str(r["yahoo"]) for r in rows]

    sector_rows = []
    counts = Counter()
    for row in rows:
        yahoo = str(row["yahoo"])
        code = "".join(ch for ch in str(row.get("code") or yahoo) if ch.isdigit())
        scan_sector = row.get("sector")
        scan_bucket = row.get("sector_bucket")
        scan_kind = classify_sector_name(scan_sector)
        cache = read_sector_cache(code)
        cache_kind = None
        if cache["read_error"]:
            cache_status = "read_error"
        elif not cache["exists"]:
            cache_status = "missing_file"
        else:
            cache_kind = classify_sector_name(cache.get("sector"))
            if in_window(cache["mtime_ts"], start, end):
                cache_status = "written_during_this_run"
            elif cache["mtime_ts"] is not None and cache["mtime_ts"] < start:
                cache_status = "older_than_this_run"
            else:
                cache_status = "newer_than_this_run"
        # Collection failure is placeholder/empty/missing/error, not bucket=other with a real name.
        scan_collect_fail = scan_kind in ("placeholder_기타", "empty")
        cache_collect_fail = cache_status in ("missing_file", "read_error") or cache_kind in ("placeholder_기타", "empty")
        other_real = scan_kind == "real_industry" and scan_bucket == "other"
        rec = rr_by_symbol.get(yahoo) or {}
        sector_rows.append({
            "symbol": yahoo,
            "code": code,
            "name": row.get("name"),
            "ok": bool(row.get("ok")),
            "scan_error": row.get("error"),
            "amount_rank": row.get("amount_rank"),
            "scan_time_sector": scan_sector,
            "scan_time_sector_bucket": scan_bucket,
            "scan_time_sector_label": row.get("sector_label"),
            "scan_time_sector_kind": scan_kind,
            "scan_time_collect_failure": scan_collect_fail,
            "scan_time_other_bucket_with_real_name": other_real,
            "current_cache_status": cache_status,
            "current_cache_sector": cache.get("sector"),
            "current_cache_bucket": cache.get("sector_bucket"),
            "current_cache_mtime": cache.get("mtime"),
            "current_cache_kind": cache_kind,
            "current_cache_collect_failure": cache_collect_fail,
            "scan_quality_can_simulate": (rec.get("quality") or {}).get("can_simulate"),
            "scan_quality_reasons": (rec.get("quality") or {}).get("reasons") or [],
            "scan_session": rec.get("session"),
            "scan_asof": rec.get("asof"),
            "scan_record_status": rec.get("status"),
        })
        counts["scan_" + scan_kind] += 1
        counts["cache_" + cache_status] += 1
        if other_real:
            counts["other_bucket_real_name"] += 1
        if scan_collect_fail:
            counts["scan_collect_failure"] += 1

    qb_latest = {k: v for k, v in latest["rows"].items() if v.get("status") == "quality_blocked"}
    qb_oct8 = {k: v for k, v in oct8["rows"].items() if v.get("status") == "quality_blocked"}
    added = sorted(set(qb_latest) - set(qb_oct8))
    released = sorted(set(qb_oct8) - set(qb_latest))
    stayed = sorted(set(qb_latest) & set(qb_oct8))

    blocked_cases = []
    for key in sorted(qb_latest):
        row = qb_latest[key]
        frozen = load_json(frozen_dir / f"{row['frozen_ref']}.json")
        rec = frozen["record"]
        feat = frozen["features"]
        q = feat.get("quality") or {}
        symbol = rec["symbol"]
        session = int(rec["session"])
        bars = rec.get("bars") or []
        by_t = {}
        for b in bars:
            t = close_time(b, symbol)
            if t is not None:
                by_t[t] = b
        completed = []
        for b in bars:
            t = close_time(b, symbol)
            if not t or t > session or b.get("synthetic") or b.get("is_complete", True) is False:
                continue
            if ohlc_ok(b):
                completed.append(t)
        completed.sort()
        ma240_start = completed[-240] if len(completed) >= 240 else None
        invalid = list(q.get("invalid_bars") or [])
        problem_bars = []
        in_ma240 = []
        for t in invalid:
            d = diagnose_invalid(by_t.get(t), symbol, session)
            d["timestamp"] = t
            d["in_ma240_span"] = ma240_start is not None and t >= ma240_start
            if d["in_ma240_span"]:
                in_ma240.append(t)
            problem_bars.append(d)
        scan_rec = rr_by_symbol.get(symbol) or {}
        scan_q = scan_rec.get("quality") or {}
        same_session_today = scan_rec.get("session") == session
        blocked_because_can_simulate = not bool(q.get("can_simulate"))
        blocked_because_ma240 = bool(in_ma240)
        if blocked_because_can_simulate:
            cause = "scan_quality_rule"
            classif = "원본 데이터 문제" if q.get("reasons") else "자료 부족"
        elif blocked_because_ma240:
            cause = "envelope_ma240_invalid_bar"
            classif = "의도된 보류"
        else:
            cause = "unexplained"
            classif = "구현 문제 의심"
        blocked_cases.append({
            "observation_id": key,
            "observation_hash": rec.get("observation_hash"),
            "symbol": symbol,
            "name": rec.get("name"),
            "observation_session": session,
            "observation_day": kst_day(session),
            "observation_clock": kst(session),
            "source_file": rec.get("source_file"),
            "registered_at": kst(frozen.get("registered_at")),
            "registered_at_ts": frozen.get("registered_at"),
            "first_observation_block": True,
            "followup_block": False,
            "present_on_2026-10-08": key in qb_oct8,
            "features_status": feat.get("status"),
            "completed_bars": feat.get("completed_bars"),
            "quality_status": q.get("status"),
            "can_simulate": q.get("can_simulate"),
            "reasons": q.get("reasons") or [],
            "warnings": q.get("warnings") or [],
            "invalid_bar_count": len(invalid),
            "invalid_bars_in_ma240": len(in_ma240),
            "ma240_span_start": ma240_start,
            "ma240_span_start_day": kst_day(ma240_start),
            "problem_bars": problem_bars,
            "judge_code": JUDGE_LINE,
            "quality_code": QUALITY_LINE,
            "block_cause": cause,
            "classification": classif,
            "today_scan_record_status": scan_rec.get("status"),
            "today_scan_session": scan_rec.get("session"),
            "today_scan_session_day": kst_day(scan_rec.get("session")),
            "today_scan_can_simulate": scan_q.get("can_simulate"),
            "today_scan_reasons": scan_q.get("reasons") or [],
            "today_scan_invalid_bar_count": len(scan_q.get("invalid_bars") or []),
            "same_session_as_today_scan": same_session_today,
        })

    scan_pass_explain = []
    for case in blocked_cases:
        if case["today_scan_can_simulate"] is True and case["block_cause"] == "envelope_ma240_invalid_bar":
            scan_pass_explain.append({
                "symbol": case["symbol"],
                "observation_id": case["observation_id"],
                "why": (
                    "스캔 PaperQuality는 최근 90일 invalid_bars만 can_simulate을 막는다. "
                    "엔벨로프 inspect는 can_simulate이 true여도 MA240 구간에 제외된 잘못된 봉이 있으면 quality_blocked로 둔다. "
                    "검사 구간이 90일 vs 240완료봉으로 다르다."
                ),
            })

    other_dir_note = {
        "path": str(OTHER_SECTOR_DIR),
        "exists": OTHER_SECTOR_DIR.is_dir(),
        "used_by_scheduled_scanner": False,
        "used_by": "ProposalService 기본값 data/cache/sector. 운영 스캐너 paper_scan_universe.php는 data/raw/cache/sector만 쓴다.",
    }

    evidence = {
        "schema": 1,
        "kind": "daily_run_20261012_readonly_audit",
        "generated_at_kst": datetime.now(KST).strftime("%Y-%m-%d %H:%M %z"),
        "scope": "cause and report only. no operational rewrite, recache, account rerun, or merge.",
        "inputs": {
            "run": {"path": str(run_path), "sha256": sha256_file(run_path)},
            "rr_audit": {"path": str(rr_path), "sha256": sha256_file(rr_path), "note": "full bars not copied into git"},
            "scan_cache_contemporaneous": {
                "path": str(SCAN_CACHE),
                "sha256": sha256_file(SCAN_CACHE),
                "mtime": mtime_iso(SCAN_CACHE),
                "in_run_window": scan_is_this_run,
                "note": "paper_scan_universe writes this at the end of the scheduled scan. Sector fields here are the scan-time values.",
            },
            "sector_cache_used": str(SECTOR_DIR),
            "envelope_latest": {"path": str(env_latest), "sha256": sha256_file(env_latest)},
            "envelope_oct8": {"path": str(env_oct8), "sha256": sha256_file(env_oct8)},
        },
        "facts": {
            "account": ACCOUNT,
            "run_id": RUN_ID,
            "started_at": kst(start),
            "finished_at": kst(end),
            "status": run.get("status"),
            "empty_universe": (run.get("summary") or {}).get("empty_universe"),
            "scan_summary": scan.get("summary"),
            "rr_summary": rr.get("summary"),
            "envelope_run": {
                "status": (run.get("envelope_research") or {}).get("status"),
                "added": (run.get("envelope_research") or {}).get("added"),
                "retained": ((run.get("envelope_research") or {}).get("summary") or {}).get("retained"),
                "statuses": ((run.get("envelope_research") or {}).get("summary") or {}).get("statuses"),
            },
        },
        "scanner_cache_path": {
            "code": "bin/paper_scan_universe.php sets cacheDir=data/raw/cache; KrAmountScanner uses cacheDir/sector",
            "path": str(SECTOR_DIR),
            "other_path_not_mixed": other_dir_note,
        },
        "sector_counts": dict(counts),
        "sector_rows": sector_rows,
        "envelope_quality_blocked": {
            "unit": "observations (symbol@session), not unique symbols",
            "latest_count": len(qb_latest),
            "latest_unique_symbols": len({k.split("@", 1)[0] for k in qb_latest}),
            "oct8_count": len(qb_oct8),
            "oct8_unique_symbols": len({k.split("@", 1)[0] for k in qb_oct8}),
            "added_since_oct8": added,
            "released_since_oct8": released,
            "unchanged_keys": stayed,
            "cases": blocked_cases,
            "why_scan_passed": scan_pass_explain,
        },
        "assumptions": [
            "Scheduled scan is called without --cache, so SectorMap::resolve fetches Naver live and only then writes cacheable results.",
            "Scan-time industry is taken from kr_amount_scan_v20_all_account1_100.json because its mtime is inside the run window.",
            "Current sector_*.json mtime inside the run window is treated as written by this run. Older files are leftovers, not proof of the live fetch result.",
            "Envelope quality in latest.json is stripped; reasons come from frozen/<hash>.json.",
        ],
    }
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "evidence.json").write_text(
        json.dumps(evidence, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )
    print(json.dumps({
        "written": str(OUT / "evidence.json"),
        "scan_cache_in_run_window": scan_is_this_run,
        "sector_counts": dict(counts),
        "qb": len(qb_latest),
        "qb_symbols": len({k.split("@", 1)[0] for k in qb_latest}),
        "added": added,
        "released": released,
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
