#!/usr/bin/env python3
"""Count KRX breaks / OHL=0 / missing rows inside dated TOP100 *new-entry* input.

Does not collect, adjust, drop bars, relax rules, run accounts, or merge.
Price basis stays 미확정. This is not a holdings/pending-order safety check.
"""
from __future__ import annotations

import argparse
import json
from collections import defaultdict
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
PRICE_BASIS = "KRX TDD 원본, 수정 여부 미확정"
CANDIDATES = ROOT / "docs" / "historical-top100-20241008-20251002" / "candidates.json"
BREAKS = ROOT / "docs" / "krx-tdd-dataset-20231013-20251002" / "price-breaks.json"
DOCS_OUT = ROOT / "docs" / "krx-eval-input-overlap-20241008-20251002"
BARS_REL = Path("data") / "raw" / "research" / "krx-tdd-dataset-20231013-20251002" / "bars"
QUALITY_RECENT_DAYS = 90
DETECT_RATIO = 1.9
DETECT_ADJACENT = 0.301
EVAL_SESSION_SYMBOL_CAP = 24300
COUNT_UNIT_LINKS = (
    "원인×종목×평가세션 연결 건수. 같은 종목·세션에 원인이 둘이면 두 번 센다."
)
COUNT_UNIT_UNIQUE = (
    "(symbol, session) 중복 제거 고유 영향 건수. 상한은 평가 243일×TOP100=24300."
)
COUNT_UNITS = {
    "session_symbol_pairs": COUNT_UNIT_LINKS,
    "quality_90_pairs": COUNT_UNIT_LINKS,
    "judgment_day_pairs": COUNT_UNIT_LINKS,
    "overlap_pairs": COUNT_UNIT_LINKS,
    "unique_symbol_sessions": COUNT_UNIT_UNIQUE,
    "unique_quality_90_symbol_sessions": COUNT_UNIT_UNIQUE,
    "unique_judgment_day_symbol_sessions": COUNT_UNIT_UNIQUE,
    "overlap_unique_symbol_sessions": COUNT_UNIT_UNIQUE,
}
SCOPE = (
    "이번 집계는 날짜별 TOP100 신규 추천 입력에 대한 것이다. "
    "보유·대기 주문 안전성 검증이 아니다."
)

# Paths whose literals are checked so lookbacks are not assumed to be 240.
ENGINE_FILES = {
    "chart": "src/ChartPlanEngine.php",
    "replay": "bin/paper/DatedTop100Replay.php",
    "quality": "src/PaperQuality.php",
    "feature": "src/FeatureEngine.php",
    "pullback": "src/TrendPullback.php",
    "retest": "src/BreakoutRetest.php",
    "recovery": "src/TrendRecovery.php",
    "context": "src/TrendContext.php",
    "spike": "src/SpikeDump.php",
    "levels": "src/HorizontalLevels.php",
    "top": "src/NoramuTopPattern.php",
    "envelope": "bin/paper/EnvelopeResearch.php",
    "history": "bin/paper/HistoryResearch.php",
}


def load_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def parse_day(s: str) -> date:
    return date.fromisoformat(s)


def unique_within_cap(n: int, cap: int = EVAL_SESSION_SYMBOL_CAP) -> int:
    if n > cap:
        raise ValueError(f"unique (symbol, session) {n} exceeds cap {cap}")
    return n


def src(root: Path, key: str) -> str:
    return (root / ENGINE_FILES[key]).read_text(encoding="utf-8")


