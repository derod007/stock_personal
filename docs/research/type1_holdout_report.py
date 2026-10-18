"""Summarize fixed holdout collection and the two unchanged execution definitions."""
import argparse,collections,hashlib,json,lzma
from pathlib import Path

def read(p):return json.loads(p.read_text())
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def stats(trades,engine):
    monthly=collections.defaultdict(lambda:{'trades':0,'realized_pnl':0.0});symbols=collections.defaultdict(float);reasons=collections.Counter()
    for t in trades:
        pnl=t['exit']['net_pnl'] if engine else t['pnl'];month=t['exit_day'][:7]
        monthly[month]['trades']+=1;monthly[month]['realized_pnl']+=pnl;symbols[t['symbol']]+=pnl
        reasons[t['exit']['reason'] if engine else t['exit_reason']]+=1
    return dict(monthly_realized=dict(sorted(monthly.items())),symbol_realized=dict(sorted(symbols.items(),key=lambda p:-p[1])),exit_reasons=dict(reasons))
def main():
    ap=argparse.ArgumentParser();ap.add_argument('--dataset',type=Path,required=True);ap.add_argument('--sectors',type=Path,required=True);ap.add_argument('--run',type=Path,required=True);ap.add_argument('--report',type=Path,required=True);a=ap.parse_args()
    coll=read(a.dataset/'collection.json');sectors=read(a.sectors);opening=read(a.run/'opening/results.json');engine={arm:read(a.run/'limit'/f'{arm}.json') for arm in ['all_types','type1']};inp=read(a.run/'engine-input.json')
    arms={};lines=['# 유형 1 미사용 역사 구간 검증','', '기간: 2023-07-03~2024-06-28. 미래 기간에서 만든 고정 중심을 이전 구간에 적용한 연구이며 전진 검증이 아니다.','', '| 모형 | 표본 | 상태 | 마지막 정상일 | 전체 기간 수익률 | 정상 처리일까지 평가수익률 | 낙폭(처리 구간) | 체결/청산 |','|---|---|---|---|---:|---:|---:|---:|']
    for model,results in [('opening',opening['results']),('limit',engine)]:
        for arm,r in results.items():
            eng=model=='limit';counts=r['counts'] if eng else {};through=r.get('last_good_day') if eng else r['through'];fills=counts.get('fill',0) if eng else r['fills'];closed=counts.get('exit',0) if eng else r['closed'];complete=r['status']=='completed'
            eq=[e['payload']['equity'] for e in r['events'] if e['type']=='equity' and e['day']==through][-1] if eng else r['equity'][-1]['equity']
            partial=(eq/opening['config']['initial_cash']-1)*100
            audit=r.get('audit',{});halt=r.get('halt') if not eng else {'state':{k:v for k,v in r['state'].items() if k in ['halted','halt_reason','halted_at']},'quality':r.get('quality',[])[-10:],'events':[e for e in r['events'] if e['type']=='account_halted']}
            arms[model+'_'+arm]=dict(status=r['status'],last_good_day=through,return_pct=r['return_pct'] if complete else None,partial_marked_return_pct=partial,partial_return_through=through,max_drawdown_pct=r['max_drawdown_pct'],drawdown_scope='full_period' if complete else 'partial_only',fills=fills,closed=closed,blocked=r['blocked'],cancelled=r.get('cancelled'),halt=halt,audit=audit,**stats(r['trades'],eng))
            val=f"{r['return_pct']:+.2f}%" if complete else '산출 안 함'
            lines.append(f"| {model} | {arm} | {r['status']} | {through} | {val} | {partial:+.2f}% | {r['max_drawdown_pct']:.2f}% | {fills}/{closed} |")
    summary=dict(period=['2023-07-03','2024-06-28'],sessions=243,collection=dict(symbols=len(coll),saved=sum(r['status']=='saved' for r in coll),unavailable=[r for r in coll if r['status']!='saved'],issues=dict(collections.Counter(x['reason'] for r in coll for x in r.get('issues',[])))),sector_counts=dict(collections.Counter(sectors['sectors'].values())),signals=len(inp['signals']),clusters=dict(collections.Counter(s['cluster'] for s in inp['signals'])),skips=inp['skips'],arms=arms,hashes=dict(frozen_dataset_manifest=sha(a.dataset/'frozen-hashes.json'),centers=inp['hashes']['centers'],candidates=inp['hashes']['candidates'],sectors=sha(a.sectors),engine_input=sha(a.run/'engine-input.json')))
    lines+=['','## 자료와 해석','',f"Yahoo {len(coll)}심볼 요청, {summary['collection']['saved']}개 저장. 미확보 {len(summary['collection']['unavailable'])}개는 원인별로 summary.json에 남겼다. 과거 TOP100 등장 심볼은 삭제하지 않았다.",f"분류 신호 {len(inp['signals'])}건, 유형별 {summary['clusters']}. 분류 제외 사유 {inp['skips']}.",f"업종: {summary['sector_counts']}. 현재 업종의 과거 적용 가정이며 미분류는 한 그룹이다.",'','완주하지 못한 결과의 수익률은 전체 기간 성과로 표시하지 않는다. 중단 이후의 손익은 미확정이며 입력을 고치고 같은 계좌를 재실행하지 않았다. 월별 값은 청산 거래의 실현손익이며 월간 계좌 수익률이 아니다. 종목별 기여도도 청산 거래만 집계한다.','', '## 고정 조건과 한계','', '- 중심·유형 1·손절 5%·목표 15%·20세션·한도 규칙은 그대로다. 전체 유형과 유형 1을 두 방식 모두 보고한다.', '- opening은 다음 시가 기준 사이징, limit는 T종가 기준 지정가/손절/목표와 기존 PHP 엔진이다. 두 방식 차이를 단순 체결 방식 하나의 효과로 해석하지 않는다.', '- Yahoo quote OHLCV를 그대로 보존했다. adjclose 적용·KRX 가격 대체·가격 보정 없음. 이전 데이터셋의 Naver 확인 종가 보정 파이프라인과는 차이가 있으므로 기간 차이의 원인을 전략 하나로 단정하지 않는다.', '- 2023-01-01부터 준비 이력을 요청했다. 종목별 60봉 부족은 그대로 분류 제외된다. 기간말 20세션 부족 제외는 기존 코드와 같다.', '- 022100·066970의 시장별 식별자를 이어 붙이지 않았다. 03473K.KS는 동일 심볼/KRW/EQUITY 응답을 직접 확인했다.', '- Yahoo instrumentType이 EQUITY가 아니거나 심볼/통화가 다르면 해당 응답으로 가격 파일을 만들지 않았다. 이는 원본의 실제 상장 유형을 확정한 판정이 아니다.', '- 운영 코드·설정·예약·기존 장부 변경 없음. 새 연구 계좌만 실행했고 병합하지 않는다.','', '상세 중단 원인, 월별 실현손익, 종목별 기여도, 취소/한도 차단은 summary.json과 압축 실행 기록에 있다.']
    audit_path=a.report/'price-audit.json'
    if audit_path.exists():
        prices=read(audit_path);summary['price_audit_sha256']=sha(audit_path)
        lines+=['', '## 2024-01-15 중단 원본 확인', '', f"같은 날짜의 저장 Yahoo 봉 {prices['halt_date_affected']}개에서 OHLC 모순을 확인했다. 봉 자체가 없는 경우와 구분해야 한다. 시가 모형의 missing_required_bar는 유효성 검사에서 제외된 봉도 포함한다.", '', '| 심볼 | Yahoo 고가/저가/종가 | KRX 고가/저가/종가 |', '|---|---:|---:|']
        for sym in ['003010.KS','012330.KS','041510.KQ']:
            r=prices['halt_date_rows'][sym];y=r['yahoo'];k=r['krx']
            lines.append(f"| {sym} | {y['high']:,.0f} / {y['low']:,.0f} / {y['close']:,.0f} | {k['high']:,.0f} / {k['low']:,.0f} / {k['close']:,.0f} |")
        lines+=['', 'KRX는 원인 대조에만 사용했다. Yahoo 내부 생성 오류의 원인·수정주가 기준 일치 여부는 미확정이다. KRX 값으로 고치거나 원본/계좌를 다시 실행하지 않았다.', '', '정상 처리된 2023-07-03~2024-01-12 구간에서는 유형 1의 평가수익률과 낙폭이 두 모형 모두 전체 유형보다 좋았다. 가격 문제로 종료된 부분 구간의 관찰이며 연간 성과 또는 운영 채택 판정이 아니다. 다음은 가격 입력의 일관성을 해결할 별도 프로토콜 검토가 필요하다.']
    a.report.mkdir(parents=True,exist_ok=True);(a.report/'summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n');(a.report/'report.md').write_text('\n'.join(lines)+'\n')
    files={'opening-results.json':a.run/'opening/results.json','limit-all_types.json':a.run/'limit/all_types.json','limit-type1.json':a.run/'limit/type1.json','engine-input.json':a.run/'engine-input.json'}
    artifacts={}
    for name,path in files.items():
        b=path.read_bytes();dest=a.report/(name+'.xz');dest.write_bytes(lzma.compress(b));artifacts[dest.name]={'sha256':sha(dest),'uncompressed_sha256':sha(path)}
    (a.report/'artifacts.json').write_text(json.dumps(artifacts,indent=2)+'\n')
if __name__=='__main__':main()
