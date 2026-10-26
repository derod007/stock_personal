#!/usr/bin/env python3
"""Read-only, same-date diagnostic of frozen original composite observations."""
import argparse
from collections import Counter, defaultdict
import hashlib
import html
import json
import math
import os
import tempfile
from pathlib import Path
from statistics import mean, median

HORIZONS = (5, 10, 20)
BINS = ('<40', '40-59', '60-69', '70-84', '85+')


def atomic_text(path, text):
    fd, name = tempfile.mkstemp(prefix='.'+path.name+'.', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as f:
            f.write(text); f.flush(); os.fsync(f.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name): os.unlink(name)


def html_report(md):
    parts=[]; table=False; code=False
    for line in md.splitlines():
        if line.startswith('```'):
            parts.append('</pre>' if code else '<pre>');code=not code;continue
        if code:parts.append(html.escape(line)+'\n');continue
        if line.startswith('|'):
            cells=line.strip('|').split('|')
            if all(set(c)<=set('-: ') for c in cells):continue
            if not table:parts.append('<div class="scroll"><table>');table=True
            parts.append('<tr>'+''.join('<td>'+html.escape(c)+'</td>' for c in cells)+'</tr>');continue
        if table:parts.append('</table></div>');table=False
        if line.startswith('# '):parts.append('<h1>'+html.escape(line[2:])+'</h1>')
        elif line.startswith('## '):parts.append('<h2>'+html.escape(line[3:])+'</h2>')
        elif line:parts.append('<p>'+html.escape(line)+'</p>')
    if table:parts.append('</table></div>')
    return '<!doctype html><html lang="ko"><meta charset="utf-8"><title>초기 점수 선별력 진단</title><style>body{font:15px/1.65 system-ui;margin:32px;color:#172438}table{border-collapse:collapse;font-size:13px}td{border:1px solid #dce2ec;padding:7px 10px;white-space:nowrap;text-align:right}tr:first-child{font-weight:bold;background:#e8edf6}tr:nth-child(even){background:#f5f7fa}.scroll{overflow:auto}pre{white-space:pre-wrap}h2{margin-top:36px}</style>'+''.join(parts)+'</html>'


def sha(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def score_bin(score):
    return BINS[0 if score < 40 else 1 if score < 60 else 2 if score < 70 else 3 if score < 85 else 4]


def valid_bar(b):
    if not b or b.get('is_complete') is not True:
        return False
    try:
        o, h, l, c, v = (float(b[k]) for k in ('open', 'high', 'low', 'close', 'volume'))
        return all(math.isfinite(x) for x in (o,h,l,c,v)) and min(o,h,l,c)>0 and v>=0 and l<=min(o,c)<=max(o,c)<=h
    except (KeyError, TypeError, ValueError):
        return False


def outcome(bars, days, index, horizon):
    if index + horizon >= len(days):
        return None, 'segment_end'
    future = [bars.get(d) for d in days[index+1:index+horizon+1]]
    if any(b is None for b in future):
        return None, 'missing_future_session'
    if not all(valid_bar(b) for b in future):
        return None, 'invalid_future_bar'
    return (future[-1]['close']/future[0]['open']-1)*100, None


def groups(rows, key):
    result = defaultdict(list)
    for r in rows:
        result[key(r)].append(r)
    return result


def stats(values):
    return {'n':len(values), 'mean':mean(values), 'median':median(values), 'positive_pct':100*mean(x>0 for x in values)} if values else {'n':0,'mean':None,'median':None,'positive_pct':None}


def contrast(rows, horizon, predicate):
    dates = groups([r for r in rows if r['returns'][str(horizon)] is not None], lambda r:(r['segment'],r['date']))
    paired, a_all, b_all = [], [], []
    sym_a, sym_b = defaultdict(list), defaultdict(list)
    for (seg, date), daily in sorted(dates.items()):
        a = [r for r in daily if predicate(r)]
        b = [r for r in daily if not predicate(r)]
        if not a or not b:
            continue
        av, bv = ([r['returns'][str(horizon)] for r in side] for side in (a,b))
        sa,sb=stats(av),stats(bv)
        paired.append({'segment':seg,'date':date,'a':sa,'b':sb,'difference_pp':sa['mean']-sb['mean']})
        a_all.extend(av);b_all.extend(bv)
        for r in a: sym_a[r['symbol']].append(r['returns'][str(horizon)]-sb['mean'])
        for r in b: sym_b[r['symbol']].append(sa['mean']-r['returns'][str(horizon)])
    result={'paired_days':len(paired),'a_pooled':stats(a_all),'b_pooled':stats(b_all),
            'difference':stats([p['difference_pp'] for p in paired]),
            'a_symbol_equal_excess':stats([mean(v) for v in sym_a.values()]),
            'b_symbol_equal_a_minus_b':stats([mean(v) for v in sym_b.values()])}
    for side in ('a','b'):
        result[side+'_date_equal']={key:mean(p[side][key] for p in paired) if paired else None for key in ('mean','median','positive_pct')}
    return result,paired


def nonoverlap(rows):
    kept=[]
    for sr in groups(rows,lambda r:(r['segment'],r['symbol'])).values():
        last=-10**9
        for r in sorted(sr,key=lambda r:r['index']):
            if r['index']>last+20:
                kept.append(r);last=r['index']
    return kept


def bin_summary(rows,horizon):
    daily=groups([r for r in rows if r['returns'][str(horizon)] is not None],lambda r:(r['segment'],r['date']))
    out={}
    for label in BINS:
        values=[];raw=[];excess=[]
        for rs in daily.values():
            av=[r['returns'][str(horizon)] for r in rs if r['bin']==label]
            bv=[r['returns'][str(horizon)] for r in rs if r['bin']!=label]
            if av and bv:
                values.extend(av);raw.append(mean(av));excess.append(mean(av)-mean(bv))
        out[label]={'pooled':stats(values),'paired_days':len(raw),'date_equal_mean':mean(raw) if raw else None,'date_equal_excess':mean(excess) if excess else None}
    return out


def fmt(v):
    return '—' if v is None else f'{v:.3f}'


def report(summary):
    lines=['# 초기 종합점수 선별력 진단', '',
           '다음 시가 → 5·10·20세션째 종가. 원시 가격 수익률이며 비용·손절·목표·계좌 한도를 적용한 실현 수익이 아닙니다.',
           '동일 날짜의 양쪽 집단이 모두 존재하는 날만 비교하고 날짜를 동일 가중했습니다. 종목 동일 가중은 종목별 평균 초과수익을 먼저 구합니다.',
           '이전에 연구한 자료의 탐색 진단입니다. 가격 조정·기업행동 미검증, 누락 미래 가격 제외, 가격 구간 경계 단절의 한계가 있습니다.', '',
           '## 자료 범위', '', '```json', json.dumps(summary['coverage'],ensure_ascii=False,indent=2),'```', '',
           '## 핵심 비교', '', '차이·종목차이는 A−B(%p). 점수 A=70점 이상, B=70점 미만. 추천 A=add_on_pullback, B=그 외. 후보는 기존 체결 여부와 무관합니다.', '',
           '|범위|비교|기간|짝 날짜|A 관측|B 관측|A 평균%|B 평균%|차이pp|A 중앙%¹|B 중앙%¹|A 상승%¹|B 상승%¹|A 종목차이pp|',
           '|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|']
    for scope, data in summary['scopes'].items():
        for kind, hs in data['contrasts'].items():
            for h,s in hs.items():
                cells=[scope,kind,h,s['paired_days'],s['a_pooled']['n'],s['b_pooled']['n'],fmt(s['a_date_equal']['mean']),fmt(s['b_date_equal']['mean']),fmt(s['difference']['mean']),fmt(s['a_pooled']['median']),fmt(s['b_pooled']['median']),fmt(s['a_pooled']['positive_pct']),fmt(s['b_pooled']['positive_pct']),fmt(s['a_symbol_equal_excess']['mean'])]
                lines.append('|'+'|'.join(map(str,cells))+'|')
    lines += ['', '¹ 중앙값·상승 비율은 관측 통합 기준. 날짜 가중 중앙·상승 비율 및 비교군 종목 가중값도 summary.json에 보존했습니다.', '', '## 점수 구간 전체', '',
              '|범위|기간|점수|짝 날짜|관측|날짜평균%|나머지 대비pp|중앙%|상승%|','|---|---:|---|---:|---:|---:|---:|---:|---:|']
    for scope,data in summary['scopes'].items():
        for h,bins in data['bins'].items():
            for label,s in bins.items():
                lines.append('|'+ '|'.join(map(str,[scope,h,label,s['paired_days'],s['pooled']['n'],fmt(s['date_equal_mean']),fmt(s['date_equal_excess']),fmt(s['pooled']['median']),fmt(s['pooled']['positive_pct'])]))+'|')
    lines += ['', '## 반복 제거 민감도', '', '종목별 첫 평가일부터 20세션 동안 추가 관측을 제외했습니다. 미래 가격 유무·점수·추천 여부를 보기 전에 관측을 선택합니다. 표본이 적은 행은 판단을 제한합니다.', '',
              '|범위|비교|기간|짝 날짜|A 관측|B 관측|차이pp|A 종목차이pp|','|---|---|---:|---:|---:|---:|---:|---:|']
    for scope,data in summary['scopes'].items():
        for kind,hs in data['nonoverlap'].items():
            for h,s in hs.items():
                lines.append('|'+ '|'.join(map(str,[scope,kind,h,s['paired_days'],s['a_pooled']['n'],s['b_pooled']['n'],fmt(s['difference']['mean']),fmt(s['a_symbol_equal_excess']['mean'])]))+'|')
    lines += ['', '## 해석 범위', '', '여러 기간·가중 방식에서 같은 방향인지 확인하십시오. 평균이 양수여도 매매 수익성 증거는 아닙니다. 혼합된 방향·부족한 표본은 미확정이며 조건 최적화로 이어가지 않습니다. 미래 가격 누락이 무작위라는 보장이 없어 제외 편향도 남습니다.']
    return '\n'.join(lines)+'\n'


def main():
    ap=argparse.ArgumentParser();ap.add_argument('--prepared',type=Path,required=True);ap.add_argument('--previous',type=Path,required=True);ap.add_argument('--output',type=Path,required=True)
    args=ap.parse_args()
    if args.output.exists():raise ValueError('Output already exists; preserve previous results')
    obs_path=args.previous/'observations.jsonl'; manifest_path=args.prepared/'manifest.json'
    old_hashes=json.loads((args.previous/'file-hashes.json').read_text())
    assert sha(obs_path)==old_hashes['observations.jsonl'], 'observations hash mismatch'
    assert sha(manifest_path)==sha(args.previous/'input-manifest.json'), 'prepared manifest mismatch'
    manifest=json.loads(manifest_path.read_text());segments={s['label']:s for s in manifest['segments']}
    indices={k:{d:i for i,d in enumerate(s['days'])} for k,s in segments.items()}
    raw=[json.loads(l) for l in obs_path.read_text().splitlines()]
    evaluated=[r for r in raw if not r.get('blocked')]
    seen=set(); rows=[]; hashes={}; missing=Counter()
    for (segment,symbol),rs in groups(evaluated,lambda r:(r['segment'],r['symbol'])).items():
        s=segments[segment];path=args.prepared/s['directory']/(symbol+'.json')
        digest=sha(path);assert digest==s['bar_hashes'][symbol],f'price hash mismatch: {segment} {symbol}'
        hashes[str(path.relative_to(args.prepared))]=digest
        bars={}
        for b in json.loads(path.read_text()):
            day=b['time_kst'][:10]
            assert day not in bars, 'duplicate price date'
            bars[day]=b
        for r in rs:
            key=(segment,r['date'],symbol);assert key not in seen;seen.add(key)
            row={k:r[k] for k in ('segment','date','symbol','name','rank','score','action')}
            row['index']=indices[segment][r['date']];row['bin']=score_bin(r['score']);row['returns']={};row['missing']={}
            for h in HORIZONS:
                value,reason=outcome(bars,s['days'],row['index'],h)
                row['returns'][str(h)]=value
                if reason:row['missing'][str(h)]=reason;missing[(segment,h,reason)]+=1
            rows.append(row)
    scopes={'all':rows}
    scopes.update({'year_'+k:v for k,v in sorted(groups(rows,lambda r:r['date'][:4]).items())})
    scopes.update({'source_'+k:v for k,v in sorted(groups(rows,lambda r:r['segment']).items())})
    selected=nonoverlap(rows);selected_keys={(r['segment'],r['date'],r['symbol']) for r in selected}
    contrasts={'score70':lambda r:r['score']>=70,'candidate':lambda r:r['action']=='add_on_pullback'}
    summary={'coverage':{'input_observations':len(raw),'evaluated':len(rows),'blocked':len(raw)-len(rows),'evaluated_dates':len({r['date'] for r in rows}),'first':min(r['date'] for r in rows),'last':max(r['date'] for r in rows),'nonoverlap_observations':len(selected),'missing':[{'segment':s,'horizon':h,'reason':reason,'n':n} for (s,h,reason),n in sorted(missing.items())], 'horizon_available':{str(h):sum(r['returns'][str(h)] is not None for r in rows) for h in HORIZONS}},'scopes':{}}
    paired_all=[]
    for scope,rs in scopes.items():
        nr=[r for r in rs if (r['segment'],r['date'],r['symbol']) in selected_keys]
        data={'contrasts':{},'nonoverlap':{},'bins':{str(h):bin_summary(rs,h) for h in HORIZONS}, 'availability':{}}
        for kind,pred in contrasts.items():
            data['contrasts'][kind]={};data['nonoverlap'][kind]={}
            data['availability'][kind]={side:{'total':len(sub), 'available':{str(h):sum(r['returns'][str(h)] is not None for r in sub) for h in HORIZONS}} for side,sub in [('a',[r for r in rs if pred(r)]),('b',[r for r in rs if not pred(r)])]}
            for h in HORIZONS:
                result,paired=contrast(rs,h,pred);data['contrasts'][kind][str(h)]=result
                data['nonoverlap'][kind][str(h)]=contrast(nr,h,pred)[0]
                if scope=='all':paired_all.extend(dict(p,contrast=kind,horizon=h) for p in paired)
        summary['scopes'][scope]=data
    args.output.mkdir(parents=True)
    def write(name,obj): atomic_text(args.output/name,json.dumps(obj,ensure_ascii=False,indent=2,allow_nan=False)+'\n')
    write('summary.json',summary)
    for filename,items in [('outcomes.jsonl',rows),('paired-days.jsonl',paired_all)]:
        atomic_text(args.output/filename, ''.join(json.dumps(r,ensure_ascii=False,allow_nan=False)+'\n' for r in items))
        assert sum(1 for _ in (args.output/filename).open()) == len(items)
    protocol=Path(__file__).resolve().parents[1]/'score-selection-diagnostic-v1/protocol.md'
    write('provenance.json',{'observations_sha256':sha(obs_path),'manifest_sha256':sha(manifest_path),'script_sha256':sha(__file__),'protocol_sha256':sha(protocol),'price_hashes':hashes,'account_execution':False})
    md=report(summary);atomic_text(args.output/'report.md',md)
    atomic_text(args.output/'report.html',html_report(md))
    write('file-hashes.json',{p.name:sha(p) for p in args.output.iterdir() if p.is_file()})
    print(json.dumps({'output':str(args.output),'coverage':summary['coverage']},ensure_ascii=False))

if __name__=='__main__':main()
