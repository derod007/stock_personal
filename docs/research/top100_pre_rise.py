"""Pre-specified TOP100 chart-feature screen. Outcomes never enter feature math."""
from __future__ import annotations

import argparse
import hashlib
import json
import math
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FEE = 0.0010
SLIP = 0.0005
BUY = 1 + FEE + SLIP
SELL = 1 - FEE - SLIP
HOLD = 20
REPEAT_GAP = 20
MIN_BARS = 60
NEAR = 0.97
STOP = 0.95
TARGET = 1.15
MIN_EDGE_N = 30
VERSION = "top100_pre_rise_v1"
PRICE_BASIS = "yahoo_chart_quote_ohlcv_daily_adjustment_unverified"
FEATURES = ("close_near_20high", "higher_10bar_low", "ma20_above_ma60")

PERIODS = {
    "prior": {
        "candidates": ROOT / "docs/historical-top100-20241008-20251002/candidates.json",
        "dataset": Path(r"C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\kr-dated-amount-top100-20241008-20251002-v2"),
        "signals": Path(r"C:\Users\acdun\Desktop\dev\stock-personal-paper\history-account\hist-rc-prior-v1-baseline\account-replay.json"),
        "signal_kind": "journal_day_log",
        "label": "2024-10-08~2025-10-02",
    },
    "recent": {
        "candidates": ROOT / "docs/historical-top100-20251008-20261008/candidates.json",
        "dataset": Path(r"C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\kr-dated-amount-top100-20251008-20261008-v2"),
        "signals": ROOT / "docs/historical-top100-replay-20251008-20261008/account/daily-log.jsonl",
        "signal_kind": "daily_log_jsonl",
        "label": "2025-10-10~2026-10-08",
    },
}


def read_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def valid_bar(b: dict) -> bool:
    if not b.get("is_complete", True) or b.get("synthetic"):
        return False
    try:
        o, h, l, c = (float(b[k]) for k in ("open", "high", "low", "close"))
        v = float(b["volume"])
    except (KeyError, TypeError, ValueError):
        return False
    nums = (o, h, l, c, v)
    if any(not math.isfinite(x) for x in nums):
        return False
    if any(x <= 0 for x in (o, h, l, c)) or v < 0:
        return False
    return l <= min(o, c) <= max(o, c) <= h


def _as_bar(b: dict) -> dict:
    return {
        "open": float(b["open"]),
        "high": float(b["high"]),
        "low": float(b["low"]),
        "close": float(b["close"]),
        "volume": float(b["volume"]),
    }


def load_bars(path: Path) -> dict[str, dict]:
    if not path.is_file():
        return {}
    raw = read_json(path)
    basis = raw.get("price_basis")
    if basis not in (None, PRICE_BASIS):
        raise ValueError(f"unexpected price_basis {path}: {basis}")
    by: dict[str, dict | None] = {}
    for b in raw.get("rows") or []:
        day = str(b.get("time_kst", ""))[:10]
        if len(day) != 10:
            continue
        if day in by:
            by[day] = None
            continue
        by[day] = _as_bar(b) if valid_bar(b) else None
    return {d: b for d, b in by.items() if b}


def sma(vals: list[float], n: int) -> float | None:
    if len(vals) < n:
        return None
    return sum(vals[-n:]) / n


def features(past: list[dict]) -> dict | None:
    if len(past) < MIN_BARS:
        return None
    closes = [b["close"] for b in past]
    last20 = past[-20:]
    hi20 = max(b["high"] for b in last20)
    lo10 = min(b["low"] for b in past[-10:])
    prev10 = min(b["low"] for b in past[-20:-10])
    ma20 = sma(closes, 20)
    ma60 = sma(closes, 60)
    close = past[-1]["close"]
    if hi20 <= 0 or ma20 is None or ma60 is None:
        return None
    return {
        "close_near_20high": close >= hi20 * NEAR,
        "higher_10bar_low": lo10 > prev10,
        "ma20_above_ma60": ma20 > ma60,
        "close": close,
        "high20": hi20,
        "ma20": ma20,
        "ma60": ma60,
    }


