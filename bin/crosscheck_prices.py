"""Read-only cross-check of six known invalid candles; never overwrites frozen prices."""
import datetime as dt, hashlib, json, pathlib, urllib.request, xml.etree.ElementTree as ET
from zoneinfo import ZoneInfo

CASES={"000660.KS":["2023-02-02","2023-02-09","2024-10-14"],
       "005380.KS":["2023-02-01","2024-01-15"],"005930.KS":["2024-10-14"]}
FIELDS=["open","high","low","close"]

def valid(b):
    return b is not None and 0<b["low"]<=min(b["open"],b["close"])<=max(b["open"],b["close"])<=b["high"]

def same(a,b):
    return a is not None and b is not None and all(abs(a[k]-b[k])<=max(0.01,abs(a[k])*1e-6) for k in FIELDS)

def fetch(url,path):
    req=urllib.request.Request(url,headers={"User-Agent":"Mozilla/5.0"})
    with urllib.request.urlopen(req,timeout=30) as r: raw=r.read()
    path.write_bytes(raw)
    return raw,{"url":url,"file":str(path),"sha256":hashlib.sha256(raw).hexdigest(),
                "fetched_at":dt.datetime.now(dt.timezone.utc).isoformat()}

def main():
    root=pathlib.Path("data/research"); out=root/"crosscheck";out.mkdir(exist_ok=True)
    result=[]
    for symbol,dates in CASES.items():
        frozen={dt.datetime.fromtimestamp(b["time"],ZoneInfo("Asia/Seoul")).strftime("%Y-%m-%d"):b
                for b in json.loads((root/(symbol+".json")).read_text())}
        yahoo={};naver={};meta={}; adjustments={}
        try:
            raw,meta["yahoo"]=fetch("https://query1.finance.yahoo.com/v8/finance/chart/"+symbol+
                "?range=5y&interval=1d&events=div%2Csplits",out/(symbol+"-yahoo.json"))
            d=json.loads(raw)["chart"]["result"][0];q=d["indicators"]["quote"][0]
            adj=d["indicators"].get("adjclose",[{}])[0].get("adjclose",[])
            for i,t in enumerate(d["timestamp"]):
                day=dt.datetime.fromtimestamp(t,ZoneInfo("Asia/Seoul")).strftime("%Y-%m-%d")
                if all(q[k][i] is not None for k in FIELDS):
                    yahoo[day]={k:q[k][i] for k in FIELDS}
                    if i<len(adj) and adj[i] is not None and q["close"][i]:
                        adjustments[day]=adj[i]/q["close"][i]
            meta["events"]=d.get("events",{})
            meta["adjustment_note"]="adjclose/quote-close is observed only; no OHLC conversion or adjustment certification"
        except Exception as e:meta["yahoo_error"]=str(e)
        try:
            raw,meta["naver"]=fetch("https://fchart.stock.naver.com/sise.nhn?symbol="+symbol[:6]+
                "&timeframe=day&count=1600&requestType=0",out/(symbol+"-naver.xml"))
            for item in ET.fromstring(raw).iter("item"):
                parts=item.attrib["data"].split("|")
                if len(parts)>=5:
                    day=dt.datetime.strptime(parts[0],"%Y%m%d").strftime("%Y-%m-%d")
                    naver[day]=dict(zip(FIELDS,map(float,parts[1:5])))
        except Exception as e:meta["naver_error"]=str(e)
        for day in dates:
            a=yahoo.get(day);b=naver.get(day)
            result.append({"symbol":symbol,"date":day,"frozen":{k:frozen[day][k] for k in FIELDS},
                "fresh_yahoo":a,"naver":b,"fresh_yahoo_valid":valid(a),"naver_valid":valid(b),
                "providers_agree":same(a,b),"adjustment_factor":adjustments.get(day),
                "status":"two_providers_agree_on_valid_ohlc" if valid(a) and valid(b) and same(a,b) else
                    ("unavailable" if a is None or b is None else "unresolved_difference"),
                "provenance":meta})
    summary={"cases":result,"counts":{s:sum(r["status"]==s for r in result) for s in
         ["two_providers_agree_on_valid_ohlc","unavailable","unresolved_difference"]},
         "frozen_data_changed":False,"corporate_action_certification":False}
    (root/"price-crosscheck.json").write_text(json.dumps(summary,indent=2))
    print("PRICE_CROSSCHECK="+json.dumps(summary))
if __name__=="__main__":main()
