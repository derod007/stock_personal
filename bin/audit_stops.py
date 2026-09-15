"""Audit frozen trades without changing fills or production strategy."""
import argparse, collections, datetime as dt, hashlib, json, math, pathlib, statistics
from zoneinfo import ZoneInfo

def valid_ohlc(b):
    return all(isinstance(b.get(k),(float,int)) and math.isfinite(b[k]) and b[k]>0
               for k in ["open","high","low","close"]) and (
        b["low"]<=min(b["open"],b["close"])<=max(b["open"],b["close"])<=b["high"])

def atr14(bars, i):
    if i < 14: return None
    return round(statistics.mean(max(bars[j]["high"]-bars[j]["low"],
        abs(bars[j]["high"]-bars[j-1]["close"]),abs(bars[j]["low"]-bars[j-1]["close"]))
        for j in range(i-13,i+1)),4)

def width_bucket(x):
    if x is None: return "unknown"
    if x < 0: return "nonpositive_risk"
    return "under_0.5_atr" if x<0.5 else ("0.5_to_1_atr" if x<1 else ("1_to_2_atr" if x<2 else "2plus_atr"))

def post_stop(bars, exit_index, partition_end, entry_fill, exit_fill, horizon):
    future=bars[exit_index+1:min(partition_end,exit_index+1+horizon)]
    if len(future)<horizon:
        return {"complete":False,"available_bars":len(future)}
    return {"complete":True,"available_bars":horizon,
        "recovered_entry_on_close":any(b["close"]>=entry_fill for b in future),
        "terminal_above_entry":future[-1]["close"]>=entry_fill,
        "terminal_return_from_exit_pct":(future[-1]["close"]/exit_fill-1)*100,
        "lowest_return_from_exit_pct":(min(b["low"] for b in future)/exit_fill-1)*100}

def ambiguity(t, bars, index, stop, target, trailing):
    flags=[]
    if t.get("ambiguous_bar"): flags.append("simulator_ambiguous")
    if t["status"]!="closed": return flags
    e=index[t["entry_at"]]; x=index[t["exit_at"]]; b=bars[x]
    # Same-day entry/stop alone is not ambiguous: a buy limit above the stop is crossed first.
    if not trailing and b["low"]<=stop and b["high"]>=target and stop<b["open"]<target:
        flags.append("stop_target_intraday_order_unknown")
    return sorted(set(flags))

def summarize(rows):
    closed=[r for r in rows if r["status"]=="closed"]
    ratios=[r["fill_risk_atr"] for r in rows if r.get("fill_risk_atr") is not None]
    stops=[r for r in closed if r["first_exit"]=="stop"]
    clean=[r for r in closed if not r["ambiguity_flags"]]
    mean=lambda xs:statistics.mean(xs) if xs else None
    out={"orders":len(rows),"closed":len(closed),"filled":sum(r["filled"] for r in rows),
         "stop_exits":len(stops),"same_day_entry_stop":sum(r["entry_at"]==r["exit_at"] for r in stops),
         "ambiguity_flagged_orders":sum(bool(r["ambiguity_flags"]) for r in rows),
         "mean_net_pct":mean([r["net_return_pct"] for r in closed]),
         "unflagged_closed_count":len(clean),
         "unflagged_mean_net_pct":mean([r["net_return_pct"] for r in clean]),
         "median_fill_risk_atr":statistics.median(ratios) if ratios else None,
         "width_buckets":dict(collections.Counter(width_bucket(r["fill_risk_atr"]) for r in rows if r["filled"])),
         "post_stop":{}}
    out["by_width"]={}
    for bucket in sorted(set(width_bucket(r["fill_risk_atr"]) for r in rows if r["filled"])):
        sample=[r for r in closed if width_bucket(r["fill_risk_atr"])==bucket]
        out["by_width"][bucket]={"closed":len(sample),
            "stop_exits":sum(r["first_exit"]=="stop" for r in sample),
            "early_stop_3_bars":sum(r["first_exit"]=="stop" and r["bars"]<=3 for r in sample),
            "mean_net_pct":mean([r["net_return_pct"] for r in sample])}
    for h in [5,10,20]:
        labels=[r["post_stop"][str(h)] for r in stops]
        valid=[x for x in labels if x["complete"]]
        out["post_stop"][str(h)]={"eligible":len(valid),"censored":len(labels)-len(valid),
            "recovered_entry_on_close":sum(x["recovered_entry_on_close"] for x in valid),
            "terminal_above_entry":sum(x["terminal_above_entry"] for x in valid),
            "mean_terminal_return_from_exit_pct":mean([x["terminal_return_from_exit_pct"] for x in valid]),
            "mean_lowest_return_from_exit_pct":mean([x["lowest_return_from_exit_pct"] for x in valid])}
    return out