def simulate(window: list[dict], entry: float) -> dict:
    buy = entry * BUY
    stop_px = entry * STOP
    target_px = entry * TARGET
    hit15 = False
    max_up = 0.0
    reason = "time"
    exit_px = window[-1]["close"] * SELL
    bars_held = len(window)
    for i, b in enumerate(window, 1):
        max_up = max(max_up, b["high"] / entry - 1)
        if b["high"] >= entry * TARGET:
            hit15 = True
        stopped = b["low"] <= stop_px
        targeted = b["high"] >= target_px
        if stopped:
            fill = min(stop_px, b["open"]) * SELL
            reason, exit_px, bars_held = "stop", fill, i
            break
        if targeted:
            reason, exit_px, bars_held = "target", target_px * SELL, i
            break
    net = (exit_px - buy) / buy
    return {
        "high_touch_15": hit15,
        "max_up_pct": max_up * 100,
        "end_return_pct": (window[-1]["close"] / entry - 1) * 100,
        "tradable_net_pct": net * 100,
        "tradable_win": net > 0,
        "exit_reason": reason,
        "bars_held": bars_held,
    }


def load_signals_jsonl(path: Path) -> tuple[set[tuple[str, str]], set[str]]:
    caught = set()
    days = set()
    for line in path.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        row = json.loads(line)
        day = row["date"]
        days.add(day)
        for s in row.get("signals") or []:
            caught.add((day, s["symbol"]))
    return caught, days


def load_signals_journal(path: Path) -> tuple[set[tuple[str, str]], set[str]]:
    caught = set()
    days = set()
    journal = read_json(path)
    for e in journal.get("events") or []:
        if e.get("type") != "day_log":
            continue
        payload = e.get("payload") or {}
        day = payload.get("date")
        if not day:
            continue
        days.add(day)
        for s in payload.get("signals") or []:
            caught.add((day, s["symbol"]))
    return caught, days


def load_period(name: str, spec: dict | None = None) -> dict:
    spec = spec or PERIODS[name]
    cand = read_json(spec["candidates"])
    days = list(cand["days"])
    if spec["signal_kind"] == "daily_log_jsonl":
        caught, covered = load_signals_jsonl(spec["signals"])
    else:
        caught, covered = load_signals_journal(spec["signals"])
    return {
        "name": name,
        "label": spec["label"],
        "days": days,
        "idx": {d: i for i, d in enumerate(days)},
        "dates": cand["dates"],
        "dataset": Path(spec["dataset"]),
        "caught": caught,
        "covered": covered,
        "candidates_sha256": sha256(spec["candidates"]),
        "signals_sha256": sha256(spec["signals"]),
        "dataset_name": Path(spec["dataset"]).name,
    }


def observations(period: dict) -> list[dict]:
    cache: dict[str, dict] = {}
    last_seen: dict[str, int] = {}
    rows = []
    for date in period["days"]:
        i = period["idx"][date]
        for item in period["dates"][date]["rows"]:
            sym = item["symbol"]
            if sym not in cache:
                cache[sym] = load_bars(period["dataset"] / "bars" / f"{sym}.json")
            bars = cache[sym]
            prev = last_seen.get(sym)
            unique = prev is None or i - prev >= REPEAT_GAP
            last_seen[sym] = i
            rec_known = date in period["covered"]
            caught = (date, sym) in period["caught"]
            feat = None
            outcome = None
            skip = None
            if date not in bars:
                skip = "missing_judgment_bar"
            else:
                past = [bars[d] for d in sorted(d for d in bars if d <= date)]
                feat = features(past)
                if feat is None:
                    skip = "insufficient_history"
                else:
                    future_days = period["days"][i + 1 : i + 1 + HOLD]
                    if len(future_days) < HOLD:
                        skip = "incomplete_future_window"
                    else:
                        window = [bars.get(d) for d in future_days]
                        if any(b is None for b in window):
                            skip = "missing_future_bar"
                        else:
                            outcome = simulate(window, window[0]["open"])
            rows.append(
                {
                    "date": date,
                    "symbol": sym,
                    "unique": unique,
                    "rec_known": rec_known,
                    "caught": caught,
                    "features": feat,
                    "outcome": outcome,
                    "skip": skip,
                }
            )
    return rows


