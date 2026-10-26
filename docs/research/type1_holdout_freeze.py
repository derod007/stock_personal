"""Freeze verified completed provider files before a single new research run."""
import argparse,hashlib,json,time
from pathlib import Path

def sha(b):return hashlib.sha256(b).hexdigest()
def snapshot(source,dest):
    records=json.loads((source/'collection.json').read_text())
    manifest=json.loads((source/'manifest.json').read_text())
    if sorted(r['symbol'] for r in records)!=manifest['symbols']:raise ValueError('incomplete collection')
    dest.mkdir(parents=True,exist_ok=False)
    for sub in ['bars','raw','records']:(dest/sub).mkdir()
    frozen=[]
    for entry in records:
        sym=entry['symbol']
        # Read one internally consistent set. Never mix a record hash with another response.
        for attempt in range(4):
            rec_bytes=(source/'records'/f'{sym}.json').read_bytes();r=json.loads(rec_bytes);parts={}
            if r['status']=='saved':
                parts={f'raw/{sym}.json':(source/'raw'/f'{sym}.json').read_bytes(),f'bars/{sym}.json':(source/'bars'/f'{sym}.json').read_bytes()}
                ok=(sha(parts[f'raw/{sym}.json'])==r['raw_sha256'] and sha(parts[f'bars/{sym}.json'])==r['bars_sha256'])
            else:
                q=source/'raw'/f'{sym}.error'
                if q.exists():parts[f'raw/{sym}.error']=q.read_bytes()
                q=source/'raw'/f'{sym}.json'
                if q.exists():parts[f'raw/{sym}.json']=q.read_bytes()
                ok=all(sha(b)==r.get('raw_sha256') for b in parts.values())
            if ok:break
            time.sleep(.25)
        else:raise ValueError('source changed during freeze: '+sym)
        for name,body in parts.items():(dest/name).write_bytes(body)
        (dest/'records'/f'{sym}.json').write_bytes(rec_bytes);frozen.append(r)
    (dest/'collection.json').write_text(json.dumps(frozen,ensure_ascii=False,indent=2)+'\n')
    (dest/'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
    hashes={str(p.relative_to(dest)):sha(p.read_bytes()) for p in sorted(dest.rglob('*')) if p.is_file()}
    (dest/'frozen-hashes.json').write_text(json.dumps(hashes,indent=2)+'\n')
    return frozen

def main():
    p=argparse.ArgumentParser();p.add_argument('--source',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args();r=snapshot(a.source,a.output);print('Frozen',len(r))
if __name__=='__main__':main()
