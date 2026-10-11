"""Compare cash-fee sensitivity runs. Does not write operational files or full-v2."""
from __future__ import annotations

import hashlib
import json
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FULL = ROOT / "docs/historical-account-replay/full-v2"
OUT = ROOT / "docs/historical-account-replay/fee-sensitivity"
KST = timezone(timedelta(hours=9))
INITIAL = 100_000_000.0
LABELS = ("fee10", "fee20", "fee30")
FEE_BPS = {"fee10": 10, "fee20": 20, "fee30": 30}
PERIODS = ("prior", "recent")
CODE = [
    "src/PaperPortfolio.php",
    "src/TradeSimulator.php",
    "bin/paper/AccountReplay.php",
    "bin/paper_account_replay.php",
    "tests/account_replay.php",
    "config/paper-kr-recovery-v1.json",
    "config/paper-history-kr-recovery-v1.json",
    "docs/historical-account-replay/sector-map-v2.json",
]
DATASETS = {
    "prior": Path(r"C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\kr-saved-scan-20251008"),
    "recent": Path(r"C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\kr-saved-scan-20261009"),
}
STATE = Path(r"C:\Users\acdun\Desktop\dev\stock-personal-paper\fee-sensitivity-state")
COMMANDS = {
    "run": (
        "php -d memory_limit=3G bin/paper_account_replay.php run "
        "--period={period} --dataset-dir={dataset} --state-dir={state} "
        "--sector-map=docs/historical-account-replay/sector-map-v2.json "
        "--confirm-full-run=after-code-review --fee-bps={bps} --account-suffix=fee{bps}"
    ),
    "export": (
        "php bin/paper_account_replay.php export "
        "--period={period} --dataset-dir={dataset} --state-dir={state} "
        "--fee-bps={bps} --account-suffix=fee{bps} "
        "--out=docs/historical-account-replay/fee-sensitivity/{period}/fee{bps}"
    ),
}


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def load(period: str, lab: str) -> dict:
    folder = OUT / period / lab
    return {
        "summary": json.loads((folder / "summary.json").read_text(encoding="utf-8")),
        "trades": json.loads((folder / "trades.json").read_text(encoding="utf-8"))["trades"],
        "reconcile": json.loads((folder / "reconcile.json").read_text(encoding="utf-8")),
        "provenance": json.loads((folder / "provenance.json").read_text(encoding="utf-8")),
    }


def near(a: float, b: float, abs_tol: float = 0.01) -> bool:
    return abs(a - b) <= max(abs_tol, 1e-9 * max(abs(a), abs(b)))


def fees(trades: list[dict], bps: int) -> dict:
    rate = bps / 10000
    buy = sum(float(t["fill"]["fee"]) for t in trades if t.get("fill"))
    sell = sum(float(t["quantity"]) * float(t["exit"]["price"]) * rate for t in trades if t.get("exit"))
    return {"buy": buy, "sell": sell, "total": buy + sell, "fee_bps": bps}


def key(t: dict) -> tuple[str, str]:
    return t["symbol"], t["signal_date"]


def slim(t: dict) -> dict:
    return {k: t[k] for k in ("symbol", "signal_date", "quantity", "entry", "stop", "target", "fill", "exit", "cancelled", "status")}


def trade_diff(base: list[dict], other: list[dict]) -> dict:
    a = {key(t): t for t in base}
    b = {key(t): t for t in other}
    dropped = [slim(a[k]) for k in sorted(set(a) - set(b))]
    added = [slim(b[k]) for k in sorted(set(b) - set(a))]
    qty = []
    down = up = same = 0
    for k in sorted(set(a) & set(b)):
        qa, qb = a[k]["quantity"], b[k]["quantity"]
        if qa == qb:
            same += 1
            continue
        if qb < qa:
            down += 1
        else:
            up += 1
        qty.append({
            "symbol": k[0], "signal_date": k[1],
            "base_qty": qa, "other_qty": qb, "delta": qb - qa,
            "base_status": a[k]["status"], "other_status": b[k]["status"],
        })
    return {
        "same_orders": len(set(a) & set(b)),
        "dropped": dropped, "added": added,
        "quantity_changed": qty,
        "quantity_down": down, "quantity_up": up, "quantity_same": same,
    }


