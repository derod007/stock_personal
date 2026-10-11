"""Read-only concentration of closed-trade realized PnL in the two full-v2 accounts.

Does not rerun an account. Does not write operational files or overwrite full-v2 exports.
"""
from __future__ import annotations

import hashlib
import json
from collections import defaultdict
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "docs/historical-account-replay/full-v2"
OUT = ROOT / "docs/historical-account-replay/concentration"
TOL = 0.01
TOPS = (1, 3, 5)

REPLAY = {
    "prior": [
        "php -d memory_limit=3G bin/paper_account_replay.php run --period=prior --dataset-dir=DATASET --state-dir=STATE --sector-map=docs/historical-account-replay/sector-map-v2.json --confirm-full-run=after-code-review",
        "php bin/paper_account_replay.php export --period=prior --dataset-dir=DATASET --state-dir=STATE --sector-map=docs/historical-account-replay/sector-map-v2.json --out=docs/historical-account-replay/full-v2/prior",
    ],
    "recent": [
        "php -d memory_limit=3G bin/paper_account_replay.php run --period=recent --dataset-dir=DATASET --state-dir=STATE --sector-map=docs/historical-account-replay/sector-map-v2.json --confirm-full-run=after-code-review",
        "php bin/paper_account_replay.php export --period=recent --dataset-dir=DATASET --state-dir=STATE --sector-map=docs/historical-account-replay/sector-map-v2.json --out=docs/historical-account-replay/full-v2/recent",
    ],
    "compare": "python docs/research/account-replay-period-compare.py",
    "this": "python docs/research/account-replay-concentration.py",
}


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def money(n: float) -> str:
    return f"{n:,.2f}원"


def pct(n: float | None) -> str:
    return "—" if n is None else f"{n:.1f}%"


def share(part: float, whole: float) -> float | None:
    if abs(whole) < 1e-9:
        return None
    return part / whole * 100


def row_of(t: dict) -> dict:
    ex = t["exit"]
    return {
        "symbol": t["symbol"],
        "pattern": t["pattern"],
        "signal_date": t["signal_date"],
        "entry_date": (t.get("fill") or {}).get("date"),
        "exit_date": ex["date"],
        "exit_reason": ex["reason"],
        "quantity": t["quantity"],
        "net_pnl": ex["net_pnl"],
    }


def group(rows: list[dict], key_fn) -> list[dict]:
    bags: dict[str, list[dict]] = defaultdict(list)
    for r in rows:
        bags[key_fn(r)].append(r)
    out = []
    for key, items in bags.items():
        pnls = [x["net_pnl"] for x in items]
        wins = [p for p in pnls if p > 0]
        losses = [p for p in pnls if p < 0]
        out.append({
            "key": key,
            "closed": len(items),
            "wins": len(wins),
            "losses": len(losses),
            "profit_sum": sum(wins),
            "loss_sum": sum(losses),
            "net_pnl": sum(pnls),
        })
    out.sort(key=lambda x: (-x["net_pnl"], x["key"]))
    return out


def top_slice(sorted_rows: list[dict], n: int, realized: float, closed: int, win_sum: float | None = None, loss_sum: float | None = None) -> dict:
    take = sorted_rows[:n]
    s = sum(x["net_pnl"] for x in take)
    rest = realized - s
    out = {
        "n": n,
        "taken": n,
        "sum": s,
        "share_of_realized": share(s, realized),
        "remainder_realized": rest,
        "remainder_closed": closed - n,
        "note": "remainder is realized minus these trades; it is not a rerun without them",
        "trades": take,
    }
    if win_sum is not None:
        out["share_of_win_sum"] = share(s, win_sum)
        out["win_sum_denominator"] = win_sum
    if loss_sum is not None:
        out["share_of_loss_sum"] = share(s, loss_sum)
        out["loss_sum_denominator"] = loss_sum
    out["realized_denominator"] = realized
    return out