def audit(root):
    sources={s["symbol"]:s for s in json.loads((root/"sources.json").read_text())}
    details=[]; quality=[]; groups=collections.defaultdict(list)
    for path in sorted(root.glob("*-research.json")):
        research=json.loads(path.read_text()); symbol=research["symbol"]
        raw=(root/(symbol+".json")).read_bytes()
        if hashlib.sha256(raw).hexdigest()!=sources[symbol]["sha256"]:
            raise ValueError("Frozen input hash mismatch: "+symbol)
        raw_bars=json.loads(raw); bars=[]; invalid=[]; timezone=ZoneInfo("Asia/Seoul" if symbol.endswith(".KS") else "America/New_York")
        date=lambda stamp:dt.datetime.fromtimestamp(stamp,timezone).date()
        seen=set(); jumps=[]
        for i,b in enumerate(raw_bars):
            if not all(isinstance(b[k],(float,int)) and math.isfinite(b[k]) for k in ["time","open","high","low","close","volume"]):
                raise ValueError("Invalid numeric bar: "+symbol)
            if not valid_ohlc(b):
                invalid.append({"date":str(date(b["time"])),"ohlc":{k:b[k] for k in ["open","high","low","close"]}})
                continue
            if b["volume"]<0: raise ValueError("Negative volume: "+symbol)
            day=date(b["time"])
            if day in seen or (bars and bars[-1]["time"]>=b["time"]): raise ValueError("Duplicate/unsorted session: "+symbol)
            seen.add(day)
            if bars and abs(b["close"]/bars[-1]["close"]-1)>=0.3:
                jumps.append({"date":str(day),"close_change_pct":(b["close"]/bars[-1]["close"]-1)*100})
            bars.append(b)
        sessions={date(b["time"]):i for i,b in enumerate(bars)}
        audit_rows=[json.loads(x) for x in pathlib.Path(str(path)[:-5]+".jsonl").read_text().splitlines()]
        by_stamp={r["asof"]:sessions[date(r["asof"])] for r in audit_rows}
        n=by_stamp[research["to"]]+1
        if n!=research["bars"]: raise ValueError("Completed bars do not align: "+symbol)
        cuts={"development":by_stamp[research["validation_from"]],
              "validation":by_stamp[research["holdout_from"]],"holdout":n}
        for row in audit_rows:
            signal=row["asof"]; i=by_stamp[signal]; atr=atr14(bars,i)
            for mode,t in (row["orders"] or {}).items():
                if "filled" not in t: continue
                if mode.startswith("candidate"): levels=row["candidate"]; planned=levels["mid"]
                else:
                    patterns=row["diagnostics"]["patterns"].values()
                    levels=next(p for p in patterns if p["version"]==row["diagnostics"]["selected_pattern"])
                    planned=levels["entry"]
                stop=levels["stop"]; target=levels["target"]
                ratio=(t["entry_fill"]-stop)/atr if t["filled"] and atr and atr>0 else None
                d={"symbol":symbol,"partition":row["partition"],"mode":mode,"signal_at":signal,
                   "atr14_at_signal":atr,"planned_entry":planned,"initial_stop":stop,
                   "planned_risk_atr":(planned-stop)/atr if atr and atr>0 else None,
                   "fill_risk_atr":ratio,"initial_stop_scope":"initial stop, not final trailing stop",
                   **t,"ambiguity_flags":ambiguity(t,bars,by_stamp,stop,target,mode.endswith("trailing")),
                   "post_stop":{}}
                if t["status"]=="closed" and t["first_exit"]=="stop":
                    for h in [5,10,20]:
                        d["post_stop"][str(h)]=post_stop(bars,by_stamp[t["exit_at"]],cuts[row["partition"]],
                                                       t["entry_fill"],t["exit_fill"],h)
                details.append(d);groups[(row["partition"],mode)].append(d)
        quality.append({"symbol":symbol,"hash_verified":True,"ohlcv_valid":not invalid,"excluded_invalid_ohlc":invalid,"frozen_bars":len(raw_bars),
            "completed_bars":n,"large_close_jumps":jumps,
            "corporate_action_verification":"unknown: frozen source did not retain adjusted closes or events"})
    if not details: raise ValueError("No audited orders")
    summary={"groups":{},"quality":quality,"production_change":False,
             "scope":"Initial risk uses signal-time ATR; recovery labels exclude exit day and stay within partition. Unflagged subset is not an alternative strategy."}
    for (g,m),rows in groups.items():summary["groups"].setdefault(g,{})[m]=summarize(rows)
    (root/"stop-audit.json").write_text(json.dumps(summary,indent=2))
    (root/"stop-audit-trades.json").write_text(json.dumps(details,indent=2))
    lines=["# 손절폭·손절 후 경로·체결 불확실성", "",
       "손절폭은 실제 체결가에서 최초 손절선까지 거리 / 신호 시점 ATR14입니다. 추적 손절의 최종 손절폭이 아닙니다.", "",
       "| 구간 | 방식 | 체결 수 | 손절 수 | 당일 손절 | 손절폭 ATR 중앙값 | 불확실성 표시 주문 |",
       "|---|---|---:|---:|---:|---:|---:|"]
    for g,modes in summary["groups"].items():
        for mode,s in modes.items():
            lines.append(f"| {g} | {mode} | {s['filled']} | {s['stop_exits']} | {s['same_day_entry_stop']} | {s['median_fill_risk_atr']} | {s['ambiguity_flagged_orders']} |")
    lines += ["", "## 손절 이후 종가 회복", "", "| 구간 | 방식 | 관찰 봉 | 관찰 가능 | 경계 제외 | 진입가 회복 | 마지막 종가도 진입가 이상 |",
              "|---|---|---:|---:|---:|---:|---:|"]
    for g,modes in summary["groups"].items():
        for mode,s in modes.items():
            for h,p in s["post_stop"].items():
                lines.append(f"| {g} | {mode} | {h} | {p['eligible']} | {p['censored']} | {p['recovered_entry_on_close']} | {p['terminal_above_entry']} |")
    lines += ["", "같은 날 체결·손절됐다는 사실만으로 순서 불명은 아닙니다. 정상적인 연속 가격에서는 매수 지정가를 거쳐 더 낮은 손절가에 도달합니다.",
              "불확실성 표시는 일봉 모델의 일부 한계만 포착하며 갭·유동성·부분체결을 검증하지 않습니다.",
              "손절 후 회복은 손절을 없애거나 넓혔을 때의 순수익이 아닙니다. 그 사이 추가 하락·자금 점유·새 거래 기회가 달라집니다.",
              "원본 해시와 OHLC 구조를 검사했습니다. 유효하지 않은 OHLC는 기존 CandleClock과 동일하게 제외하고 목록을 남깁니다. 기존 원본에 수정종가·기업행위가 없어 액면분할/배당 보정의 적정성은 확인되지 않았습니다.",
              "30% 이상 종가 변화는 조사 대상이며 기업행위 오류라고 단정하지 않습니다.",
              "세부 분포와 불확실성 표시 제외 평균은 JSON에 있습니다. 제외 평균은 선택된 하위집합 통계이며 개선된 전략 수익률이 아닙니다."]
    (root/"stop-audit.md").write_text("\n".join(lines))
    print("STOP_AUDIT="+json.dumps(summary))
    return summary

if __name__=="__main__":
    p=argparse.ArgumentParser();p.add_argument("--root",default="data/research")
    audit(pathlib.Path(p.parse_args().root))
