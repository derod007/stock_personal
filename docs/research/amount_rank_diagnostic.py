"""Offline KRX universe diagnostic; requires pandas and numpy, no network/account calls."""
import argparse
from collections import defaultdict
from contextlib import ExitStack
import hashlib
import html
import importlib.util
import json
import math
import os
from pathlib import Path
import tempfile
import zipfile
import numpy as np
import pandas as pd

BANDS=['1-100','101-300','301-500']
HORIZONS=(5,10,20)


def sha(p):return hashlib.sha256(Path(p).read_bytes()).hexdigest()

def atomic(path,text):
    fd,tmp=tempfile.mkstemp(dir=path.parent,prefix='.'+path.name)
    with os.fdopen(fd,'w') as f:f.write(text);f.flush();os.fsync(f.fileno())
    os.replace(tmp,path)

def number(v):
    try:return float(str(v).replace(',',''))
    except (TypeError,ValueError):return float('nan')

def rank_rows(rows,pilot):
    seen=set();eligible=[]
    for r in rows:
        if r['market'] not in ('KOSPI','KOSDAQ') or r['amount_won'] is None or pilot.exclusion_kind(r['name']):continue
        if r['code'] in seen:continue
        seen.add(r['code']);eligible.append(r)
    return sorted(eligible,key=lambda r:(-r['amount_won'],r['code']))[:500]

def valid(b):
    if b is None:return False
    o,h,l,c,v=b
    return all(math.isfinite(x) for x in b) and min(o,h,l,c)>0 and v>0 and l<=min(o,c)<=max(o,c)<=h

def forward(prices,idx,symbol,horizon,length):
    if idx+horizon>=length:return None,'boundary'
    future=[prices[i].get(symbol) for i in range(idx+1,idx+horizon+1)]
    if any(b is None for b in future):return None,'missing_session'
    if not all(valid(b) for b in future):return None,'invalid_or_zero_volume'
    entry=future[0][0]
    return ((future[-1][3]/entry-1)*100,(min(b[2] for b in future)/entry-1)*100,(max(b[1] for b in future)/entry-1)*100,any(b[1]==b[2] for b in future)),None

def nonoverlap(observations):
    last={};keep=[]
    for r in observations:
        yes=r['index']>last.get(r['symbol'],-1000)+20
        keep.append(yes)
        if yes:last[r['symbol']]=r['index']
    return keep

def describe(x):
    x=pd.Series(x).dropna()
    return {'n':len(x),'mean':float(x.mean()) if len(x) else None,'median':float(x.median()) if len(x) else None,'positive_pct':float(x.gt(0).mean()*100) if len(x) else None}

def compare(df):
    valid_df=df[df.reason=='ok']
    daily=valid_df.groupby(['date','band'])[['ret','low','high']].mean()
    output={}
    for b in BANDS[1:]:
        a=daily.xs(b,level='band') if b in daily.index.get_level_values('band') else pd.DataFrame(columns=['ret','low','high'])
        base=daily.xs(BANDS[0],level='band') if BANDS[0] in daily.index.get_level_values('band') else pd.DataFrame(columns=['ret','low','high'])
        pair=a.join(base,lsuffix='_a',rsuffix='_b',how='inner')
        ar=valid_df[valid_df.band==b].merge(pair[['ret_b']],left_on='date',right_index=True)
        br=valid_df[valid_df.band==BANDS[0]].merge(pair[['ret_a']],left_on='date',right_index=True)
        output[b]={'paired_days':len(pair),'a_n':len(ar),'base_n':len(br),'return_diff':describe(pair.ret_a-pair.ret_b),'low_diff':describe(pair.low_a-pair.low_b),
          'a_symbol_equal_excess':describe((ar.ret-ar.ret_b).groupby(ar.symbol).mean()),'base_symbol_equal_a_minus_base':describe((br.ret_a-br.ret).groupby(br.symbol).mean())}
    return output

