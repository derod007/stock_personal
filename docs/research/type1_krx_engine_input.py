"""KRX-only input builder, retaining invalid bars for the PHP quality gate."""
import argparse,collections,json
from pathlib import Path
from type1_krx_continuity import audit,entry_reasons
from type1_krx_input_audit import quality,digest
from top100_type1_portfolio import centers_from
from top100_pre_rise_shapes import series60,assign
BASIS='krx_tdd_ohlcv_adjustment_unverified'
def build(pre,evaluation,centers_path,sectors_path,out):
    audit_result=audit(pre,evaluation)
    sources={}
    for root in (pre,evaluation):
        for p in (root/'raw-root/raw').glob('*.json'):sources[str(p)]=digest(p)
    centers=centers_from(centers_path)
    if digest(centers_path)!='768c083a5c51cb452dad032f018a97b20527d07ff6fc1ab36559b53c406b4dfd':raise ValueError('Frozen centers changed')
    c=json.loads((evaluation/'docs/candidates.json').read_text());days=c['days']
    syms={r['symbol'] for d in c['dates'].values() for r in d['rows']}
    rows=collections.defaultdict(list);valid=collections.defaultdict(list);invalid=collections.defaultdict(list);events=collections.defaultdict(list)
    for r in audit_result['reference_events']:events[r['symbol']].append(r['day'])
    for root in (pre,evaluation):
        for p in sorted((root/'raw-root/raw').glob('*.json')):
            suffix='.KS' if 'kospi_equity' in p.name else '.KQ'
            for r in json.loads(p.read_text())['OutBlock_1']:
                sym=r['ISU_CD']+suffix
                if sym not in syms:continue
                rawday=r['BAS_DD'];day=rawday[:4]+'-'+rawday[4:6]+'-'+rawday[6:]
                b,reason=quality(r);b.update(day=day,time_kst=day+' 09:00:00',is_complete=True)
                rows[sym].append(b)
                if reason:invalid[sym].append(day)
                else:valid[sym].append(b)
    out.mkdir(exist_ok=False);(out/'bars').mkdir()
    hashes={}
    for sym in sorted(syms):
        p=out/'bars'/f'{sym}.json';p.write_text(json.dumps(dict(price_basis=BASIS,rows=rows[sym]))+'\n');hashes[sym]=digest(p)
    signals=[];last={};skips=collections.Counter();types=collections.Counter()
    for i,day in enumerate(days):
        for row in sorted(c['dates'][day]['rows'],key=lambda r:r['symbol']):
            sym=row['symbol']
            if i<=last.get(sym,-1):continue
            last[sym]=i+20
            if i+20>=len(days):skips['period_end']+=1;continue
            past=[b for b in valid[sym] if b['day']<=day]
            reasons=entry_reasons([b['day'] for b in past],day,events.get(sym,[]),invalid.get(sym,[]))
            if reasons:skips['quality_blocked']+=1;continue
            series=series60(past)
            if series is None:skips['invalid_feature']+=1;continue
            typ=assign([series['feat']],centers)[0];types[typ]+=1;close=past[-1]['close']
            signals.append(dict(symbol=sym,anchor=day,entry_day=days[i+1],end=days[i+20],cluster=typ,entry=close,stop=close*.95,target=close*1.15,order_valid_bars=1))
    sectors=json.loads(sectors_path.read_text())['sectors']
    result=dict(schema=1,days=days,signals=signals,skips=dict(skips),clusters=dict(types),dataset=str(out.resolve()),sectors=sectors,price_basis=BASIS,reference_events=dict(events),hashes=dict(bars=hashes,centers=digest(centers_path),candidates=digest(evaluation/'docs/candidates.json'),sectors=digest(sectors_path),builder=digest(Path(__file__))))
    if any(digest(Path(p))!=h for p,h in sources.items()):raise ValueError('Raw source changed during build')
    result['hashes']['raw_sources']=sources
    (out/'input.json').write_text(json.dumps(result,ensure_ascii=False)+'\n')
    print(json.dumps(dict(signals=len(signals),clusters=dict(types),skips=dict(skips))))
if __name__=='__main__':
    p=argparse.ArgumentParser()
    for k in ('prehistory','evaluation','centers','sectors','output'):p.add_argument('--'+k,type=Path,required=True)
    a=p.parse_args();build(a.prehistory,a.evaluation,a.centers,a.sectors,a.output)
