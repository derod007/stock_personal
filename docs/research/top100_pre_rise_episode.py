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
VERSION = "top100_pre_rise_v2_rev1"
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
    same_bar = False
    reason = "time"
    exit_px = window[-1]["close"] * SELL
    bars_held = len(window)
    for i, b in enumerate(window, 1):
        if b["high"] >= entry * TARGET:
            hit15 = True
        stopped = b["low"] <= stop_px
        targeted = b["high"] >= target_px
        same_bar = stopped and targeted
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
        "same_bar_stop_and_target": same_bar,
        "intraday_order": "unknown" if same_bar else "not_same_bar",
        "stop_first_is_model_assumption": True,
    }


def unconstrained(window: list[dict], entry: float) -> dict:
    hit_i = None
    before_hit_day = None
    through_hit_day = None
    running = 0.0
    same_bar = False
    for i, b in enumerate(window, 1):
        running = min(running, b["low"] / entry - 1)
        if b["high"] >= entry * TARGET:
            hit_i = i
            through_hit_day = running
            same_bar = b["low"] <= entry * STOP
            break
        before_hit_day = running
    return {
        "hit15": hit_i is not None,
        "bars_to_15": hit_i,
        "max_dd_before_hit_day_pct": None if before_hit_day is None else before_hit_day * 100,
        "max_dd_through_hit_day_pct": None if through_hit_day is None else through_hit_day * 100,
        "max_dd_window_pct": running * 100,
        "same_bar_stop_and_target": bool(hit_i and same_bar),
        "intraday_order": "unknown" if (hit_i and same_bar) else "not_same_bar",
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
        seq = 0
        if sym not in cache:
            cache[sym] = load_symbol(period["dataset"] / "bars" / f"{sym}.json")
        bars, quality = cache[sym]
        for i in idxs:
            if i <= used_until:
                continue
            if i + HOLD >= len(days):
                seq += 1
                episodes.append(
                    {
                        "symbol": sym,
                        "anchor": days[i],
                        "skip": "incomplete_future_window",
                        "new_entry_status": "unknown",
                        "left_censored": i < HOLD,
                        "nonoverlap_seq": seq,
                    }
                )
                used_until = len(days)
                continue
            used_until = i + HOLD
            seq += 1
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
                "new_entry_status": "unknown",
                "left_censored": i < HOLD,
                "nonoverlap_seq": seq,
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
                            trade["entry_delay_bars"] = buy_i - 1
                            trade["remaining_bars"] = len(sub)
                            trade["hold_clipped_at_t20"] = True
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
        "same_bar_stop_and_target_n": sum(bool(t.get("same_bar_stop_and_target")) for t in trades),
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
    before = [x["max_dd_before_hit_day_pct"] for x in hits if x["max_dd_before_hit_day_pct"] is not None]
    through = [x["max_dd_through_hit_day_pct"] for x in hits if x["max_dd_through_hit_day_pct"] is not None]
    return {
        "n": len(u),
        "hit15_n": len(hits),
        "hit15_pct": round(len(hits) / len(u) * 100, 2),
        "median_bars_to_15": _median([x["bars_to_15"] for x in hits]),
        "median_max_dd_before_hit_day_pct": _median(before),
        "median_max_dd_through_hit_day_pct": _median(through),
        "hit_on_first_hold_bar_n": sum(x["bars_to_15"] == 1 for x in hits),
        "same_bar_stop_and_target_n": sum(bool(x["same_bar_stop_and_target"]) for x in hits),
        "dd_gt_5pct_before_hit_day_then_hit15": sum(
            x["max_dd_before_hit_day_pct"] is not None and x["max_dd_before_hit_day_pct"] < -5 for x in hits
        ),
    }


def pair_later(episodes: list[dict], name: str) -> dict:
    rows = []
    for e in episodes:
        info = (e.get("features") or {}).get(name) or {}
        trade = info.get("trade")
        if info.get("timing") != "later" or not trade or not e.get("immediate"):
            continue
        rows.append(
            {
                "immediate_net": e["immediate"]["tradable_net_pct"],
                "wait_net": trade["tradable_net_pct"],
                "delta": trade["tradable_net_pct"] - e["immediate"]["tradable_net_pct"],
                "delay": trade["entry_delay_bars"],
                "remaining": trade["remaining_bars"],
            }
        )
    if not rows:
        return {"n": 0}
    return {
        "n": len(rows),
        "mean_immediate_net_pct": _mean([r["immediate_net"] for r in rows]),
        "mean_wait_net_pct": _mean([r["wait_net"] for r in rows]),
        "mean_wait_minus_immediate_pct": _mean([r["delta"] for r in rows]),
        "mean_entry_delay_bars": _mean([r["delay"] for r in rows]),
        "mean_remaining_bars": _mean([r["remaining"] for r in rows]),
    }


def summarize(period: dict, episodes: list[dict]) -> dict:
    scored = [e for e in episodes if e.get("immediate")]
    left_censored = [e for e in scored if e.get("left_censored")]
    later_windows = [e for e in scored if (e.get("nonoverlap_seq") or 1) > 1]
    rise = [e for e in scored if e.get("unconstrained") and e["unconstrained"]["hit15"]]
    flat = [e for e in scored if e.get("unconstrained") and not e["unconstrained"]["hit15"]]
    old_rise = [e for e in scored if e["immediate"].get("high_touch_15")]
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
            "later_paired": pair_later(scored, name),
            "later_trade": trade_stats(
                [
                    ((e.get("features") or {}).get(name) or {}).get("trade")
                    for e in by.get("later", [])
                    if ((e.get("features") or {}).get(name) or {}).get("trade")
                ]
            ),
            "never_n": len(by.get("never", [])),
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
        "window_name": "nonoverlapping_top100_observation_window",
        "new_entry_status": "unknown",
        "left_censored_scored": len(left_censored),
        "subsequent_nonoverlap_scored": len(later_windows),
        "left_top100_scored": sum(1 for e in scored if e.get("left_top100")),
        "caught_in_window": sum(1 for e in scored if e.get("caught_in_window")),
        "skips": dict(skip),
        "immediate": trade_stats([e["immediate"] for e in scored]),
        "immediate_left_censored": trade_stats([e["immediate"] for e in left_censored]),
        "immediate_subsequent_nonoverlap": trade_stats([e["immediate"] for e in later_windows]),
        "unconstrained": unc_stats(scored),
        "stop_model_target_n": sum(e["immediate"]["exit_reason"] == "target" for e in scored),
        "unconstrained_hit15_n": sum(e["unconstrained"]["hit15"] for e in scored),
        "stopped_then_later_hit15": sum(
            e["immediate"]["exit_reason"] == "stop"
            and e["unconstrained"]["hit15"]
            and not e["immediate"].get("same_bar_stop_and_target")
            for e in scored
        ),
        "same_bar_stop_and_target_n": sum(bool(e["immediate"].get("same_bar_stop_and_target")) for e in scored),
        "chart_rise_n": len(rise),
        "chart_rise_n_old_stop_path_label": len(old_rise),
        "shape_rise_n_old_stop_path_label": sum(1 for e in scored if e.get("shape") and e["immediate"].get("high_touch_15")),
        "shape_nonrise_n_old_stop_path_label": sum(1 for e in scored if e.get("shape") and not e["immediate"].get("high_touch_15")),
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
                    "left_censored": e.get("left_censored"),
                    "nonoverlap_seq": e.get("nonoverlap_seq"),
                    "new_entry_status": "unknown",
                }
                for e in sorted(losses, key=lambda x: x["large_loss"]["tradable_net_pct"])[:8]
            ],
        },
        "limitations": [
            "Already-used periods; not independent validation",
            "Does not change v1 conditions, 5% stop, or 15% target",
            "Chart-rise label is unconstrained.hit15 over the full hold window, not the stop-model path",
            "Windows are non-overlapping TOP100 observation windows; in-period index>=20 is not a confirmed first entry",
            "Wait entries hold only through T+20; never-true is not an entry filter",
            "Same-bar stop and target: model assumes stop-first; actual intraday order is unknown",
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
        "# 비중첩 TOP100 관찰 구간 v2 (rev1)",
        "",
        "PR #103 결과는 유지한다. 조건·손절 5%·목표 15%를 최적화하지 않았다. PR #104 초안 숫자는 `report-before-rev1.md`에 보존한다. 두 기간은 이미 썼으므로 독립 검증이 아니다. 운영·원본·계좌는 바꾸지 않았다.",
        "",
        "관찰 단위는 **비중첩 TOP100 관찰 구간**이다. 종목별 기간 안 첫 등장 T부터 이후 20세션(T..T+20)이며, 겹치면 다음 구간을 시작하지 않는다. 이것이 실제 신규 상장·최초 편입을 확인한다는 뜻이 아니다. 신규 진입 여부는 기간 전 TOP100 리스트가 없어 **전부 unknown**이다. 기간 초 20세션 안 시작은 `left_censored`(왼쪽 잘림)일 뿐, 인덱스 ≥20을 최초 진입 확인으로 쓰지 않는다.",
        "",
        "즉시 매수는 T 다음 시가. 특징 대기는 처음 참인 날의 다음 시가이며 **보유는 T+20까지만**이다. 새 20봉을 연장하지 않는다. 차트 상승 라벨은 손절과 무관하게 관찰창 전체 `unconstrained.hit15`다. 거래 손익은 손절 모형으로 따로 둔다.",
        "",
        "같은 일봉에 손절가와 목표가가 모두 닿으면 거래 모형은 손절 우선이고, 실제 장중 순서는 **미확정**이다. 미충족은 구간이 끝난 뒤의 관찰이며 과거 진입 필터가 아니다.",
        "",
        "## 수정 전후 차이 (초안 대비)",
        "",
        "| 항목 | 초안 | rev1 |",
        "|---|---|---|",
        "| 차트 상승 라벨 | 손절 모형 경로의 high_touch_15 (손절 후 봉 제외) | 관찰창 전체 unconstrained.hit15 |",
        "| 인덱스 ≥20 | 최초 진입 확인으로 표시 | 비중첩 다음 구간. 신규 진입은 unknown |",
        "| 같은 봉 손절+목표 | 손절 우선만 기록 | 모형 가정과 장중 순서 미확정을 분리 |",
        "| 최대하락 | 도달일 저가 포함 한 값 | 도달 전일 / 도달일 포함을 분리 |",
        "| 미충족 | 즉시 매수 대조 경로로 제시 | 사후 건수만. 진입 필터 아님 |",
        "| 나중 충족 | 대기 거래만 집계 | 같은 구간의 즉시 vs 대기를 짝지어 차이·지연·잔여봉 보고 |",
        "",
        "## 기간별 표본",
        "",
        "| 기간 | 거래일 | 비중첩 구간 | 결과 있음 | 왼쪽 잘림 | 이후 비중첩 구간 | TOP100 이탈 후도 관찰 | 구간 안 저장신호 | 기간말 부족 | 앵커봉없음 | 결과봉없음 |",
        "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    periods = report["periods"]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        sk = p.get("skips") or {}
        lines.append(
            f"| {p['label']} | {p['top100_days']} | {p['episodes']} | {p['scored']} | {p['left_censored_scored']} | "
            f"{p['subsequent_nonoverlap_scored']} | {p['left_top100_scored']} | {p['caught_in_window']} | "
            f"{sk.get('incomplete_future_window', 0)} | {sk.get('missing_anchor_bar', 0)} | {sk.get('missing_outcome_bar', 0)} |"
        )
    lines += [
        "",
        "숫자: n / 손절모형 고가도달%(청산 전) / 거래승률% / 비용반영 평균% / 손실평균% / 평균보유봉.",
        "",
        "## 즉시 매수",
        "",
        "| 기간 | 경로 | 숫자 |",
        "|---|---|---|",
    ]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        lines.append(f"| {p['label']} | 즉시(전체 비중첩 구간) | {_fmt(p['immediate'])} |")
        lines.append(f"| {p['label']} | 즉시·왼쪽 잘림 | {_fmt(p['immediate_left_censored'])} |")
        lines.append(f"| {p['label']} | 즉시·이후 비중첩 구간 | {_fmt(p['immediate_subsequent_nonoverlap'])} |")
    lines += [
        "",
        "## 나중 충족: 같은 구간 즉시 vs 대기 (T+20에서 보유 종료)",
        "",
        "대기 진입은 남은 봉만 보유한다. 첫날 충족은 즉시와 같은 시점(T+1)이라 짝 비교하지 않는다. never는 진입 필터가 아니므로 여기에 넣지 않는다.",
        "",
        "| 기간 | 특징 | n | 즉시 평균% | 대기 평균% | 대기−즉시 | 평균 지연봉 | 평균 잔여보유봉 |",
        "|---|---|---:|---:|---:|---:|---:|---:|",
    ]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        for name in FEATURES:
            pr = p["features"][name]["later_paired"]
            if not pr or pr.get("n", 0) == 0:
                lines.append(f"| {p['label']} | {name} | 0 | | | | | |")
                continue
            lines.append(
                f"| {p['label']} | {name} | {pr['n']} | {pr['mean_immediate_net_pct']} | {pr['mean_wait_net_pct']} | "
                f"{pr['mean_wait_minus_immediate_pct']} | {pr['mean_entry_delay_bars']} | {pr['mean_remaining_bars']} |"
            )
    lines += [
        "",
        "### 충족 시점 건수 (사후 관찰, 진입 필터 아님)",
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
        "| 기간 | 손절모형 목표청산 | 같은 봉 손절+목표(순서 미확정) | 손절 없이 +15% | 손절 후 며칠 뒤 +15% | 도달봉 중앙값 | 도달 전일 최대하락% 중앙값 | 도달일 포함 최대하락% 중앙값 | 전일까지 5% 초과 하락 후 +15% |",
        "|---|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for key in ("prior", "recent"):
        if key not in periods:
            continue
        p = periods[key]
        u = p["unconstrained"]
        lines.append(
            f"| {p['label']} | {p['stop_model_target_n']} | {p['same_bar_stop_and_target_n']} | "
            f"{p['unconstrained_hit15_n']} | {p['stopped_then_later_hit15']} | {u.get('median_bars_to_15')} | "
            f"{u.get('median_max_dd_before_hit_day_pct')} | {u.get('median_max_dd_through_hit_day_pct')} | "
            f"{u.get('dd_gt_5pct_before_hit_day_then_hit15')} |"
        )
    lines += [
        "",
        "도달 전일 최대하락은 보유 2봉째 이후 +15%인 경우만이다. 1봉 만에 도달하면 전일 값이 없다. 같은 봉에 손절가와 목표가가 모두 있으면 거래 모형은 손절 우선이고 장중 순서는 미확정이다.",
        "",
        "## 상승 vs 비상승, T까지 60봉 (차트 라벨 = unconstrained.hit15, 진입 신호 아님)",
        "",
        "| 기간 | 구분 | n | 초안 n (손절경로 라벨) | 정규화종가 -59/-20/-5/0 | 조정깊이 중앙값 | 거래량비 10/50 중앙값 | 60봉 종가변화 중앙값 |",
        "|---|---|---:|---:|---|---:|---:|---:|",
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
            old = p.get("shape_rise_n_old_stop_path_label") if lab == "상승" else p.get("shape_nonrise_n_old_stop_path_label")
            lines.append(
                f"| {p['label']} | {lab} | {block.get('n', 0)} | {old} | {seq} | {block.get('median_pullback_depth')} | "
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
        "| 같은 봉 | 모형은 손절 우선. 실제 순서는 미확정 |",
        "| 대기 보유 | T+20에서 종료. 연장하지 않음 |",
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
                "left_censored_scored",
                "subsequent_nonoverlap_scored",
                "skips",
                "immediate",
                "stop_model_target_n",
                "unconstrained_hit15_n",
                "stopped_then_later_hit15",
                "same_bar_stop_and_target_n",
                "chart_rise_n",
                "chart_rise_n_old_stop_path_label",
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
