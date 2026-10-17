"""Episode-unit TOP100 follow-up. Does not change v1 conditions, stops, or targets."""
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
MIN_BARS = 60
NEAR = 0.97
STOP = 0.95
TARGET = 1.15
LARGE_LOSS = -8.0
GAP_ABS = 0.15
VERSION = "top100_pre_rise_v2"
PRICE_BASIS = "yahoo_chart_quote_ohlcv_daily_adjustment_unverified"
FEATURES = ("close_near_20high", "higher_10bar_low", "ma20_above_ma60")
KNOTS = (59, 40, 20, 10, 5, 1, 0)

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
    if any(not math.isfinite(x) for x in (o, h, l, c, v)):
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


def load_symbol(path: Path) -> tuple[dict[str, dict], dict]:
    if not path.is_file():
        return {}, {}
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
    bars = {d: b for d, b in by.items() if b}
    return bars, raw.get("quality") or {}


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
    }


def simulate(window: list[dict], entry: float) -> dict:
    buy = entry * BUY
    stop_px = entry * STOP
    target_px = entry * TARGET
    hit15 = False
    reason = "time"
    exit_px = window[-1]["close"] * SELL
    bars_held = len(window)
    for i, b in enumerate(window, 1):
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
        "tradable_net_pct": net * 100,
        "tradable_win": net > 0,
        "exit_reason": reason,
        "bars_held": bars_held,
        "stop_then_target_same_bar": reason == "stop" and hit15,
    }


def unconstrained(window: list[dict], entry: float) -> dict:
    hit_i = None
    worst = 0.0
    for i, b in enumerate(window, 1):
        worst = min(worst, b["low"] / entry - 1)
        if b["high"] >= entry * TARGET:
            hit_i = i
            break
    return {
        "hit15": hit_i is not None,
        "bars_to_15": hit_i,
        "max_dd_before_15_pct": worst * 100,
    }


def shape60(past: list[dict]) -> dict | None:
    if len(past) < MIN_BARS:
        return None
    win = past[-MIN_BARS:]
    c_t = win[-1]["close"]
    if c_t <= 0:
        return None
    path = {}
    for k in KNOTS:
        path[f"-{k}"] = win[-(k + 1)]["close"] / c_t
    runmax = win[0]["high"]
    depth = 0.0
    for b in win:
        runmax = max(runmax, b["high"])
        if runmax > 0:
            depth = max(depth, (runmax - b["low"]) / runmax)
    vol10 = sum(b["volume"] for b in win[-10:]) / 10
    vol50 = sum(b["volume"] for b in win[:-10]) / 50
    return {
        "path": path,
        "pullback_depth": depth,
        "vol_ratio_10_50": None if vol50 <= 0 else vol10 / vol50,
        "close_change_60": win[-1]["close"] / win[0]["close"] - 1,
    }


def load_signals_jsonl(path: Path) -> tuple[set[tuple[str, str]], set[str]]:
    caught, days = set(), set()
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
    caught, days = set(), set()
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
    members = []
    for d in days:
        members.append({row["symbol"] for row in cand["dates"][d]["rows"]})
    return {
        "name": name,
        "label": spec["label"],
        "days": days,
        "members": members,
        "dataset": Path(spec["dataset"]),
        "caught": caught,
        "covered": covered,
        "candidates_sha256": sha256(spec["candidates"]),
        "signals_sha256": sha256(spec["signals"]),
        "dataset_name": Path(spec["dataset"]).name,
    }


def _past(bars: dict[str, dict], day: str) -> list[dict]:
    return [bars[d] for d in sorted(d for d in bars if d <= day)]


