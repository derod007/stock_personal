<?php
declare(strict_types=1);
require_once __DIR__.'/Followup.php';
require_once __DIR__.'/Chrome.php';
function paper_followup_panel(?array $report,?array $window=null):void
{
    $h=static fn($v)=>paper_esc($v);
    $num=static fn($v)=>is_numeric($v)?sprintf('%+.2f%%',$v):'—';
    $labels=['saved'=>'갱신 완료','partial'=>'일부 보류·오류','no_observations'=>'관측 기록 없음',
        'complete'=>'관측 완료','pending'=>'관측 중','no_future_bars'=>'이후 봉 없음','late_observation'=>'사후 관측으로 집계 제외',
        'not_yet_observed'=>'관측 시점 전','price_or_evaluation_error'=>'가격 수집·계산 오류',
        'input_hash_mismatch'=>'원본 해시 불일치','historical_revision_or_missing'=>'과거 가격 변경·누락',
        'future_quality_blocked'=>'일봉 품질 차단','invalid_reference_price'=>'기준가격 오류'];
    echo '<section class="panel"><h2 class="paper-section-title">탈락 종목 후속 추적</h2>';
    if(!$report){echo '<p class="paper-note">아직 후속 추적 결과가 없습니다. 다음 한국 daily 실행에서 자동 생성됩니다.</p></section>';return;}
    $s=PaperFollowup::summarize($report['rows'],$window);
    $at=(new DateTimeImmutable('@'.$report['generated_at']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i');
    echo '<p class="paper-lede">최근 갱신 '.$h($at).' · '.$h($labels[$report['status']]??$report['status']).' · 관측 '.$h($s['observations']).'건 / 탈락 '.$h($s['rejected']).'건</p>';
    echo '<p class="paper-note">'.($window?'선택 주에 판정된 종목의 이후 결과를 최근 갱신 시점까지 집계합니다. 해당 주 안의 수익률이 아닙니다. ':'전체 판정 기록의 이후 결과입니다. ').'판정일 종가 대비 이후 완료 일봉 기준, 비용 전 가격 변화입니다. 종목별 관측 봉을 세며 거래정지·누락은 달력 거래일로 보충하지 않습니다. 미완료는 평균에서 제외합니다. 사유가 겹치면 각 그룹에 포함되므로 그룹 합계를 전체 건수로 해석하지 않습니다.</p>';
    echo '<p class="paper-note">재실행 중복 제외 '.$h($report['duplicates']).'건 · 원본 조회 불가 '.$h($report['unavailable_observations']).'건 · 파일/가격 오류 '.$h(count($report['errors'])).'건. 동일 종목·세션은 가장 이른 기록을 사용합니다.</p>';
    $bits=[];foreach($s['statuses'] as $k=>$n)$bits[]=($labels[$k]??$k).' '.$n.'건';echo '<p class="paper-note">'.$h(implode(' / ',$bits)).'</p>';
    echo '<div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>탈락 사유</th><th>기간(봉)</th><th>완료 / 전체</th><th>평균 등락</th><th>평균 최대 상승</th><th>평균 최대 하락</th><th>이후 상승 / 하락 / 보합</th></tr></thead><tbody>';
    foreach($s['groups'] as $g)foreach($g['horizons'] as $n=>$v){
        echo '<tr><td>'.$h($g['label']).'</td><td>'.$h($n).'</td><td>'.$h($v['n'].' / '.$g['signals']).'</td><td>'.$h($num($v['mean_return_pct'])).'</td><td>'.$h($num($v['mean_max_up_pct'])).'</td><td>'.$h($num($v['mean_max_down_pct'])).'</td><td>'.$h($v['up'].' / '.$v['down'].' / '.$v['flat']).'</td></tr>';
    }
    echo '</tbody></table></div><p class="paper-note">탈락 후 하락은 손실 회피 가능성, 상승은 놓친 기회 가능성을 살펴보는 자료입니다. 실제로 매수했을 손익이나 특정 조건의 효과를 입증하지 않습니다.</p>';
    echo '<h3 class="paper-section-title">지정가·기존 추천 자동 재검증</h3><p class="paper-note">계좌 자금 제한 없는 개별 모의 거래입니다. 청산된 거래만 평균, 수수료 편도 10bp·슬리피지 5bp. 미체결·취소·관측 부족을 구분합니다.</p>';
    foreach($s['trades'] as $kind=>$t){
        echo '<p>'.$h($kind==='limit'?'지정가 후보':'기존 추천').' · 신호 '.$h($t['signals']).' / 체결 '.$h($t['filled']).' / 청산 '.$h($t['closed']).' / 손절 '.$h($t['stops']).' / 목표 '.$h($t['targets']).' · 평균 순수익률 '.$h($num($t['mean_net_pct'])).'<br>'.$h(paper_ko_counts($t['statuses'])).'</p>';
    }
    $rows=array_values(array_filter($report['rows'],fn($r)=>!$window||($r['session']>=$window['start']&&$r['session']<$window['end'])));
    usort($rows,fn($a,$b)=>$b['session']<=>$a['session']);
    echo '<details><summary>종목별 후속 결과 (최근 최대 200건)</summary><div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>종목 · 판정일</th><th>상태 / 사유</th><th>1봉</th><th>3봉</th><th>5봉</th><th>10봉</th><th>20봉</th><th>20봉 최대 상승 / 하락</th><th>모의 거래 결과</th></tr></thead><tbody>';
    foreach(array_slice($rows,0,200) as $r){
        $day=(new DateTimeImmutable('@'.$r['session']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
        echo '<tr><td>'.$h($r['name'].' · '.$day).'</td><td>'.$h(($labels[$r['status']]??$r['status']).' / '.implode(', ',$r['reasons'])).'</td>';
        foreach(PaperFollowup::HORIZONS as $n)echo '<td>'.$h($num($r['horizons'][$n]['return_pct']??null)).'</td>';
        echo '<td>'.$h($num($r['horizons'][20]['max_up_pct']??null).' / '.$num($r['horizons'][20]['max_down_pct']??null)).'</td><td>';
        foreach($r['trades'] as $t){$o=$t['outcome'];echo $h(($t['kind']==='limit'?'지정가':'기존').' · '.paper_ko($o['status']).' · '.$num($o['net_return_pct']??null)).'<br>';}
        echo '</td></tr>';
    }
    echo '</tbody></table></div></details></section>';
}