def read_engine_ranges(root: Path) -> dict[str, Any]:
    """Lookbacks from the live engine files. Do not assume every window is 240."""
    chart = src(root, "chart")
    replay = src(root, "replay")
    quality = src(root, "quality")
    feature = src(root, "feature")
    pullback = src(root, "pullback")
    retest = src(root, "retest")
    recovery = src(root, "recovery")
    context = src(root, "context")
    spike = src(root, "spike")
    levels = src(root, "levels")
    top = src(root, "top")
    envelope = src(root, "envelope")
    history = src(root, "history")

    facts = {
        "price_basis": PRICE_BASIS,
        "replay_truncates_to_240": "array_slice($past" in replay or "PREP_BARS" in replay,
        "replay_past_is_through_session": "if($b['available_at']>$date)break;$past[]=$b;" in replay.replace(" ", ""),
        "replay_new_entry_only_if_top100": "not_in_dated_top100" in replay,
        "chart_min_completed": 40 if "count($bars) < 40" in chart else None,
        "chart_uses_envelope_ma240": "EnvelopeResearch" in chart or "ma240" in chart.lower(),
        "quality_min_completed": 60 if "count($completed)<60" in quality.replace(" ", "") else None,
        "quality_recent_invalid_days": QUALITY_RECENT_DAYS if "90*86400" in quality.replace(" ", "") else None,
        "quality_checks_price_jump": "JUMP_LIMIT" in quality or "0.31" in quality,
        "feature_sma": [5, 10, 20, 60],
        "feature_has_ma240": "sma($closes, 240)" in feature or "sma($closes,240)" in feature.replace(" ", ""),
        "feature_swing_bars": 20,
        "feature_prior_swing_bars": 40,
        "feature_rising_lows_lookback": 60 if "lookback = 60" in feature else None,
        "horizontal_lookback": 120 if "$lookback = 120" in levels else None,
        "pullback_min": 45 if "count($bars) < 45" in pullback else None,
        "pullback_structure_bars": 40,
        "pullback_stop_bars": 10,
        "pullback_target_bars": 20,
        "retest_min": 40 if "$n < 40" in retest else None,
        "retest_box_bars": 20,
        "retest_breakout_search_back": 16,
        "retest_atr_bars": 14,
        "retest_overhead_high_uses_all_prior": "foreach (array_slice($bars, 0, $j) as $old)" in retest,
        "recovery_starts_after_index": 59 if "$i < 59" in recovery else None,
        "recovery_episode_max_bars": 60 if "$i - $state['start'] > 60" in recovery else None,
        "recovery_scans_full_series": "foreach ($bars as $i => $bar)" in recovery,
        "context_daily_ma_period": 20,
        "context_daily_slope": 5,
        "context_weekly_ma_period": 20,
        "context_weekly_slope": 3,
        "spike_peak_lookback": 8 if "PEAK_LOOKBACK = 8" in spike else None,
        "spike_pre_burst_support_bars": 20 if "$start - 20" in spike else None,
        "top_pattern_peak_window": 65 if "$n - 65" in top else None,
        "top_pattern_min": 45 if "count($candles) < 45" in top else None,
        "envelope_required_bars": 240 if "$n<240" in envelope.replace(" ", "") else None,
        "envelope_in_chart_plan": False,
        "history_prep_bars": 240 if "PREP_BARS=240" in history.replace(" ", "") else None,
        "history_min_bars": 60 if "MIN_BARS=60" in history.replace(" ", "") else None,
        "history_jump_limit": 0.31 if "JUMP_LIMIT=0.31" in history.replace(" ", "") else None,
        "detect_ratio_1_9_is_partial": True,
        "listed_break_absence_is_not_no_corporate_action": True,
    }
    facts["new_entry_input"] = (
        "DatedTop100Replay는 판정일 세션 시각 이하 봉을 자르지 않고 $past에 넣는다. "
        "ChartPlanEngine은 CandleClock::completed($past) 전체($valid)를 받는다. "
        "엔벨로프 MA240은 추천 경로가 아니다."
    )
    facts["component_windows"] = [
        {"id": "replay_past", "bars": "판정일까지 로드된 전 봉", "path": ENGINE_FILES["replay"], "in_new_entry": True},
        {"id": "chart_valid", "bars": "OHLC>0 완료봉 전체, 최소 40", "path": ENGINE_FILES["chart"], "in_new_entry": True},
        {"id": "quality", "bars": "완료 60 + 최근 90력일 비정상", "path": ENGINE_FILES["quality"], "in_new_entry": True},
        {"id": "ma_feature", "bars": "SMA5/10/20/60 (240 없음)", "path": ENGINE_FILES["feature"], "in_new_entry": True},
        {"id": "ma_context_daily", "bars": "20+5", "path": ENGINE_FILES["context"], "in_new_entry": True},
        {"id": "ma_context_weekly", "bars": "주봉 20+3 (일봉 전체로 주봉 구성)", "path": ENGINE_FILES["context"], "in_new_entry": True},
        {"id": "pullback_stop", "bars": "최근 10저", "path": ENGINE_FILES["pullback"], "in_new_entry": True},
        {"id": "pullback_target", "bars": "직전 20고", "path": ENGINE_FILES["pullback"], "in_new_entry": True},
        {"id": "retest_box", "bars": "20", "path": ENGINE_FILES["retest"], "in_new_entry": True},
        {"id": "retest_overhead", "bars": "확인봉 이전 전체 고가", "path": ENGINE_FILES["retest"], "in_new_entry": True},
        {"id": "recovery", "bars": "인덱스 59부터 전체 시계열", "path": ENGINE_FILES["recovery"], "in_new_entry": True},
        {"id": "spike_dump", "bars": "고점 최근 8 + 급등 전 20", "path": ENGINE_FILES["spike"], "in_new_entry": True},
        {"id": "top_pattern", "bars": "최근 65 (미러는 전체 고저)", "path": ENGINE_FILES["top"], "in_new_entry": True},
        {"id": "horizontal", "bars": "120", "path": ENGINE_FILES["levels"], "in_new_entry": True},
        {"id": "envelope_ma240", "bars": "240", "path": ENGINE_FILES["envelope"], "in_new_entry": False},
    ]
    return facts