def snapshot(doc: dict, bps: int) -> dict:
    s = doc["summary"]
    eq = float(s["equity"])
    f = fees(doc["trades"], bps)
    return {
        "account": s["account"],
        "fee_bps": bps,
        "equity": eq,
        "return_pct": (eq / INITIAL - 1) * 100,
        "max_close_drawdown": float(s["max_close_drawdown"]),
        "realized_pnl": float(s["realized_pnl"]),
        "unrealized_pnl_open_positions": float(s["unrealized_pnl_open_positions"]),
        "unrealized_mark": "last completed close, after modeled sell cash fee",
        "cash": float(s["cash"]),
        "reserved_cash": float(s["reserved_cash"]),
        "fees": f,
        "orders": s["event_counts"].get("order", 0),
        "fills": s["event_counts"].get("fill", 0),
        "exits": s["event_counts"].get("exit", 0),
        "cancelled": s["event_counts"].get("order_cancelled", 0),
        "closed": s["closed_trades"],
        "open": len(s["open_positions"]),
        "pending": len(s["pending_orders"]),
        "halted": s["halted"],
        "halt_reason": s.get("halt_reason"),
        "reconcile_pass": doc["reconcile"]["pass"],
        "last_day": s["last_day"],
    }


def baseline_vs_full(period: str, doc: dict) -> dict:
    full_s = json.loads((FULL / period / "summary.json").read_text(encoding="utf-8"))
    full_t = json.loads((FULL / period / "trades.json").read_text(encoding="utf-8"))["trades"]
    full_p = json.loads((FULL / period / "provenance.json").read_text(encoding="utf-8"))
    s = doc["summary"]
    money_keys = (
        "cash", "reserved_cash", "equity", "realized_pnl",
        "unrealized_pnl_open_positions", "max_close_drawdown",
    )
    money_ok = {k: near(float(full_s[k]), float(s[k])) for k in money_keys}
    count_ok = {
        "event_counts": full_s["event_counts"] == s["event_counts"],
        "closed_trades": full_s["closed_trades"] == s["closed_trades"],
        "last_day": full_s["last_day"] == s["last_day"],
        "halted": full_s["halted"] == s["halted"],
        "trades": [slim(t) for t in full_t] == [slim(t) for t in doc["trades"]],
    }
    return {
        "economic_match": all(money_ok.values()) and all(count_ok.values()),
        "money": money_ok,
        "counts": count_ok,
        "metadata_differs": {
            "account_id": {"full_v2": full_s["account"], "this_run": s["account"]},
            "config_hash": {"full_v2": full_p["config_hash"], "this_run": doc["provenance"]["config_hash"]},
            "strategy_fingerprint": {
                "full_v2": full_p["strategy_fingerprint"],
                "this_run": doc["provenance"]["strategy_fingerprint"],
            },
            "journal_normalized_sha256": {
                "full_v2": full_p.get("journal_normalized_sha256"),
                "this_run": doc["provenance"].get("journal_normalized_sha256"),
            },
            "why": [
                "account id has a fee10 suffix so the run cannot write the original research folder",
                "config_hash includes that account id",
                "PaperPortfolio now reads an optional fee_bps (default 10); that file is in the strategy fingerprint",
                "AccountReplay records cash_fee_bps and slippage_bps in research metadata",
            ],
        },
    }


def money(n: float | None) -> str:
    return "—" if n is None else f"{n:,.2f}"


def pct(n: float) -> str:
    return f"{n:.4f}%"