def gap_evidence(window: list[dict], prev_close: float | None, quality: dict, start: str, end: str) -> dict:
    gaps = []
    prev = prev_close
    for b in window:
        if prev and prev > 0:
            g = b["open"] / prev - 1
            if abs(g) >= GAP_ABS:
                gaps.append(round(g * 100, 4))
        prev = b["close"]
    jumps = []
    for j in quality.get("price_jumps") or []:
        d = str(j.get("date") or "")
        if start <= d <= end:
            jumps.append(j)
    corrs = []
    for c in (quality.get("corrections") or {}).get("items") or []:
        d = str(c.get("date") or "")
        if start <= d <= end:
            corrs.append(c)
    if gaps:
        kind = "open_gap_vs_prior_close"
    elif jumps:
        kind = "stored_price_jump"
    elif corrs:
        kind = "stored_correction"
    else:
        kind = "unknown"
    return {
        "kind": kind,
        "open_gaps_pct": gaps[:5],
        "stored_price_jumps": jumps[:3],
        "stored_corrections": corrs[:3],
    }


def build_episodes(period: dict) -> list[dict]:
    days = period["days"]
    members = period["members"]
    first_idx: dict[str, list[int]] = defaultdict(list)
    for i, syms in enumerate(members):
        for sym in sorted(syms):
            first_idx[sym].append(i)
    cache: dict[str, tuple[dict, dict]] = {}
    episodes = []
    for sym, idxs in first_idx.items():
        used_until = -1
        if sym not in cache:
            cache[sym] = load_symbol(period["dataset"] / "bars" / f"{sym}.json")
        bars, quality = cache[sym]
        for i in idxs:
            if i <= used_until:
                continue
            if i + HOLD >= len(days):
                episodes.append(
                    {
                        "symbol": sym,
                        "anchor": days[i],
                        "skip": "incomplete_future_window",
                        "first_entry_unverified": i < HOLD,
                    }
                )
                used_until = len(days)
                continue
            used_until = i + HOLD
            anchor = days[i]
            follow = days[i + 1 : i + 1 + HOLD]
            span = days[i : i + 1 + HOLD]
            rec_days = [d for d in span if d in period["covered"]]
            caught = any((d, sym) in period["caught"] for d in rec_days)
            in_top = sum(sym in members[i + k] for k in range(HOLD + 1))
            row = {
                "symbol": sym,
                "anchor": anchor,
                "end": days[i + HOLD],
                "first_entry_unverified": i < HOLD,
                "days_in_top100": in_top,
                "left_top100": in_top < HOLD + 1,
                "rec_known": bool(rec_days),
                "caught_in_window": caught,
                "skip": None,
                "immediate": None,
                "unconstrained": None,
                "shape": None,
                "features": {},
                "large_loss": None,
            }
            if anchor not in bars:
                row["skip"] = "missing_anchor_bar"
                episodes.append(row)
                continue
            window = [bars.get(d) for d in follow]
            if any(b is None for b in window):
                row["skip"] = "missing_outcome_bar"
                episodes.append(row)
                continue
            entry = window[0]["open"]
            imm = simulate(window, entry)
            unc = unconstrained(window, entry)
            row["immediate"] = imm
            row["unconstrained"] = unc
            past_t = _past(bars, anchor)
            row["shape"] = shape60(past_t)
            feat0 = features(past_t)
            for name in FEATURES:
                timing = "insufficient_history"
                first_s = None
                trade = None
                if feat0 is not None:
                    if feat0[name]:
                        timing = "first_day"
                        first_s = 0
                    else:
                        timing = "never"
                        for k in range(1, HOLD):
                            day_s = days[i + k]
                            if day_s not in bars:
                                continue
                            feat_s = features(_past(bars, day_s))
                            if feat_s and feat_s[name]:
                                timing = "later"
                                first_s = k
                                break
                    if first_s is not None:
                        buy_i = first_s + 1
                        if buy_i <= HOLD:
                            sub = window[buy_i - 1 :]
                            trade = simulate(sub, sub[0]["open"])
                            trade = dict(trade)
                            trade["entry_offset"] = buy_i
                row["features"][name] = {"timing": timing, "trade": trade}
            if imm["tradable_net_pct"] < LARGE_LOSS:
                row["large_loss"] = {
                    "tradable_net_pct": round(imm["tradable_net_pct"], 4),
                    "evidence": gap_evidence(window, bars[anchor]["close"], quality, follow[0], follow[-1]),
                }
            episodes.append(row)
    return episodes


def _mean(xs: list[float]) -> float | None:
    return None if not xs else round(sum(xs) / len(xs), 4)


