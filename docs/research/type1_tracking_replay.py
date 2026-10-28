"""Replay saved TOP100 and bars through the production tracker, offline only.
The candidate calendar is historical session evidence, not forward metadata.
"""
import argparse,bisect,datetime,hashlib,json,pathlib,subprocess,zipfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
def main():
    p=argparse.ArgumentParser()
    for k in ('candidates','bars-zip','output'):p.add_argument('--'+k,type=pathlib.Path,required=True)
    p.add_argument('--php',default='php');a=p.parse_args()
    if a.output.exists():raise ValueError('Refuse existing replay output')
    raw=json.loads(a.candidates.read_text());days=raw['days'];bars={};dates={}
    with zipfile.ZipFile(a.bars_zip) as z:
        for n in z.namelist():
            if '/bars/' not in n or not n.endswith('.json'):continue
            sym=pathlib.Path(n).stem;rs=json.loads(z.read(n))['rows']
            rs=sorted(rs,key=lambda b:str(b.get('time_kst',''))[:10]);bars[sym]=rs;dates[sym]=[str(b.get('time_kst',''))[:10] for b in rs]
    a.output.parent.mkdir(parents=True,exist_ok=True)
    with a.output.open('x') as out:
        proc=subprocess.Popen([a.php,'-n',str(ROOT/'tests/type1_tracking_replay.php')],stdin=subprocess.PIPE,stdout=out,text=True)
        try:
            recent=[]
            for i,day in enumerate(days):
                leaders=[dict(r,yahoo=r['symbol']) for r in raw['dates'][day]['rows']]
                current={r['yahoo'] for r in leaders};recent.append(current);recent=recent[-21:];symbols=set.union(*recent)
                prices={}
                for s in symbols:
                    end=bisect.bisect_right(dates.get(s,[]),day)
                    prices[s]=bars.get(s,[])[max(0,end-(60 if s in current else 21)):end]
                stamp=int(datetime.datetime.fromisoformat(day+'T20:20:00+09:00').timestamp())
                snapshot=dict(day=day,as_of=stamp,days=days[:i+1],leaders=leaders,prices=prices,ranking_session_verified=True)
                proc.stdin.write(json.dumps(snapshot,separators=(',',':'))+'\n')
                if (i+1)%50==0:print('replayed',i+1,flush=True)
            proc.stdin.close();code=proc.wait()
            if code:raise RuntimeError(f'PHP replay failed: {code}')
        finally:
            if proc.poll() is None:proc.kill();proc.wait()
    print(json.dumps({'sessions':len(days),'candidates_sha256':hashlib.sha256(a.candidates.read_bytes()).hexdigest(),'bars_sha256':hashlib.sha256(a.bars_zip.read_bytes()).hexdigest(),'output':str(a.output)}))
if __name__=='__main__':main()