def write_report(doc: dict) -> None:
    lines = [
        "# 현금 거래비용 민감도",
        "",
        "슬리피지 5bp는 그대로 두고, 현금에서 빼는 매수·매도 비용만 각 10bp / 20bp / 30bp로 바꿨습니다. 끝난 거래에서 비용만 뺀 것이 아니라 계좌를 처음부터 다시 진행했습니다.",
        "",
        "운영 계좌·캐시·원본 데이터셋·기존 full-v2는 쓰지 않았습니다. 일봉 범위 밖 모형 가격 9건도 보정하지 않았습니다.",
        "",
        "## 실행 전 검증",
        "",
        f"- 기존 `tests/account_replay.php`와 추가한 비용 고정 사례: 통과. 20bp는 수량이 줄고 예약 현금이 늘며, 30bp는 10bp에서 들어가던 1주를 거절합니다. 매수·매도 비용은 한 번씩만 붙고 슬리피지를 현금으로 다시 빼지 않습니다. 같은 계좌를 다시 돌리면 사건이 늘지 않습니다. 수수료가 다른 설정으로 이어 하면 `Account config changed`로 거절됩니다.",
        f"- 기준 10bp 재현 대 full-v2: 이전·최근 모두 주문·체결·청산·수량·현금·평가금액이 같습니다. "
        f"이전 통과={doc['baseline_vs_full']['prior']['economic_match']}, 최근 통과={doc['baseline_vs_full']['recent']['economic_match']}.",
        "- 달라진 메타데이터: 계좌 ID에 `fee10` 접미사가 붙어 config_hash가 바뀌고, `PaperPortfolio.php` 변경으로 전략 지문이 바뀌며, 연구 메타에 `cash_fee_bps`/`slippage_bps`가 들어갑니다. 경제 숫자는 그대로입니다.",
        "",
        "## 기간별 비교",
        "",
    ]
    for period, title in (("prior", "이전 (2024-10-08–2025-10-02)"), ("recent", "최근 (2025-10-08–2026-10-08)")):
        rows = doc["periods"][period]
        lines += [
            f"### {title}",
            "",
            "| 항목 | 각 10bp (기준) | 각 20bp | 각 30bp |",
            "|---|---:|---:|---:|",
        ]
        def col(key, fmt=money):
            return [fmt(rows[lab][key]) for lab in LABELS]
        def add(label, key, fmt=money):
            a, b, c = col(key, fmt)
            lines.append(f"| {label} | {a} | {b} | {c} |")
        add("최종 총자산", "equity")
        add("변화율", "return_pct", pct)
        add("종가 최대 낙폭", "max_close_drawdown", lambda n: f"{n*100:.4f}%")
        add("실현손익", "realized_pnl")
        add("미실현손익", "unrealized_pnl_open_positions")
        add("부과 비용 합계", "fee_total")
        add("주문", "orders", str)
        add("체결", "fills", str)
        add("종료", "closed", str)
        add("취소", "cancelled", str)
        add("보유", "open", str)
        add("대기", "pending", str)
        lines += [
            f"| 정지 | {'아니오' if not rows['fee10']['halted'] else '예'} | {'아니오' if not rows['fee20']['halted'] else '예'} | {'아니오' if not rows['fee30']['halted'] else '예'} |",
            f"| 장부 대사 | {'통과' if rows['fee10']['reconcile_pass'] else '실패'} | {'통과' if rows['fee20']['reconcile_pass'] else '실패'} | {'통과' if rows['fee30']['reconcile_pass'] else '실패'} |",
            "",
            "미실현은 마지막 완료 종가에 매도 현금 비용을 붙인 값입니다. 강제 청산은 없습니다.",
            "",
        ]
        for lab in ("fee20", "fee30"):
            d = doc["diffs"][period][lab]
            lines += [
                f"**기준 대비 {FEE_BPS[lab]}bp**",
                "",
                f"같은 신호 {d['same_orders']}건. 수량 감소 {d['quantity_down']}, 증가 {d['quantity_up']}, 동일 {d['quantity_same']}. "
                f"빠진 주문 {len(d['dropped'])}건, 새로 생긴 주문 {len(d['added'])}건.",
                "",
            ]
            if d["dropped"] or d["added"]:
                for t in d["dropped"]:
                    lines.append(f"- 빠짐 `{t['symbol']}` 신호일 {t['signal_date']} 수량 {t['quantity']} {t['status']}")
                for t in d["added"]:
                    lines.append(f"- 추가 `{t['symbol']}` 신호일 {t['signal_date']} 수량 {t['quantity']} {t['status']}")
                lines += [
                    "",
                    "이전 기간 30bp에서만 거래 구성이 바뀌었습니다. 2025-03-05에 기준은 `005290.KQ`·`034020.KS` 뒤에 남은 여력으로 `092870.KQ` 1주를 넣었습니다. 30bp는 앞 두 주문의 예약이 커져 그 1주를 못 넣습니다. 그 자리와 이후 현금이 달라져 2025-03-10 `067310.KQ`가 들어가고, 2025-03-12 `007810.KS`는 빠지며, 2025-03-21 `015760.KS`가 들어옵니다.",
                    "",
                ]
            else:
                lines.append("주문 목록은 같고 수량만 달라졌습니다. 수량이 늘어난 건은 앞선 거래의 현금·평가가 달라져 나중에 여유가 생긴 경우입니다. 결과를 맞추려고 전략을 바꾸지 않았습니다.")
                lines.append("")
            if d["quantity_changed"]:
                lines.append("수량 변화 일부:")
                for row in d["quantity_changed"][:8]:
                    lines.append(
                        f"- `{row['symbol']}` 신호일 {row['signal_date']}: {row['base_qty']} → {row['other_qty']}"
                    )
                if len(d["quantity_changed"]) > 8:
                    lines.append(f"- 나머지 {len(d['quantity_changed']) - 8}건은 comparison.json")
                lines.append("")
    lines += [
        "## 재현",
        "",
        "상태 경로는 데이터셋(`history-research`) 밖이어야 합니다.",
        "",
        "```",
        COMMANDS["run"].format(
            period="prior",
            dataset=DATASETS["prior"],
            state=STATE,
            bps=10,
        ),
        COMMANDS["export"].format(
            period="prior",
            dataset=DATASETS["prior"],
            state=STATE,
            bps=10,
        ),
        "```",
        "",
        "최근 기간과 `--fee-bps=20` / `30`도 같습니다. 비교표는 `python docs/research/account-replay-fee-sensitivity.py` 입니다.",
        "",
        f"작성 시각 {doc['generated_at_kst']}.",
        "",
    ]
    (OUT / "report.md").write_text("\n".join(lines), encoding="utf-8", newline="\n")


