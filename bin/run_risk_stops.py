import argparse, concurrent.futures, json, pathlib, subprocess
p=argparse.ArgumentParser();p.add_argument("--root",default="data/research");args=p.parse_args()
root=pathlib.Path(args.root)
sources=json.loads((root/"sources.json").read_text())
def replay(s):
    if "file" not in s:return {"symbol":s["symbol"],"error":"missing source"}
    result_file=root/(s["symbol"]+"-research.json")
    if not result_file.exists():
        q=subprocess.run(["php","bin/research_strategies.php","--file="+s["file"],"--symbol="+s["symbol"],
                         "--out="+str(root/(s["symbol"]+"-research"))],text=True,capture_output=True)
        if q.returncode:return {"symbol":s["symbol"],"error":q.stderr or q.stdout}
    q=subprocess.run(["php","bin/compare_risk_stops.php","--root="+str(root),"--symbol="+s["symbol"]],
                      text=True,capture_output=True)
    if q.returncode:return {"symbol":s["symbol"],"error":q.stderr or q.stdout}
    return json.loads((root/(s["symbol"]+"-risk.json")).read_text())
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:rows=list(pool.map(replay,sources))
valid=[r for r in rows if "metrics" in r];errors=[r for r in rows if "error" in r]
if not valid:raise RuntimeError(str(errors))
out={"successful_symbols":[r["symbol"] for r in valid],"errors":errors,"groups":{},"production_change":False}
for g in ["development","validation","holdout"]:
    out["groups"][g]={}
    for mode in ["structure","atr_floor_1","skip_under_1_atr"]:
        ms=[r["metrics"][g][mode] for r in valid];n=sum(x["closed"] for x in ms)
        out["groups"][g][mode]={"closed":n,"orders":sum(x["orders"] for x in ms),
            "mean_net_r":sum((x["mean_net_r"] or 0)*x["closed"] for x in ms)/n if n else None,
            "win_rate":sum(x["wins"] for x in ms)/n if n else None,
            "positive_symbols":sum((x["mean_net_r"] or 0)>0 for x in ms),
            "gap_losses_over_1r":sum(x["gap_losses_over_1r"] for x in ms)}
out["candidate_counts"]={}
for r in valid:
    for g,cs in r["candidate_counts"].items():
        for k,v in cs.items():out["candidate_counts"].setdefault(g,{})[k]=out["candidate_counts"].setdefault(g,{}).get(k,0)+v
(root/"risk-summary.json").write_text(json.dumps(out,indent=2))
lines=["# 동일 위험 예산 손절 비교", "", "1R=거래당 계획 손실 예산 100 통화단위. 시장별 통화가 다르므로 금액을 합산하지 않습니다.", "",
       "| 구간 | 방식 | 주문 | 완료 | 평균 순손익 R | 승률 | 1R 초과 손실 |",
       "|---|---|---:|---:|---:|---:|---:|"]
for g,ms in out["groups"].items():
    for mode,m in ms.items():
        lines.append(f"| {g} | {mode} | {m['orders']} | {m['closed']} | {m['mean_net_r']} | {m['win_rate']} | {m['gap_losses_over_1r']} |")
lines+=["", "분수 수량을 사용하는 연구 모델이며 실제 주문 수량/계좌 수익률이 아닙니다.",
        "모든 방식은 공통 후보/자금 한도 조건을 적용합니다. 보유 기간 차이로 이후 체결 후보는 달라질 수 있습니다.",
        "손절폭 확장 후 RR 기준을 재적용하지 않아 손절 자체의 효과를 비교합니다. 목표가도 그대로 유지합니다.",
        "기존 기간은 재사용 연구이고 새 종목도 같은 시장 기간을 공유하므로 완전한 독립 검증이 아닙니다."]
(root/"risk-report.md").write_text("\n".join(lines))
print("RISK_COMPARISON="+json.dumps({"root":str(root),**out}))
