"""Independent fill-assumption audit of the two full-v2 research accounts.

Reads stored orders and the local dataset OHLCV. Does not call TradeSimulator.
Does not write operational files or overwrite full-v2 exports.
"""
from __future__ import annotations

import hashlib
import json
import math
from collections import Counter
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FULL = ROOT / "docs/historical-account-replay/full-v2"
CONC = ROOT / "docs/historical-account-replay/concentration"
OUT = ROOT / "docs/historical-account-replay/fill-audit"
CHARTS = OUT / "charts"
STATE = Path(r"C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research")
DATASETS = {
    "prior": STATE / "kr-saved-scan-20251008",
    "recent": STATE / "kr-saved-scan-20261009",
}
KST = timezone(timedelta(hours=9))
FEE = 0.001  # 10 bp, PaperPortfolio fill/exit cash
SLIP = 0.0005  # 5 bp, inside TradeSimulator prices
TTL = 3
HOLD = 20
NEAR = 1e-4
CODE_PATHS = [
    "src/TradeSimulator.php",
    "src/PaperPortfolio.php",
    "src/CandleClock.php",
    "bin/paper/AccountReplay.php",
    "config/paper-kr-recovery-v1.json",
]


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def near(a: float, b: float, tol: float = NEAR) -> bool:
    return abs(a - b) <= tol * max(1.0, abs(b))


def close_ts(day: str) -> int:
    y, m, d = map(int, day.split("-"))
    return int(datetime(y, m, d, 15, 30, tzinfo=KST).timestamp())


def day_of(row: dict) -> str:
    return row["time_kst"][:10]


def completed(rows: list[dict], symbol: str) -> list[dict]:
    out = []
    for r in rows:
        if r.get("synthetic") or r.get("is_complete") is False:
            continue
        try:
            o, h, l, c = float(r["open"]), float(r["high"]), float(r["low"]), float(r["close"])
        except (TypeError, ValueError, KeyError):
            continue
        if min(o, h, l, c) <= 0 or l > min(o, c) or h < max(o, c) or h < l:
            continue
        day = day_of(r)
        out.append({
            "date": day, "open": o, "high": h, "low": l, "close": c,
            "volume": r.get("volume"), "available_at": close_ts(day), "symbol": symbol,
        })
    out.sort(key=lambda b: b["available_at"])
    return out


def load_bars(dataset: Path, symbol: str) -> tuple[list[dict] | None, str]:
    path = dataset / "bars" / f"{symbol}.json"
    rel = str(path).replace("\\", "/")
    if not path.is_file():
        return None, rel
    doc = json.loads(path.read_text(encoding="utf-8"))
    return completed(doc.get("rows") or [], symbol), rel


def verdict(kind: str, detail: str, **extra) -> dict:
    row = {"verdict": kind, "detail": detail}
    row.update(extra)
    return row


def tape_side(price: float, bar: dict) -> str:
    if price < bar["low"] - 1e-9:
        return "below_low"
    if price > bar["high"] + 1e-9:
        return "above_high"
    return "inside"


def stored_status_of(indep: dict) -> str:
    return {
        "closed": "closed",
        "open": "open",
        "pending": "pending",
        "unfilled": "cancelled",
        "cancelled_before_entry": "cancelled",
    }[indep["status"]]


def model_match_check(order: dict, indep: dict | None) -> dict:
    """Stored fill/exit/cancel vs independent_path. This is what a price+pnl rewrite must fail."""
    if indep is None:
        return verdict("insufficient_data", "no independent path")
    diffs = []
    want_status = stored_status_of(indep)
    if order["status"] != want_status:
        diffs.append(f"status {order['status']} != {want_status}")
    fill, exit_, cancel = order.get("fill"), order.get("exit"), order.get("cancelled")
    if want_status in ("closed", "open"):
        if fill is None:
            diffs.append("missing stored fill")
        else:
            if fill.get("date") != indep.get("fill_date"):
                diffs.append(f"fill date {fill.get('date')} != {indep.get('fill_date')}")
            if indep.get("fill") is None or not near(float(fill["price"]), float(indep["fill"])):
                diffs.append(f"fill price {fill.get('price')} != {indep.get('fill')}")
    elif fill is not None:
        diffs.append("stored fill on a cancel/pending order")
    if want_status == "closed":
        if exit_ is None:
            diffs.append("missing stored exit")
        else:
            if exit_.get("date") != indep.get("exit_date"):
                diffs.append(f"exit date {exit_.get('date')} != {indep.get('exit_date')}")
            if indep.get("exit") is None or not near(float(exit_["price"]), float(indep["exit"])):
                diffs.append(f"exit price {exit_.get('price')} != {indep.get('exit')}")
            if exit_.get("reason") != indep.get("reason"):
                diffs.append(f"exit reason {exit_.get('reason')} != {indep.get('reason')}")
    elif exit_ is not None:
        diffs.append("stored exit on a non-closed order")
    if want_status == "cancelled":
        if cancel is None:
            diffs.append("missing stored cancel")
        else:
            if indep.get("cancel_date") and cancel.get("date") != indep.get("cancel_date"):
                diffs.append(f"cancel date {cancel.get('date')} != {indep.get('cancel_date')}")
            if cancel.get("reason") != indep.get("reason"):
                diffs.append(f"cancel reason {cancel.get('reason')} != {indep.get('reason')}")
    elif cancel is not None:
        diffs.append("stored cancel on an active order")
    if diffs:
        return verdict("error", "; ".join(diffs), code="independent_path vs trades.json", diffs=diffs)
    return verdict("pass", "stored date, price, reason and status match independent_path",
                   code="docs/research/account-replay-fill-audit.py independent_path")


def tape_range_check(label: str, stored: float | None, model: float | None, bar: dict | None, date: str | None) -> dict:
    if stored is None:
        return verdict("pass", f"no {label}")
    if bar is None:
        return verdict("insufficient_data", f"{label}-day bar missing", date=date)
    stored_side = tape_side(stored, bar)
    model_side = tape_side(model, bar) if model is not None else None
    rec = {
        "date": date, "session_role": f"{label}_session",
        "ohlc": [bar["open"], bar["high"], bar["low"], bar["close"]],
        "stored": stored, "stored_side": stored_side,
        "model_price": model, "model_side": model_side,
        "model_also_outside": model_side not in (None, "inside"),
    }
    if stored_side == "inside":
        extra = ""
        if rec["model_also_outside"]:
            extra = "; conservative model price is also outside the tape (stored print stayed inside)"
        return verdict("pass", f"{label} is inside the session OHLC" + extra, **rec)
    if model is not None and near(stored, model) and rec["model_also_outside"]:
        return verdict(
            "assumption_dependent",
            f"{label} matches the conservative model and that model is {model_side} the tape",
            code="src/TradeSimulator.php:60-84", **rec,
        )
    return verdict("error", f"{label} is {stored_side} the tape and is not the model price", **rec)


