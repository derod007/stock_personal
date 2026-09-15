"""Integration check on already-built replay accounts. Uses disposable modified copies."""
import hashlib, json, os, pathlib, shutil, subprocess, tempfile
state=pathlib.Path(os.environ["PAPER_STATE_DIR"])
before=(state/"paper-us-replay.json").read_bytes()
cmd=["php","bin/paper_account.php","--config=config/paper-us.json","--data=data/research","--mode=replay"]
subprocess.run(cmd,check=True)
assert (state/"paper-us-replay.json").read_bytes()==before,"Duplicate update changed journal"
d=json.loads(before)
snapshots=[e for e in d["events"] if e["type"]=="snapshot"]
assert snapshots and all(e["payload"]["origin"]=="replay" for e in snapshots)
assert all(e["payload"]["recorded_at"]>=e["payload"]["session"] for e in snapshots)
assert all(p["cash"]+1e-6>=p["reserved_cash"] for p in d["state"]["equity"])
assert len(d["state"]["active"])<=d["state"]["config"]["max_positions"]
with tempfile.TemporaryDirectory() as tmp:
    tmp=pathlib.Path(tmp);source=tmp/"source";shutil.copytree("data/research",source)
    target=tmp/"state";target.mkdir();(target/"paper-us-replay.json").write_bytes(before)
    bars=json.loads((source/"MU.json").read_text())
    # A rolling-window truncation must not erase old input or halt the account.
    cropped=json.dumps(bars[10:]).encode()
    (source/"MU.json").write_bytes(cropped)
    meta=json.loads((source/"sources.json").read_text())
    for m in meta:
        if m["symbol"]=="MU":m["sha256"]=hashlib.sha256(cropped).hexdigest()
    (source/"sources.json").write_text(json.dumps(meta))
    env=dict(os.environ,PAPER_STATE_DIR=str(target))
    subprocess.run(["php","bin/paper_account.php","--config=config/paper-us.json","--data="+str(source),"--mode=replay"],env=env,check=True)
    assert (target/"paper-us-replay.json").read_bytes()==before,"Rolling window changed frozen history"
    bars[0]["volume"]+=1
    raw=json.dumps(bars).encode();(source/"MU.json").write_bytes(raw)
    meta=json.loads((source/"sources.json").read_text())
    for m in meta:
        if m["symbol"]=="MU":m["sha256"]=hashlib.sha256(raw).hexdigest()
    (source/"sources.json").write_text(json.dumps(meta))
    env=dict(os.environ,PAPER_STATE_DIR=str(target))
    subprocess.run(["php","bin/paper_account.php","--config=config/paper-us.json","--data="+str(source),"--mode=replay"],env=env,check=True)
    revised=json.loads((target/"paper-us-replay.json").read_text())
    assert revised["state"]["halted"]
    assert revised["events"][:-1]==d["events"],"Original history was rewritten"
    assert revised["events"][-1]["type"]=="data_revision"
summary={}
for market in ["us","kr"]:
    d=json.loads((state/("paper-"+market+"-replay.json")).read_text());s=d["state"]
    counts={}
    for e in d["events"]:counts[e["type"]]=counts.get(e["type"],0)+1
    decisions={}
    for e in d["events"]:
        if e["type"]=="decision":
            reason=e["payload"]["reason"];decisions[reason]=decisions.get(reason,0)+1
    summary[market]={"mode":s["mode"],"currency":s["config"]["currency"],"cash":s["cash"],
        "latest_equity":s["equity"][-1],"closed_trades":s["closed_trades"],
        "max_drawdown":s["max_drawdown"],"event_counts":counts,"decision_reasons":decisions}
(state/"replay-summary.json").write_text(json.dumps(summary,indent=2))
print("PAPER_REPLAY="+json.dumps(summary))
print("PAPER_INTEGRATION_PASS")
