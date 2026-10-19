"""Descriptive summaries; no threshold fitting or account equity inference."""
import json,sys,collections,statistics
from pathlib import Path

def avg(xs):return statistics.mean(xs) if xs else None
def stats(rr):
    closed=[r for r in rr if r['result']['status']=='closed'];vals=[r['result']['net_return_pct'] for r in closed]
    sy=collections.defaultdict(list)
    for r in closed:sy[r['signal']['symbol']].append(r['result']['net_return_pct'])
    top=sorted(sy,key=lambda x:sum(sy[x]),reverse=True)[:5]
    return {'signals':len(rr),'statuses':dict(collections.Counter(r['result']['status'] for r in rr)),
      'closed':len(vals),'symbols':len(sy),'mean_pct':avg(vals),'median_pct':statistics.median(vals) if vals else None,
      'win_pct':100*sum(x>0 for x in vals)/len(vals) if vals else None,'symbol_equal_mean_pct':avg([avg(v) for v in sy.values()]),
      'exclude_top5_mean_pct':avg([v for s,vs in sy.items() if s not in top for v in vs]),
      'exits':dict(collections.Counter(r['result']['first_exit'] for r in closed)),
      'holding_mean':avg([r['result']['bars'] for r in closed]),
      'paths':{k:{'n':len(v:=[r['path'][k] for r in rr if k in r['path']]),'mean_pct':avg(v),'median_pct':statistics.median(v) if v else None} for k in ['close1_pct','close5_pct','close10_pct','close20_pct','max20_pct','min20_pct']},
      'full_path_unknown':sum(not r['path']['complete'] for r in rr)}

def main():
    data=json.loads(Path(sys.argv[1]).read_text());out=Path(sys.argv[2]);out.mkdir(exist_ok=True)
    rr=data['records'];ss=data['scores'];groups={}
    for g in ['all','score5plus','score12']:
        rows=[r for r in rr if r['group']==g]
        groups[g]={'all':stats(rows),'halves':{h:stats([r for r in rows if (r['signal']['anchor']<'2024-01-01')==v]) for h,v in [('2023H2',True),('2024H1',False)]},
          'months':{m:stats([r for r in rows if r['signal']['anchor'][:7]==m]) for m in sorted({r['signal']['anchor'][:7] for r in rows})},
          'score_at_entry':{str(s):stats([r for r in rows if r['evaluation']['score']==s]) for s in [0,5,12]},
          'phase_at_entry':{p:stats([r for r in rows if r['evaluation']['phase']==p]) for p in sorted({r['evaluation']['phase'] for r in rows})}}
    result={'observation_n':len(ss),'eligible_n':sum(r['evaluation']['eligible'] for r in ss),
        'blocked_reasons':dict(collections.Counter(x for r in ss for x in r['evaluation'].get('reasons',[]))),
        'daily_status_counts':dict(collections.Counter(r['evaluation']['status'] for r in ss if r['evaluation']['eligible'])),
        'daily_score_counts':dict(collections.Counter(r['evaluation']['score'] for r in ss if r['evaluation']['eligible'])),
        'period_end_eligible_observations':data['counts'].get('period_end',0),'groups':groups}
    (out/'summary.json').write_text(json.dumps(result,ensure_ascii=False,indent=2))
    print(json.dumps({k:v['all'] for k,v in groups.items()},ensure_ascii=False,indent=2))

if __name__=='__main__':main()