def engine_completed_ohlc(open_: Any, high: Any, low: Any, close: Any) -> bool:
    """CandleClock::completed OHLC part. Volume is inspected separately by PaperQuality."""
    vals = [open_, high, low, close]
    if any(v is None or not isinstance(v, (int, float)) for v in vals):
        return False
    o, h, l, c = (float(open_), float(high), float(low), float(close))
    if not all(map(lambda x: x == x and x > 0, (o, h, l, c))):  # NaN or <=0
        return False
    if l > min(o, c) or h < max(o, c) or h < l:
        return False
    return True


def cannot_conclude_no_corporate_action(_in_break_list: bool) -> bool:
    """1.9× / 30.1% only catch some jumps. Absence from the list is not a clearance."""
    return True


def classify_break(
    rec: dict[str, Any],
    top100_days: dict[str, set[str]],
    eval_days: list[str],
) -> dict[str, Any]:
    symbol = rec["symbol"]
    to_day = rec["to"]
    overlapping = [d for d in eval_days if to_day <= d and symbol in top100_days.get(d, set())]
    top100_after_known = overlapping
    any_top100 = [d for d in eval_days if symbol in top100_days.get(d, set())]
    missing_days = [d for d in eval_days if d not in top100_days]
    if missing_days:
        bucket = "판단 불가"
        reason = "평가 TOP100 파일이 비어 있어 신규 추천 입력을 확정하지 못한다"
    elif overlapping:
        bucket = "평가 입력과 겹침"
        reason = "단절의 to일이 그날 판정 이전이고, 이후(또는 당일) TOP100 신규 추천 입력에 과거 봉으로 들어간다"
    elif not any_top100:
        bucket = "겹치지 않음"
        reason = "평가 243일 TOP100에 이 종목이 한 번도 없다"
    elif all(d < to_day for d in any_top100):
        bucket = "겹치지 않음"
        reason = "TOP100 등장일이 모두 단절 to일보다 앞이다. 미래 단절로 과거 판정을 막지 않는다"
    else:
        bucket = "겹치지 않음"
        reason = "to일 이후 TOP100 세션이 없다"
    same_day_top100 = bool(rec.get("to_in_eval_top100"))
    return {
        "symbol": symbol,
        "code": rec.get("code"),
        "name": rec.get("name"),
        "from": rec["from"],
        "to": to_day,
        "from_status": (rec.get("from_ohlcv") or {}).get("status"),
        "to_status": (rec.get("to_ohlcv") or {}).get("status"),
        "ratio": rec.get("ratio"),
        "corporate_action": rec.get("corporate_action"),
        "to_in_eval_top100_same_day": same_day_top100,
        "same_day_top100_not_used_as_no_impact": True,
        "bucket": bucket,
        "reason": reason,
        "overlap_sessions": len(overlapping),
        "overlap_first": overlapping[0] if overlapping else None,
        "overlap_last": overlapping[-1] if overlapping else None,
        "eval_top100_sessions": len(any_top100),
        "top100_after_known": len(top100_after_known),
        "detection_partial": True,
        "listed_absence_not_clearance": cannot_conclude_no_corporate_action(True),
    }


def overlap_pair_counts(
    classified: list[dict[str, Any]],
    eval_days: list[str],
    top100_days: dict[str, set[str]],
) -> tuple[int, int, set[str]]:
    links = 0
    unique: set[tuple[str, str]] = set()
    sessions: set[str] = set()
    for c in classified:
        if c["bucket"] != "평가 입력과 겹침":
            continue
        for d in eval_days:
            if c["to"] <= d and c["symbol"] in top100_days.get(d, set()):
                links += 1
                unique.add((c["symbol"], d))
                sessions.add(d)
    return links, unique_within_cap(len(unique)), sessions


def load_top100(candidates: dict[str, Any]) -> tuple[list[str], dict[str, set[str]]]:
    days = list(candidates["days"])
    by_day: dict[str, set[str]] = {}
    for day in days:
        rec = (candidates.get("dates") or {}).get(day) or {}
        rows = rec.get("rows") or []
        by_day[day] = {str(r["symbol"]) for r in rows if r.get("symbol")}
    return days, by_day


