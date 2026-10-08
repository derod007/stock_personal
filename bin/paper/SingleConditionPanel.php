<?php
declare(strict_types=1);
require_once __DIR__.'/Chrome.php';
require_once __DIR__.'/SingleConditionReview.php';

function paper_single_condition_panel(array $review):void
{
    $e=static fn($v)=>htmlspecialchars(paper_status_text((string)$v),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $num=static fn($v)=>is_numeric($v)?number_format((float)$v,2).'%' : '—';
    $labels=['single_verified'=>'단일 탈락 확인','single_unverified'=>'확인된 차단 1개 · 다른 조건 미확인',
        'ready_reference'=>'기존 추천 참고군','multiple_or_unresolved'=>'복수 탈락·증거 부족'];
    echo '<h3>단일 조건 탈락 후속 비교</h3><p>저장된 선택 패턴을 기준으로 다른 조건의 통과가 확인된 기록과 손익비 등이 미평가된 기록을 분리합니다. 단일 탈락 확인도 조건 변경 후 추천·체결을 보장하지 않습니다.</p>';
    echo '<p>분석 불가 '.($review['unavailable']??0).'건 · 판정봉 미기록 '.($review['missing_session']??0).'건은 분류에서 제외합니다.</p>';
    echo '<p>같은 종목·판정봉 중복 '.$review['duplicates'].'건 제외. 전략 버전·패턴·추세 적용 여부별로 분리합니다. 버전 미기록 묶음은 관찰용이며 버전 간 성과 비교 근거로 쓰지 않습니다. 수익률은 판정 다음 완료봉의 종가 변화입니다.</p><p>';
    foreach($labels as $key=>$label)echo $e($label).' '.($review['statuses'][$key]??0).'건 · ';
    echo '</p>';
    if(!$review['groups'])echo '<p>분류 가능한 기록이 없습니다. 미기록 조건을 통과로 추정하지 않습니다.</p>';
    foreach($review['groups'] as $g){
        $scope=$g['scope'];
        echo '<details open><summary>'.$e($labels[$g['status']].' / '.($g['condition']===null?'기존 추천':PaperSingleConditionReview::label($g['condition']))).' · '.$g['signals'].'건</summary>';
        echo '<p>전략: '.$e($scope['strategy_version']??'미기록 · 관찰용').' / 패턴: '.$e($scope['pattern']??'미기록').' / 추세 적용: '.($scope['context_applied']===true?'사용':($scope['context_applied']===false?'미사용':'미기록')).' / 추적 연결 '.$g['linked'].'건</p>';
        echo '<p>선택 패턴 원래 상태: ';foreach($g['pattern_stages']??[] as $stage=>$count)echo $e($stage).' '.$count.'건 · ';echo '</p>';
        echo '<table><thead><tr><th>완료봉</th><th>완료 표본 / 대상</th><th>상승 비율</th><th>평균 종가 수익률</th><th>평균 최대 상승폭</th><th>평균 최대 하락폭</th></tr></thead><tbody>';
        foreach($g['horizons'] as $n=>$h)echo '<tr><td>'.$n.'</td><td>'.$h['n'].' / '.$g['signals'].'</td><td>'.$num($h['n']?$h['up']/$h['n']*100:null).'</td><td>'.$num($h['mean_return_pct']).'</td><td>'.$num($h['mean_max_up_pct']).'</td><td>'.$num($h['mean_max_down_pct']).'</td></tr>';
        echo '</tbody></table><details><summary>종목별 근거·미확인 조건 펼치기</summary><table><thead><tr><th>기록일·종목</th><th>선택 패턴 상태·사유</th><th>최종 판정</th><th>미확인 조건</th><th>추적 상태</th></tr></thead><tbody>';
        foreach($g['rows'] as $r)echo '<tr><td>'.$e($r['date'].' '.$r['name']).'</td><td>'.$e(paper_ko($r['raw']??'unknown').' · '.($r['raw_reason']??'')).'</td><td>'.$e($r['final']).'</td><td>'.$e(implode(', ',array_map([PaperSingleConditionReview::class,'label'],$r['single_condition']['unknown']))).'</td><td>'.$e($r['followup_status']).'</td></tr>';
        echo '</tbody></table></details></details>';
    }
    echo '<p>새 완료봉은 일반 후속 실행으로 수집합니다: <code>php bin/paper_followup.php --account=paper-kr</code>. <code>--saved-evidence</code>는 저장된 가격으로만 재계산합니다. 기존 예약 실행도 추천 0건일 때 후속 추적을 실행합니다. 5개 기록일과 5봉 완료는 다릅니다.</p>';
}