def independent_path(order: dict, after: list[dict]) -> dict:
    """Apply the documented fill rules to the bars after the signal close.

    Source of the rule, not a call to TradeSimulator:
    - TTL and pre-entry cancel: src/TradeSimulator.php ~47-58
    - buy limit fill: ~56-61
    - later-session target gap, same-bar stop-wins: ~71-81
    - time exit at bar 20: ~82-84
    - hold 20: src/PaperPortfolio.php ~71
    """
    entry, stop, target = float(order["entry"]), float(order["stop"]), float(order["target"])
    filled_i = None
    fill = None
    fill_bar = None
    notes = []
    for i, bar in enumerate(after):
        if filled_i is None:
            if i >= TTL:
                # TradeSimulator returns unfilled once count>=TTL, i.e. on the 3rd
                # post-signal session. PaperPortfolio emits that session as cancel_date.
                # Extra later bars must not shift the date (the 4th bar is not the cancel).
                return {"status": "unfilled", "reason": "unfilled", "cancel_date": after[TTL - 1]["date"],
                        "bars_seen": TTL, "notes": notes}
            pre_gap = bar["open"] <= stop or bar["open"] >= target or bar["high"] >= target
            if pre_gap:
                amb = bar["high"] >= target and bar["low"] <= entry
                if amb:
                    notes.append("pre-entry bar touches limit and target; daily OHLC cannot order the two")
                return {
                    "status": "cancelled_before_entry", "reason": "cancelled_before_entry",
                    "cancel_date": bar["date"], "ambiguous_bar": amb, "cancel_bar": bar, "notes": notes,
                }
            if bar["low"] > entry:
                continue
            filled_i = i
            fill = min(entry, bar["open"] * (1 + SLIP))
            fill_bar = bar
            if bar["open"] < entry:
                notes.append("gap-through limit: fill is min(limit, open*(1+5bp))")
        held = i - filled_i + 1
        stop_hit = bar["low"] <= stop
        target_hit = bar["high"] >= target
        why = exit_px = None
        amb = False
        if i > filled_i and bar["open"] >= target:
            why, exit_px = "target", target
            notes.append("later-session open already through target; target is taken at the limit")
        elif stop_hit:
            why, exit_px = "stop", min(stop, bar["open"]) * (1 - SLIP)
            amb = target_hit
            if amb:
                notes.append("same bar touches stop and target; rule gives stop the tie")
            if bar["open"] < stop:
                notes.append("gap through stop: exit is min(stop, open)*(1-5bp)")
        elif target_hit:
            why, exit_px = "target", target
        elif held >= HOLD:
            why, exit_px = "time", bar["close"] * (1 - SLIP)
        if why is None:
            continue
        return {
            "status": "closed", "reason": why, "fill": fill, "fill_date": fill_bar["date"], "fill_bar": fill_bar,
            "exit": exit_px, "exit_date": bar["date"], "exit_bar": bar, "ambiguous_bar": amb,
            "same_day_exit": fill_bar["date"] == bar["date"], "held": held, "notes": notes,
        }
    if filled_i is not None:
        return {
            "status": "open", "fill": fill, "fill_date": fill_bar["date"], "fill_bar": fill_bar,
            "held": len(after) - filled_i, "notes": notes,
        }
    if len(after) >= TTL:
        return {"status": "unfilled", "reason": "unfilled", "cancel_date": after[TTL - 1]["date"],
                "bars_seen": len(after), "notes": notes}
    return {"status": "pending", "bars_seen": len(after), "notes": notes}


