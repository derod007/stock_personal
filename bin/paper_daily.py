"""One local forward update; schedule externally if desired. No brokerage connection."""
import argparse, json, pathlib, subprocess
p=argparse.ArgumentParser();p.add_argument("--config",default="config/paper-us.json");a=p.parse_args()
config=json.loads(pathlib.Path(a.config).read_text())
if not config["id"].replace("-","").replace("_","").isalnum():raise ValueError("Invalid account ID")
out=pathlib.Path("data/cache/paper-input")/config["id"]
subprocess.run(["python3","bin/fetch_comparison_data.py","--years=5","--symbols="+",".join(config["symbols"]),"--out="+str(out)],check=True)
subprocess.run(["php","bin/paper_account.php","--config="+a.config,"--data="+str(out),"--mode=forward"],check=True)