def summarize(df):
    out={}
    for h in HORIZONS:
        sub=df[df.horizon==h]; bands={}
        for b in BANDS:
            g=sub[sub.band==b];v=g[g.reason=='ok'];daily=v.groupby('date')[['ret','low','high']].mean()
            bands[b]={'selected':len(g),'valid':len(v),'unknown':g[g.reason!='ok'].reason.value_counts().to_dict(),'locked_valid':int(v.locked.eq(True).sum()),'valid_dates':len(daily),
              'date_equal':{k:float(daily[k].mean()) if len(daily) else None for k in ('ret','low','high')},'pooled_return':describe(v.ret),'pooled_low':describe(v.low),'pooled_high':describe(v.high)}
        out[str(h)]={'bands':bands,'comparisons':compare(sub),'nonoverlap':compare(sub[sub.nonoverlap]),'without_locked':compare(sub[~sub.locked.eq(True)])}
    return out

def render(summary):
    lines=['# 거래대금 순위별 진단','', 'KRX 2023-01-02~2024-10-07, 431거래일. 순위는 당시 제외 규칙 적용 후입니다. 비용 전 가격 변화이며 실제 체결·계좌 수익이 아닙니다. 가격 조정 미검증·누락 제외 편향·시가 체결 불확실성이 남습니다. TOP100 전략 손실의 원인임을 증명하는 비교는 아닙니다.','', '## 같은 날짜 비교: 순위 구간 − TOP100','', '|범위|세션|순위|짝 날짜|관측|수익 차이pp|하방 차이pp¹|종목 동일 차이pp|반복 제거 차이pp|반복 제거 날짜|잠김 봉 제외 차이pp|','|---|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|']
    def f(x):return '—' if x is None else f'{x:.3f}'
    for scope,hs in summary['scopes'].items():
        for h,d in hs.items():
            for b,c in d['comparisons'].items():
                n=d['nonoverlap'][b];k=d['without_locked'][b]
                vals=[scope,h,b,c['paired_days'],c['a_n'],f(c['return_diff']['mean']),f(c['low_diff']['mean']),f(c['a_symbol_equal_excess']['mean']),f(n['return_diff']['mean']),n['paired_days'],f(k['return_diff']['mean'])]
                lines.append('|'+'|'.join(map(str,vals))+'|')
    lines+=['','¹ 하방=min(보유기간 저가)/다음 시가−1. 차이가 양수면 저가 하락폭이 덜 깊습니다. 항상 0 이하라는 보장은 없습니다.','', '## 구간별 가격 성과','', '|범위|세션|순위|선택|유효|날짜 평균%|관측 중앙%|상승 관측%|날짜 평균 하방%|잠김 봉 포함|','|---|---:|---|---:|---:|---:|---:|---:|---:|---:|']
    for scope,hs in summary['scopes'].items():
        for h,d in hs.items():
            for b,s in d['bands'].items():
                lines.append('|'+'|'.join(map(str,[scope,h,b,s['selected'],s['valid'],f(s['date_equal']['ret']),f(s['pooled_return']['median']),f(s['pooled_return']['positive_pct']),f(s['date_equal']['low']),s['locked_valid']]))+'|')
    lines+=['','## 유동성·구성 및 누락','', '거래대금 단위는 원. 당시 하루 거래대금만으로 주문 체결 가능성을 확정할 수 없습니다. high=low 봉도 상·하한가 또는 거래 정지를 단정하지 않습니다.','',json.dumps(summary['liquidity'],ensure_ascii=False,indent=2),'', '관측별 누락 사유는 outcomes.jsonl.gz, 집계는 summary.json에 보존했습니다. 2025~2026년 전체 종목 자료는 이 비교에 포함되지 않았습니다. 기존 점수 평가와 겹치는 날짜는 matched_score_window에서 따로 비교했습니다.']
    return '\n'.join(lines)+'\n'