def stats(rows: list[dict], pred) -> dict:
    q = [r for r in rows if r["outcome"] is not None and r["features"] is not None and pred(r)]
    n = len(q)
    if n == 0:
        return {"n": 0}
    hits = sum(bool(r["outcome"]["high_touch_15"]) for r in q)
    trad_w = sum(bool(r["outcome"]["tradable_win"]) for r in q)
    nets = [r["outcome"]["tradable_net_pct"] for r in q]
    losses = [x for x in nets if x < 0]
    holds = [r["outcome"]["bars_held"] for r in q]
    stops = sum(r["outcome"]["exit_reason"] == "stop" for r in q)
    return {
        "n": n,
        "high_touch_15": hits,
        "high_touch_15_pct": round(hits / n * 100, 2),
        "tradable_wins": trad_w,
        "tradable_win_pct": round(trad_w / n * 100, 2),
        "mean_tradable_net_pct": round(sum(nets) / n, 4),
        "mean_loss_net_pct": round(sum(losses) / len(losses), 4) if losses else None,
        "loss_n": len(losses),
        "mean_bars_held": round(sum(holds) / n, 2),
        "stop_n": stops,
    }


def has_edge(true_s: dict, false_s: dict) -> bool:
    if true_s.get("n", 0) < MIN_EDGE_N or false_s.get("n", 0) < MIN_EDGE_N:
        return False
    if true_s["mean_tradable_net_pct"] <= 0:
        return False
    if true_s["mean_tradable_net_pct"] <= false_s["mean_tradable_net_pct"]:
        return False
    if true_s["tradable_win_pct"] <= false_s["tradable_win_pct"]:
        return False
    return True


def pack(sample: list[dict]) -> dict:
    out = {"base": stats(sample, lambda r: True)}
    for name in FEATURES:
        true_s = stats(sample, lambda r, n=name: r["features"][n])
        false_s = stats(sample, lambda r, n=name: not r["features"][n])
        out[name] = {
            "true": true_s,
            "false": false_s,
            "edge": has_edge(true_s, false_s),
            "similar_no_high_touch": sum(
                1
                for r in sample
                if r["features"][name] and not r["outcome"]["high_touch_15"]
            ),
            "similar_tradable_loss": sum(
                1 for r in sample if r["features"][name] and r["outcome"]["tradable_net_pct"] < 0
            ),
        }
    return out


def failure_examples(sample: list[dict], n: int = 5) -> dict[str, list[dict]]:
    out = {}
    for name in FEATURES:
        fails = [
            r
            for r in sample
            if r["features"][name]
            and (not r["outcome"]["high_touch_15"] or r["outcome"]["tradable_net_pct"] < 0)
        ]
        fails.sort(key=lambda r: r["outcome"]["tradable_net_pct"])
        out[name] = [
            {
                "date": r["date"],
                "symbol": r["symbol"],
                "unique": r["unique"],
                "high_touch_15": r["outcome"]["high_touch_15"],
                "tradable_net_pct": round(r["outcome"]["tradable_net_pct"], 4),
                "exit_reason": r["outcome"]["exit_reason"],
                "bars_held": r["outcome"]["bars_held"],
            }
            for r in fails[:n]
        ]
    return out


def summarize(period: dict, rows: list[dict]) -> dict:
    scored = [r for r in rows if r["outcome"] is not None and r["features"] is not None]
    known = [r for r in scored if r["rec_known"]]
    missed = [r for r in known if not r["caught"]]
    unique_missed = [r for r in missed if r["unique"]]
    unique_missed_pack = pack(unique_missed)
    return {
        "version": VERSION,
        "period": period["name"],
        "label": period["label"],
        "price_basis": PRICE_BASIS,
        "dataset": period["dataset_name"],
        "candidates_sha256": period["candidates_sha256"],
        "signals_sha256": period["signals_sha256"],
        "top100_days": len(period["days"]),
        "signal_days_covered": len(period["covered"]),
        "stored_signals": len(period["caught"]),
        "observations": len(rows),
        "scored": len(scored),
        "rec_known_scored": len(known),
        "caught_scored": sum(1 for r in known if r["caught"]),
        "unique_missed_scored": len(unique_missed),
        "repeat_missed_scored": sum(1 for r in missed if not r["unique"]),
        "all_scored": pack(scored),
        "missed": pack(missed),
        "unique_missed": unique_missed_pack,
        "caught": pack([r for r in known if r["caught"]]),
        "failures_unique_missed": failure_examples(unique_missed),
        "skips": _count(r["skip"] for r in rows if r["skip"]),
        "lock_edges_unique_missed": {
            name: unique_missed_pack[name]["edge"] for name in FEATURES
        },
        "limitations": [
            "Already-used periods; not independent validation",
            "High-touch is not realized account PnL",
            "Prior recommendation coverage is incomplete if the journal halted",
            "Yahoo daily bars, adjustment unverified; not mixed with KRX TDD",
        ],
    }


