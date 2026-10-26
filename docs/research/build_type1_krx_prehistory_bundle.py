"""Build a source-only local collector bundle; never includes collected market data."""
import argparse,hashlib,json,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
DOC=ROOT/'docs/type1-krx-input-v1'
FILES=['docs/research/type1_krx_prehistory_collect.py','docs/research/historical-amount-top100-pilot.py','docs/research/dated-amount-top100-range.py','docs/historical-top100-pilot/evidence.json','docs/type1-krx-input-v1/trading-calendar.json','docs/type1-krx-input-v1/protocol.md']
def build(dest):
 manifest={n:hashlib.sha256((ROOT/n).read_bytes()).hexdigest() for n in FILES}
 with zipfile.ZipFile(dest,'x',zipfile.ZIP_DEFLATED) as z:
  for n in FILES:z.write(ROOT/n,n)
  for n in ['RUN_COLLECT.cmd','README.md']:z.write(DOC/n,n)
  z.writestr('bundle-manifest.json',json.dumps(manifest,indent=2)+'\n')
 return manifest
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--output',required=True,type=Path);a=p.parse_args();build(a.output)
