#!/usr/bin/env python3
"""Save official KOSPI and KOSDAQ index daily responses outside git.

Uses kospi_dd_trd and kosdaq_dd_trd only. Does not update data/ohlcv or data/cache.
shortcut: one weekday per request, resume by leaving existing raw files in place.
"""
from __future__ import annotations

import hashlib
import json
import re
import sys
import time
from datetime import date, datetime, timedelta, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Any, Callable

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(Path(__file__).resolve().parent))
from index_daily_access import OPENAPI, assert_research_dir, now_kst  # noqa: E402

RAW_ROOT = ROOT / "data" / "raw" / "research" / "index-daily-20240901-20261008"
DOCS = ROOT / "docs" / "index-daily-20240901-20261008"
RANGE_FROM = date(2024, 9, 1)
RANGE_TO = date(2026, 10, 8)
NEED_FROM = date(2025, 1, 1)
WINDOW = 60
KST = timezone(timedelta(hours=9))
Get = Callable[[str, dict[str, str]], tuple[int, dict[str, str], bytes]]
MARKETS = (
    {"market": "KOSPI", "api_id": "kospi_dd_trd", "index_name": "코스피"},
    {"market": "KOSDAQ", "api_id": "kosdaq_dd_trd", "index_name": "코스닥"},
)
CALENDARS = (
    "docs/type1-krx-input-v1/trading-calendar.json",
    "docs/type1-holdout-v1/trading-calendar.json",
    "docs/krx-prep-bars-before-20241008/trading-calendar.json",
    "docs/historical-top100-20241008-20251002/trading-calendar.json",
    "docs/historical-top100-20251008-20261008/trading-calendar.json",
)


def weekdays(start: date, end: date) -> list[str]:
    days = []
    cursor = start
    while cursor <= end:
        if cursor.weekday() < 5:
            days.append(cursor.strftime("%Y%m%d"))
        cursor += timedelta(days=1)
    return days


def _iso(compact: str) -> str:
    return f"{compact[:4]}-{compact[4:6]}-{compact[6:8]}"


def load_ranges(root: Path = ROOT) -> list[dict[str, Any]]:
    ranges = []
    for rel in CALENDARS:
        payload = json.loads((root / rel).read_text(encoding="utf-8"))
        if "search_range" in payload:
            span = payload["search_range"]
        elif isinstance(payload.get("range"), dict):
            span = payload["range"]
        else:
            span = {"from": payload["range"][0], "to": payload["range"][1]}
        closed = set()
        for key in payload["closed_weekdays"]:
            text = str(key)
            closed.add(_iso(text) if len(text) == 8 else text)
        ranges.append({"from": span["from"], "to": span["to"], "closed": closed})
    return ranges


def day_kind(day: str, ranges: list[dict[str, Any]]) -> str:
    if date.fromisoformat(day).weekday() >= 5:
        return "closed"
    flags = []
    for item in ranges:
        if day < item["from"] or day > item["to"]:
            continue
        flags.append("closed" if day in item["closed"] else "open")
    if len(set(flags)) != 1:
        return "unknown"
    return flags[0]


def collect_raw(raw_dir: Path, key: str, http_get: Get, days: list[str], pause: float = 0.0) -> dict[str, Any]:
    """Write one response per weekday. Existing files stay untouched."""
    assert_research_dir(raw_dir)
    summary: dict[str, Any] = {"requests": 0, "written": 0, "reused": 0, "stopped": None}
    if not key.strip():
        summary["stopped"] = "no_key"
        return summary
    for compact in days:
        for market in MARKETS:
            dest = raw_dir / "raw" / market["api_id"] / f"{compact}.json"
            if dest.is_file():
                summary["reused"] += 1
                continue
            url = f"{OPENAPI}/svc/apis/idx/{market['api_id']}?basDd={compact}"
            status, _, body = http_get(url, {"Accept": "application/json", "AUTH_KEY": key})
            summary["requests"] += 1
            if status != 200 or b"Unauthorized" in body[:240] or key.strip().encode() in body:
                summary["stopped"] = {"day": compact, "api_id": market["api_id"], "http": status}
                return summary
            dest.parent.mkdir(parents=True, exist_ok=True)
            with dest.open("xb") as handle:
                handle.write(body)
            summary["written"] += 1
            if summary["written"] % 100 == 0:
                print(json.dumps({"written": summary["written"], "day": compact}), flush=True)
            if pause:
                time.sleep(pause)
    return summary


def bars_from_day(payload: Any, index_name: str, requested: str) -> dict[str, Any]:
    if not isinstance(payload, dict) or not isinstance(payload.get("OutBlock_1"), list):
        return {"bar": None, "issue": "structure"}
    block = payload["OutBlock_1"]
    if block == []:
        return {"bar": None, "issue": None}
    hits = [row for row in block if isinstance(row, dict) and row.get("IDX_NM") == index_name]
    if len(hits) != 1:
        return {"bar": None, "issue": "name_count"}
    row = hits[0]
    if str(row.get("BAS_DD")) != requested:
        return {"bar": None, "issue": "date"}
    return {
        "bar": {
            "date": _iso(requested),
            "close": str(row.get("CLSPRC_IDX")),
            "idx_nm": index_name,
            "idx_clss": row.get("IDX_CLSS"),
        },
        "issue": None,
    }