def _count(items):
    out = defaultdict(int)
    for x in items:
        out[x] += 1
    return dict(out)


def run_period(name: str, spec: dict | None = None) -> dict:
    period = load_period(name, spec)
    rows = observations(period)
    return summarize(period, rows)


def _fmt(s: dict) -> str:
    if not s or s.get("n", 0) == 0:
        return "n=0"
    loss = "n/a" if s["mean_loss_net_pct"] is None else f"{s['mean_loss_net_pct']:.4f}"
    return (
        f"{s['n']} / {s['high_touch_15_pct']:.2f}% / {s['tradable_win_pct']:.2f}% / "
        f"{s['mean_tradable_net_pct']:.4f}% / {loss}% / {s['mean_bars_held']:.2f}"
    )


def render_report(report: dict) -> str:
    lines = [
        "# TOP100 판단일 차트 공통점 후보 v1",
        "",
        "탐색 연구다. 두 기간은 이미 연구에 썼으므로 독립 검증이 아니다. 운영 추천·설정·예약·장부·원본은 바꾸지 않았다. 계좌를 다시 실행하지 않았다.",
        "",
        "가격 기준: `yahoo_chart_quote_ohlcv_daily_adjustment_unverified`. KRX TDD와 섞지 않았다. 수정주가 여부는 미확정.",
        "",
        "## 기존 연구 요약",
        "",
        "| 연구 | 범위 | 시도한 조건 | 결과 | 미확정·누락 |",
        "|---|---|---|---|---|",
        "| 최근 1년 계좌 재현 | 2025-10-10~2026-10-08 TOP100 244일, 저장 장부 | 기존 상승눌림·재지지·회복 | 추천 103, 주문 68, 한도차단 28, 체결 59, 청산 56. 눌림 종료 42건 평균 −2.17% | 운영 조건 변경 없음 |",
        "| 이전 1년 자료·장부 | 2024-10-08~2025-10-02 TOP100 243일 | 같은 엔진 재현 시도 | 합집합 1248. 로컬 `hist-rc-prior-v1-baseline` day_log 234일·신호 101건 후 `unpriced_position` 중단 | 243일 전체 추천 목록 없음. 중단 이후는 추천 여부를 모름 |",
        "| opportunity-v1 | 최근 기간 TOP100 24,400 | 기존 10개 눌림 원자 중 하나만 실패, 재지지·회복은 RR만 부족 | 평가 23,560, 한도 28건 종료 평균 +0.37%, potential_new_symbol_days 758. 독립 단일실패 RR 눌림 455·회복 207·재지지 150 | 기존 패턴의 완화이지 새 차트 공통점이 아님 |",
        "| 눌림 42건 차트 검토 | 최근 기간 종료 눌림만 | 신호일까지 120·240봉 화면 | 코드 재계산 diffs 없음 | 성공 사례만 고르지 않았으나 기존 추천 거래로 제한 |",
        "| PR #100 과거 진단 요약 | 종목별 첫 TOP100 후 ≥20세션, 다음 시가 대비 20봉 +15% 고가 / 종가 손실 | 기존 엔진 상태 집계 | 로컬에 diagnosis 출력·summary 파일이 없음 | 숫자 없음. 추측하지 않음 |",
        "| 60봉 모양 2,450건 | decision-evidence README가 분모로 언급 | 파일·코드·집계 없음 | 없음 | 누락. 추측하지 않음 |",
        "",
        "이번 실험은 날짜별 TOP100 전체에서, 기존 세 패턴과 무관한 단순 특징 3개를 판단일 완료봉만으로 계산한다.",
        "",
        "## 이번 후보의 정확한 조건",
        "",
        "관찰 단위는 `(날짜, 종목)`이다. 같은 날 진입하거나 같은 기간 뒤에 오를 필요는 없다.",
        "",
        "| 항목 | 고정값 |",
        "|---|---|",
        "| 특징 계산 | 판단일 포함, 그날까지 완료되고 유효한 봉. 판단일 봉이 없으면 계산하지 않음. 이후 봉 사용 금지 |",
        "| 최소 이력 | 60봉 |",
        "| close_near_20high | 종가 ≥ 최근 20봉 고가 × 0.97 |",
        "| higher_10bar_low | 최근 10봉 최저 > 직전 10봉 최저 |",
        "| ma20_above_ma60 | 종가 SMA20 > 종가 SMA60 |",
        "| 결합 | 없음. 각각 단독 |",
        "| 고가 도달 | 다음 세션 시가 대비 이후 20봉 안 고가 ≥ 1.15×. 체결 수익 아님 |",
        "| 거래 모형 | 다음 세션 시가 매수, 수수료·슬리피지 각 10bp+5bp, 손절 5%, 목표 15%, 같은 봉이면 손절 우선, 20봉 종가 청산 |",
        "| 반복 | 같은 종목이 20세션 안에 다시 TOP100이면 repeat. unique와 합치지 않음 |",
        "| 미포착 | 저장 신호가 있는 날짜에만 판정. 이전 기간 중단 이후는 rec_known=false |",
        "| 유리함 | unique·미포착 표본에서 n≥30, 비용 반영 평균>0, 평균·승률이 특징 없는 쪽보다 큼. 이전 기간에서 본 뒤 임계값을 바꾸지 않음 |",
        "",
    ]
    periods = report["periods"]
    lines += [
        "## 기간별 결과",
        "",
        "| 기간 | 거래일 | 추천 커버 일 | 저장 신호 | 관찰 | 결과 있음 | unique 미포착 | 반복 미포착 | 이력부족 | 판단일봉없음 | 미래봉없음 | 기간말 |",
        "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        sk = p.get("skips") or {}
        lines.append(
            f"| {p['label']} | {p['top100_days']} | {p['signal_days_covered']} | {p['stored_signals']} | "
            f"{p['observations']} | {p['scored']} | {p['unique_missed_scored']} | {p['repeat_missed_scored']} | "
            f"{sk.get('insufficient_history', 0)} | {sk.get('missing_judgment_bar', 0)} | "
            f"{sk.get('missing_future_bar', 0)} | {sk.get('incomplete_future_window', 0)} |"
        )
    lines += [
        "",
        "### unique·미포착 (추천을 아는 날만, 결정 표본)",
        "",
        "숫자: n / 고가도달% / 거래승률% / 비용반영 평균% / 손실평균% / 평균보유봉.",
        "",
        "| 기간 | 특징 | 구분 | 숫자 | 유리함 |",
        "|---|---|---|---|---|",
    ]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        u = p["unique_missed"]
        lines.append(f"| {p['label']} | (전체) | — | {_fmt(u['base'])} | — |")
        for name in FEATURES:
            lines.append(f"| {p['label']} | {name} | 참 | {_fmt(u[name]['true'])} | {u[name]['edge']} |")
            lines.append(f"| {p['label']} | {name} | 거짓 | {_fmt(u[name]['false'])} | — |")
    lines += [
        "",
        "### 반복 포함 전체 결과 있음 (참고, 성과를 이 분모로 주장하지 않음)",
        "",
        "| 기간 | 특징 | 참 평균% | 거짓 평균% | 반복포함 유리함 | unique 미포착 유리함 |",
        "|---|---|---:|---:|---|---|",
    ]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        for name in FEATURES:
            a = p["all_scored"][name]
            lines.append(
                f"| {p['label']} | {name} | {a['true'].get('mean_tradable_net_pct', 'n/a')} | "
                f"{a['false'].get('mean_tradable_net_pct', 'n/a')} | {a['edge']} | {p['lock_edges_unique_missed'][name]} |"
            )
    both = [name for name in FEATURES if all(
        key in periods and periods[key]["lock_edges_unique_missed"][name] for key in ("prior", "recent")
        if key in periods
    ) and {"prior", "recent"} <= set(periods)]
    if {"prior", "recent"} <= set(periods):
        if both:
            verdict = ", ".join(both) + " — 두 기간 unique·미포착에서 사전 규칙을 통과. 이미 쓴 기간이므로 독립 검증이 아니다."
        else:
            verdict = "유리한 결과 없음. 세 후보 모두 두 기간 unique·미포착에서 사전 규칙을 동시에 통과하지 못했다."
    else:
        verdict = "한 기간만 계산되어 두 기간 동시 통과를 판단하지 않는다."
    lines += [
        "",
        f"**판단:** {verdict}",
        "",
        "고가 도달률은 거래 수익이 아니다. 반복 TOP100 관찰은 위 unique 표에 넣지 않았다. 전체 관찰(반복 포함)은 summary.json의 `all_scored`에 있다.",
        "",
        "## 실패 사례 (unique·미포착, 특징 참, 고가 미도달 또는 거래 손실, 최악 5건)",
        "",
    ]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        lines.append(f"### {p['label']}")
        lines.append("")
        for name in FEATURES:
            lines.append(f"| {name} | 날짜 | 종목 | 고가도달 | 거래순익% | 청산 | 보유봉 |")
            lines.append("|---|---|---|---|---:|---|---:|")
            rows = p["failures_unique_missed"].get(name) or []
            if not rows:
                lines.append("| (없음) | | | | | | |")
            for r in rows:
                lines.append(
                    f"| | {r['date']} | {r['symbol']} | {r['high_touch_15']} | {r['tradable_net_pct']:.4f} | {r['exit_reason']} | {r['bars_held']} |"
                )
            lines.append("")
            similar = p["unique_missed"][name]
            lines.append(
                f"비슷했지만 고가 미도달 {similar['similar_no_high_touch']}건, 거래 손실 {similar['similar_tradable_loss']}건 (unique·미포착·특징 참)."
            )
            lines.append("")
    lines += [
        "## 다음 판단",
        "",
        "| 항목 | 내용 |",
        "|---|---|",
        f"| 운영 반영 | 하지 않음. {verdict} |",
        "| 이전 기간 추천 커버 | 234/243일만 알 수 있음. 나머지 날은 미포착 집계에서 제외 |",
        "| PR #100 진단 출력 | 로컬에 없음. 이번 실험이 그 숫자를 대체하지 않음 |",
        "| 60봉 모양 2,450건 | 파일 없음 |",
        "| 다음 실험 | 조건을 더 붙이지 않는다. 유리함이 없으면 후보를 운영에 넣지 않는다 |",
        "",
        "연구 출력 JSON은 Git 밖 `history-research/top100-pre-rise-v1/summary.json`이다.",
        "",
    ]
    return "\n".join(lines) + "\n"


def main(argv=None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--period", choices=["prior", "recent", "both"], default="both")
    ap.add_argument("--output", required=True)
    ap.add_argument("--report")
    args = ap.parse_args(argv)
    out = Path(args.output)
    out.mkdir(parents=True, exist_ok=True)
    names = ["prior", "recent"] if args.period == "both" else [args.period]
    report = {"version": VERSION, "price_basis": PRICE_BASIS, "periods": {}}
    for name in names:
        report["periods"][name] = run_period(name)
    (out / "summary.json").write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    md = render_report(report)
    (out / "report.md").write_text(md, encoding="utf-8")
    if args.report:
        Path(args.report).write_text(md, encoding="utf-8")
    compact = {
        n: {
            "label": report["periods"][n]["label"],
            "top100_days": report["periods"][n]["top100_days"],
            "signal_days_covered": report["periods"][n]["signal_days_covered"],
            "stored_signals": report["periods"][n]["stored_signals"],
            "scored": report["periods"][n]["scored"],
            "unique_missed_scored": report["periods"][n]["unique_missed_scored"],
            "skips": report["periods"][n]["skips"],
            "lock_edges_unique_missed": report["periods"][n]["lock_edges_unique_missed"],
            "unique_missed": report["periods"][n]["unique_missed"],
        }
        for n in names
    }
    print(json.dumps(compact, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