def html_page(md):
    parts=[];table=False
    for l in md.splitlines():
        if l.startswith('|'):
            cells=l.strip('|').split('|')
            if all(set(c)<=set(':- ') for c in cells):continue
            if not table:parts.append('<div style="overflow:auto"><table>');table=True
            parts.append('<tr>'+''.join('<td>'+html.escape(c)+'</td>' for c in cells)+'</tr>');continue
        if table:parts.append('</table></div>');table=False
        if l.startswith('# '):parts.append('<h1>'+html.escape(l[2:])+'</h1>')
        elif l.startswith('## '):parts.append('<h2>'+html.escape(l[3:])+'</h2>')
        elif l:parts.append('<p>'+html.escape(l)+'</p>')
    if table:parts.append('</table></div>')
    return '<!doctype html><html lang="ko"><meta charset="utf-8"><title>거래대금 순위 진단</title><style>body{font:15px/1.6 system-ui;margin:32px;color:#18283d}table{border-collapse:collapse;font-size:13px}td{padding:7px;border:1px solid #ddd;white-space:nowrap}tr:first-child{background:#dce8f6;font-weight:bold}h2{margin-top:35px}</style>'+''.join(parts)+'</html>'

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--inputs',type=Path,required=True);ap.add_argument('--prepared',type=Path,required=True);ap.add_argument('--output',type=Path,required=True);a=ap.parse_args()
    if a.output.exists():raise ValueError('Use a fresh output directory')
    module=Path(__file__).with_name('historical-amount-top100-pilot.py');spec=importlib.util.spec_from_file_location('pilot',module);pilot=importlib.util.module_from_spec(spec);spec.loader.exec_module(pilot)
    manifest=json.loads((a.prepared/'manifest.json').read_text());seg=next(s for s in manifest['segments'] if s['label']=='krx');days=seg['days']
    known=json.loads((a.prepared/'krx/candidates.json').read_text());assert sha(a.prepared/'krx/candidates.json')==seg['candidates_sha256']
    raw=defaultdict(list);archive_hashes={};prices=[];observations=[];raw_hashes={};duplicates=0
    with ExitStack() as stack:
        for item in manifest['archives']:
            p=a.inputs/item['name']
            if p.name.startswith('kr-dated'):continue
            digest=sha(p);assert digest==item['sha256'],p.name;archive_hashes[p.name]=digest
            z=stack.enter_context(zipfile.ZipFile(p))
            for n in z.namelist():
                if n.endswith('_equity.json') and ('/raw/' in n or '/2024_raw/' in n):raw[n.split('/')[-1]].append((z,n))
        expected={d.replace('-','')+'_'+m+'_equity.json' for d in days for m in ('kospi','kosdaq')};assert set(raw)==expected
        for i,day in enumerate(days):
            allrows=[];dayprices={};details={}
            for market in ('kospi','kosdaq'):
                name=day.replace('-','')+'_'+market+'_equity.json';copies=raw[name];b=copies[0][0].read(copies[0][1]);payload=json.loads(b)
                for z,n in copies[1:]:assert json.loads(z.read(n))==payload;duplicates+=1
                raw_hashes[name]=hashlib.sha256(b).hexdigest();block=payload['OutBlock_1']
                assert block and all(r['BAS_DD']==day.replace('-','') for r in block)
                allrows+=pilot.rows_from_openapi_block(block,'krx_openapi_'+market+'_equity')
                for r in block:
                    if r['MKT_NM'] not in ('KOSPI','KOSDAQ'):continue
                    symbol=pilot.yahoo_symbol(r['ISU_CD'],r['MKT_NM']);assert symbol not in dayprices
                    dayprices[symbol]=tuple(number(r[k]) for k in ('TDD_OPNPRC','TDD_HGPRC','TDD_LWPRC','TDD_CLSPRC','ACC_TRDVOL'))
                    details[symbol]=(number(r['MKTCAP']),r['MKT_NM'])
            ranked=rank_rows(allrows,pilot);assert len(ranked)==500
            assert [r['code'] for r in ranked[:100]]==[r['code'] for r in known[day]]
            assert [r['code'] for r in ranked[:100]]==[r['code'] for r in pilot.select_top100(allrows)['top100']]
            for rank,r in enumerate(ranked,1):
                symbol=pilot.yahoo_symbol(r['code'],r['market']);cap,board=details[symbol]
                observations.append(dict(index=i,date=day,symbol=symbol,rank=rank,band=BANDS[0 if rank<=100 else 1 if rank<=300 else 2],amount=r['amount_won'],market_cap=cap,board=board))
            prices.append(dayprices)
    print('raw validated; 431 dated TOP100 sets reproduced',flush=True)
    keeps=nonoverlap(observations);long=[]
    for r,keep in zip(observations,keeps):
        for h in HORIZONS:
            value,reason=forward(prices,r['index'],r['symbol'],h,len(days))
            ret,low,high,locked=value if value is not None else (None,None,None,False)
            long.append((r['date'],r['symbol'],r['band'],h,ret,low,high,locked,reason or 'ok',keep))
    df=pd.DataFrame(long,columns=['date','symbol','band','horizon','ret','low','high','locked','reason','nonoverlap']);del long,prices
    ob=pd.DataFrame(observations);liquidity={}
    for b,g in ob.groupby('band'):
        liquidity[b]={'observations':len(g),'unique_symbols':g.symbol.nunique(),'turnover_won_quantiles':{str(q):float(g.amount.quantile(q)) for q in (.1,.5,.9)},'median_market_cap_won':float(g.market_cap.median()),'kosdaq_pct':float(g.board.eq('KOSDAQ').mean()*100)}
    scopes={'all':df,'2023':df[df.date<'2024-01-01'],'2024_partial':df[df.date>='2024-01-01'],'matched_score_window':df[df.date>='2023-06-27']}
    summary={'coverage':{'from':days[0],'to':days[-1],'sessions':len(days),'selected_observations':len(ob),'horizon_observations':len(df),'raw_files':len(raw),'duplicate_copies_verified':duplicates},'liquidity':liquidity,'scopes':{name:summarize(g) for name,g in scopes.items()}}
    a.output.mkdir(parents=True)
    def dump(name,obj):atomic(a.output/name,json.dumps(obj,ensure_ascii=False,indent=2,allow_nan=False)+'\n')
    dump('summary.json',summary)
    # Atomically publish compressed row artifacts, then verify row counts.
    import gzip
    for name,frame in [('outcomes.jsonl.gz',df),('selection.jsonl.gz',ob)]:
        tmp=a.output/('.'+name+'.tmp')
        with gzip.open(tmp,'wt') as f:frame.to_json(f,orient='records',lines=True,force_ascii=False,double_precision=15)
        os.replace(tmp,a.output/name)
        with gzip.open(a.output/name,'rt') as f:assert sum(1 for _ in f)==len(frame)
    daily=df[df.reason=='ok'].groupby(['date','horizon','band']).agg(n=('ret','size'),mean_return=('ret','mean'),mean_low=('low','mean'),mean_high=('high','mean')).reset_index()
    atomic(a.output/'daily.json',daily.to_json(orient='records',force_ascii=False,double_precision=15))
    proto=Path(__file__).parents[1]/'amount-rank-diagnostic-v1/protocol.md'
    dump('provenance.json',{'archives':archive_hashes,'raw':raw_hashes,'manifest_sha256':sha(a.prepared/'manifest.json'),'script_sha256':sha(__file__),'selector_sha256':sha(module),'protocol_sha256':sha(proto),'account_execution':False})
    md=render(summary);atomic(a.output/'report.md',md);atomic(a.output/'report.html',html_page(md))
    dump('file-hashes.json',{p.name:sha(p) for p in a.output.iterdir() if p.is_file()})
    print(json.dumps(summary['coverage']))

if __name__=='__main__':main()