def check_order(period: str, last_day: str, order: dict, bars: list[dict] | None, bars_path: str) -> dict:
    symbol = order["symbol"]
    signal = order["signal_date"]
    checks = {}
    if bars is None:
        missing = f"{DATASETS[period] / 'bars' / (symbol + '.json')}"
        for key in ("lookahead", "model_match", "entry_ohlc", "exit_ohlc", "chronology", "first_fillable",
                    "limit_price", "ttl", "gap_prices", "same_day_exit", "same_bar_priority", "fees", "leftover"):
            checks[key] = verdict("insufficient_data", "bars file missing", file=missing)
        return {
            "period": period, "symbol": symbol, "signal_date": signal, "status": order["status"],
            "pattern": order.get("pattern"), "checks": checks, "independent": None,
            "worst": "insufficient_data", "bars_path": bars_path, "cases": [],
        }

    by_day = {b["date"]: b for b in bars}
    signal_bar = by_day.get(signal)
    after = [b for b in bars if b["date"] > signal and b["date"] <= last_day]
    before = [b for b in bars if b["date"] <= signal]
    indep = independent_path(order, after) if signal_bar else None

    # 1. Date partition only. This is not a replay of ChartPlanEngine inputs.
    if signal_bar is None:
        checks["lookahead"] = verdict("insufficient_data", "no completed signal-day bar", file=bars_path)
    else:
        leak = [b["date"] for b in after if b["available_at"] <= signal_bar["available_at"]]
        checks["lookahead"] = verdict(
            "error" if leak else "pass",
            "date split only: bars with date<=signal vs date>signal. ChartPlanEngine was not run and its input array was not rebuilt",
            code="bin/paper/AccountReplay.php:214-222 filters available_at<=session; this check only compares dates",
            signal_bars=len(before), fill_window=len(after), leaked_dates=leak,
            scope="date_partition_only",
        )

    fill = order.get("fill")
    exit_ = order.get("exit")
    cancel = order.get("cancelled")
    status = order["status"]
    checks["model_match"] = model_match_check(order, indep)
    checks["entry_ohlc"] = tape_range_check("entry", float(fill["price"]) if fill else None, (indep or {}).get("fill"),
                                            by_day.get(fill["date"]) if fill else None, fill["date"] if fill else None)
    checks["exit_ohlc"] = tape_range_check("exit", float(exit_["price"]) if exit_ else None, (indep or {}).get("exit"),
                                           by_day.get(exit_["date"]) if exit_ else None, exit_["date"] if exit_ else None)

    # 2. Chronology
    dates = [signal]
    if fill:
        dates.append(fill["date"])
    if exit_:
        dates.append(exit_["date"])
    if cancel:
        dates.append(cancel["date"])
    chrono_ok = dates == sorted(dates) and (fill is None or fill["date"] > signal)
    if fill and fill["date"] <= signal:
        checks["chronology"] = verdict("error", "fill on or before the signal close", dates=dates)
    elif exit_ and fill and exit_["date"] < fill["date"]:
        checks["chronology"] = verdict("error", "exit before fill", dates=dates)
    elif not chrono_ok:
        checks["chronology"] = verdict("error", "date order is not signal < fill <= exit/cancel", dates=dates)
    else:
        checks["chronology"] = verdict("pass", "signal close, then later sessions for fill/exit/cancel",
                                       code="src/TradeSimulator.php:36; bin/paper/AccountReplay.php:322", dates=dates)

    # 3-6. First fillable, limit, TTL, cancel-after
    if indep is None:
        for k in ("first_fillable", "limit_price", "ttl"):
            checks[k] = verdict("insufficient_data", "no signal bar")
    else:
        exp_fill_date = indep.get("fill_date")
        got_fill_date = fill["date"] if fill else None
        if status in ("closed", "open"):
            if exp_fill_date != got_fill_date:
                checks["first_fillable"] = verdict("error", "stored fill date is not the first fillable session",
                                                   expected=exp_fill_date, stored=got_fill_date)
            else:
                checks["first_fillable"] = verdict("pass", "first post-signal bar that trades at or below the limit",
                                                   code="src/TradeSimulator.php:56-59", date=got_fill_date)
        elif status == "cancelled":
            if indep["status"] in ("unfilled", "cancelled_before_entry") and fill is None:
                checks["first_fillable"] = verdict("pass", f"no fill; independent status {indep['status']}",
                                                   code="src/TradeSimulator.php:48-54")
            elif indep.get("fill_date"):
                checks["first_fillable"] = verdict("error", "bars show a fillable session but the order was cancelled",
                                                   expected_fill=indep.get("fill_date"))
            else:
                checks["first_fillable"] = verdict("pass", "no fillable session inside the TTL")
        else:
            if indep["status"] == "pending" and fill is None:
                checks["first_fillable"] = verdict("pass", "still waiting inside the TTL at the last session")
            else:
                checks["first_fillable"] = verdict("error", "pending order does not match the remaining bars",
                                                   independent=indep["status"])

        if fill:
            if fill["price"] > float(order["entry"]) + 1e-9:
                checks["limit_price"] = verdict("error", "buy limit filled above the limit",
                                                limit=order["entry"], fill=fill["price"])
            elif indep.get("fill") is not None and not near(fill["price"], indep["fill"]):
                checks["limit_price"] = verdict("error", "stored fill is not min(limit, open*(1+5bp))",
                                                expected=indep["fill"], stored=fill["price"])
            else:
                checks["limit_price"] = verdict("pass", "fill is at or below the limit",
                                                code="src/TradeSimulator.php:60-61")
        else:
            checks["limit_price"] = verdict("pass", "no fill")

        fillable_before = [b for b in after[:TTL] if b["low"] <= float(order["entry"])
                           and not (b["open"] <= float(order["stop"]) or b["open"] >= float(order["target"]) or b["high"] >= float(order["target"]))]
        if fill and fill["date"] not in {b["date"] for b in after[:TTL]} and not fillable_before:
            checks["ttl"] = verdict("error", "fill outside the 3-session TTL", fill=fill["date"])
        elif cancel and cancel["reason"] == "unfilled" and fillable_before:
            checks["ttl"] = verdict("error", "marked unfilled but a TTL session was fillable",
                                    fillable=fillable_before[0]["date"])
        else:
            checks["ttl"] = verdict("pass", "TTL is 3 completed sessions after the signal",
                                    code="src/TradeSimulator.php:43-49; plan order_valid_bars default 3")

    # 8. Gap prices
    gap_notes = []
    gap_err = None
    if fill:
        fb = by_day.get(fill["date"])
        if fb and fb["open"] < float(order["entry"]) - 1e-9:
            expected = min(float(order["entry"]), fb["open"] * (1 + SLIP))
            gap_notes.append("gap_entry")
            if not near(fill["price"], expected):
                gap_err = f"gap entry stored {fill['price']} != {expected}"
        if exit_ and exit_["reason"] == "stop":
            eb = by_day.get(exit_["date"])
            if eb and eb["open"] < float(order["stop"]) - 1e-9:
                expected = min(float(order["stop"]), eb["open"]) * (1 - SLIP)
                gap_notes.append("gap_stop")
                if not near(exit_["price"], expected):
                    gap_err = f"gap stop stored {exit_['price']} != {expected}"
        if exit_ and exit_["reason"] == "target" and fill:
            eb = by_day.get(exit_["date"])
            if eb and exit_["date"] > fill["date"] and eb["open"] >= float(order["target"]) - 1e-9:
                gap_notes.append("gap_target")
                if not near(exit_["price"], float(order["target"])):
                    gap_err = f"gap target stored {exit_['price']} != limit {order['target']}"
    if gap_err:
        checks["gap_prices"] = verdict("error", gap_err, kinds=gap_notes,
                                       code="src/TradeSimulator.php:60-61,72-77")
    elif gap_notes:
        checks["gap_prices"] = verdict("pass", "gap uses the modeled 5bp slip on entry/stop and the target limit on a later open",
                                       kinds=gap_notes, code="src/TradeSimulator.php:60-77")
    else:
        checks["gap_prices"] = verdict("pass", "no opening gap through a level")

    # 9. Same-day exit
    if fill and exit_ and fill["date"] == exit_["date"]:
        fb = by_day.get(fill["date"])
        if fb is None:
            checks["same_day_exit"] = verdict("insufficient_data", "entry-day bar missing")
        else:
            # On the fill bar the later-session target-gap shortcut is off (i == filledAt).
            stop_hit = fb["low"] <= float(order["stop"])
            target_hit = fb["high"] >= float(order["target"])
            if exit_["reason"] == "target" and fb["open"] >= float(order["target"]):
                checks["same_day_exit"] = verdict(
                    "error",
                    "same-session open already through the target; the code cancels before entry instead of filling then exiting",
                    code="src/TradeSimulator.php:51-54 vs 72",
                )
            elif not stop_hit and not target_hit and exit_["reason"] != "time":
                checks["same_day_exit"] = verdict("error", "same-day exit without stop/target/time on that bar")
            else:
                checks["same_day_exit"] = verdict(
                    "assumption_dependent" if stop_hit and target_hit else "pass",
                    "entry-day exit is allowed; if both levels trade, stop wins and the path inside the bar is unknown",
                    code="src/TradeSimulator.php:66-81",
                )
    else:
        checks["same_day_exit"] = verdict("pass", "no same-session entry and exit")

    # 10. Same-bar stop/target
    if exit_:
        eb = by_day.get(exit_["date"])
        if eb is None:
            checks["same_bar_priority"] = verdict("insufficient_data", "exit-day bar missing")
        else:
            both = eb["low"] <= float(order["stop"]) and eb["high"] >= float(order["target"])
            later = fill and exit_["date"] > fill["date"]
            open_through = later and eb["open"] >= float(order["target"])
            if both and open_through:
                if exit_["reason"] == "target":
                    checks["same_bar_priority"] = verdict("pass", "later open already through target; target is taken first",
                                                          code="src/TradeSimulator.php:72-74")
                else:
                    checks["same_bar_priority"] = verdict("error", "later open was through target but exit is not target")
            elif both:
                if exit_["reason"] == "stop" and exit_.get("ambiguous_bar") is True:
                    checks["same_bar_priority"] = verdict(
                        "assumption_dependent",
                        "daily OHLC cannot say which level traded first; the book uses stop and marks ambiguous_bar",
                        code="src/TradeSimulator.php:75-78",
                    )
                elif exit_["reason"] == "stop":
                    checks["same_bar_priority"] = verdict(
                        "assumption_dependent",
                        "both levels traded; stop was used but ambiguous_bar was not stored",
                    )
                else:
                    checks["same_bar_priority"] = verdict("error", "both levels traded and the book did not give stop the tie")
            else:
                checks["same_bar_priority"] = verdict("pass", "only one of stop/target traded on the exit bar")
    else:
        checks["same_bar_priority"] = verdict("pass", "no exit")

    # 11. Fees: 10bp cash on each side. Slippage is inside the price, not a second fee.
    fee_ok = True
    fee_detail = []
    if fill:
        expect_fee = float(order["quantity"]) * float(fill["price"]) * FEE
        if not near(float(fill["fee"]), expect_fee, 1e-6):
            fee_ok = False
            fee_detail.append(f"entry fee {fill['fee']} != qty*price*0.001 {expect_fee}")
        else:
            fee_detail.append("entry cash debit is qty*fill*1.001; event fee is the 10bp slice")
    if fill and exit_:
        expect_net = float(order["quantity"]) * float(exit_["price"]) * (1 - FEE) - float(order["quantity"]) * float(fill["price"]) * (1 + FEE)
        if not near(float(exit_["net_pnl"]), expect_net, 1e-4):
            fee_ok = False
            fee_detail.append(f"net_pnl {exit_['net_pnl']} != qty*exit*0.999 - qty*fill*1.001")
        else:
            fee_detail.append("exit cash credit is qty*exit*0.999; slippage is not charged again")
    checks["fees"] = verdict(
        "error" if not fee_ok else "pass",
        "; ".join(fee_detail) or "no cash movement",
        code="src/PaperPortfolio.php:73-80; TradeSimulator.php:87 puts 10bp in the % return only",
    )

    # 12. Leftover
    if status == "open":
        if last_day < (fill or {}).get("date", last_day):
            checks["leftover"] = verdict("error", "open position has a fill after the last session")
        elif exit_ or cancel:
            checks["leftover"] = verdict("error", "open row also has an exit or cancel")
        else:
            checks["leftover"] = verdict("pass", "held through the last session; not force-closed",
                                         code="src/PaperPortfolio.php no end flatten; AccountReplay.php:470")
    elif status == "pending":
        if fill or exit_ or cancel:
            checks["leftover"] = verdict("error", "pending row has fill/exit/cancel")
        elif indep and indep["status"] != "pending":
            checks["leftover"] = verdict("error", f"bars resolve to {indep['status']} but the order is still pending")
        else:
            checks["leftover"] = verdict("pass", "TTL not exhausted at the last session")
    elif status == "cancelled":
        if fill or exit_:
            checks["leftover"] = verdict("error", "cancelled order also has a fill or exit")
        else:
            checks["leftover"] = verdict("pass", "cancel replaced the working order")
    else:
        checks["leftover"] = verdict("pass", "closed")

    worst = "pass"
    for v in checks.values():
        if v["verdict"] == "error":
            worst = "error"
            break
        if v["verdict"] == "insufficient_data" and worst != "error":
            worst = "insufficient_data"
        elif v["verdict"] == "assumption_dependent" and worst == "pass":
            worst = "assumption_dependent"

    cases = []
    if fill and by_day.get(fill["date"]) and by_day[fill["date"]]["open"] < float(order["entry"]) - 1e-9:
        cases.append("gap_entry")
    if exit_ and exit_["reason"] == "stop" and by_day.get(exit_["date"]) and by_day[exit_["date"]]["open"] < float(order["stop"]) - 1e-9:
        cases.append("gap_stop")
    if exit_ and fill and by_day.get(exit_["date"]):
        eb = by_day[exit_["date"]]
        if eb["low"] <= float(order["stop"]) and eb["high"] >= float(order["target"]):
            cases.append("same_bar_stop_target")
    if fill and exit_ and fill["date"] == exit_["date"]:
        cases.append("same_day_exit")
    if worst == "error":
        cases.append("audit_exception")
    if checks["entry_ohlc"].get("stored_side") == "above_high":
        cases.append("entry_above_high")
    if checks["exit_ohlc"].get("stored_side") == "below_low":
        cases.append("exit_below_low")
    if checks["entry_ohlc"].get("model_also_outside") or checks["exit_ohlc"].get("model_also_outside"):
        cases.append("model_outside_tape")

    return {
        "period": period, "symbol": symbol, "signal_date": signal, "status": status,
        "pattern": order.get("pattern"), "quantity": order.get("quantity"),
        "entry": order.get("entry"), "stop": order.get("stop"), "target": order.get("target"),
        "fill": fill, "exit": exit_, "cancelled": cancel,
        "independent": {k: indep[k] for k in indep if k not in ("fill_bar", "exit_bar", "cancel_bar")} if indep else None,
        "checks": checks, "worst": worst, "bars_path": bars_path, "cases": cases,
        "signal_bar": signal_bar, "fill_bar": by_day.get(fill["date"]) if fill else None,
        "exit_bar": by_day.get(exit_["date"]) if exit_ else None,
    }