def _median(xs: list[float]) -> float | None:
    if not xs:
        return None
    s = sorted(xs)
    n = len(s)
    mid = n // 2
    if n % 2:
        return round(s[mid], 4)
    return round((s[mid - 1] + s[mid]) / 2, 4)


def trade_stats(trades: list[dict]) -> dict:
    if not trades:
        return {"n": 0}
    nets = [t["tradable_net_pct"] for t in trades]
    losses = [x for x in nets if x < 0]
    return {
        "n": len(trades),
        "high_touch_15": sum(bool(t["high_touch_15"]) for t in trades),
        "high_touch_15_pct": round(sum(bool(t["high_touch_15"]) for t in trades) / len(trades) * 100, 2),
        "tradable_wins": sum(bool(t["tradable_win"]) for t in trades),
        "tradable_win_pct": round(sum(bool(t["tradable_win"]) for t in trades) / len(trades) * 100, 2),
        "mean_tradable_net_pct": _mean(nets),
        "mean_loss_net_pct": _mean(losses),
        "loss_n": len(losses),
        "mean_bars_held": round(sum(t["bars_held"] for t in trades) / len(trades), 2),
        "stop_n": sum(t["exit_reason"] == "stop" for t in trades),
        "target_n": sum(t["exit_reason"] == "target" for t in trades),
        "stop_then_high_touch_n": sum(bool(t.get("stop_then_target_same_bar")) for t in trades),
    }


def shape_stats(rows: list[dict]) -> dict:
    shapes = [r["shape"] for r in rows if r.get("shape")]
    if not shapes:
        return {"n": 0}
    path = {}
    for k in KNOTS:
        key = f"-{k}"
        path[key] = _mean([s["path"][key] for s in shapes])
    depths = [s["pullback_depth"] for s in shapes]
    vols = [s["vol_ratio_10_50"] for s in shapes if s["vol_ratio_10_50"] is not None]
    chg = [s["close_change_60"] for s in shapes]
    return {
        "n": len(shapes),
        "mean_norm_close": path,
        "median_pullback_depth": _median(depths),
        "median_vol_ratio_10_50": _median(vols),
        "median_close_change_60": _median(chg),
    }


def unc_stats(rows: list[dict]) -> dict:
    u = [r["unconstrained"] for r in rows if r.get("unconstrained")]
    if not u:
        return {"n": 0}
    hits = [x for x in u if x["hit15"]]
    return {
        "n": len(u),
        "hit15_n": len(hits),
        "hit15_pct": round(len(hits) / len(u) * 100, 2),
        "median_bars_to_15": _median([x["bars_to_15"] for x in hits]),
        "median_max_dd_before_15_pct": _median([x["max_dd_before_15_pct"] for x in hits]),
        "dd_gt_5pct_then_hit15": sum(x["max_dd_before_15_pct"] < -5 for x in hits),
    }