def normalize_market(raw_dir: Path, market: dict[str, str]) -> dict[str, Any]:
    folder = raw_dir / "raw" / market["api_id"]
    bars = []
    issues = []
    paths = sorted(folder.glob("*.json")) if folder.is_dir() else []
    for path in paths:
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            issues.append({"day": path.stem, "issue": "json"})
            continue
        parsed = bars_from_day(payload, market["index_name"], path.stem)
        if parsed["issue"]:
            issues.append({"day": path.stem, "issue": parsed["issue"]})
        elif parsed["bar"]:
            bars.append(parsed["bar"])
    record = {
        "source": f"{OPENAPI}/svc/apis/idx/{market['api_id']}",
        "api_id": market["api_id"],
        "index_name": market["index_name"],
        "date_field": "BAS_DD",
        "date_meaning": "기준일자. 그 호출의 일별시세 날짜이며 요청한 basDd와 같아야 한다.",
        "close_field": "CLSPRC_IDX",
        "close_meaning": "종가. 그 기준일의 지수 종가다.",
        "conversion": "IDX_NM이 지수명과 정확히 같은 행이 하나일 때만 날짜와 종가를 남긴다. 없거나 여럿이면 봉을 만들지 않고, 빈 목록은 채우지 않는다.",
        "bars": bars,
        "issues": issues,
    }
    dest = raw_dir / "normalized" / f"{market['api_id']}.json"
    dest.parent.mkdir(parents=True, exist_ok=True)
    if dest.exists():
        existing = json.loads(dest.read_text(encoding="utf-8"))
        if existing != record:
            raise ValueError("existing normalized data differs from raw responses")
        return existing
    text = json.dumps(record, ensure_ascii=False, indent=2) + "\n"
    with dest.open("x", encoding="utf-8") as handle:
        handle.write(text)
    return record


def _close_ok(value: str) -> bool:
    try:
        number = Decimal(value)
    except InvalidOperation:
        return False
    return number.is_finite() and number > 0


def assess(bars: list[dict[str, Any]], ranges: list[dict[str, Any]], start: date = RANGE_FROM, end: date = RANGE_TO) -> dict[str, Any]:
    dates = [bar["date"] for bar in bars]
    present = set(dates)
    missing = []
    on_closed = []
    on_unknown = []
    unknown_absent = []
    cursor = start
    while cursor <= end:
        day = cursor.isoformat()
        kind = day_kind(day, ranges)
        if kind == "open" and day not in present:
            missing.append(day)
        elif kind == "closed" and day in present:
            on_closed.append(day)
        elif kind == "unknown" and cursor.weekday() < 5 and day in present:
            on_unknown.append(day)
        elif kind == "unknown" and cursor.weekday() < 5:
            unknown_absent.append(day)
        cursor += timedelta(days=1)
    opens = []
    cursor = start
    while cursor <= end:
        day = cursor.isoformat()
        if day_kind(day, ranges) == "open":
            opens.append(day)
        cursor += timedelta(days=1)
    first = next((day for day in opens if day >= NEED_FROM.isoformat()), None)
    through = [day for day in opens if first and day <= first]
    ready = (
        dates == sorted(dates)
        and len(dates) == len(present)
        and all(_close_ok(bar["close"]) for bar in bars if first and bar["date"] <= first)
        and first is not None
        and len(through) >= WINDOW
        and all(day in present for day in through[-WINDOW:])
        and not any(start.isoformat() <= day <= first for day in unknown_absent + on_unknown)
        and not any(day <= first for day in on_closed)
    )
    range_clear = (
        dates == sorted(dates)
        and len(dates) == len(present)
        and all(_close_ok(bar["close"]) for bar in bars)
        and not missing
        and not unknown_absent
        and not on_unknown
        and not on_closed
    )
    return {
        "bars": len(bars),
        "first": dates[0] if dates else None,
        "last": dates[-1] if dates else None,
        "unsorted": dates != sorted(dates),
        "duplicate": len(dates) != len(present),
        "bad_close": [bar["date"] for bar in bars if not _close_ok(bar["close"])],
        "missing_open": missing,
        "on_closed": on_closed,
        "on_unknown": on_unknown,
        "unknown_absent": unknown_absent,
        "before_2025": sum(1 for day in opens if day < NEED_FROM.isoformat() and day in present),
        "first_open_from_2025": first,
        "ready_60": ready,
        "range_clear": range_clear,
    }


def naver_closes(text: str) -> dict[str, str]:
    found = {}
    for match in re.finditer(r'\["(\d{8})",\s*[0-9.]+,\s*[0-9.]+,\s*[0-9.]+,\s*([0-9.]+)', text):
        found[_iso(match.group(1))] = match.group(2)
    return found