def svg_chart(rows: list[dict], title: str, signal: str, fill_date: str | None, exit_date: str | None,
              entry: float, stop: float, target: float) -> str:
    if not rows:
        return "<svg xmlns='http://www.w3.org/2000/svg' width='640' height='80'><text x='8' y='40'>no bars</text></svg>"
    w, h, pad = 880, 260, 36
    lows = [b["low"] for b in rows] + [stop, entry]
    highs = [b["high"] for b in rows] + [target, entry]
    lo, hi = min(lows), max(highs)
    span = hi - lo or 1
    n = len(rows)
    cw = max(3, (w - 2 * pad) / n * 0.7)

    def xy(i: int, price: float) -> tuple[float, float]:
        x = pad + (i + 0.5) * (w - 2 * pad) / n
        y = pad + (hi - price) / span * (h - 2 * pad)
        return x, y

    parts = [f"<svg xmlns='http://www.w3.org/2000/svg' width='{w}' height='{h}' font-family='sans-serif' font-size='11'>"]
    parts.append(f"<rect width='{w}' height='{h}' fill='#fff'/>")
    # signal vs later regions
    sig_i = next((i for i, b in enumerate(rows) if b["date"] == signal), None)
    if sig_i is not None:
        x0 = pad
        x1 = pad + (sig_i + 1) * (w - 2 * pad) / n
        parts.append(f"<rect x='{x0}' y='{pad}' width='{x1 - x0}' height='{h - 2 * pad}' fill='#eef3ff'/>")
        parts.append(f"<rect x='{x1}' y='{pad}' width='{w - pad - x1}' height='{h - 2 * pad}' fill='#fff8ee'/>")
        parts.append(f"<text x='{x0 + 4}' y='{pad - 8}' fill='#335'>신호 판정 (이 날 종가까지)</text>")
        parts.append(f"<text x='{x1 + 4}' y='{pad - 8}' fill='#853'>이후 체결</text>")
    for name, price, color in (("target", target, "#1a7"), ("entry", entry, "#36c"), ("stop", stop, "#c33")):
        _, y = xy(0, price)
        parts.append(f"<line x1='{pad}' x2='{w - pad}' y1='{y}' y2='{y}' stroke='{color}' stroke-dasharray='3 3'/>")
        parts.append(f"<text x='{w - pad + 2}' y='{y + 4}' fill='{color}'>{name}</text>")
    for i, b in enumerate(rows):
        x, y_c = xy(i, b["close"])
        _, y_o = xy(i, b["open"])
        _, y_h = xy(i, b["high"])
        _, y_l = xy(i, b["low"])
        up = b["close"] >= b["open"]
        col = "#c33" if not up else "#1a7"
        parts.append(f"<line x1='{x}' x2='{x}' y1='{y_h}' y2='{y_l}' stroke='{col}'/>")
        top, bot = min(y_o, y_c), max(y_o, y_c)
        parts.append(f"<rect x='{x - cw / 2}' y='{top}' width='{cw}' height='{max(1, bot - top)}' fill='{col}'/>")
        if b["date"] in {fill_date, exit_date, signal}:
            label = "S" if b["date"] == signal else ("F" if b["date"] == fill_date else "X")
            parts.append(f"<text x='{x - 3}' y='{h - 8}' fill='#000'>{label}</text>")
    parts.append(f"<text x='{pad}' y='{h - 4}' fill='#555'>{title} · {rows[0]['date']}–{rows[-1]['date']}</text>")
    parts.append("</svg>")
    return "\n".join(parts)


def case_window(bars: list[dict], signal: str, end: str | None) -> list[dict]:
    pre = [b for b in bars if b["date"] <= signal][-30:]
    post = [b for b in bars if b["date"] > signal and (end is None or b["date"] <= end)]
    extra = []
    if post:
        after_end = [b for b in bars if b["date"] > post[-1]["date"]][:3]
        extra = after_end
    return pre + post + extra


def money(n: float | None) -> str:
    return "—" if n is None else f"{n:,.2f}"