def summarize(period: dict, episodes: list[dict]) -> dict:
    scored = [e for e in episodes if e.get("immediate")]
    verified = [e for e in scored if not e["first_entry_unverified"]]
    unverified = [e for e in scored if e["first_entry_unverified"]]
    rise = [e for e in scored if e["immediate"]["high_touch_15"]]
    flat = [e for e in scored if not e["immediate"]["high_touch_15"]]
    feat_block = {}
    for name in FEATURES:
        by = defaultdict(list)
        trades = []
        for e in scored:
            info = (e.get("features") or {}).get(name) or {}
            by[info.get("timing", "insufficient_history")].append(e)
            if info.get("trade"):
                trades.append(info["trade"])
        feat_block[name] = {
            "timing_n": {k: len(v) for k, v in sorted(by.items())},
            "first_day_immediate": trade_stats([e["immediate"] for e in by.get("first_day", [])]),
            "later_trade": trade_stats([((e.get("features") or {}).get(name) or {}).get("trade") for e in by.get("later", []) if ((e.get("features") or {}).get(name) or {}).get("trade")]),
            "never_immediate": trade_stats([e["immediate"] for e in by.get("never", [])]),
            "all_feature_trades": trade_stats(trades),
        }
    losses = [e for e in scored if e.get("large_loss")]
    skip = defaultdict(int)
    for e in episodes:
        if e.get("skip"):
            skip[e["skip"]] += 1
    return {
        "version": VERSION,
        "period": period["name"],
        "label": period["label"],
        "price_basis": PRICE_BASIS,
        "dataset": period["dataset_name"],
        "candidates_sha256": period["candidates_sha256"],
        "signals_sha256": period["signals_sha256"],
        "top100_days": len(period["days"]),
        "episodes": len(episodes),
        "scored": len(scored),
        "verified_first_entry": len(verified),
        "unverified_first_entry": len(unverified),
        "left_top100_scored": sum(1 for e in scored if e.get("left_top100")),
        "caught_in_window": sum(1 for e in scored if e.get("caught_in_window")),
        "skips": dict(skip),
        "immediate": trade_stats([e["immediate"] for e in scored]),
        "immediate_verified": trade_stats([e["immediate"] for e in verified]),
        "unconstrained": unc_stats(scored),
        "stop_model_target_n": sum(e["immediate"]["exit_reason"] == "target" for e in scored),
        "unconstrained_hit15_n": sum(e["unconstrained"]["hit15"] for e in scored),
        "stopped_but_unconstrained_hit15": sum(
            e["immediate"]["exit_reason"] == "stop" and e["unconstrained"]["hit15"] for e in scored
        ),
        "features": feat_block,
        "shape_rise": shape_stats(rise),
        "shape_nonrise": shape_stats(flat),
        "large_losses": {
            "n": len(losses),
            "by_evidence": _count(e["large_loss"]["evidence"]["kind"] for e in losses),
            "examples": [
                {
                    "date": e["anchor"],
                    "symbol": e["symbol"],
                    "tradable_net_pct": e["large_loss"]["tradable_net_pct"],
                    "evidence": e["large_loss"]["evidence"]["kind"],
                    "open_gaps_pct": e["large_loss"]["evidence"]["open_gaps_pct"],
                    "first_entry_unverified": e["first_entry_unverified"],
                }
                for e in sorted(losses, key=lambda x: x["large_loss"]["tradable_net_pct"])[:8]
            ],
        },
        "limitations": [
            "Already-used periods; not independent validation",
            "Does not change v1 conditions, 5% stop, or 15% target",
            "High-touch is not realized account PnL",
            "First entry before period start is unverified when fewer than 20 in-period TOP100 lists exist",
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
    return summarize(period, build_episodes(period))


def _fmt(s: dict) -> str:
    if not s or s.get("n", 0) == 0:
        return "n=0"
    loss = "n/a" if s.get("mean_loss_net_pct") is None else f"{s['mean_loss_net_pct']:.4f}"
    mean = "n/a" if s.get("mean_tradable_net_pct") is None else f"{s['mean_tradable_net_pct']:.4f}"
    return (
        f"{s['n']} / {s.get('high_touch_15_pct', 0):.2f}% / {s.get('tradable_win_pct', 0):.2f}% / "
        f"{mean}% / {loss}% / {s.get('mean_bars_held', 0):.2f}"
    )


def render_report(report: dict) -> str:
    lines = [
        "# TOP100 최초 진입 구간 관찰 v2",
        "",
        "PR #103(v1) 일별 표본 결과는 유지한다. 조건·손절 5%·목표 15%를 최적화하지 않았다. 두 기간은 이미 썼으므로 독립 검증이 아니다. 운영·원본·계좌는 바꾸지 않았다.",
        "",
        "관찰 단위는 종목별 최초 TOP100 등장 T부터 이후 20세션이다. 겹치는 구간은 세지 않는다. TOP100 이탈 후에도 같은 20세션은 관찰한다. 기간 초 20세션 안에 시작한 구간은 실제 최초 진입 미확정이다.",
        "",
        "즉시 매수는 T 다음 시가(T 당일 TOP100은 장 후에 확정). 특징 대기는 v1과 같은 세 특징이 구간 안 완료봉에서 처음 참인 날의 다음 시가. 보유는 구간 끝까지만.",
        "",
        "## 기간별 표본",
        "",
        "| 기간 | 거래일 | 구간 | 결과 있음 | 최초진입 확인 | 최초진입 미확정 | TOP100 이탈 후도 관찰 | 구간 안 저장신호 | 기간말 부족 | 앵커봉없음 | 결과봉없음 |",
        "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    periods = report["periods"]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        sk = p.get("skips") or {}
        lines.append(
            f"| {p['label']} | {p['top100_days']} | {p['episodes']} | {p['scored']} | {p['verified_first_entry']} | "
            f"{p['unverified_first_entry']} | {p['left_top100_scored']} | {p['caught_in_window']} | "
            f"{sk.get('incomplete_future_window', 0)} | {sk.get('missing_anchor_bar', 0)} | {sk.get('missing_outcome_bar', 0)} |"
        )
    lines += [
        "",
        "숫자: n / 고가도달% / 거래승률% / 비용반영 평균% / 손실평균% / 평균보유봉.",
        "",
        "## 즉시 매수 vs 특징 대기",
        "",
        "| 기간 | 경로 | 숫자 |",
        "|---|---|---|",
    ]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        lines.append(f"| {p['label']} | 즉시(전체) | {_fmt(p['immediate'])} |")
        lines.append(f"| {p['label']} | 즉시(최초진입 확인만) | {_fmt(p['immediate_verified'])} |")
        for name in FEATURES:
            f = p["features"][name]
            lines.append(f"| {p['label']} | {name} 첫날충족·즉시와 동일시점 | {_fmt(f['first_day_immediate'])} |")
            lines.append(f"| {p['label']} | {name} 나중충족·다음시가 | {_fmt(f['later_trade'])} |")
            lines.append(f"| {p['label']} | {name} 미충족·즉시만 | {_fmt(f['never_immediate'])} |")
    lines += [
        "",
        "### 충족 시점 건수",
        "",
        "| 기간 | 특징 | first_day | later | never | 이력부족 |",
        "|---|---|---:|---:|---:|---:|",
    ]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        for name in FEATURES:
            t = p["features"][name]["timing_n"]
            lines.append(
                f"| {p['label']} | {name} | {t.get('first_day', 0)} | {t.get('later', 0)} | {t.get('never', 0)} | {t.get('insufficient_history', 0)} |"
            )
    lines += [
        "",
        "## 고정 5% 손절과 손절 없는 +15% 경로 (손절을 넓히지 않음)",
        "",
        "| 기간 | 손절모형 목표청산 | 손절 없이 +15% 고가 | 손절모형은 손절·손절 없이 +15% | 손절 없이 +15%인 경우 도달봉 중앙값 | 그때까지 최대하락% 중앙값 | 5%보다 깊게 빠진 뒤 +15% |",
        "|---|---:|---:|---:|---:|---:|---:|",
    ]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        u = p["unconstrained"]
        lines.append(
            f"| {p['label']} | {p['stop_model_target_n']} | {p['unconstrained_hit15_n']} | "
            f"{p['stopped_but_unconstrained_hit15']} | {u.get('median_bars_to_15')} | "
            f"{u.get('median_max_dd_before_15_pct')} | {u.get('dd_gt_5pct_then_hit15')} |"
        )
    lines += [
        "",
        "## 상승 vs 비상승, T까지 60봉 (즉시 경로 고가도달 라벨, 진입 신호 아님)",
        "",
        "| 기간 | 구분 | n | 정규화종가 -59/-20/-5/0 | 조정깊이 중앙값 | 거래량비 10/50 중앙값 | 60봉 종가변화 중앙값 |",
        "|---|---|---:|---|---:|---:|---:|",
    ]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        for lab, block in (("상승", p["shape_rise"]), ("비상승", p["shape_nonrise"])):
            path = block.get("mean_norm_close") or {}
            seq = "/".join(
                "n/a" if path.get(k) is None else f"{path[k]:.3f}"
                for k in ("-59", "-20", "-5", "-0")
            )
            lines.append(
                f"| {p['label']} | {lab} | {block.get('n', 0)} | {seq} | {block.get('median_pullback_depth')} | "
                f"{block.get('median_vol_ratio_10_50')} | {block.get('median_close_change_60')} |"
            )
    lines += [
        "",
        "## 큰 손실과 가격 단절 (삭제·보정 없음)",
        "",
        "즉시 경로 거래 순익 < −8%. 저장 `price_jumps`/`corrections`와 전일 종가 대비 시가 공백만 본다.",
        "",
        "| 기간 | n | 근거 | 최악 사례 |",
        "|---|---:|---|---|",
    ]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        ll = p["large_losses"]
        ex = ll.get("examples") or []
        worst = ", ".join(f"{e['symbol']}@{e['date']} {e['tradable_net_pct']}% ({e['evidence']})" for e in ex[:3]) or "—"
        ev = ll.get("by_evidence") or {}
        ev_s = ", ".join(f"{k} {v}" for k, v in ev.items()) or "—"
        lines.append(f"| {p['label']} | {ll['n']} | {ev_s} | {worst} |")
    both_imm = []
    for key in ("prior", "recent"):
        if key in periods:
            both_imm.append(periods[key]["immediate"].get("mean_tradable_net_pct"))
    feat_means = []
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        for name in FEATURES:
            feat_means.append(periods[key]["features"][name]["all_feature_trades"].get("mean_tradable_net_pct"))
    positive = [x for x in both_imm + feat_means if x is not None and x > 0]
    if len(both_imm) == 2 and all(x is not None and x > 0 for x in both_imm):
        verdict = "즉시 매수 평균이 두 기간 모두 양수다. 손절·목표는 바꾸지 않았고 독립 검증이 아니다."
    elif positive:
        verdict = "일부 경로는 한 기간에서 평균이 양수일 수 있으나, 두 기간 즉시 매수가 동시에 유리하다고 보지 않는다. 조건을 더 붙이거나 손절을 넓히지 않는다."
    else:
        verdict = "유리한 결과 없음. 즉시·특징 대기 모두 결정에 쓸 만한 양수 평균이 두 기간에서 동시에 나오지 않았다. 손절을 넓히지 않는다."
    if len(both_imm) == 2 and not all(x is not None and x > 0 for x in both_imm):
        if not any(
            all(
                (periods[k]["features"][name]["all_feature_trades"].get("mean_tradable_net_pct") or -1) > 0
                for k in ("prior", "recent")
            )
            for name in FEATURES
            if set(periods) >= {"prior", "recent"}
        ):
            verdict = "유리한 결과 없음. 즉시 매수와 세 특징 대기 모두 두 기간에서 비용 반영 평균이 동시에 양수가 아니다. 손절을 넓히지 않는다."
    lines += [
        "",
        "## 다음 판단",
        "",
        f"**판단:** {verdict}",
        "",
        "| 항목 | 내용 |",
        "|---|---|",
        "| PR #103 | 유지. 일별 unique 미포착 결론을 이 숫자로 바꾸지 않음 |",
        "| 손절 | 5% 고정. 손절 없이 +15%가 더 많아 보여도 넓히지 않음 |",
        "| 가격 단절 | 큰 손실은 저장 근거만 표시. unknown은 미확정. 행 삭제 없음 |",
        "| 운영 | 반영하지 않음 |",
        "",
        "연구 JSON은 Git 밖 `history-research/top100-pre-rise-v2/summary.json`이다.",
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
    (out / "summary.json").write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    md = render_report(report)
    (out / "report.md").write_text(md, encoding="utf-8")
    if args.report:
        Path(args.report).write_text(md, encoding="utf-8")
    compact = {
        n: {
            k: report["periods"][n][k]
            for k in (
                "label",
                "episodes",
                "scored",
                "verified_first_entry",
                "unverified_first_entry",
                "skips",
                "immediate",
                "stop_model_target_n",
                "unconstrained_hit15_n",
                "stopped_but_unconstrained_hit15",
                "large_losses",
            )
        }
        | {"features_timing": {fn: report["periods"][n]["features"][fn]["timing_n"] for fn in FEATURES}}
        for n in names
    }
    print(json.dumps(compact, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