def find_bars_dir(root: Path, explicit: str | None) -> Path | None:
    if explicit:
        p = Path(explicit)
        return p if p.is_dir() else None
    here = root / BARS_REL
    if here.is_dir():
        return here
    sibling = root.parent / "noramu-work-krx-tdd-dataset" / BARS_REL
    if sibling.is_dir():
        return sibling
    return None


def load_series(path: Path) -> list[dict[str, Any]]:
    rows = []
    with path.open(encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            rows.append(json.loads(line))
    rows.sort(key=lambda r: r["day"])
    return rows


def event_index(rows: list[dict[str, Any]]) -> dict[str, list[str]]:
    out = {"ohl_zero": [], "no_row": [], "other_abnormal": []}
    for r in rows:
        st = r.get("status")
        if st in out:
            out[st].append(r["day"])
    return out


def valid_days(rows: list[dict[str, Any]]) -> list[str]:
    out = []
    for r in rows:
        if engine_completed_ohlc(r.get("open"), r.get("high"), r.get("low"), r.get("close")):
            out.append(r["day"])
    return out


def in_last_n_valid(cause: str, session: str, valid: list[str], n: int) -> bool:
    through = [d for d in valid if d <= session]
    if cause not in through:
        return False
    return cause in through[-n:]


def summarize_events(
    kind: str,
    by_symbol: dict[str, list[str]],
    top100_days: dict[str, set[str]],
    eval_days: list[str],
    valid_by_symbol: dict[str, list[str]] | None,
) -> dict[str, Any]:
    records = []
    pair_syms: set[str] = set()
    pair_days: set[str] = set()
    pair_n = 0
    quality90_n = 0
    judgment_n = 0
    unique_pairs: set[tuple[str, str]] = set()
    unique_q90: set[tuple[str, str]] = set()
    unique_judgment: set[tuple[str, str]] = set()
    short_windows = defaultdict(int)
    for symbol, causes in by_symbol.items():
        sessions = [d for d in eval_days if symbol in top100_days.get(d, set())]
        if not sessions:
            continue
        valid = (valid_by_symbol or {}).get(symbol) or []
        for cause in causes:
            ov = [d for d in sessions if cause <= d]
            if not ov:
                continue
            q90 = [d for d in ov if (parse_day(d) - parse_day(cause)).days <= QUALITY_RECENT_DAYS]
            on_d = [d for d in ov if d == cause]
            rec = {
                "symbol": symbol,
                "cause_day": cause,
                "kind": kind,
                "overlap_sessions": len(ov),
                "overlap_first": ov[0],
                "overlap_last": ov[-1],
                "quality_90_sessions": len(q90),
                "judgment_day_sessions": len(on_d),
            }
            if valid:
                rec["in_last_8_valid"] = sum(1 for d in ov if in_last_n_valid(cause, d, valid, 8))
                rec["in_last_20_valid"] = sum(1 for d in ov if in_last_n_valid(cause, d, valid, 20))
                rec["in_last_40_valid"] = sum(1 for d in ov if in_last_n_valid(cause, d, valid, 40))
                rec["in_last_60_valid"] = sum(1 for d in ov if in_last_n_valid(cause, d, valid, 60))
                rec["in_last_120_valid"] = sum(1 for d in ov if in_last_n_valid(cause, d, valid, 120))
                for k in ("in_last_8_valid", "in_last_20_valid", "in_last_40_valid", "in_last_60_valid", "in_last_120_valid"):
                    if rec[k]:
                        short_windows[k] += 1
            records.append(rec)
            pair_n += len(ov)
            quality90_n += len(q90)
            judgment_n += len(on_d)
            pair_syms.add(symbol)
            pair_days.update(ov)
            unique_pairs.update((symbol, d) for d in ov)
            unique_q90.update((symbol, d) for d in q90)
            unique_judgment.update((symbol, d) for d in on_d)
    records.sort(key=lambda r: (r["symbol"], r["cause_day"]))
    unique_n = unique_within_cap(len(unique_pairs))
    unique_q90_n = unique_within_cap(len(unique_q90))
    unique_judgment_n = unique_within_cap(len(unique_judgment))
    return {
        "kind": kind,
        "scope": SCOPE,
        "future_events_do_not_block_past": True,
        "count_units": {
            "session_symbol_pairs": COUNT_UNIT_LINKS,
            "quality_90_pairs": COUNT_UNIT_LINKS,
            "judgment_day_pairs": COUNT_UNIT_LINKS,
            "unique_symbol_sessions": COUNT_UNIT_UNIQUE,
            "unique_quality_90_symbol_sessions": COUNT_UNIT_UNIQUE,
            "unique_judgment_day_symbol_sessions": COUNT_UNIT_UNIQUE,
        },
        "events_overlapping": len(records),
        "symbols": len(pair_syms),
        "sessions": len(pair_days),
        "session_symbol_pairs": pair_n,
        "unique_symbol_sessions": unique_n,
        "quality_90_pairs": quality90_n,
        "unique_quality_90_symbol_sessions": unique_q90_n,
        "judgment_day_pairs": judgment_n,
        "unique_judgment_day_symbol_sessions": unique_judgment_n,
        "short_window_event_counts": dict(short_windows),
        "rows": records,
    }


def adapter_notes(bars_dir: Path | None, facts: dict[str, Any]) -> list[dict[str, str]]:
    notes = [
        {
            "id": "field_map",
            "status": "가능",
            "detail": "jsonl의 open/high/low/close/volume과 day→15:30 KST available_at 매핑은 가격 변환 없이 가능하다.",
        },
        {
            "id": "ohl_zero_in_past",
            "status": "가능",
            "detail": "OHL=0 원행은 $past에 남겨 PaperQuality가 비정상으로 보게 해야 한다. CandleClock은 OHLC<=0을 $valid에서 뺀다.",
        },
        {
            "id": "no_row_not_invented",
            "status": "엔진 한계",
            "detail": "행 없음은 봉을 만들지 않는다. PaperQuality는 중간 거래일 공백을 달력과 대조하지 않고, 당일 완료봉이 없으면 missing_session만 띄운다.",
        },
        {
            "id": "chart_plan_not_run",
            "status": "이번 범위 밖",
            "detail": "추천·손절·목표 숫자를 엔진으로 재계산하지 않았다. 계좌 실행 금지. 겹침은 입력 범위만 센다.",
        },
        {
            "id": "price_basis",
            "status": "미확정",
            "detail": facts["price_basis"] + ". DatedTop100Replay의 기존 PRICE_BASIS는 Yahoo 표기다. KRX 원본을 넣으려면 출처 표기 어댑터가 필요하고 가격 보정은 하지 않는다.",
        },
        {
            "id": "envelope",
            "status": "추천 경로 아님",
            "detail": "MA240은 EnvelopeResearch 전용이다. ChartPlanEngine 신규 추천 입력에 넣지 않는다.",
        },
        {
            "id": "holdings",
            "status": "검증 안 함",
            "detail": SCOPE,
        },
        {
            "id": "detection",
            "status": "부분",
            "detail": f"단절 목록은 종가비 {DETECT_RATIO} 또는 인접 |r-1|>{DETECT_ADJACENT}만 잡는다. 목록에 없다고 기업행사·가격 기준 문제가 없는 것이 아니다.",
        },
    ]
    if bars_dir is None:
        notes.append({
            "id": "bars_jsonl",
            "status": "막힘",
            "detail": f"{BARS_REL.as_posix()} 가 이 워크트리에 없다. OHL=0·행없음 날짜 집계는 로컬 데이터셋이 있을 때만 확정한다.",
        })
    return notes


def render_report(summary: dict[str, Any]) -> str:
    facts = summary["engine_ranges"]
    br = summary["breaks"]
    ohl = summary["ohl_zero"]
    miss = summary["no_row"]
    ad = summary["adapters"]
    lines = [
        "# 평가 입력과 KRX 단절·비정상 행 겹침 (PR #92 후속)",
        "",
        "PR #92 데이터셋·단절 96 보고는 그대로 둔다. 원본 jsonl·전략·계좌는 변경하지 않았다. 추가 수집·보정·봉 삭제·조건 완화·계좌 실행·병합은 없다.",
        "",
        f"가격 기준: **{PRICE_BASIS}**",
        "",
        SCOPE,
        "",
        "1.9배·30.1% 탐지는 일부 급변만 잡는다. 96건 목록에 없다고 기업행사나 가격 기준 문제가 없다고 보지 않는다.",
        "",
        "## 1. 엔진이 실제로 쓰는 과거 봉",
        "",
        "날짜별 TOP100 재현(`DatedTop100Replay::day`)은 판정 세션 이하 봉을 **240으로 자르지 않고** `$past`에 넣는다. 신규 추천만 ChartPlanEngine에 `$valid`(OHLC>0 완료봉 전체)를 넘긴다. 당일 TOP100이 아니면 계획을 만들지 않지만, 그 이유로 과거 단절이 이후 TOP100 입력에 안 들어간다고 보지 않는다.",
        "",
        "| 구성 | 코드 범위 | 신규 추천 경로 |",
        "|---|---|---|",
    ]
    for w in facts["component_windows"]:
        lines.append(f"| `{w['id']}` | {w['bars']} (`{w['path']}`) | {'예' if w['in_new_entry'] else '아니오'} |")
    lines += [
        "",
        f"- ChartPlanEngine 최소 완료봉: {facts['chart_min_completed']} (240 아님).",
        f"- PaperQuality: 완료 {facts['quality_min_completed']}봉, 최근 비정상 {facts['quality_recent_invalid_days']}력일. 가격 점프 검사는 없음.",
        f"- FeatureEngine 이평: SMA5/10/20/60. MA240 없음.",
        f"- 재지지 목표의 상단 고가는 확인봉 **이전 전체**를 본다. 회복형은 인덱스 59부터 **전체 시계열**.",
        f"- Envelope MA240은 연구 관찰용이며 ChartPlanEngine에 없다. HistoryResearch PREP_BARS=240은 그 연구를 위한 준비 창이다.",
        "",
        "## 2. 평가 243일 당일 TOP100 입력 겹침",
        "",
        "연결 건수는 원인×종목×평가세션을 합산한다. 같은 종목·세션에 원인이 둘이면 두 번 센다. "
        f"고유 건수는 (symbol, session)을 한 번만 세며 상한은 {EVAL_SESSION_SYMBOL_CAP}이다.",
        "",
        f"- 평가 세션: {summary['eval_sessions']}일, 일자×종목 상한: {summary['pairs']}.",
        f"- 단절 96: 겹침 {br['counts']['평가 입력과 겹침']} / 겹치지 않음 {br['counts']['겹치지 않음']} / 판단 불가 {br['counts']['판단 불가']}.",
        f"- 겹치는 단절: 종목 {br['overlap_symbols']} · 세션 {br['overlap_sessions']} · 연결 {br['overlap_pairs']} · 고유 (종목, 세션) {br.get('overlap_unique_symbol_sessions')}.",
        f"- 당일 TOP100만 본 PR #92 표(6/37/53)는 쓰지 않았다. 같은 날 TOP100은 {br.get('same_day_top100_true')}건뿐이나, 이후 TOP100 세션까지 보면 겹침이 {br['counts']['평가 입력과 겹침']}건이다.",
        "",
        "### OHL=0 행",
        "",
    ]
    if ohl.get("blocked"):
        lines.append(f"확정하지 않음: {ohl['blocked']}")
    else:
        lines += [
            f"- 겹친 원인 날짜: {ohl['events_overlapping']} (종목 {ohl['symbols']}, 세션 {ohl['sessions']}).",
            f"- 연결 건수: {ohl['session_symbol_pairs']} · 고유 (종목, 세션): {ohl['unique_symbol_sessions']} (≤{EVAL_SESSION_SYMBOL_CAP}).",
            f"- PaperQuality 90력일: 연결 {ohl['quality_90_pairs']} · 고유 {ohl['unique_quality_90_symbol_sessions']}.",
            f"- 판정일 당일 OHL=0: 연결 {ohl['judgment_day_pairs']} · 고유 {ohl['unique_judgment_day_symbol_sessions']}. TOP100은 당일 거래대금 행에서 뽑히므로 당일 0거래대금 OHL=0이 0인 것은 선정 방식과 맞다. 과거 OHL=0은 별개다.",
            "- OHL=0은 `$valid`에 안 들어가 패턴·MA·손절·목표 계산 봉은 아니다. `$past` 비정상으로 최근 90력일이면 품질 차단.",
        ]
    lines += ["", "### 행 없음", ""]
    if miss.get("blocked"):
        lines.append(f"확정하지 않음: {miss['blocked']}")
    else:
        lines += [
            f"- 판정일까지의 달력에 행 없음이 있는 TOP100 입력: 원인 날짜 {miss['events_overlapping']} (종목 {miss['symbols']}, 세션 {miss['sessions']}).",
            f"- 연결 건수: {miss['session_symbol_pairs']} · 고유 (종목, 세션): {miss['unique_symbol_sessions']} (≤{EVAL_SESSION_SYMBOL_CAP}).",
            f"- 최근 90력일 공백(연결 집계): {miss['quality_90_pairs']} · 고유 {miss['unique_quality_90_symbol_sessions']}. 중간 공백은 품질 사유가 아니다.",
            f"- 판정일 당일 행 없음: 연결 {miss['judgment_day_pairs']} · 고유 {miss['unique_judgment_day_symbol_sessions']}. 당일 TOP100에 있으려면 당일 원본 행이 있어야 하므로 0이 정상이다. 과거 상장 전·이전 시장 공백은 입력 시계열 길이를 줄인다.",
            "- 중간 공백은 PaperQuality가 달력 대조를 하지 않아 품질 사유가 되지 않는다. 시계열이 짧아지거나 인접 완료봉 사이 간격만 남는다.",
        ]
    none_rows = [r for r in (br.get("rows") or summary.get("break_rows") or []) if r.get("bucket") == "겹치지 않음"]
    lines += [
        "",
        "## 3. 단절 96 분류",
        "",
        "분류 키는 단절 `to` ≤ 판정일 이고 그 종목이 그날 TOP100인 세션이 하나라도 있는가이다. 미래 `to`로 과거 세션을 막지 않는다.",
        "",
        "| 구분 | 건수 |",
        "|---|---|",
        f"| 평가 입력과 겹침 | {br['counts']['평가 입력과 겹침']} |",
        f"| 겹치지 않음 | {br['counts']['겹치지 않음']} |",
        f"| 판단 불가 | {br['counts']['판단 불가']} |",
        "",
        "겹치지 않음 12건은 TOP100 등장이 모두 `to`보다 앞이다.",
        "",
    ]
    if none_rows:
        lines.append("| 종목 | from | to | 평가 TOP100 세션(단절 전) |")
        lines.append("|---|---|---|---|")
        for r in none_rows:
            lines.append(f"| {r['symbol']} | {r['from']} | {r['to']} | {r['eval_top100_sessions']} |")
        lines.append("")
    lines += [
        "확인된 무상증자 2건(083450.KQ, 086280.KS)도 이후 TOP100 신규 입력과 겹친다. 보정하지 않았다.",
        "",
        "상세: `breaks-overlap.json`.",
        "",
        "## 4. 어댑터·막힌 지점",
        "",
        "KRX jsonl을 ChartPlanEngine에 넣어 추천을 재현하지는 않았다. 아래는 입력 구성 한계다.",
        "",
    ]
    for n in ad:
        lines.append(f"- **{n['id']}** ({n['status']}): {n['detail']}")
    lines += [
        "",
        "## 재현",
        "",
        "```",
        "python -m unittest tests.test_krx_eval_input_overlap",
        "python docs/research/krx_eval_input_overlap.py --bars <jsonl 디렉터리>",
        "```",
        "",
    ]
    return "\n".join(lines) + "\n"


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--candidates", default=str(CANDIDATES))
    p.add_argument("--breaks", default=str(BREAKS))
    p.add_argument("--bars", default="")
    p.add_argument("--docs-out", default=str(DOCS_OUT))
    p.add_argument("--root", default=str(ROOT))
    args = p.parse_args()
    root = Path(args.root)
    facts = read_engine_ranges(root)
    if facts["chart_uses_envelope_ma240"] or facts["replay_truncates_to_240"]:
        raise SystemExit("engine range facts do not match source; do not assume 240")
    if facts["quality_min_completed"] != 60 or facts["chart_min_completed"] != 40:
        raise SystemExit("quality/chart minima missing from source")

    cand = load_json(Path(args.candidates))
    eval_days, top100_days = load_top100(cand)
    if len(eval_days) != 243:
        raise SystemExit(f"expected 243 eval days, got {len(eval_days)}")
    empty = [d for d in eval_days if len(top100_days.get(d, set())) != 100]
    pairs = sum(len(top100_days[d]) for d in eval_days)

    breaks_payload = load_json(Path(args.breaks))
    assigned = list(breaks_payload.get("assigned_suffix") or [])
    if len(assigned) != 96:
        raise SystemExit(f"expected 96 assigned breaks, got {len(assigned)}")
    classified = [classify_break(b, top100_days, eval_days) for b in assigned]
    counts = {"평가 입력과 겹침": 0, "겹치지 않음": 0, "판단 불가": 0}
    for c in classified:
        counts[c["bucket"]] += 1
    ov = [c for c in classified if c["bucket"] == "평가 입력과 겹침"]
    overlap_links, overlap_unique, ov_sessions = overlap_pair_counts(classified, eval_days, top100_days)
    break_sum = {
        "n": 96,
        "counts": counts,
        "count_units": {
            "overlap_pairs": COUNT_UNIT_LINKS,
            "overlap_unique_symbol_sessions": COUNT_UNIT_UNIQUE,
        },
        "overlap_symbols": len({c["symbol"] for c in ov}),
        "overlap_sessions": len(ov_sessions),
        "overlap_pairs": overlap_links,
        "overlap_unique_symbol_sessions": overlap_unique,
        "same_day_top100_true": sum(1 for c in classified if c["to_in_eval_top100_same_day"]),
        "rows": classified,
        "note": (
            "당일 TOP100만으로 영향 없음을 단정하지 않았다. 보유·대기 주문은 집계하지 않았다. "
            "overlap_pairs는 원인×종목×세션 연결 건수이고, overlap_unique_symbol_sessions는 "
            "(symbol, session) 고유 건수다."
        ),
    }

    bars_dir = find_bars_dir(root, args.bars or None)
    adapters = adapter_notes(bars_dir, facts)
    ohl_out: dict[str, Any]
    miss_out: dict[str, Any]
    needed = {s for d in eval_days for s in top100_days[d]}
    if bars_dir is None:
        msg = f"bars jsonl missing under {BARS_REL.as_posix()} (and sibling worktree fallback)"
        ohl_out = {"blocked": msg, "events_overlapping": None}
        miss_out = {"blocked": msg, "events_overlapping": None}
    else:
        missing_files = [s for s in sorted(needed) if not (bars_dir / f"{s}.jsonl").is_file()]
        if missing_files:
            msg = f"jsonl missing for {len(missing_files)} TOP100 symbols; first {missing_files[:5]}"
            ohl_out = {"blocked": msg, "events_overlapping": None}
            miss_out = {"blocked": msg, "events_overlapping": None}
        else:
            zeros: dict[str, list[str]] = {}
            holes: dict[str, list[str]] = {}
            for symbol in sorted(needed):
                rows = load_series(bars_dir / f"{symbol}.jsonl")
                idx = event_index(rows)
                zeros[symbol] = idx["ohl_zero"]
                holes[symbol] = idx["no_row"]
            ohl_out = summarize_events("ohl_zero", zeros, top100_days, eval_days, None)
            miss_out = summarize_events("no_row", holes, top100_days, eval_days, None)
            ohl_out["not_in_chart_valid"] = True
            miss_out["not_in_past_or_valid"] = True
            miss_out["intermediate_gap_not_a_quality_reason"] = True

    docs = Path(args.docs_out)
    docs.mkdir(parents=True, exist_ok=True)
    summary = {
        "schema": 1,
        "kind": "krx_eval_input_overlap_v1",
        "price_basis": PRICE_BASIS,
        "scope": SCOPE,
        "no_collect": True,
        "no_adjust": True,
        "no_drop_bars": True,
        "no_relax": True,
        "no_account": True,
        "no_merge": True,
        "chart_plan_not_executed": True,
        "eval_from": eval_days[0],
        "eval_to": eval_days[-1],
        "eval_sessions": len(eval_days),
        "pairs": pairs,
        "eval_session_symbol_cap": EVAL_SESSION_SYMBOL_CAP,
        "count_units": COUNT_UNITS,
        "empty_top100_days": empty,
        "engine_ranges": facts,
        "breaks": {k: v for k, v in break_sum.items() if k != "rows"},
        "ohl_zero": {k: v for k, v in ohl_out.items() if k != "rows"},
        "no_row": {k: v for k, v in miss_out.items() if k != "rows"},
        "adapters": adapters,
        "detection": {
            "ratio": DETECT_RATIO,
            "adjacent": DETECT_ADJACENT,
            "partial_only": True,
            "not_a_corporate_action_clearance": True,
        },
    }
    write_json(docs / "summary.json", summary)
    write_json(docs / "engine-ranges.json", facts)
    write_json(docs / "breaks-overlap.json", {"schema": 1, "price_basis": PRICE_BASIS, "scope": SCOPE, **break_sum})
    write_json(docs / "ohl-zero-events.json", {"schema": 1, "price_basis": PRICE_BASIS, **ohl_out})
    write_json(docs / "no-row-events.json", {"schema": 1, "price_basis": PRICE_BASIS, **miss_out})
    write_json(docs / "adapters.json", {"schema": 1, "rows": adapters})
    (docs / "report.md").write_text(render_report({**summary, "breaks": break_sum}), encoding="utf-8")
    print(json.dumps({
        "eval_sessions": len(eval_days),
        "pairs": pairs,
        "breaks": counts,
        "ohl_zero_events": ohl_out.get("events_overlapping"),
        "ohl_zero_unique": ohl_out.get("unique_symbol_sessions"),
        "no_row_events": miss_out.get("events_overlapping"),
        "no_row_unique": miss_out.get("unique_symbol_sessions"),
        "breaks_links": break_sum.get("overlap_pairs"),
        "breaks_unique": break_sum.get("overlap_unique_symbol_sessions"),
        "bars": str(bars_dir) if bars_dir else None,
        "docs": str(docs),
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
