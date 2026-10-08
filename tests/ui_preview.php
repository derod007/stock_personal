<?php
// Static examples for layout checks; no account reads or writes.
require __DIR__.'/../bin/paper/Chrome.php';
require __DIR__.'/../bin/paper/PatternPanel.php';
require __DIR__.'/../bin/paper/ScorePanel.php';
paper_open(['title'=>'진입 확인 → 결과','page'=>'journey','account'=>'paper-kr']);
?>
<form class="paper-filter"><label>계좌<select><option>한국 모의</option></select></label><label>수동 프로필<select><option>기본 설정</option></select></label><button type="button">조회</button></form>
<p class="paper-lede">진입 확인 당시 계획과 기존 후속 모의 체결을 연결합니다. 실제 주문·계좌 체결과 다릅니다.</p>
<section class="panel"><h2>진입 확인·장중 관찰 기록</h2>
<details class="paper-trade" open><summary><span class="record-name">삼성E&A</span><span class="record-meta">2026-10-06 20:23:13 · 완료봉 기록</span><span class="record-state">아직 보유 중</span></summary>
<div class="scan-table-wrap"><table class="scan-table paper-kv"><tbody>
<tr><th>당시 최종 판정</th><td>확인됨</td></tr><tr><th>판정 근거</th><td>상승 추세 눌림 후 고점 회복 확인</td></tr>
<tr><th>당시 고정 가격 계획</th><td><?= paper_prices(['entry'=>48050,'stop'=>46260,'target'=>55200]) ?><p>손익비 3.994</p></td></tr>
<tr><th>체결일 / 체결가</th><td>2026-10-07 15:30:00 / 48,050</td></tr><tr><th>비용 반영 순손익</th><td>—</td></tr>
</tbody></table></div></details></section>
<section class="panel"><h2>거래대금 스캔 표 예시</h2><div class="scan-table-wrap"><table class="scan-table" id="scan-results"><thead><tr><th>거래대금 순위</th><th>종목</th><th>업종</th><th>현재가</th><th>등락</th><th>대금</th><th>구조 점수</th><th>분석 상태</th><th>판단 요약</th><th class="scan-col-entry">가격 계획·확인할 내용</th></tr></thead><tbody><tr><td>87</td><td><a>삼성E&A</a><span class="scan-code">028050.KS</span></td><td>기타</td><td>48,050</td><td>+0.6%</td><td>574억</td><td class="score-cell">24<small>완료 일봉</small></td><td>패턴 확인 · 지정가 계획</td><td>진입 확인</td><td class="scan-col-entry"><?= paper_prices(['entry'=>48050,'stop'=>46260,'target'=>55200]) ?><details class="inline-details"><summary>가격·판정 근거</summary><p>상승 추세 눌림 후 고점 회복 확인</p></details></td></tr></tbody></table></div></section>
<?php
paper_score_panel(['final_score'=>24,'base_score'=>24,'items'=>[
 ['key'=>'higher_low','label'=>'저점 상승','earned'=>25,'max'=>25,'status'=>'pass','detail'=>'이전 저점보다 높은 저점'],
 ['key'=>'spike_dump','label'=>'급등 후 급락','earned'=>-16,'max'=>0,'min'=>-16,'status'=>'penalty','detail'=>'종가 되돌림과 지지 이탈'],
]],'표시 검사용 예시 · 실제 추천 아님');
paper_pattern_panel(['basis'=>'completed_reference','plan'=>['pattern'=>'trend_pullback_v1','status'=>'context_wait','reason'=>'상위 추세 추가 확인 필요','diagnostics'=>['patterns'=>[
 'trend_pullback'=>['version'=>'trend_pullback_v1','status'=>'ready','reward_risk'=>2,'reason'=>'패턴 확인','gates'=>['rising_structure'=>true]],
 'breakout_retest'=>['version'=>'breakout_retest_v1','status'=>'await_retest','reason'=>'재지지 대기'],
]]]]);
paper_close(); ?>
