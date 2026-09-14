"""Batch replay and development-only selection. Never changes production settings."""
import concurrent.futures, json, pathlib, subprocess, statistics
root = pathlib.Path("data/research")
sources = json.loads((root/"sources.json").read_text())
def replay(s):
    if "file" not in s:
        return {"symbol": s["symbol"], "error": s.get("error", "no data")}
    out = root/(s["symbol"]+"-research")
    p = subprocess.run(["php", "bin/research_strategies.php", "--file="+s["file"],
                        "--symbol="+s["symbol"], "--out="+str(out)], capture_output=True, text=True)
    if p.returncode:
        return {"symbol": s["symbol"], "error": p.stderr or p.stdout}
    return json.loads(pathlib.Path(str(out)+".json").read_text())
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
    rows = list(pool.map(replay, sources))
valid = [r for r in rows if "metrics" in r]
if not valid:
    raise RuntimeError("No successful replays")
modes = list(valid[0]["metrics"]["development"])
scores = {}
for mode in modes:
    dev = [r["metrics"]["development"][mode] for r in valid]
    n = sum(x["closed"] for x in dev)
    scores[mode] = {"closed": n, "symbols_with_trades": sum(x["closed"] > 0 for x in dev),
        "expectancy_pct": sum((x["expectancy_pct"] or 0)*x["closed"] for x in dev)/n if n else None}
eligible = [m for m,s in scores.items() if s["closed"] >= 30 and s["symbols_with_trades"] >= 3 and s["expectancy_pct"] > 0]
selected = max(eligible, key=lambda m: scores[m]["expectancy_pct"]) if eligible else None
summary = {"sources_requested": len(sources), "successful": len(valid),
           "errors": [r for r in rows if "error" in r], "development_scores": scores,
           "selected_on_development_only": selected, "production_change": False,
           "reason": "Research comparison only; no automatic production promotion"}
summary["partition_results"] = {}
for g in ["development","validation","holdout"]:
    summary["partition_results"][g] = {}
    for mode in modes:
        ms = [r["metrics"][g][mode] for r in valid]
        n = sum(m["closed"] for m in ms)
        summary["partition_results"][g][mode] = {
            "closed": n, "orders": sum(m["orders"] for m in ms),
            "expectancy_pct": sum((m["expectancy_pct"] or 0)*m["closed"] for m in ms)/n if n else None,
            "positive_symbols": sum((m["expectancy_pct"] or 0)>0 for m in ms),
            "worst_symbol_closed_trade_drawdown_pct": max((m["closed_trade_drawdown_pct"] or 0) for m in ms)}
(root/"summary.json").write_text(json.dumps(summary, indent=2))
lines = ["# 전략 연구 결과", "", "후보 조기 진입은 연구용입니다. 실시간 추천 조건은 바꾸지 않습니다.", "",
         "| 구간 | 방식 | 완료 거래 | 거래당 평균 순손익(%) | 최악 종목의 청산 기준 낙폭(%) |", "|---|---|---:|---:|---:|"]
for g, modes_ in summary["partition_results"].items():
    for mode, m in modes_.items():
        lines.append(f"| {g} | {mode} | {m['closed']} | {m['expectancy_pct']} | {m['worst_symbol_closed_trade_drawdown_pct']} |")
lines += ["", "development 선택: "+str(selected), "", "미실현 손익을 포함한 포트폴리오 최대 낙폭이 아닙니다.",
          "탈락 사유·후속 움직임은 종목별 JSON, 시점별 근거는 JSONL에 있습니다.",
          "현재 생존 종목 표본 및 과거에 관찰한 기간을 포함하므로 독립적인 미래 수익성 증거가 아닙니다."]
(root/"report.md").write_text("\n".join(lines))
print("RESEARCH_SUMMARY="+json.dumps(summary))
if summary["errors"]:
    print("PARTIAL_DATA="+json.dumps(summary["errors"]))
