"""Private HTML/JSON report from a completed original-score study. No market fetch."""
import os,argparse,collections,datetime,hashlib,html,json,pathlib,statistics,subprocess

def main():
 ap=argparse.ArgumentParser();ap.add_argument('--result',type=pathlib.Path,required=True);ap.add_argument('--input',type=pathlib.Path,required=True);a=ap.parse_args();out=a.result
 assert (out/'COMPLETE').is_file(),'Incomplete study'
 trades=json.loads((out/'trades.json').read_text());counts=json.loads((out/'summary-counts.json').read_text());manifest=json.loads((a.input/'manifest.json').read_text())
 root=pathlib.Path(__file__).resolve().parents[2]
 files=['src/'+s+'.php' for s in ['FeatureEngine','AccountPlaybook','AccountProfile','HorizontalLevels','NoramuLesson1','NoramuTopPattern','SpikeDump','ThemeSmell','CandleClock','PaperQuality','TradeSimulator']]+['docs/research/OriginalCompositeStudy.php','docs/research/original_composite_prepare.py','docs/research/original_composite_report.py','docs/original-composite-study-v1/protocol.md','tests/original_composite_study.php']
 hashes={f:hashlib.sha256((root/f).read_bytes()).hexdigest() for f in files}
 original=pathlib.Path(os.environ['ORIGINAL_SCORE_ROOT']);original_hashes={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in original.glob('*.php')}
 obs_counts=collections.Counter();actions=collections.Counter();year_obs=collections.defaultdict(collections.Counter);candidates=collections.Counter(); evaluated_days=set(); blocked_dates=collections.defaultdict(collections.Counter)
 for line in (out/'observations.jsonl').open():
  o=json.loads(line);obs_counts['total']+=1;year_obs[o['date'][:4]]['observations']+=1
  if 'blocked' in o:obs_counts['blocked']+=1;year_obs[o['date'][:4]]['blocked']+=1;blocked_dates[o['date']].update(o['blocked']);continue
  evaluated_days.add(o['date'])
  obs_counts['evaluated']+=1;year_obs[o['date'][:4]]['evaluated']+=1;actions[o['action']]+=1
  assert o['score']==o['breakdown']['final_score']
  if o['action']=='add_on_pullback':candidates[o['date'][:4]]+=1
 def agg(rows):
  closed=[r for r in rows if r['trade']['status']=='closed'];rets=[r['trade']['net_return_pct'] for r in closed];pnl=[r['pnl_won'] for r in closed]
  for r in closed:
   t=r['trade'];assert t['entry_at']>r['plan']['signal_at'];assert t['exit_at']>=t['entry_at'];assert abs(((t['exit_fill']*.999)/(t['entry_fill']*1.001)-1)*100-t['net_return_pct'])<.0002
   assert abs((r['sell_proceeds']-r['buy_cost'])-r['pnl_won'])<.0001
  gain=sum(p for p in pnl if p>0);loss=sum(p for p in pnl if p<0)
  return {'attempts':len(rows),'valid_orders':sum(r['trade']['status']!='invalid_levels' for r in rows),'filled':sum(bool(r['trade']['filled']) for r in rows),'closed':len(closed),'unresolved':sum(r['trade']['status']=='unresolved' for r in rows),'unresolved_filled':sum(r['trade']['status']=='unresolved' and r['trade']['filled'] for r in rows),'status_counts':dict(collections.Counter(r['trade']['status'] for r in rows)),'exit_counts':dict(collections.Counter(r['trade']['first_exit'] for r in closed)),'mean_pct':statistics.mean(rets) if rets else None,'median_pct':statistics.median(rets) if rets else None,'win_pct':sum(v>0 for v in rets)/len(rets)*100 if rets else None,'profit_won':gain,'loss_won':loss,'net_won':sum(pnl),'profit_factor':gain/-loss if loss else None,'mean_holding_bars':statistics.mean(r['trade']['bars'] for r in closed) if closed else None,'worst_pct':min(rets) if rets else None,'best_pct':max(rets) if rets else None}
 annual={year:dict(year_obs[year])|{'candidates':candidates[year]}|agg([r for r in trades if r['date'].startswith(year)]) for year in sorted(year_obs)}
 summary={'definitions':'Independent trades, not a portfolio; stored unverified price basis; annual grouping by signal date','evaluated_session_count':len(evaluated_days),'first_evaluated':min(evaluated_days),'last_evaluated':max(evaluated_days),'fully_unavailable_days':{d:dict(blocked_dates[d]) for seg in manifest['segments'] for d in seg['days'] if min(evaluated_days)<=d<=max(evaluated_days) and d not in evaluated_days},'observations':dict(obs_counts),'actions':dict(actions),'segments':{s['label']:agg([r for r in trades if r['segment']==s['label']]) for s in manifest['segments']},'annual':annual,'all':agg(trades),'counts':counts}
 (out/'summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2))
 (out/'provenance.json').write_text(json.dumps({'score_commit':'e39d0f38a3c2dedc7258062281ed5ac317fb1156','execution_commit':'be02d7c75d3d466e5c56e868c0e1e90dbac5ccf2','original_score_hashes':original_hashes,'code_hashes':hashes,'input_manifest_sha256':hashlib.sha256((a.input/'manifest.json').read_bytes()).hexdigest(),'source_archives':manifest['archives'],'checks':['all TOP100 rows have unique codes','prepared input hashes verified','future bars cannot affect assessments fixture','all closed cash/return formulas reconciled'],'limitations':['research execution adapter, not original historical automated account','independent budgets and no portfolio limits','corporate actions and adjustment unverified','no cross-source price/position stitching','calendar session close standardized to 15:30 KST','full entry/exit-bar extrema not exact intraday held extrema']},ensure_ascii=False,indent=2))
 def fmt(v,n=2):return '—' if v is None else f'{v:,.{n}f}'
 def day(t):return datetime.datetime.fromtimestamp(t,datetime.timezone(datetime.timedelta(hours=9))).strftime('%Y-%m-%d') if t else '—'
 labels={'krx':'KRX 2023~2024-10-07','yahoo_prior':'Yahoo 2024-10-08~2025-10-02','yahoo_recent':'Yahoo 2025-10-10~2026-10-08'}
 def table(headers,rows):return '<table><thead><tr>'+''.join('<th>'+html.escape(str(x))+'</th>' for x in headers)+'</tr></thead><tbody>'+''.join('<tr>'+''.join('<td>'+html.escape(str(x))+'</td>' for x in row)+'</tr>' for row in rows)+'</tbody></table>'
 report=['<!doctype html><html lang="ko"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>기존 종합점수 연구</title><style>body{font:15px/1.65 system-ui,sans-serif;background:#f4f6f8;color:#162334;margin:0;padding:30px}main{max-width:1500px;margin:auto}h1{font-size:28px}h2{margin-top:35px}table{border-collapse:collapse;width:100%;background:white;font-size:13px}td,th{padding:9px;border-bottom:1px solid #ddd;text-align:right;white-space:nowrap}th{background:#18354b;color:white;position:sticky;top:0}td:first-child,th:first-child{text-align:left}.scroll{overflow:auto;max-height:650px}.note{background:#fff0d9;padding:18px;border-left:4px solid #c27d14}input{padding:10px;width:330px;max-width:90%;margin:10px 0}.muted{color:#526271}</style><main><h1>기존 종합점수만 사용한 과거 연구</h1>',
 '<p>자료 범위 2023-01-02~2026-10-08 · 918거래일 · 당일 TOP100 · 종합점수/원래 판단 코드 유지</p>',
 '<div class="note"><b>아래 금액은 거래마다 1,000만 원을 배정한 독립 거래 합계입니다.</b> 한 계좌에 1,000만 원을 넣고 얻은 수익이나 복리 수익이 아닙니다. 가격의 수정·기업행사는 미검증이며, 구간 경계를 넘는 보유와 자료 누락은 미확정으로 남겼습니다.</div>',
 '<h2>원래 판단과 연구 체결 규칙</h2><p>2026-09-03 초기 버전(e39d0f3)의 FeatureEngine → AccountPlaybook(custom)의 add_on_pullback만 매수 후보로 사용합니다. 70점·저점 상승·최근 하방 슈팅과 기존 선행 차단을 그대로 적용합니다. 관심 후보는 진입시키지 않습니다. 원래 중간가격 지정가·구조 손절·1차 목표를 사용하고, 체결은 신호 다음 세션부터 3봉 유효·최대 20봉 보유·수수료 각 10bp/슬리피지 5bp의 기존 시뮬레이터로 계산했습니다. 이 체결 가정은 원래 점수 자체와 별개입니다. 120봉 준비와 기존 자료품질 검사를 적용했습니다.</p>',
 '<h2>구간별 결과</h2>',table(['가격 구간','주문','체결','청산','미확정','평균 순익%','승률%','이익합 원','손실합 원','순손익합 원'],[[labels[k],v['valid_orders'],v['filled'],v['closed'],v['unresolved'],fmt(v['mean_pct']),fmt(v['win_pct']),fmt(v['profit_won'],0),fmt(v['loss_won'],0),fmt(v['net_won'],0)] for k,v in summary['segments'].items()]),
 '<h2>연도별 결과 — 신호 발생 연도</h2>',table(['연도','평가','매수 후보','주문','체결','청산','평균 순익%','중앙값%','순손익합 원'],[[k,v['evaluated'],v['candidates'],v['valid_orders'],v['filled'],v['closed'],fmt(v['mean_pct']),fmt(v['median_pct']),fmt(v['net_won'],0)] for k,v in annual.items()]),
 '<h2>누락과 미확정</h2><p>실제 평가일 '+str(len(evaluated_days))+'일 ('+min(evaluated_days)+'~'+max(evaluated_days)+'). 준비 기간 이후 전 종목 평가 불가일: '+html.escape(', '.join(summary['fully_unavailable_days']))+'. 평가 불가일은 추천 0건으로 해석하지 않습니다.</p>',table(['구간','관찰','평가','평가 불가','첫 평가일','후보','중복 억제','상태'],[[s['segment'],s['observations'],s['evaluated'],s['blocked_observations'],s['first_evaluated'],s['candidate_signals'],s['overlap_suppressed'],json.dumps(s['trade_status'],ensure_ascii=False)] for s in counts]),
 '<p class="muted">평가 불가는 미추천이 아닙니다. 같은 종목의 주문·보유가 끝나기 전 후속 신호는 중복 진입시키지 않았습니다. 결과 미확정 후에는 해당 종목의 그 구간 후속 주문도 보류합니다. 종료된 거래만 손익 평균에 포함합니다.</p>',
 '<h2>개별 거래 — 수익률 높은 순</h2><p>봉 고가/저가 상승·하락률은 체결일부터 청산일까지 일봉 전체 범위이며, 체결 전·청산 후 같은 날 움직임이 포함될 수 있습니다. 업종은 당시 분류 자료를 이 연구에 연결하지 않아 미확정입니다.</p><input id="q" placeholder="종목명·코드·날짜·상태 검색"><div class="scroll">']
 rows=[]
 for r in sorted(trades,key=lambda r:(r['trade']['net_return_pct'] is not None,r['trade']['net_return_pct'] or 0),reverse=True):
  t=r['trade'];rows.append([r['name'],r['symbol'],r['date'],r['score'],t['status'],day(t['entry_at']),fmt(t['entry_fill']),day(t['exit_at']),fmt(t['exit_fill']),fmt(t['net_return_pct']),fmt(r.get('pnl_won'),0),t['bars'] if t['filled'] else '—',r.get('holding_calendar_days','—'),fmt(r.get('holding_bar_high_pct')),fmt(r.get('holding_bar_low_pct')),t['first_exit'] or t.get('unresolved_reason','—'),r['segment']])
 report.append(table(['종목','코드','신호일','점수','상태','체결일','매수가','청산일','매도가','순익%','순손익 원','보유봉','경과일','봉고가%','봉저가%','청산/미확정 사유','가격 구간'],rows))
 report.append('</div><p>상세 원본: trades.json · observations.jsonl · summary.json · provenance.json. 운영·설정·예약·기존 장부 변경 없음.</p></main><script>document.getElementById("q").oninput=e=>{let q=e.target.value.toLowerCase();document.querySelectorAll(".scroll tbody tr").forEach(r=>r.hidden=!r.textContent.toLowerCase().includes(q))}</script></html>')
 (out/'report.html').write_text('\n'.join(report))
 print(json.dumps(summary,ensure_ascii=False,indent=2))
if __name__=='__main__':main()
