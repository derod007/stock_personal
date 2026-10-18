"""Read-only joined KRX history audit; no classification, returns or accounts."""
import argparse, bisect, collections, json
from pathlib import Path
from type1_krx_input_audit import digest, quality, number


def affected(window_days, event_days):
    # An event on the first bar has no preceding comparison inside this window.
    return any(window_days[0] < d <= window_days[-1] for d in event_days)


def audit(pre, evaluation):
    candidates=json.loads((evaluation/'docs/candidates.json').read_text())
    days=candidates['days']
    symbols={r['symbol'] for d in candidates['dates'].values() for r in d['rows']}
    histories=collections.defaultdict(list); events=collections.defaultdict(list)
    previous={}; hashes={}; counts=collections.Counter(); reference=[]; seen=set()
    for root in (pre,evaluation):
        frozen=json.loads((root/'docs/file-hashes.json').read_text())
        for kind in ('raw','selected'):
            for name, expected in frozen[kind].items():
                p=root/'raw-root'/kind/name
                if digest(p)!=expected: raise ValueError('Hash mismatch: '+str(p))
                hashes[str(p)]=expected
        for name in sorted(frozen['raw']):
            day=name[:8]; day=day[:4]+'-'+day[4:6]+'-'+day[6:]
            suffix='.KS' if 'kospi_equity' in name else '.KQ'
            for row in json.loads((root/'raw-root/raw'/name).read_text())['OutBlock_1']:
                if row['BAS_DD']!=day.replace('-',''): raise ValueError('Date mismatch')
                sym=row['ISU_CD']+suffix
                if sym not in symbols: continue
                if (sym,day) in seen: raise ValueError('Duplicate symbol/date')
                seen.add((sym,day)); bar,reason=quality(row)
                counts[reason or 'valid']+=1
                delta=number(row.get('CMPPREVDD_PRC')); close=bar['close']
                if close is not None and close>0 and delta is not None and sym in previous:
                    pd,pc=previous[sym]; ref=close-delta
                    if ref>0 and abs(ref-pc)>max(1.,pc*.00001):
                        rec=dict(symbol=sym,day=day,previous_day=pd,previous_close=pc,comparison_price=ref,cause='unconfirmed')
                        reference.append(rec);events[sym].append(day)
                if close is not None and close>0: previous[sym]=(day,close)
                if not reason: histories[sym].append(day)
    coverage=collections.Counter(); episodes=collections.Counter(); last={}; first_short=[]
    for i,day in enumerate(days):
        for row in candidates['dates'][day]['rows']:
            sym=row['symbol']; past=histories[sym][:bisect.bisect_right(histories[sym],day)]
            status=('missing_anchor' if not past or past[-1]!=day else 'under60' if len(past)<60 else 'ready60')
            coverage[status]+=1
            flagged=status=='ready60' and affected(past[-60:],events.get(sym,[]))
            if flagged: coverage['ready60_with_reference_event']+=1
            if i==0 and status!='ready60': first_short.append(dict(symbol=sym,valid_bars_including_anchor=len(past),status=status))
            if i<=last.get(sym,-1): continue
            last[sym]=i+20
            if i+20>=len(days): episodes['period_end']+=1;continue
            episodes[status]+=1
            if flagged: episodes['ready60_with_reference_event']+=1
    if any(digest(Path(p))!=h for p,h in hashes.items()): raise ValueError('Input changed')
    return dict(price_basis='krx_tdd_ohlcv_adjustment_unverified',evaluation_period=[days[0],days[-1]],verified_files=len(hashes),symbols=len(symbols),quality=dict(counts),candidate_coverage=dict(coverage),nonoverlap_episodes=dict(episodes),first_day_short=first_short,reference_event_count=len(reference),reference_symbols=len({r["symbol"] for r in reference}),prehistory_events=sum(r['day']<days[0] for r in reference),evaluation_events=sum(r['day']>=days[0] for r in reference),join_events=[r for r in reference if r['day']==days[0]],reference_events=reference,account_execution=False,decision='Input audit complete; reference discontinuity policy unresolved; no returns computed')

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--prehistory',type=Path,required=True);p.add_argument('--evaluation',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args()
    r=audit(a.prehistory,a.evaluation)
    with a.output.open('x') as f: json.dump(r,f,ensure_ascii=False,indent=2);f.write('\n')
    print(json.dumps({k:v for k,v in r.items() if k!='reference_events'},ensure_ascii=False,indent=2))
