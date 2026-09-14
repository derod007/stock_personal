"""Descriptive diagnostics on frozen v3 artifacts. Forward labels are not trading returns."""
import argparse, collections, json, pathlib, statistics

def classify(trade, bars):
    if trade["status"] != "closed" or trade["net_return_pct"] >= 0:
        return None
    # Exclude fill/exit candles: daily OHLC does not establish intraday chronology.
    inside = [b for b in bars if trade["entry_at"] < b["available_at"] < trade["exit_at"]]
    entry = trade["entry_fill"]
    peak_close = max([entry]+[b["close"] for b in inside])
    gain = (peak_close/entry-1)*100
    if gain >= 2:
        category = "gave_back_2pct_close_gain"
    elif trade["first_exit"] == "stop" and trade["bars"] <= 3:
        category = "early_stop_within_3_bars"
    elif trade["first_exit"] == "time":
        category = "time_exit_loss"
    else:
        category = "other_loss"
    return {"category": category, "prior_close_gain_pct": gain,
            "interior_sessions": len(inside), "net_return_pct": trade["net_return_pct"]}

def analyze(root):
    counts = collections.defaultdict(collections.Counter)
    losses = collections.defaultdict(list)
    rejected = collections.defaultdict(list)
    examples = []
    files = sorted(root.glob("*-research.json"))
    if not files: raise RuntimeError("No frozen research inputs")
    for path in files:
        r = json.loads(path.read_text())
        # Audit rows provide exchange-aware completed session timestamps and candidates.
        audit = [json.loads(line) for line in pathlib.Path(str(path)[:-5]+".jsonl").read_text().splitlines()]
        source = json.loads((root/(r["symbol"]+".json")).read_text())
        # Match source and completed audit timestamps using the exchange-local session date.
        import datetime as dt
        from zoneinfo import ZoneInfo
        tz = ZoneInfo("Asia/Seoul" if r["symbol"].endswith(".KS") else "America/New_York")
        by_date = {dt.datetime.fromtimestamp(row["asof"],tz).date(): row["asof"] for row in audit}
        bars = []
        for b in source:
            day = dt.datetime.fromtimestamp(b["time"],tz).date()
            if day in by_date:
                bars.append(dict(b, available_at=by_date[day]))
        for row in audit:
            g = row["partition"]
            raw = row["diagnostics"]
            counts[g]["final:"+raw["final_status"]] += 1
            label = row.get("excluded_forward_label")
            if label is not None:
                rejected[(g,"final:"+raw["final_status"])].append(label["close_return_10_pct"])
            for pattern, p in raw["patterns"].items():
                counts[g][pattern+":"+p["status"]] += 1
                for gate, passed in p.get("gates",{}).items():
                    key = pattern+":"+gate+":"+("pass" if passed else "fail")
                    counts[g][key] += 1
                    if label is not None: rejected[(g,key)].append(label["close_return_10_pct"])
        for g, modes in r["trades"].items():
            for mode, trades in modes.items():
                for t in trades:
                    loss = classify(t,bars)
                    if loss:
                        losses[(g,mode)].append(loss)
                        examples.append(dict(loss,symbol=r["symbol"],partition=g,mode=mode,
                                             entry_at=t["entry_at"],exit_at=t["exit_at"]))
    out = {"symbols":len(files), "stage_counts":dict(counts), "losses":{}, "rejected":[],
           "production_change":False,
           "scope":"Descriptive association, not causal filter effect; overlapping excluded candidate days"}
    for (g,mode), rows in losses.items():
        out["losses"].setdefault(g,{})[mode] = {
            "n":len(rows),"categories":dict(collections.Counter(x["category"] for x in rows)),
            "avg_net_return_pct":statistics.mean(x["net_return_pct"] for x in rows)}
    for (g,key), values in rejected.items():
        out["rejected"].append({"partition":g,"reason":key,"candidate_days":len(values),
            "mean_forward_close_10_pct":statistics.mean(values),
            "positive_fraction":sum(x>0 for x in values)/len(values)})
    dev = out["losses"].get("development",{}).get("candidate_fixed",{})
    out["development_focus"] = max(dev.get("categories",{}),key=dev.get("categories",{}).get) if dev.get("categories") else None
    (root/"diagnosis.json").write_text(json.dumps(out,indent=2))
    (root/"loss-examples.json").write_text(json.dumps(examples,indent=2))
    lines=["# 탈락 및 손실 분석", "", "후속 움직임은 겹치는 후보 날짜의 관찰치이며 체결 수익률이나 필터의 인과 효과가 아닙니다.", "",
           "## 손실 분류", "", "| 구간 | 방식 | 손실 거래 | 분류별 건수 |", "|---|---|---:|---|"]
    for g,modes in out["losses"].items():
        for mode,v in modes.items():
            lines.append(f"| {g} | {mode} | {v['n']} | {v['categories']} |")
    lines += ["", "## 제외 사유별 이후 10봉", "", "| 구간 | 사유 | 후보 날짜 수 | 평균 종가 변화(%) |", "|---|---|---:|---:|"]
    for r in out["rejected"]:
        lines.append(f"| {r['partition']} | {r['reason']} | {r['candidate_days']} | {r['mean_forward_close_10_pct']:.3f} |")
    lines += ["", "손실 분류는 체결일과 청산일의 고가·저가를 제외하고 중간 거래일 종가만 사용합니다.",
              "2% 이상 종가 상승 후 손실 → 3봉 이내 손절 → 시간 청산 손실 → 기타 순서로 상호 배타 분류합니다.",
              "2%·3봉은 사전 고정한 진단용 기준으로 최적화된 전략 조건이 아닙니다.",
              "기회 누락과 손실 회피를 판정하려면 같은 후보의 주문 체결을 비교하는 추가 실험이 필요합니다."]
    (root/"diagnosis.md").write_text("\n".join(lines))
    print("DIAGNOSIS="+json.dumps({k:v for k,v in out.items() if k != "rejected"}))
    print("REJECTED="+json.dumps([r for r in out["rejected"] if r["partition"]=="development"]))
    return out

if __name__ == "__main__":
    p=argparse.ArgumentParser();p.add_argument("--root",default="data/research")
    analyze(pathlib.Path(p.parse_args().root))