def analyze_period(name: str) -> dict:
    folder = SRC / name
    files = {
        "trades.json": folder / "trades.json",
        "summary.json": folder / "summary.json",
        "daily-log.jsonl": folder / "daily-log.jsonl",
        "provenance.json": folder / "provenance.json",
    }
    hashes = {k: sha256(p) for k, p in files.items()}
    summary = json.loads(files["summary.json"].read_text(encoding="utf-8"))
    provenance = json.loads(files["provenance.json"].read_text(encoding="utf-8"))
    listed = json.loads(files["trades.json"].read_text(encoding="utf-8"))["trades"]
    logs = [json.loads(line) for line in files["daily-log.jsonl"].read_text(encoding="utf-8").splitlines() if line.strip()]

    closed, skipped = [], []
    for t in listed:
        status = t.get("status")
        if status == "closed" and t.get("exit"):
            closed.append(row_of(t))
        else:
            skipped.append({"symbol": t.get("symbol"), "status": status, "has_exit": bool(t.get("exit")), "cancelled": t.get("cancelled") is not None})

    assert all(s["status"] != "closed" for s in skipped), "a closed row was skipped"
    assert all(s["status"] in ("open", "pending", "cancelled") for s in skipped), "unexpected trade status"
    assert not any(s["has_exit"] and s["status"] != "closed" for s in skipped), "non-closed trade has an exit"

    wins = [r for r in closed if r["net_pnl"] > 0]
    losses = [r for r in closed if r["net_pnl"] < 0]
    flats = [r for r in closed if r["net_pnl"] == 0]
    realized = sum(r["net_pnl"] for r in closed)
    win_sum = sum(r["net_pnl"] for r in wins)
    loss_sum = sum(r["net_pnl"] for r in losses)
    log_exits = sum(len(day.get("exits") or []) for day in logs)
    last_realized = logs[-1]["realized"] if logs else None

    checks = {
        "closed_count_matches_summary": len(closed) == summary["closed_trades"],
        "wins_match_summary": len(wins) == summary["wins"],
        "losses_match_summary": len(losses) == summary["losses"],
        "closed_net_equals_summary_realized": abs(realized - summary["realized_pnl"]) < TOL,
        "closed_net_equals_last_daily_realized": last_realized is not None and abs(realized - last_realized) < TOL,
        "daily_exit_count_equals_closed": log_exits == len(closed),
        "open_count_matches_summary": sum(1 for s in skipped if s["status"] == "open") == len(summary["open_positions"]),
        "pending_count_matches_summary": sum(1 for s in skipped if s["status"] == "pending") == len(summary["pending_orders"]),
        "no_zero_pnl_placeholders": all(s["status"] != "closed" for s in skipped),
        "group_nets_sum_to_realized": True,
    }
    if not all(checks.values()):
        failed = [k for k, v in checks.items() if not v]
        raise AssertionError(f"{name} checks failed: {failed}")

    by_symbol = group(closed, lambda r: r["symbol"])
    by_pattern = group(closed, lambda r: r["pattern"])
    by_month = group(closed, lambda r: r["exit_date"][:7])
    for rows in (by_symbol, by_pattern, by_month):
        if abs(sum(x["net_pnl"] for x in rows) - realized) >= TOL or sum(x["closed"] for x in rows) != len(closed):
            raise AssertionError(f"{name} group totals drifted from the closed set")

    winners = sorted(wins, key=lambda r: (-r["net_pnl"], r["exit_date"], r["symbol"]))
    losers = sorted(losses, key=lambda r: (r["net_pnl"], r["exit_date"], r["symbol"]))
    top_wins = {n: top_slice(winners, n, realized, len(closed), win_sum=win_sum) for n in TOPS}
    top_losses = {n: top_slice(losers, n, realized, len(closed), loss_sum=loss_sum) for n in TOPS}

    return {
        "period": name,
        "account": summary["account"],
        "dataset": provenance.get("dataset", {}).get("name") or summary["research"]["dataset"],
        "first_day": logs[0]["date"],
        "last_day": logs[-1]["date"],
        "sessions": len(logs),
        "initial_cash": summary["initial_cash"],
        "equity": summary["equity"],
        "equity_change_pct": (summary["equity"] / summary["initial_cash"] - 1) * 100,
        "realized_pnl": realized,
        "unrealized_pnl_open_positions": summary["unrealized_pnl_open_positions"],
        "closed": len(closed),
        "wins": len(wins),
        "losses": len(losses),
        "flats": len(flats),
        "win_sum": win_sum,
        "loss_sum": loss_sum,
        "skipped": {
            "open": sum(1 for s in skipped if s["status"] == "open"),
            "pending": sum(1 for s in skipped if s["status"] == "pending"),
            "cancelled": sum(1 for s in skipped if s["status"] == "cancelled"),
            "note": "open, pending and cancelled rows are excluded; they are not booked as 0 realized PnL",
        },
        "top_wins": {str(n): top_wins[n] for n in TOPS},
        "top_losses": {str(n): top_losses[n] for n in TOPS},
        "by_symbol": by_symbol,
        "by_pattern": by_pattern,
        "by_exit_month": by_month,
        "checks": checks,
        "input_sha256": hashes,
        "strategy_fingerprint": provenance.get("strategy_fingerprint"),
        "sector_map_file_sha256": provenance.get("research", {}).get("sector_map_file_sha256"),
        "replay": REPLAY[name],
    }