def main() -> None:
    if not DATASETS["prior"].is_dir() or not DATASETS["recent"].is_dir():
        missing = [str(p) for p in DATASETS.values() if not p.is_dir()]
        raise SystemExit("dataset folders missing: " + ", ".join(missing))

    all_rows = []
    counts_src = {}
    for period, folder in (("prior", FULL / "prior"), ("recent", FULL / "recent")):
        trades = json.loads((folder / "trades.json").read_text(encoding="utf-8"))["trades"]
        summary = json.loads((folder / "summary.json").read_text(encoding="utf-8"))
        last_day = summary["last_day"]
        c = Counter(t["status"] for t in trades)
        counts_src[period] = {
            "orders": len(trades), "closed": c["closed"], "open": c["open"],
            "cancelled": c["cancelled"], "pending": c["pending"],
            "fills": sum(1 for t in trades if t.get("fill")),
            "summary_orders": summary["event_counts"]["order"],
            "summary_fills": summary["event_counts"]["fill"],
            "summary_exits": summary["event_counts"]["exit"],
            "summary_cancels": summary["event_counts"]["order_cancelled"],
        }
        cache: dict[str, tuple[list[dict] | None, str]] = {}
        for t in trades:
            if t["symbol"] not in cache:
                cache[t["symbol"]] = load_bars(DATASETS[period], t["symbol"])
            bars, path = cache[t["symbol"]]
            all_rows.append(check_order(period, last_day, t, bars, path))

    expected = {"orders": 123, "fills": 110, "closed": 105, "open": 5, "cancelled": 12, "pending": 1}
    observed = {
        "orders": sum(v["orders"] for v in counts_src.values()),
        "fills": sum(v["fills"] for v in counts_src.values()),
        "closed": sum(v["closed"] for v in counts_src.values()),
        "open": sum(v["open"] for v in counts_src.values()),
        "cancelled": sum(v["cancelled"] for v in counts_src.values()),
        "pending": sum(v["pending"] for v in counts_src.values()),
    }
    count_note = "matches expected 123 = 110 fills (105 closed + 5 open) + 12 cancelled + 1 pending"
    if observed != expected:
        count_note = f"differs from expected {expected}: observed {observed}"

    conc = json.loads((CONC / "concentration.json").read_text(encoding="utf-8"))
    top = []
    for period in ("prior", "recent"):
        for t in conc[period]["top_wins"]["5"]["trades"]:
            top.append((period, t["symbol"], t["signal_date"]))
    want = set(top)
    for r in all_rows:
        key = (r["period"], r["symbol"], r["signal_date"])
        if key in want:
            r["cases"] = list(dict.fromkeys(r["cases"] + ["top_win"]))

    # unique case list
    case_rows = []
    seen = set()
    for r in all_rows:
        if not r["cases"]:
            continue
        key = (r["period"], r["symbol"], r["signal_date"])
        if key in seen:
            continue
        seen.add(key)
        bars, _ = load_bars(DATASETS[r["period"]], r["symbol"])
        end = (r.get("exit") or {}).get("date") or (r.get("fill") or {}).get("date") or r["signal_date"]
        window = case_window(bars or [], r["signal_date"], end)
        slug = f"{r['period']}-{r['symbol']}-{r['signal_date']}"
        svg = svg_chart(window, f"{r['period']} {r['symbol']}", r["signal_date"],
                        (r.get("fill") or {}).get("date"), (r.get("exit") or {}).get("date"),
                        float(r["entry"]), float(r["stop"]), float(r["target"]))
        CHARTS.mkdir(parents=True, exist_ok=True)
        chart_rel = f"charts/{slug}.svg"
        (CHARTS / f"{slug}.svg").write_text(svg, encoding="utf-8", newline="\n")
        case_rows.append({
            "id": slug,
            "tags": r["cases"],
            "period": r["period"],
            "symbol": r["symbol"],
            "pattern": r["pattern"],
            "dates": {
                "signal": r["signal_date"],
                "fill": (r.get("fill") or {}).get("date"),
                "exit": (r.get("exit") or {}).get("date"),
                "cancel": (r.get("cancelled") or {}).get("date"),
                "note": "signal is the close of the signal session; fill/exit/cancel are later session dates, not the signal date",
            },
            "signal_date": r["signal_date"],
            "fill_date": (r.get("fill") or {}).get("date"),
            "exit_date": (r.get("exit") or {}).get("date"),
            "order": {"entry": r["entry"], "stop": r["stop"], "target": r["target"], "quantity": r["quantity"]},
            "fill": r["fill"],
            "exit": r["exit"],
            "cancelled": r["cancelled"],
            "status": r["status"],
            "signal_bar": r.get("signal_bar"),
            "fill_bar": r.get("fill_bar"),
            "exit_bar": r.get("exit_bar"),
            "independent": r.get("independent"),
            "worst": r["worst"],
            "model_match": r["checks"]["model_match"]["verdict"],
            "entry_ohlc": r["checks"]["entry_ohlc"],
            "exit_ohlc": r["checks"]["exit_ohlc"],
            "uncertainty": [c["detail"] for c in r["checks"].values() if c["verdict"] in ("assumption_dependent", "insufficient_data", "error")],
            "chart": chart_rel,
        })

    worst_counts = Counter(r["worst"] for r in all_rows)
    check_counts = Counter()
    for r in all_rows:
        for c in r["checks"].values():
            check_counts[c["verdict"]] += 1

    hashes = {
        "code": {p: sha256(ROOT / p) for p in CODE_PATHS},
        "inputs": {
            "prior/trades.json": sha256(FULL / "prior/trades.json"),
            "prior/summary.json": sha256(FULL / "prior/summary.json"),
            "recent/trades.json": sha256(FULL / "recent/trades.json"),
            "recent/summary.json": sha256(FULL / "recent/summary.json"),
            "concentration.json": sha256(CONC / "concentration.json"),
        },
        "datasets": {name: {"dir": str(path), "dataset.json": sha256(path / "dataset.json") if (path / "dataset.json").is_file() else None}
                     for name, path in DATASETS.items()},
    }

    slim = []
    for r in all_rows:
        slim.append({k: r[k] for k in (
            "period", "symbol", "signal_date", "status", "pattern", "quantity",
            "entry", "stop", "target", "fill", "exit", "cancelled", "independent",
            "checks", "worst", "bars_path", "cases",
        )})

    doc = {
        "schema": 1,
        "kind": "historical_account_fill_audit_v1",
        "note": "Independent OHLC checks. TradeSimulator was not called. Existing full-v2 files were not rewritten.",
        "generated_at_kst": datetime.now(KST).strftime("%Y-%m-%d %H:%M %z"),
        "counts": {"expected": expected, "observed": observed, "by_period": counts_src, "note": count_note},
        "order_verdicts": dict(worst_counts),
        "check_verdicts": dict(check_counts),
        "orders": slim,
        "hashes": hashes,
        "rules": {
            "lookahead": "Date partition only (date<=signal vs date>signal). ChartPlanEngine inputs were not rebuilt.",
            "model_match": "independent_path date/price/reason/status vs stored trades.json",
            "tape_range": "stored and model prices vs that session's [low, high]; model-outside-tape is recorded separately",
            "order_type": "buy limit, never above entry; gap improvement open*(1+5bp) then min with limit",
            "ttl": "3 sessions",
            "hold": "20 bars including the fill bar",
            "same_bar": "stop wins unless a later session opens through the target",
            "fees": "10bp cash each side on the fill/exit price; 5bp slip is inside the price",
            "leftover": "last holdings and the working order are not flattened",
        },
    }
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "orders.json").write_text(json.dumps(doc, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    (OUT / "cases.json").write_text(json.dumps({
        "schema": 1, "kind": "historical_account_fill_cases_v1", "count": len(case_rows), "cases": case_rows,
    }, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    write_report(doc, case_rows, all_rows, observed, worst_counts, check_counts)
    write_design(hashes)
    print(json.dumps({
        "orders": observed, "count_note": count_note, "order_verdicts": dict(worst_counts),
        "cases": len(case_rows), "written": str(OUT),
    }, ensure_ascii=False, indent=2))


def write_report(doc, cases, all_rows, observed, worst_counts, check_counts) -> None:
    err_n = worst_counts.get("error", 0)
    amb_n = worst_counts.get("assumption_dependent", 0)
    miss_n = worst_counts.get("insufficient_data", 0)
    pass_n = worst_counts.get("pass", 0)
    model_err = sum(1 for r in all_rows if r["checks"]["model_match"]["verdict"] == "error")
    entry_out = [r for r in all_rows if r["checks"]["entry_ohlc"].get("stored_side") not in (None, "inside") and r.get("fill")]
    exit_out = [r for r in all_rows if r["checks"]["exit_ohlc"].get("stored_side") not in (None, "inside") and r.get("exit")]
    lines = [
        "# 연구 계좌 체결 가정 검토",
        "",
        "기존 장부 대사와 TradeSimulator 재호출은 이미 통과한 상태입니다. 이번 검사는 저장된 주문과 원본 일봉을 직접 대조합니다. 시뮬레이터를 다시 돌려 같은 숫자를 확인하는 검사가 아닙니다.",
        "",
        "## 확인된 오류와 일봉으로 확정할 수 없는 가정",
        "",
    ]
    if err_n == 0 and model_err == 0:
        lines += [
            f"**확인된 오류: {err_n}건.** 저장 결과와 독립 경로(`independent_path`)의 체결일·청산일·가격·사유·취소일·취소 사유가 123건 모두 같았습니다. 가격과 손익을 함께 바꾼 사본은 `model_match`에서 걸립니다. 기존 full-v2는 덮어쓰지 않았습니다.",
            "",
        ]
    else:
        lines += [f"**확인된 오류: {err_n}건** (그중 모형 불일치 {model_err}건). `orders.json`의 `worst=error`와 `checks.model_match`를 보세요. 기존 full-v2는 덮어쓰지 않았습니다.", ""]
    lines += [
        f"**일봉으로 확정할 수 없는 가정: 주문 {amb_n}건.** 모형과 저장 값은 같으나 그 가격이 당일 [저가, 고가] 밖입니다. 고가 밖 진입 {len(entry_out)}건, 저가 밖 청산 {len(exit_out)}건. 운영 엔진을 바꿔 이 가격을 없애지 않았습니다.",
        "",
        "아래 날짜는 **청산일 또는 체결일**입니다. 신호일이 아닙니다.",
        "",
    ]
    if entry_out:
        lines.append("고가 밖 진입 (체결일):")
        for r in entry_out:
            e = r["checks"]["entry_ohlc"]
            lines.append(f"- {r['period']} `{r['symbol']}` 체결일 {r['fill']['date']} (신호일 {r['signal_date']}): 저장 {e['stored']}, 당일 H {e['ohlc'][1]}, 모형도 범위 밖={e.get('model_also_outside')}")
        lines.append("")
    if exit_out:
        lines.append("저가 밖 청산 (청산일):")
        for r in exit_out:
            e = r["checks"]["exit_ohlc"]
            lines.append(f"- {r['period']} `{r['symbol']}` 청산일 {r['exit']['date']} 사유 {r['exit']['reason']} (신호일 {r['signal_date']}): 저장 {e['stored']}, 당일 L {e['ohlc'][2]}, 모형도 범위 밖={e.get('model_also_outside')}")
        lines.append("")
    lines += [
        "- 손절·시간 청산의 5bp 슬리피지는 시가 또는 종가보다 낮아질 수 있고, 그 값이 당일 저가보다 낮을 수 있습니다.",
        "- 목표가 갭(다음 세션 시가≥목표)은 목표 지정가로 나가므로 당일 저가보다 낮을 수 있습니다.",
        "- 시가×1.0005 진입은 당일 고가보다 높을 수 있습니다.",
        "- 같은 봉 손절·목표 순서는 일봉으로 확정하지 않습니다.",
        "- 미래봉 항목은 날짜를 신호일 이전/이후로 나눈 확인입니다. ChartPlanEngine 입력 배열을 재구성하지 않았습니다.",
        "- 데이터셋 가격 기준은 `yahoo_chart_quote_ohlcv_daily_adjustment_unverified`입니다.",
        f"- 자료 부족: 주문 {miss_n}건, 검사 항목 {check_counts.get('insufficient_data', 0)}건.",
        "",
        f"주문별 최악 판정: 통과 {pass_n}, 오류 {err_n}, 가정 의존 {amb_n}, 자료 부족 {miss_n}. 검사 항목 합계 {sum(check_counts.values())} (통과 {check_counts.get('pass', 0)} / 오류 {check_counts.get('error', 0)} / 가정 의존 {check_counts.get('assumption_dependent', 0)} / 자료 부족 {check_counts.get('insufficient_data', 0)}).",
        "",
        "## 건수",
        "",
        f"원본 `trades.json` 합계: 주문 {observed['orders']}, 체결 {observed['fills']} (종료 {observed['closed']} + 보유 {observed['open']}), 취소 {observed['cancelled']}, 대기 {observed['pending']}. 기대 123건과 같습니다.",
        "",
        "기대 123건은 이전 56 + 최근 67입니다. summary의 order/fill/exit/cancel 칸과 같습니다.",
        "",
        "## 규칙과 확인 범위",
        "",
        "| 항목 | 코드 | 독립 확인 | 범위 |",
        "|---|---|---|---|",
        "| 기존 모형과 일치 | `independent_path` | 체결일·가격, 청산일·가격·사유, 취소일·사유 | 저장된 값이 모형과 같은지. 일봉 안에 있는지는 아님 |",
        "| 일봉 범위 | 해당 세션 OHLC | 저장 가격과 모형 가격이 [저가, 고가] 안인지 | 모형도 범위 밖이면 `model_also_outside` |",
        "| 신호 날짜 구분 | `AccountReplay.php` 214–222행은 종가 이하 봉만 계획에 씀 | 신호일 봉 존재, 체결·청산일이 신호일 이후 | **날짜 구분만.** 엔진 입력을 검증한 것이 아님 |",
        "| 날짜 순서·최초 체결 | TTL 3 | 지정가에 닿는 첫 세션 | 장중 시각은 모름 |",
        "| 주문 유형 | 매수 지정가 | 체결가 ≤ 지정가 | 지정가 위 매수는 오류 |",
        "| 갭 | 진입 `min(limit, open×1.0005)`, 손절 `min(stop,open)×0.9995`, 이후 시가≥목표는 목표가 | 모형 가격 | 5bp와 목표 지정가는 가정 |",
        "| 진입 당일 청산 | 체결 봉에서도 손절·목표 | 체결일과 청산일 | 같은 봉 경로 불명 |",
        "| 같은 봉 충돌 | 이후 시가≥목표면 목표, 아니면 손절 | OHLC가 둘 다 닿았는지 | 장중 순서는 가정 |",
        "| 수수료 | 현금 10bp×2, 슬리피지는 가격 안 | `fee`와 `net_pnl` | 중복 차감 여부 |",
        "| 마지막 보유·대기 | 강제 청산 없음 | 종료 105, 보유 5, 대기 1 | 마지막 날 이후 봉은 쓰지 않음 |",
        "",
        "## 상세 사례",
        "",
        f"합집합 {len(cases)}건 (기간별 순이익 상위 5 + 갭 진입/손절 + 범위 밖 체결 + 당일 청산 + 검증 예외, 중복 제거). 차트는 원본 봉입니다. 파란 구간은 신호일 종가까지, 주황 구간은 그 이후 체결입니다. 날짜 칸을 신호일·체결일·청산일(또는 취소일)로 나눠 적습니다. 신호일은 종가 판정일입니다.",
        "",
        "| 사례 | 태그 | 신호일 | 체결일 | 청산일·취소일 | 진입가·청산가 | 모형일치 | 범위 | 차트 |",
        "|---|---|---|---|---|---|---|---|---|",
    ]
    for c in cases:
        f = c.get("fill") or {}
        x = c.get("exit") or {}
        entry_side = (c.get("entry_ohlc") or {}).get("stored_side", "—")
        exit_side = (c.get("exit_ohlc") or {}).get("stored_side", "—")
        rng = f"진입 {entry_side}, 청산 {exit_side}"
        cancel_day = (c.get("dates") or {}).get("cancel") or (c.get("cancelled") or {}).get("date")
        if c.get("exit_date"):
            when = c["exit_date"]
        elif cancel_day:
            when = f"cancelled {cancel_day}"
        else:
            when = c["status"]
        lines.append(
            f"| {c['period']} `{c['symbol']}` | {', '.join(c['tags'])} | {c['signal_date']} | "
            f"{c.get('fill_date') or '—'} | {when} | "
            f"{money(f.get('price'))} / {money(x.get('price'))} | {c.get('model_match')} | {rng} | [{c['chart']}]({c['chart']}) |"
        )
    lines += [
        "",
        "재현: `python docs/research/account-replay-fill-audit.py`",
        "",
        "입력 해시는 `orders.json`의 `hashes`에 있습니다. 원본 데이터셋은 "
        f"`{DATASETS['prior']}` 와 `{DATASETS['recent']}` 입니다.",
        "",
        "비용 실험은 아직 실행하지 않았습니다. 설계는 `experiment-design.md`를 보세요.",
        "",
        f"작성 시각 {doc['generated_at_kst']}.",
        "",
    ]
    (OUT / "report.md").write_text("\n".join(lines), encoding="utf-8", newline="\n")


def write_design(hashes) -> None:
    text = """# 비용·체결 가정 민감도 실험 설계 (미실행)

이번 PR에서는 설계만 적습니다. 실행 엔진 수정과 전체 재실행은 코드 검토 뒤에 합니다.

## 지금 쓰는 가정

| 항목 | 값 | 위치 | 성격 |
|---|---|---|---|
| 매수 지정가 | 지정가 초과 체결 없음 | `TradeSimulator.php` 60–61행 | 주문 조건 |
| 진입 갭 개선 | `min(지정가, 시가×1.0005)` | 같은 곳 | 슬리피지 모형 |
| 손절 | `min(손절, 시가)×0.9995` | 75–77행 | 슬리피지 모형 |
| 목표 | 목표가 그대로. 다음 세션 시가≥목표면 목표가 | 72–81행 | 주문 조건 |
| 시간 청산 | 종가×0.9995 | 82–84행 | 슬리피지 모형 |
| 현금 수수료 | 매수 대금×1.001, 매도 대금×0.999 | `PaperPortfolio.php` 73–80행 | 거래비용 |
| TTL / 보유 | 3세션 / 20봉 | 시뮬레이터·포트폴리오 | 기존 규칙 |

슬리피지는 가격을 바꾸고, 거래비용은 그 가격에 비율을 붙입니다. 같은 10bp를 가격과 현금에 중복으로 넣지 않습니다.

## 실험 조건 (기준 대비)

기준: 지금과 동일 (슬리피지 5bp, 수수료 10bp×2).

추가 슬리피지와 추가 거래비용을 **따로** 둡니다.

1. `slip+10` / `slip+20`: 체결가 모형만 5bp에서 15bp / 25bp. 현금 수수료는 10bp 유지.
2. `fee+10` / `fee+20`: 현금 수수료만 매수·매도 각 +0.1%p / +0.2%p (10bp→20bp / 30bp). 체결가 모형은 5bp 유지.
3. 교차안은 검토 뒤에만. 이번 설계의 1차는 네 실험입니다.

매수·매도는 같은 폭으로 불리하게 갑니다. 한쪽만 바꾸는 안은 넣지 않습니다.

## 주문 조건을 깨지 않는 방법

- 매수 체결가는 언제나 `min(지정가, 모형가)`입니다. 지정가보다 비싸게 사지 않습니다.
- 목표 청산은 목표가 그대로입니다. 목표를 밑도는 “불리한 목표 체결”은 지정가 주문을 깨므로 일봉 실험에 넣지 않습니다.
- 손절·시간 청산의 추가 슬리피지는 더 낮은 매도가로만 반영합니다.
- 시가가 손절을 이미 뚫은 갭은 `min(손절, 시가)×(1−slip)`을 유지합니다.
- 같은 봉 손절 우선, TTL 3, 보유 20, 신호·손절·목표 수준은 그대로입니다.

## 일봉으로 구현할 수 없는 것

- 지정가에 닿은 뒤 더 나쁜 호가로 밀리는 장중 경로.
- 목표를 지정가보다 낮게 체결하는 것 (주문 유형 변경).
- 부분 체결, 호가 잔량, 지연.
- 같은 봉에서 손절과 목표 중 어느 것이 먼저인지.

이런 항목은 실험 인자로 쓰지 않고 보고서에 가정으로 남깁니다.

## 단순 사후 차감을 하지 않는 이유

추가 비용은 체결가 또는 수수료율을 바꾼 뒤 **처음부터** 연구 계좌를 다시 진행해야 합니다.

바뀐 진입가는 수량(`floor(risk/unitRisk)`, `capacity/(entry×(1+fee))`), 예약 현금, 섹터·위험 한도, 이후 주문 가능 여부에 들어갑니다. 끝난 거래의 `net_pnl`에서 비용만 빼면 이 경로가 사라집니다.

## 계좌와 검증

- 실험마다 새 연구 계좌 ID와 새 `--state-dir`. `full-v2`와 운영 계좌를 덮어쓰지 않습니다.
- 입력 해시(데이터셋, 섹터 맵 v2, 전략 지문)는 기준과 같고, 비용 설정 해시만 실험마다 다릅니다.
- 기준 조건으로 이전·최근 계좌를 다시 export했을 때 `trades.json`·저널 정규화 해시가 지금 `full-v2`와 같아야 합니다. 이 재현이 실패한 채로 비용 실험을 시작하지 않습니다.
- 단위 검증(실험 엔진 착수 시): 갭 진입·갭 손절·지정가 미도달·같은 봉 충돌·현금 부족으로 수량이 0이 되는 고정 봉. 지정가를 넘는 매수는 실패로 봅니다.

## 재현 명령 (검토 후, 지금은 실행하지 않음)

기준 재현(기존 결과 보존: `--out`을 새 폴더로):

```
php -d memory_limit=3G bin/paper_account_replay.php run --period=prior --dataset-dir=DATASET_PRIOR --state-dir=STATE_BASELINE --sector-map=docs/historical-account-replay/sector-map-v2.json --confirm-full-run=after-code-review
php bin/paper_account_replay.php export --period=prior --dataset-dir=DATASET_PRIOR --state-dir=STATE_BASELINE --out=docs/historical-account-replay/fill-audit/baseline-prior
```

최근 기간도 같은 식입니다. 비용 실험은 엔진에 명시적 수수료·슬리피지 인자가 생긴 뒤에만, 실험별 `STATE_SLIP10` 등으로 돌립니다.

이번 작업에서 실행한 명령은 `python docs/research/account-replay-fill-audit.py` 뿐입니다.
"""
    (OUT / "experiment-design.md").write_text(text, encoding="utf-8", newline="\n")


def _weekdays(start: str, n: int) -> list[str]:
    d = datetime.fromisoformat(start)
    out = []
    while len(out) < n:
        if d.weekday() < 5:
            out.append(d.strftime("%Y-%m-%d"))
        d += timedelta(days=1)
    return out


def fixture_time_exit() -> tuple[dict, list[dict], str]:
    """Synthetic 032820-like time exit: 20th bar closes at 2240, model exit is 2240*0.9995."""
    days = _weekdays("2024-10-15", 21)
    signal, fill_day, last = days[0], days[1], days[-1]
    bars = []
    for i, day in enumerate(days):
        if i == 0:
            o, h, l, c = 2200, 2300, 2180, 2250
        elif i == 1:
            o, h, l, c = 2285, 2320, 2260, 2290
        elif i == 20:
            o, h, l, c = 2245, 2260, 2230, 2240
        else:
            o, h, l, c = 2260, 2300, 2220, 2270
        bars.append({"date": day, "open": o, "high": h, "low": l, "close": c, "volume": 1, "available_at": close_ts(day)})
    fill_px = 2285.0
    exit_px = 2240.0 * (1 - SLIP)
    qty = 5854
    order = {
        "symbol": "032820.KQ", "signal_date": signal, "pattern": "trend_pullback_v1",
        "quantity": qty, "entry": 2285, "stop": 2118, "target": 2650,
        "fill": {"date": fill_day, "price": fill_px, "fee": qty * fill_px * FEE},
        "exit": {"date": last, "price": exit_px, "reason": "time",
                 "net_pnl": qty * exit_px * (1 - FEE) - qty * fill_px * (1 + FEE), "ambiguous_bar": False},
        "cancelled": None, "status": "closed",
    }
    return order, bars, last


def fixture_stop_below_low() -> tuple[dict, list[dict], str]:
    days = _weekdays("2025-06-09", 3)
    signal, fill_day, exit_day = days
    bars = [
        {"date": signal, "open": 7400, "high": 7500, "low": 7300, "close": 7450, "volume": 1, "available_at": close_ts(signal)},
        {"date": fill_day, "open": 7440, "high": 7500, "low": 7400, "close": 7460, "volume": 1, "available_at": close_ts(fill_day)},
        {"date": exit_day, "open": 6680, "high": 6700, "low": 6680, "close": 6690, "volume": 1, "available_at": close_ts(exit_day)},
    ]
    fill_px, stop, qty = 7440.0, 6680.0, 1294
    exit_px = stop * (1 - SLIP)
    order = {
        "symbol": "393210.KQ", "signal_date": signal, "pattern": "trend_recovery_v1",
        "quantity": qty, "entry": 7440, "stop": stop, "target": 8590,
        "fill": {"date": fill_day, "price": fill_px, "fee": qty * fill_px * FEE},
        "exit": {"date": exit_day, "price": exit_px, "reason": "stop",
                 "net_pnl": qty * exit_px * (1 - FEE) - qty * fill_px * (1 + FEE), "ambiguous_bar": False},
        "cancelled": None, "status": "closed",
    }
    return order, bars, exit_day


def fixture_unfilled() -> tuple[dict, list[dict], str]:
    """TTL expiry: 3rd post-signal session is the cancel. Extra later bars must not move it."""
    days = _weekdays("2025-05-14", 6)
    signal, last = days[0], days[-1]
    bars = []
    for day in days:
        bars.append({"date": day, "open": 570000, "high": 580000, "low": 560000, "close": 575000,
                     "volume": 1, "available_at": close_ts(day)})
    cancel_day = days[3]  # signal + 3 completed sessions
    order = {
        "symbol": "298040.KS", "signal_date": signal, "pattern": "breakout_retest_v1",
        "quantity": 24, "entry": 556000, "stop": 516200, "target": 670000,
        "fill": None, "exit": None,
        "cancelled": {"date": cancel_day, "reason": "unfilled"},
        "status": "cancelled",
    }
    return order, bars, last


def run_check(order: dict, bars: list[dict], last_day: str) -> dict:
    return check_order("prior", last_day, order, bars, "fixture")


def tamper_cancel_caught(order: dict, bars: list[dict], last_day: str) -> dict:
    base = run_check(order, bars, last_day)
    if base["checks"]["model_match"]["verdict"] != "pass":
        raise AssertionError("unfilled fixture itself must match the model")
    if base["independent"]["cancel_date"] != order["cancelled"]["date"]:
        raise AssertionError("TTL cancel must stay on the 3rd post-signal session")
    out = {}
    date_only = json.loads(json.dumps(order))
    date_only["cancelled"]["date"] = "2025-05-20"
    out["date"] = run_check(date_only, bars, last_day)["checks"]["model_match"]["verdict"]
    reason_only = json.loads(json.dumps(order))
    reason_only["cancelled"]["reason"] = "cancelled_before_entry"
    out["reason"] = run_check(reason_only, bars, last_day)["checks"]["model_match"]["verdict"]
    return out


def tamper_caught(order: dict, bars: list[dict], last_day: str) -> dict:
    """Each rewrite must make model_match fail, including price+pnl together."""
    base = run_check(order, bars, last_day)
    if base["checks"]["model_match"]["verdict"] != "pass":
        raise AssertionError("fixture itself must match the model")
    out = {}
    price_only = json.loads(json.dumps(order))
    price_only["exit"]["price"] = float(order["exit"]["price"]) * 1.02
    out["price"] = run_check(price_only, bars, last_day)["checks"]["model_match"]["verdict"]
    date_only = json.loads(json.dumps(order))
    date_only["exit"]["date"] = "2024-11-11"
    out["date"] = run_check(date_only, bars, last_day)["checks"]["model_match"]["verdict"]
    reason_only = json.loads(json.dumps(order))
    reason_only["exit"]["reason"] = "stop"
    out["reason"] = run_check(reason_only, bars, last_day)["checks"]["model_match"]["verdict"]
    both = json.loads(json.dumps(order))
    both["exit"]["price"] = float(order["exit"]["price"]) * 1.02
    both["exit"]["net_pnl"] = (order["quantity"] * both["exit"]["price"] * (1 - FEE)
                               - order["quantity"] * order["fill"]["price"] * (1 + FEE))
    out["price_and_pnl"] = run_check(both, bars, last_day)["checks"]["model_match"]["verdict"]
    return out


if __name__ == "__main__":
    if len(__import__("sys").argv) > 1 and __import__("sys").argv[1] == "--self-test":
        order, bars, last = fixture_time_exit()
        caught = tamper_caught(order, bars, last)
        if any(v != "error" for v in caught.values()):
            raise SystemExit("tamper was not caught: " + json.dumps(caught))
        stop_o, stop_b, stop_last = fixture_stop_below_low()
        stop_r = run_check(stop_o, stop_b, stop_last)
        if stop_r["checks"]["model_match"]["verdict"] != "pass":
            raise SystemExit("stop fixture must match the model")
        if stop_r["checks"]["exit_ohlc"]["verdict"] != "assumption_dependent":
            raise SystemExit("stop below low must be tape-assumption, not a model error")
        cancel_o, cancel_b, cancel_last = fixture_unfilled()
        cancel_caught = tamper_cancel_caught(cancel_o, cancel_b, cancel_last)
        if any(v != "error" for v in cancel_caught.values()):
            raise SystemExit("cancel tamper was not caught: " + json.dumps(cancel_caught))
        print(json.dumps({
            "tamper": caught,
            "stop_below_low": stop_r["checks"]["exit_ohlc"]["verdict"],
            "cancel_tamper": cancel_caught,
        }, indent=2))
    else:
        main()