def stored_closes(rows: list[dict[str, Any]]) -> dict[str, str]:
    found = {}
    for row in rows:
        if row.get("synthetic") or row.get("is_complete") is False:
            continue
        stamp = str(row.get("time_kst") or "")
        if len(stamp) < 10:
            continue
        found[stamp[:10]] = str(row.get("close"))
    return found


def compare_closes(left: dict[str, str], right: dict[str, str]) -> dict[str, Any]:
    both = sorted(set(left) & set(right))
    differ = []
    for day in both:
        try:
            # shortcut: 0.01 is the CLSPRC_IDX scale, keep a finer difference only when it still differs there
            same = Decimal(left[day]).quantize(Decimal("0.01")) == Decimal(right[day]).quantize(Decimal("0.01"))
        except InvalidOperation:
            same = False
        if not same:
            differ.append(day)
    return {"overlap": len(both), "equal": len(both) - len(differ), "differ": differ}


def _cache_compare(market: str, bars: list[dict[str, Any]]) -> dict[str, Any]:
    official = {bar["date"]: bar["close"] for bar in bars}
    checks = []
    paths = []
    if market == "KOSPI":
        paths.append(("kospi_naver", ROOT / "data" / "cache" / "kospi_naver.json", "naver"))
        paths.append(("yahoo_ks11", ROOT / "data" / "type1-cache" / "intraday" / "^KS11_1mo_1d_closed_v2.json", "stored"))
    else:
        paths.append(("yahoo_kq11", ROOT / "data" / "type1-cache" / "intraday" / "^KQ11_1mo_1d_closed_v2.json", "stored"))
    for name, path, kind in paths:
        if not path.is_file():
            checks.append({"name": name, "present": False})
            continue
        if kind == "naver":
            other = naver_closes(path.read_text(encoding="utf-8"))
        else:
            other = stored_closes(json.loads(path.read_text(encoding="utf-8")))
        compared = compare_closes(official, other)
        checks.append({"name": name, "present": True, "sha256": sha256_file(path), "bytes": path.stat().st_size, **compared})
    return {"files": checks}


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def raw_seal(raw_dir: Path, api_id: str) -> dict[str, Any]:
    folder = raw_dir / "raw" / api_id
    paths = sorted(folder.glob("*.json")) if folder.is_dir() else []
    lines = [f"{path.name} {sha256_file(path)}" for path in paths]
    return {
        "files": len(paths),
        "sha256": hashlib.sha256("\n".join(lines).encode()).hexdigest(),
        "names": lines,
    }


def main() -> None:
    if (DOCS / "series.json").exists():
        raise SystemExit("refusing to overwrite series.json before authentication or collection")
    import importlib.util

    spec = importlib.util.spec_from_file_location(
        "historical_amount_top100_pilot",
        ROOT / "docs" / "research" / "historical-amount-top100-pilot.py",
    )
    assert spec is not None and spec.loader is not None
    pilot = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(pilot)
    days = weekdays(RANGE_FROM, RANGE_TO)
    summary = collect_raw(RAW_ROOT, pilot.load_auth_key(), pilot.http_get, days, pause=0.15)
    print(json.dumps({"collect": summary}, ensure_ascii=False))
    if summary["stopped"]:
        return
    ranges = load_ranges()
    public = {"checked_at_kst": now_kst(), "range": {"from": RANGE_FROM.isoformat(), "to": RANGE_TO.isoformat()}, "markets": {}}
    for market in MARKETS:
        record = normalize_market(RAW_ROOT, market)
        checked = assess(record["bars"], ranges)
        seal = raw_seal(RAW_ROOT, market["api_id"])
        normalized = RAW_ROOT / "normalized" / f"{market['api_id']}.json"
        public["markets"][market["market"]] = {
            "api_id": market["api_id"],
            "index_name": market["index_name"],
            "date_field": record["date_field"],
            "date_meaning": record["date_meaning"],
            "close_field": record["close_field"],
            "close_meaning": record["close_meaning"],
            "conversion": record["conversion"],
            "raw_files": seal["files"],
            "raw_seal_sha256": seal["sha256"],
            "normalized_sha256": sha256_file(normalized),
            "normalized_bytes": normalized.stat().st_size,
            "parse_issues": record["issues"],
            "quality": checked,
        }
        seal_path = RAW_ROOT / "raw" / f"{market['api_id']}.sha256"
        seal_text = "\n".join(seal["names"]) + "\n"
        if seal_path.exists():
            if seal_path.read_text(encoding="utf-8") != seal_text:
                raise ValueError("existing raw seal differs from raw responses")
        else:
            with seal_path.open("x", encoding="utf-8") as handle:
                handle.write(seal_text)
        public["markets"][market["market"]]["cache_compare"] = _cache_compare(market["market"], record["bars"])
    dest = DOCS / "series.json"
    if dest.exists():
        raise SystemExit("refusing to overwrite series.json")
    with dest.open("x", encoding="utf-8") as handle:
        handle.write(json.dumps(public, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps({"series": str(dest), "ready": {key: value["quality"]["ready_60"] for key, value in public["markets"].items()}}, ensure_ascii=False))


if __name__ == "__main__":
    main()