def table(headers: list[str], rows: list[list[str]]) -> str:
    align = ["|---"] + ["|---:"] * (len(headers) - 1)
    lines = ["| " + " | ".join(headers) + " |", "".join(align) + "|"]
    for row in rows:
        lines.append("| " + " | ".join(row) + " |")
    return "\n".join(lines)


def trade_rows(items: list[dict]) -> list[list[str]]:
    return [[
        r["symbol"], r["pattern"], r["signal_date"], r["entry_date"] or "—", r["exit_date"],
        r["exit_reason"], str(r["quantity"]), money(r["net_pnl"]),
    ] for r in items]


def group_rows(items: list[dict], realized: float) -> list[list[str]]:
    return [[
        x["key"], str(x["closed"]), money(x["profit_sum"]), money(x["loss_sum"]),
        money(x["net_pnl"]), pct(share(x["net_pnl"], realized)),
    ] for x in items]


def write_report(doc: dict) -> str:
    prior, recent = doc["prior"], doc["recent"]
    lines = [
        "# 두 기간 연구 계좌 수익 집중도",
        "",
        "각 기간은 초기 자금 1억 원의 독립 계좌입니다. 연속 2년 운용이 아닙니다. 아래 비중은 이미 끝난 종료 거래의 실현손익 기여도이며, 해당 거래를 빼고 계좌를 다시 돌린 수익률이 아닙니다.",
        "",
        "## 요약",
        "",
    ]
    for p, label, eq in ((prior, "이전", "+4.9229%"), (recent, "최근", "+18.1689%")):
        tw, tl = p["top_wins"], p["top_losses"]
        top_sym = p["by_symbol"][0]
        top_pat = p["by_pattern"][0]
        top_mo = p["by_exit_month"][0]
        lines += [
            f"**{label}** (종료 {p['closed']}건, 총자산 {eq}, 실현손익 {money(p['realized_pnl'])}; 미실현 {money(p['unrealized_pnl_open_positions'])}은 별도)",
            "",
            f"- 순이익 상위 1건은 실현손익의 {pct(tw['1']['share_of_realized'])} (분모 실현손익 {money(p['realized_pnl'])}), 이익 합계의 {pct(tw['1']['share_of_win_sum'])} (분모 이익 거래 {p['wins']}건 합 {money(p['win_sum'])}).",
            f"- 상위 3건은 실현손익의 {pct(tw['3']['share_of_realized'])}, 이익 합계의 {pct(tw['3']['share_of_win_sum'])}. 상위 5건은 실현손익의 {pct(tw['5']['share_of_realized'])}, 이익 합계의 {pct(tw['5']['share_of_win_sum'])}.",
            f"- 상위 5건을 빼면 나머지 {tw['5']['remainder_closed']}건의 실현손익은 {money(tw['5']['remainder_realized'])}입니다.",
            f"- 손실 상위 1·3·5건은 전체 손실 합계 {money(p['loss_sum'])}의 {pct(tl['1']['share_of_loss_sum'])} / {pct(tl['3']['share_of_loss_sum'])} / {pct(tl['5']['share_of_loss_sum'])}입니다.",
            f"- 종목 최다 기여는 `{top_sym['key']}` {top_sym['closed']}건·순손익 {money(top_sym['net_pnl'])} (실현손익의 {pct(share(top_sym['net_pnl'], p['realized_pnl']))}). 패턴은 `{top_pat['key']}` {money(top_pat['net_pnl'])}, 청산 월은 {top_mo['key']} {money(top_mo['net_pnl'])}.",
            "",
        ]
    lines += [
        "전체 실현손익 대비 비중은 100%를 넘을 수 있습니다. 분모는 이익에서 손실을 뺀 값이고, 분자는 이익 거래만의 합이기 때문입니다.",
        "",
        "## 범위와 검증",
        "",
        "- 종료 거래만 집계했습니다. 미청산 보유, 대기 주문, 취소 주문은 넣지 않았고 손익 0으로도 넣지 않았습니다.",
        "- 실현손익은 종료 거래 `exit.net_pnl` 합입니다. 미실현손익은 summary의 보유 평가이며 이 집계에 더하지 않았습니다.",
        "- 거래 평균을 계좌 수익률로 쓰지 않았습니다. 총자산 변화율은 현금+종가 평가이고, 실현손익과 다릅니다.",
        "- 상위 거래를 제외한 나머지는 단순 차감입니다. 그 거래가 없었을 때의 수량·한도·후속 체결은 다시 계산하지 않았습니다.",
        "- 이 결과만으로 전략의 유효성이나 운영 변경을 확정하지 않습니다.",
        "",
        f"입력 해시와 검사 결과는 `concentration.json`에 있습니다. 비교표 `{doc['comparison_md']}` sha256 `{doc['comparison_md_sha256'][:12]}…`.",
        "",
    ]
    headers_t = ["종목", "패턴", "신호일", "진입일", "청산일", "사유", "수량", "net_pnl"]
    headers_g = ["구분", "종료", "이익 합계", "손실 합계", "순손익", "실현손익 대비"]
    for p, label in ((prior, "이전"), (recent, "최근")):
        lines += [
            f"## {label} 기간 ({p['first_day']}~{p['last_day']}, {p['sessions']}세션)",
            "",
            f"계좌 `{p['account']}`, 데이터셋 `{p['dataset']}`, 초기 자금 {money(p['initial_cash'])}. 종료 {p['closed']}건(이익 {p['wins']}, 손실 {p['losses']}). 실현손익 {money(p['realized_pnl'])}, 미실현 {money(p['unrealized_pnl_open_positions'])}. 제외: 보유 {p['skipped']['open']}, 대기 {p['skipped']['pending']}, 취소 {p['skipped']['cancelled']}.",
            "",
            "### 순이익 상위 거래",
            "",
            table(["제외", "합계", "실현손익 대비", "이익 합계 대비", "나머지 실현손익"], [
                [f"상위 {n}건", money(p["top_wins"][str(n)]["sum"]), pct(p["top_wins"][str(n)]["share_of_realized"]),
                 pct(p["top_wins"][str(n)]["share_of_win_sum"]), money(p["top_wins"][str(n)]["remainder_realized"])]
                for n in TOPS
            ]),
            "",
            "분모: 실현손익 대비는 종료 거래 순손익 합, 이익 합계 대비는 `net_pnl > 0`인 거래만의 합.",
            "",
            table(headers_t, trade_rows(p["top_wins"]["5"]["trades"])),
            "",
            "### 손실 금액이 큰 거래",
            "",
            table(["제외", "합계", "전체 손실 합계 대비"], [
                [f"상위 {n}건", money(p["top_losses"][str(n)]["sum"]), pct(p["top_losses"][str(n)]["share_of_loss_sum"])]
                for n in TOPS
            ]),
            "",
            f"분모: 손실 거래 {p['losses']}건의 `net_pnl` 합 {money(p['loss_sum'])}.",
            "",
            table(headers_t, trade_rows(p["top_losses"]["5"]["trades"])),
            "",
            "### 종목별 (같은 종목의 여러 거래는 합산)",
            "",
            table(headers_g, group_rows(p["by_symbol"], p["realized_pnl"])),
            "",
            "### 패턴별",
            "",
            table(headers_g, group_rows(p["by_pattern"], p["realized_pnl"])),
            "",
            "### 청산 월별",
            "",
            table(headers_g, group_rows(p["by_exit_month"], p["realized_pnl"])),
            "",
        ]

    p_sym = {x["key"] for x in prior["by_symbol"] if x["net_pnl"] > 0}
    r_sym = {x["key"] for x in recent["by_symbol"] if x["net_pnl"] > 0}
    both_win_sym = sorted(p_sym & r_sym)
    p_top = {x["symbol"] for x in prior["top_wins"]["5"]["trades"]}
    r_top = {x["symbol"] for x in recent["top_wins"]["5"]["trades"]}
    lines += [
        "## 두 기간 비교",
        "",
        "반복된 집중:",
        "",
        "- 두 기간 모두 종료 거래는 손실 건수가 이익 건수보다 많습니다. 실현손익이 플러스인 것은 소수 이익 거래의 금액이 다수 손실을 넘어서기 때문입니다.",
        f"- 순이익 상위 5건이 이익 합계에서 차지하는 비중은 이전 {pct(prior['top_wins']['5']['share_of_win_sum'])}, 최근 {pct(recent['top_wins']['5']['share_of_win_sum'])}입니다. 이익 자체도 소수 거래에 모여 있습니다.",
        f"- 손실 상위 5건이 전체 손실에서 차지하는 비중은 이전 {pct(prior['top_losses']['5']['share_of_loss_sum'])}, 최근 {pct(recent['top_losses']['5']['share_of_loss_sum'])}입니다.",
        f"- 패턴 `{prior['by_pattern'][0]['key']}`가 이전 기간 순손익 1위({money(prior['by_pattern'][0]['net_pnl'])})이고, 최근 1위는 `{recent['by_pattern'][0]['key']}`({money(recent['by_pattern'][0]['net_pnl'])})입니다.",
        "",
        "한 기간에만 나타난 집중:",
        "",
        f"- 이전 기간의 실현손익 {money(prior['realized_pnl'])}은 상위 1건만으로도 {pct(prior['top_wins']['1']['share_of_realized'])}입니다. 상위 5건을 빼면 나머지 실현손익은 {money(prior['top_wins']['5']['remainder_realized'])}으로 부호가 바뀝니다. 최근 기간은 실현손익이 더 크고({money(recent['realized_pnl'])}) 상위 1건 비중은 {pct(recent['top_wins']['1']['share_of_realized'])}입니다.",
        f"- 상위 5건 종목은 이전 `{', '.join(sorted(p_top))}`, 최근 `{', '.join(sorted(r_top))}`입니다. 겹치는 종목은 {('없습니다' if not (p_top & r_top) else ', '.join(sorted(p_top & r_top)))}.",
        f"- 두 기간 모두 이익이 난 종목은 {len(both_win_sym)}개입니다" + (f" (`{'`, `'.join(both_win_sym)}`)." if both_win_sym else "."),
        f"- 이전 기간 청산 월 순손익 1위는 {prior['by_exit_month'][0]['key']}, 최근은 {recent['by_exit_month'][0]['key']}입니다. 시기가 겹치지 않습니다.",
        "",
        "이 차이는 같은 규칙을 다른 시구간에 적용한 결과의 분포일 뿐입니다. 특정 종목·패턴·월을 늘리거나 줄이는 근거로 쓰지 않습니다.",
        "",
        "## 재현",
        "",
        "이 스크립트는 저장된 export만 읽습니다. 계좌를 다시 실행하지 마세요. 이미 있는 `full-v2/`를 덮어쓰지 마세요.",
        "",
        "```",
        REPLAY["this"],
        "```",
        "",
        "입력 export를 처음부터 다시 만들 때의 명령(참고, 이번 작업에서는 실행하지 않음):",
        "",
        "```",
        *REPLAY["prior"],
        *REPLAY["recent"],
        REPLAY["compare"],
        "```",
        "",
        f"작성 시각(KST) {doc['generated_at_kst']}.",
        "",
    ]
    return "\n".join(lines)


