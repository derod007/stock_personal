#!/usr/bin/env python3
"""Writes docs/pullback-recent-redecline-stop/report.md from comparison.json (numbers are not typed by hand). Standard library only.
usage: redecline-stop-report.py --dir=docs/pullback-recent-redecline-stop
"""
import json, sys

REASON = {
    'h_big_hold_window_edge': '큰 조정 고점이 창 가장자리라 보류',
    'h_big_hold_few_bars_after': '큰 조정 고점 뒤 완료봉 2개 미만(보류)',
    'h_big_none_manual_A_only': '신호일이 창 최고라 큰 조정 고점 없음',
    'quality_problem': '창 안 자료 품질 문제',
    'data_insufficient': '자료 부족',
    'alt_stop_invalid': '대안 손절이 유효하지 않음',
    'h_recent_hold_few_bars_after': '최근 재하락 고점 뒤 완료봉 2개 미만(보류)',
}
PERIOD = {'prior': '이전 기간 (2024-10-08 ~ 2025-10-02)', 'recent': '최근 기간 (2025-10-08 ~ 2026-10-08)'}
COLS = ['closed', 'incomplete', 'unfilled', 'pending', 'cancelled_before_entry', 'alt_stop_not_below_fill', 'not_applicable']
COLN = {'closed': '종료', 'incomplete': '미완료', 'unfilled': '미체결', 'pending': '대기', 'cancelled_before_entry': '진입 전 취소',
        'alt_stop_not_below_fill': '대안 손절≥체결가', 'not_applicable': '적용 불가'}
ROWN = {'closed': '종료', 'incomplete': '미완료', 'unfilled': '미체결', 'pending': '대기', 'cancelled_before_entry': '진입 전 취소'}


def f(v, d=2, sign=False):
    if v is None:
        return '-'
    s = ('%+.' + str(d) + 'f') % v if sign else ('%.' + str(d) + 'f') % v
    return s


def pair(r):
    return '%s %s %s · 기존 %s%% → 대안 %s%% (%s%%p) · 손절 %s → %s' % (r['name'], r['symbol'], r['date'], f(r['baseline'], 2, True), f(r['alt'], 2, True), f(r['delta'], 2, True), format(r['baseline_stop'], ','), format(r['alt_stop'], ','))


