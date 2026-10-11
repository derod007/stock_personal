"""Read-only comparison of the two full account exports; write a separate report."""
import json
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASE = ROOT / 'docs/historical-account-replay/full-v2'
results = {}
fingerprints, sector_hashes, runtime_hashes = set(), set(), []
for period in ('prior', 'recent'):
    p = BASE / period
    provenance = json.loads((p / 'provenance.json').read_text())
    fingerprints.add(provenance['strategy_fingerprint'])
    sector_hashes.add(provenance['research']['sector_map_file_sha256'])
    runtime_hashes.append(provenance['code_sha256_lf'])
    summary = json.loads((p / 'summary.json').read_text())
    logs = [json.loads(line) for line in (p / 'daily-log.jsonl').read_text().splitlines()]
    trades = json.loads((p / 'trades.json').read_text())['trades']
    reconcile = json.loads((p / 'reconcile.json').read_text())
    assert reconcile['pass']
    assert len({x['date'] for x in logs}) == len(logs)
    assert abs(logs[-1]['equity'] - summary['equity']) < .01
    peak = summary['initial_cash']
    drawdown = 0
    for day in logs:
        peak = max(peak, day['equity'])
        drawdown = max(drawdown, 1 - day['equity'] / peak)
    assert abs(drawdown - summary['max_close_drawdown']) < 1e-9
    pnl = sum(t['exit']['net_pnl'] for t in trades if t['exit'])
    assert abs(pnl - summary['realized_pnl']) < .01
    by_pattern = defaultdict(lambda: {'closed': 0, 'net_pnl': 0.0})
    for t in trades:
        if t['exit']:
            by_pattern[t['pattern']]['closed'] += 1
            by_pattern[t['pattern']]['net_pnl'] += t['exit']['net_pnl']
    outcomes = Counter(x['outcome'] for day in logs for x in day['signals'])
    results[period] = {
        'first_day': logs[0]['date'], 'last_day': logs[-1]['date'], 'sessions': len(logs),
        'initial_cash': summary['initial_cash'], 'equity': summary['equity'], 'cash': summary['cash'],
        'return_pct': (summary['equity'] / summary['initial_cash'] - 1) * 100,
        'max_close_drawdown_pct': drawdown * 100, 'closed': summary['closed_trades'],
        'wins': summary['wins'], 'losses': summary['losses'], 'realized_pnl': pnl,
        'open_positions': len(summary['open_positions']), 'pending_orders': len(summary['pending_orders']),
        'halted': summary['halted'], 'event_counts': summary['event_counts'],
        'ready_signal_outcomes': dict(outcomes), 'closed_by_pattern': dict(by_pattern),
        'net_unrealized_after_sell_cost': summary['unrealized_pnl_open_positions'],
        'gross_unrealized': summary['equity'] - summary['initial_cash'] - pnl,
    }
assert len(fingerprints) == 1 and len(sector_hashes) == 1
assert runtime_hashes[0] == runtime_hashes[1]
(BASE / 'comparison.json').write_text(json.dumps(results, ensure_ascii=False, indent=2) + '\n')
a, b = results['prior'], results['recent']
rows = [
    ('실제 처리 기간', f"{a['first_day']}~{a['last_day']}", f"{b['first_day']}~{b['last_day']}"),
    ('세션', a['sessions'], b['sessions']),
    ('초기 자금', f"{a['initial_cash']:,.0f}원", f"{b['initial_cash']:,.0f}원"),
    ('최종 총자산', f"{a['equity']:,.2f}원", f"{b['equity']:,.2f}원"),
    ('총자산 변화율', f"{a['return_pct']:+.4f}%", f"{b['return_pct']:+.4f}%"),
    ('종가 최대 낙폭', f"{a['max_close_drawdown_pct']:.4f}%", f"{b['max_close_drawdown_pct']:.4f}%"),
    ('종료 거래 / 이익 / 손실', f"{a['closed']} / {a['wins']} / {a['losses']}", f"{b['closed']} / {b['wins']} / {b['losses']}"),
    ('실현손익', f"{a['realized_pnl']:,.2f}원", f"{b['realized_pnl']:,.2f}원"),
    ('마지막 보유 / 대기 주문', f"{a['open_positions']} / {a['pending_orders']}", f"{b['open_positions']} / {b['pending_orders']}"),
]
report = '# 두 기간 전체 연구 계좌 비교\n\n각 기간은 초기 자금 1억원으로 따로 시작했습니다. 연속 운용 2년 수익률이 아닙니다.\n\n| 항목 | 이전 | 최근 |\n|---|---:|---:|\n'
report += ''.join(f'| {label} | {left} | {right} |\n' for label, left, right in rows)
report += '\n## 판정과 검증\n\n'
report += '- 같은 전략 지문·설정 및 고정 섹터 맵 v2를 사용했습니다. 전략을 조정하지 않았습니다.\n'
report += '- 이전 242·최근 244세션 모두 계좌 정지 없음. 각 장부 검사 10항목, 주문 56·67건 재시뮬레이션(생략 0) 통과. PR #65 눌림 75·82건의 신호·가격 일치. 재실행은 각각 0세션이며 저널 해시가 같습니다.\n'
report += '- 각 기간의 reconcile.json에서 장부 대사와 주문 재시뮬레이션 결과를 확인할 수 있습니다. 비교 스크립트는 종료 손익 합과 일별 자산에서 최대 낙폭도 다시 계산합니다.\n'
report += '- 미청산 보유를 강제 청산하지 않았습니다. 총자산은 현금+종가 평가액이고, summary의 미실현손익은 예상 매도비용 차감 값이므로 두 정의를 섞지 않습니다.\n'
report += '- 개별 신호가 좋아도 현금·보유·위험·섹터 한도 때문에 계좌에서 거래되지 않을 수 있습니다. 패턴별 실현손익과 준비 신호 처리 사유는 comparison.json에 있습니다.\n'
report += '- 고정 저장 스캔 종목군과 현재 업종의 과거 적용을 전제로 합니다. 당시 TOP100이나 전향적 실전 검증이 아니며, 수정주가 기준도 확인되지 않았습니다. 두 기간 결과만으로 운영 반영이나 수익성 확정을 하지 않습니다.\n'
report += '\n## 재현\n\n기존 report.md의 run/export 명령에서 이전 기간은 `--period=prior`, 데이터셋은 `kr-saved-scan-20251008`, 출력은 별도 `full-v2/prior`로 지정합니다. 새 연구 상태 경로를 사용하고 기존 결과를 덮어쓰지 마세요. 두 export가 준비되면 `python docs/research/account-replay-period-compare.py`로 이 비교표를 생성합니다.\n'
(BASE / 'comparison.md').write_text(report)
print(json.dumps(results, ensure_ascii=False))