def main() -> None:
    prior = analyze_period("prior")
    recent = analyze_period("recent")
    comparison = SRC / "comparison.md"
    doc = {
        "schema": 1,
        "kind": "historical_account_concentration_v1",
        "assumption": "현재 업종 맵 v2를 과거 두 기간에 같이 적용한 연구 계좌의 종료 거래만 본다. 당시 업종이나 매일 TOP100을 복원한 것이 아니다.",
        "generated_at_kst": datetime.now(timezone(timedelta(hours=9))).strftime("%Y-%m-%d %H:%M %z"),
        "generated_at_utc": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "note": "Concentration of already-closed trades. Excluding a trade here is arithmetic, not a replay without that trade. Unrealized PnL is reported separately and is not added to realized totals. Trade averages are not account returns.",
        "comparison_md": str(comparison.relative_to(ROOT)).replace("\\", "/"),
        "comparison_md_sha256": sha256(comparison),
        "replay": REPLAY,
        "prior": prior,
        "recent": recent,
    }
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "concentration.json").write_text(json.dumps(doc, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    (OUT / "report.md").write_text(write_report(doc), encoding="utf-8", newline="\n")
    print(json.dumps({
        "written": [str(OUT / "concentration.json"), str(OUT / "report.md")],
        "prior": {"closed": prior["closed"], "realized": prior["realized_pnl"], "top1_share_realized": prior["top_wins"]["1"]["share_of_realized"]},
        "recent": {"closed": recent["closed"], "realized": recent["realized_pnl"], "top1_share_realized": recent["top_wins"]["1"]["share_of_realized"]},
        "checks_ok": all(prior["checks"].values()) and all(recent["checks"].values()),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
