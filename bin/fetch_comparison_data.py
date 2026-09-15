"""Public daily-data comparison fixture. Records source and hash; no credentials."""
import argparse
import csv, datetime as dt, hashlib, io, json, pathlib, time, urllib.request
parser = argparse.ArgumentParser()
parser.add_argument("--symbols", default="MU,AAPL,005930.KS")
parser.add_argument("--years", type=int, choices=[2,5,10], default=2)
parser.add_argument("--out", default="data/comparison")
args = parser.parse_args()
out = pathlib.Path(args.out)
out.mkdir(parents=True, exist_ok=True)
meta = []
for symbol in args.symbols.split(","):
    record = {"symbol": symbol, "fetched_at": dt.datetime.now(dt.timezone.utc).isoformat()}
    bars = []
    try:
        url = "https://query1.finance.yahoo.com/v8/finance/chart/" + symbol + f"?range={args.years}y&interval=1d&events=div%2Csplits"
        req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
        with urllib.request.urlopen(req, timeout=25) as r:
            raw = r.read()
        # Preserve the provider response so adjusted close and corporate actions can be audited.
        raw_path = out / (symbol + "-provider.json")
        raw_path.write_bytes(raw)
        record.update({"provider_file": str(raw_path), "provider_sha256": hashlib.sha256(raw).hexdigest(),
                       "price_basis": "provider quote OHLC; no additional adjustment applied"})
        data = json.loads(raw)["chart"]["result"][0]
        q = data["indicators"]["quote"][0]
        for i, stamp in enumerate(data["timestamp"]):
            if any(q[k][i] is None for k in ["open", "high", "low", "close"]):
                continue
            bars.append({"time": stamp, "time_kst": dt.datetime.fromtimestamp(stamp, dt.timezone(dt.timedelta(hours=9))).strftime("%Y-%m-%d %H:%M:%S"),
                         **{k: q[k][i] for k in ["open", "high", "low", "close", "volume"]}})
        record["source"] = url
    except Exception as e:
        record["yahoo_error"] = str(e)
        if symbol.endswith(".KS"):
            record["error"] = "Korean history unavailable; no substitution"
        else:
            try:
                from zoneinfo import ZoneInfo
                url = "https://stooq.com/q/d/l/?s=" + symbol.lower() + ".us&i=d"
                with urllib.request.urlopen(url, timeout=25) as r:
                    raw = r.read()
                raw_path = out / (symbol + "-provider.csv")
                raw_path.write_bytes(raw)
                record.update({"provider_file": str(raw_path), "provider_sha256": hashlib.sha256(raw).hexdigest(),
                               "price_basis": "Stooq CSV; adjustment basis not independently verified"})
                for row in list(csv.DictReader(io.StringIO(raw.decode()))) [-(args.years*255):]:
                    stamp = dt.datetime.fromisoformat(row["Date"] + "T16:00:00").replace(tzinfo=ZoneInfo("America/New_York"))
                    bars.append({"time": int(stamp.timestamp()), "time_kst": stamp.astimezone(dt.timezone(dt.timedelta(hours=9))).strftime("%Y-%m-%d %H:%M:%S"),
                                 **{k: float(row[k.title()]) for k in ["open", "high", "low", "close", "volume"]}})
                record["source"] = url
            except Exception as fallback:
                record["error"] = str(fallback)
    if bars:
        bars = [b for b in bars if all(isinstance(b[k], (float, int)) for k in ["open", "high", "low", "close", "volume"])]
    if bars:
        path = out / (symbol + ".json")
        payload = json.dumps(bars, ensure_ascii=False).encode()
        path.write_bytes(payload)
        record.update({"sha256": hashlib.sha256(payload).hexdigest(), "bars": len(bars), "file": str(path)})
    meta.append(record)
(out / "sources.json").write_text(json.dumps(meta, indent=2))
print("DATA_SOURCES=" + json.dumps(meta))

if not any("file" in item for item in meta):
    raise RuntimeError("No historical data fetched")