def main() -> None:
    hashes = {p: sha256(ROOT / p) for p in CODE}
    hashes["datasets"] = {
        name: {
            "dir": str(path),
            "dataset.json": sha256(path / "dataset.json") if (path / "dataset.json").is_file() else None,
        }
        for name, path in DATASETS.items()
    }
    periods = {}
    diffs = {}
    vs_full = {}
    for period in PERIODS:
        docs = {lab: load(period, lab) for lab in LABELS}
        vs_full[period] = baseline_vs_full(period, docs["fee10"])
        periods[period] = {}
        diffs[period] = {}
        for lab in LABELS:
            snap = snapshot(docs[lab], FEE_BPS[lab])
            snap["fee_total"] = snap["fees"]["total"]
            periods[period][lab] = snap
        for lab in ("fee20", "fee30"):
            diffs[period][lab] = trade_diff(docs["fee10"]["trades"], docs[lab]["trades"])
        if not vs_full[period]["economic_match"]:
            raise SystemExit(f"baseline {period} does not match full-v2")

    out = {
        "schema": 1,
        "kind": "historical_account_cash_fee_sensitivity_v1",
        "generated_at_kst": datetime.now(KST).strftime("%Y-%m-%d %H:%M %z"),
        "note": "Cash fee only. Slippage stays 5bp. Accounts were rerun from 100,000,000 KRW. full-v2 was not rewritten.",
        "state_dir": str(STATE),
        "commands": COMMANDS,
        "baseline_vs_full": vs_full,
        "periods": periods,
        "diffs": diffs,
        "hashes": hashes,
    }
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "comparison.json").write_text(json.dumps(out, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")
    write_report(out)
    print(json.dumps({
        "written": str(OUT),
        "baseline_match": {p: vs_full[p]["economic_match"] for p in PERIODS},
        "equity": {p: {lab: periods[p][lab]["equity"] for lab in LABELS} for p in PERIODS},
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