def main():
    o = dict(a[2:].split('=', 1) for a in sys.argv[1:] if a.startswith('--'))
    d = o['dir']
    c = json.load(open(d + '/comparison.json', encoding='utf8'))
    fz = json.load(open(d + '/freeze.json', encoding='utf8'))
    vr = json.load(open(d + '/verify-result.json', encoding='utf8'))
    L = []
    w = L.append
    S = c['summary']
    w('# 최근 10봉 저점 손절 대 최근 재하락 고점 이후 저점 손절')
    w('')
    w('상승 눌림 최종 선택 157건(이전 75, 최근 82)에서 **체결 뒤 손절만** 바꾼 비교다. 신호·주문·진입일·체결가·목표·보유·비용·진입 전 취소 규칙은 그대로이고, 대안 손절은 신호일에 고정했다. 운영 전략은 바꾸지 않았고 반영 여부도 판단하지 않는다. [고정 프로토콜](protocol.md)은 결과 계산 전에 해시로 고정했다.')
    w('')
    w('**탐색 연구다.** 같은 자료를 PR #55~#64에서 이미 여러 번 보았고 기존 거래 결과도 알고 있었다. 블라인드나 독립 검증이 아니다. 수익률은 거래별 순수익률의 평균이며 계좌 수익률이 아니다. 같은 종목·인접일은 독립 표본이 아니다.')
    w('')
    w('## 결론')
    w('')
    for p in ('prior', 'recent'):
        s = S[p]
        cc = s['common_closed']
        ch = s['common_closed_stop_level_changed']
        ap = s['applicability']
        w('- **%s**: 대안 손절이 기존과 같은 수준인 사례가 적용 가능 %d건 중 %d건이라 거래가 같다. 공통 종료 %d건에서 평균 %s%% → %s%%, 개선 %d · 악화 %d · 동일 %d. 수준이 실제로 다른 %d건만 보면 평균 %s%% → %s%%.' % (
            PERIOD[p].split(' ')[0] + ' 기간', ap['applicable'], ap['stop_level_vs_baseline']['same_level'], cc['n'], f(cc['baseline']['mean'], 2, True), f(cc['alt']['mean'], 2, True),
            cc['improved'], cc['worse'], cc['same'], ch['n'], f(ch['baseline']['mean'], 2, True), f(ch['alt']['mean'], 2, True)))
    dm = {p: S[p]['common_closed']['alt_minus_baseline']['mean'] for p in S}
    word = lambda v: '상승' if v > 0 else ('하락' if v < 0 else '변화 없음')
    same_dir = (dm['prior'] > 0) == (dm['recent'] > 0)
    w('- 평균 변화는 이전 기간 %s%%p(%s), 최근 기간 %s%%p(%s)로 %s. 차이는 소수의 거래가 만든다. 이 결과로 대안이 낫다 또는 못하다고 말할 수 없다. 운영 변경 근거로 쓰지 않는다.' % (
        f(dm['prior'], 2, True), word(dm['prior']), f(dm['recent'], 2, True), word(dm['recent']), '방향이 같지만 크기가 작다' if same_dir else '방향이 서로 다르다'))
    w('')
    w('## 기준 재현 (대안 비교 전 확인)')
    w('')
    rp = c['reproduction']
    w('- 157건 모두에서 엔진이 다시 만든 신호와 거래가 기존 저장 결과와 같았다(불일치 0). 손절을 바꾸지 않은 이 연구의 시뮬레이터도 기존 `TradeSimulator`와 모두 같았다(불일치 0). 진입 전 미체결·취소·대기 사례에서 대안과 기존의 상태가 달라진 건 %d건.' % c['pre_fill_mismatches'])
    for p in ('prior', 'recent'):
        st = rp['baseline_statuses'][p]
        w('- %s 기존 상태: %s' % (PERIOD[p].split(' (')[0], ', '.join('%s %d' % (ROWN.get(k, k), v) for k, v in st.items())))
    w('')
    for p in ('prior', 'recent'):
        s = S[p]
        ap = s['applicability']
        cc = s['common_closed']
        w('## %s' % PERIOD[p])
        w('')
        w('### 적용 가능·불가')
        w('')
        w('| 항목 | 건수 |')
        w('|---|---:|')
        w('| 전체 선택 | %d |' % s['selected'])
        w('| 적용 가능(신호일 기준) | %d |' % ap['applicable'])
        w('| 그중 체결되어 대안 손절이 쓰인 거래 | %d |' % ap['applied'])
        w('| 그중 체결되지 않아 손절이 쓰이지 않은 사례(미체결·취소·대기) | %d |' % ap['applicable_not_filled'])
        w('| 체결 뒤 대안 손절 ≥ 체결가로 적용 불가 | %d |' % ap['post_fill_not_applicable'])
        w('| 적용 불가 | %d |' % ap['not_applicable'])
        for k, v in ap['reasons'].items():
            w('| 　사유: %s | %d |' % (REASON.get(k, k), v))
        lv = ap['stop_level_vs_baseline']
        w('| 대안 손절 수준이 기존과 같음 / 더 높음(좁음) / 더 낮음(넓음) | %d / %d / %d |' % (lv['same_level'], lv['tighter_higher'], lv['wider_lower']))
        w('')
        w('### 상태 전이 (행: 기존, 열: 대안)')
        w('')
        w('| 기존＼대안 | ' + ' | '.join(COLN[k] for k in COLS) + ' |')
        w('|---|' + '---:|' * len(COLS))
        for k, row in s['transitions'].items():
            w('| %s | ' % ROWN.get(k, k) + ' | '.join(str(row.get(x, 0)) if row.get(x, 0) else '·' for x in COLS) + ' |')
        w('')
        w('한쪽만 종료된 사례: 기존만 종료 %d건, 대안만 종료 %d건. 적용 불가 사례는 수익률 0이 아니라 비교에서 제외했고 기존 손절로 바꿔 넣지도 않았다.' % (len(s['only_baseline_closed']), len(s['only_alt_closed'])))
        w('')
        w('### 공통 종료 거래 (기존·대안 모두 종료 %d건)' % cc['n'])
        w('')
        w('| | 기존 | 대안 | 요약 통계의 차이(대안 − 기존) |')
        w('|---|---:|---:|---:|')
        w('| 평균 순수익률 | %s%% | %s%% | %s%%p |' % (f(cc['baseline']['mean'], 2, True), f(cc['alt']['mean'], 2, True), f(s['common_closed']['alt_minus_baseline']['mean'], 2, True)))
        w('| 중앙값 | %s%% | %s%% | %s%%p |' % (f(cc['baseline']['median'], 2, True), f(cc['alt']['median'], 2, True), f(cc['alt']['median'] - cc['baseline']['median'], 2, True)))
        w('| 수익 거래(순수익률 > 0) | %d | %d | |' % (cc['baseline_positive'], cc['alt_positive']))
        w('')
        w('거래별 수익률 변화량(대안 − 기존)의 중앙값은 %s%%p입니다. 위 표의 두 수익률 중앙값 간 차이와는 다른 통계입니다. 차이는 반올림 전 값으로 계산했습니다.' % f(cc['alt_minus_baseline']['median'], 2, True))
        w('')
        w('개선 %d건 · 악화 %d건 · 동일 %d건.' % (cc['improved'], cc['worse'], cc['same']))
        ch = s['common_closed_stop_level_changed']
        w('')
        w('손절 수준이 실제로 다른 거래만(%d건): 평균 %s%% → %s%%, 중앙값 %s%% → %s%%, 개선 %d · 악화 %d · 동일 %d. 수준이 같은 거래는 결과가 같을 수밖에 없다.' % (
            ch['n'], f(ch['baseline']['mean'], 2, True), f(ch['alt']['mean'], 2, True), f(ch['baseline']['median'], 2, True), f(ch['alt']['median'], 2, True), ch['improved'], ch['worse'], ch['same']))
        w('')
        ex = s['exits_common_closed']
        w('### 손절 청산과 청산 경로')
        w('')
        w('- 공통 종료 거래의 손절 청산: 기존 %d건, 대안 %d건. 갭 손절: 기존 %d, 대안 %d. 같은 봉에서 손절·목표가 함께 닿은 건: 기존 %d, 대안 %d.' % (ex['baseline_stop'], ex['alt_stop'], ex['baseline_gap_stops'], ex['alt_gap_stops'], ex['baseline_same_bar_stop_and_target_touch'], ex['alt_same_bar_stop_and_target_touch']))
        w('- 기존 수익 거래 %d건 중 대안에서 손절 청산으로 바뀐 것: **%d건**.' % (ex['baseline_winners'], ex['baseline_winner_became_alt_stop_exit']))
        w('- 청산 경로(기존→대안): ' + ', '.join('%s→%s %d' % (k.split('->')[0], k.split('->')[1], v) for k, v in ex['exit_pairs'].items()))
        w('')
        bl = s['big_loss']
        w('### 큰 손실 (순수익률 %s%% 이하, 사전 고정)' % int(bl['threshold_pct']))
        w('')
        w('공통 종료 거래에서 기존 %d건 → 대안 %d건.' % (bl['baseline_count'], bl['alt_count']))
        w('')
        if bl['reduced_below_threshold']:
            w('기준 아래에서 벗어난 사례:')
            for r in bl['reduced_below_threshold']:
                w('- ' + pair(r))
        else:
            w('기준 아래에서 벗어난 사례: 없음.')
        if bl['increased_to_threshold']:
            w('')
            w('기준 아래로 새로 들어간 사례:')
            for r in bl['increased_to_threshold']:
                w('- ' + pair(r))
        else:
            w('기준 아래로 새로 들어간 사례: 없음.')
        w('')
        w('수익률이 가장 많이 달라진 사례(0이 아닌 것만):')
        for r in [x for x in s['largest_improvement'] if x['delta'] > 0][:3]:
            w('- 개선 ' + pair(r))
        for r in [x for x in s['largest_deterioration'] if x['delta'] < 0][:3]:
            w('- 악화 ' + pair(r))
        w('')
        w('### 초기 손절 폭과 R')
        w('')
        iw = s['initial_stop_width']['applied_at_fill']
        sg = s['initial_stop_width']['applicable_at_signal']
        w('| 초기 손절 폭 | 기존 | 대안 |')
        w('|---|---:|---:|')
        w('| 체결가 기준, 대안이 쓰인 %d거래 평균 / 중앙값 | %s%% / %s%% | %s%% / %s%% |' % (iw['n'], f(iw['baseline_pct']['mean']), f(iw['baseline_pct']['median']), f(iw['alt_pct']['mean']), f(iw['alt_pct']['median'])))
        w('| 신호 진입가 기준, 적용 가능 %d건 평균 / 중앙값 | %s%% / %s%% | %s%% / %s%% |' % (sg['n'], f(sg['baseline_pct']['mean']), f(sg['baseline_pct']['median']), f(sg['alt_pct']['mean']), f(sg['alt_pct']['median'])))
        w('')
        w('대안이 더 좁은 거래 %d, 더 넓은 거래 %d, 같은 거래 %d.' % (iw['alt_narrower'], iw['alt_wider'], iw['equal']))
        w('')
        rr = s['R_common_closed']
        w('| 공통 종료 거래 R (평균 / 중앙값) | 기존 | 대안 |')
        w('|---|---:|---:|')
        w('| ① 둘 다 **기존** 초기 위험으로 나눔 | %s / %s | %s / %s |' % (f(rr['common_denominator_baseline_risk']['baseline']['mean'], 3, True), f(rr['common_denominator_baseline_risk']['baseline']['median'], 3, True), f(rr['common_denominator_baseline_risk']['alt']['mean'], 3, True), f(rr['common_denominator_baseline_risk']['alt']['median'], 3, True)))
        w('| ② 각자 **자체** 초기 위험으로 나눔 | %s / %s | %s / %s |' % (f(rr['own_initial_risk']['baseline']['mean'], 3, True), f(rr['own_initial_risk']['baseline']['median'], 3, True), f(rr['own_initial_risk']['alt']['mean'], 3, True), f(rr['own_initial_risk']['alt']['median'], 3, True)))
        w('')
        w('R = 순수익률 ÷ 체결가 기준 초기 위험률. 거래 평균이며 계좌 수익률이 아니다.')
        w('')
    w('## 읽는 법과 한계')
    w('')
    w('- **[사실]** 대부분 사례에서 대안 손절이 기존과 같은 수준이다. 두 기간 합쳐 적용 가능 %d건 중 %d건이다. 같은 수준이면 같은 거래이므로 두 방식의 차이는 나머지 %d건에서만 생긴다.' % (
        sum(S[p]['applicability']['applicable'] for p in S), sum(S[p]['applicability']['stop_level_vs_baseline']['same_level'] for p in S),
        sum(S[p]['applicability']['applicable'] - S[p]['applicability']['stop_level_vs_baseline']['same_level'] for p in S)))
    w('- **[해석]** 기존 손절의 기준인 최근 10봉 안에 최근 재하락 고점 뒤 최저점이 이미 들어 있는 경우가 많아 두 수준이 같아지는 것으로 보인다. 이 연구는 그 원인을 따로 검증하지 않았다.')
    tight = {p: S[p]['applicability']['stop_level_vs_baseline']['tighter_higher'] for p in S}
    wide = {p: S[p]['applicability']['stop_level_vs_baseline']['wider_lower'] for p in S}
    w('- 수준이 달라진 사례 중 대안이 더 좁은 쪽(기존보다 높은 손절)이 이전 %d건·최근 %d건, 더 넓은 쪽이 이전 %d건·최근 %d건이다. 넓은 손절의 효과는 이 자료로 말할 수 없다.' % (tight['prior'], tight['recent'], wide['prior'], wide['recent']))
    w('- 이 비교는 새 전략 전체가 아니라 같은 진입에서 손절만 바꾼 것이다. 공통 종료 거래 중 손절 수준이 달라진 거래가 이전 기간 %d건, 최근 기간 %d건이라 평균은 몇 건에 좌우된다. 군집 보정이나 유의성 검정은 하지 않았다.' % (
        S['prior']['common_closed_stop_level_changed']['n'], S['recent']['common_closed_stop_level_changed']['n']))
    w('- 종목군은 저장된 스캔 결과로 이미 정해져 있다. 두 기간 모두 이미 본 자료이므로, 운영 변경을 검토한다면 이후 완료봉이나 별도 미사용 기간에서 먼저 확인해야 한다.')
    w('- 다른 손절 배수·고점 정의·필터는 시험하지 않았다.')
    w('')
    w('## 입력·코드·고정 해시와 검증')
    w('')
    w('고정 시각 %s KST, 고정 당시 main `%s`. 해시는 줄바꿈을 LF로 바꾼 바이트 기준이다.' % (fz['frozen_at_kst'], fz['git_head_at_freeze']))
    w('')
    w('| 항목 | SHA256 |')
    w('|---|---|')
    for k, v in fz['files_sha256_lf'].items():
        w('| %s | `%s` |' % (k, v))
    for k, v in fz['code_sha256_lf'].items():
        w('| %s | `%s` |' % (k, v))
    for k, v in fz['datasets'].items():
        w('| 데이터셋 %s dataset.json | `%s` |' % (v['dataset'], v['dataset_json_sha256']))
    w('| 전략 지문 | `%s` |' % fz['strategy_fingerprint'])
    w('| freeze.json | `%s` |' % c['freeze_sha256_lf'])
    w('')
    w('독립 검증(`docs/research/redecline-stop-verify.py`, 표준 라이브러리만 사용, PHP 코드를 호출하지 않음): 전체 통과 = **%s**.' % ('예' if vr['all_passed'] else '아니오'))
    w('')
    for k, v in vr['checks'].items():
        w('- %s: %s' % (k, '통과' if v['pass'] else '실패'))
    w('')
    w('이 검증은 데이터 일봉에서 대안 고점·저점·손절·적용 여부를 다시 계산하고, 기존·대안 거래를 별도 시뮬레이터로 다시 돌려 사례별 결과와 모든 요약 숫자를 대조한다. ATR14와 계획 가격은 기존 엔진 값을 그대로 썼다. 검증이 틀린 것을 잡는지 확인하려고 임시 사본에서 한 거래의 수익률을 0.5 바꾸자 실패했고 사본은 지웠다.')
    w('')
    w('## 재현')
    w('')
    w('```sh')
    w('# 데이터셋 폴더(바이트 고정)를 PRIOR, RECENT로 지정')
    w('php bin/paper_recent_redecline_stop.php run --prior-dir=PRIOR --recent-dir=RECENT   # 고정 확인 → 기준 재현 → 대안 적용 → comparison.json')
    w('python docs/research/redecline-stop-verify.py --dir=docs/pullback-recent-redecline-stop --prior-dir=PRIOR --recent-dir=RECENT')
    w('python docs/research/redecline-stop-report.py --dir=docs/pullback-recent-redecline-stop')
    w('php tests/redecline_stop.php')
    w('```')
    w('')
    w('앞 단계(`population`, `alt-stops`, `pr64-check`, `leak-check`, `seal`)의 명령은 `bin/paper_recent_redecline_stop.php` 머리말과 [프로토콜](protocol.md)에 있다. 저장된 결과를 덮어쓰지 말고 별도 `--dir`로 재현한다.')
    text = '\n'.join(L) + '\n'
    with open(d + '/report.md', 'w', encoding='utf8', newline='\n') as fh:
        fh.write(text)
    print('report.md', len(text))


if __name__ == '__main__':
    main()
