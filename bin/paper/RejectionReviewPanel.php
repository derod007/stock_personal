<?php
declare(strict_types=1);
require_once __DIR__.'/RejectionReview.php';
require_once __DIR__.'/SingleConditionPanel.php';

function paper_rejection_review_panel(array $report):void
{
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $num=static fn($v)=>is_numeric($v)?number_format((float)$v,2):'—';
    echo '<section class="paper-card"><h2>최근 5개 기록일 진단</h2><p>'. $e(implode(' · ',$report['dates'])).'</p>';
    echo '<p>날짜별 종목의 첫 저장 기록을 사용합니다. 총 '.count($report['rows']).'건 · 같은 날 재실행 중복 '.$report['duplicates'].'건 제외. 휴장 달력이 아닌 저장일 기준이며, 서로 다른 날짜의 같은 판정봉은 별도 기록입니다.</p>';
    echo '<p>선택 패턴과 최종 판정을 분리합니다. 차단 사유는 중복 집계이며, 조건 하나를 지웠을 때의 추천 수가 아닙니다. 과거 기록에 없는 조건·버전·수익률은 추정하지 않습니다.</p>';
    foreach(['cross'=>'선택 패턴 → 최종 판정','blockers'=>'기록된 독립 차단 사유','quality'=>'분석 불가 사유'] as $key=>$label){
        echo '<details open><summary>'.$label.'</summary><table><thead><tr><th>구분</th><th>건수</th></tr></thead><tbody>';
        foreach($report[$key] as $code=>$n)echo '<tr><td>'.$e($code).'</td><td>'.$n.'</td></tr>';
        echo '</tbody></table></details>';
    }
    paper_single_condition_panel($report['single_condition_review']);
    echo '<h3>눌림 거래량만 미충족한 선택 패턴</h3><p>다른 조건이 미평가되었거나 추세·위험 차단이 남아 있으면 거래량 기준을 완화해도 추천된다고 볼 수 없습니다. RR 재판정도 필요합니다. 비율은 저장된 당시 측정값입니다.</p><table><thead><tr><th>기록일·종목</th><th>거래량 비율 / 기준</th><th>최종 판정·차단</th><th>미평가 조건</th></tr></thead><tbody>';
    foreach($report['volume_only'] as $r){$m=$r['measurements'];
        echo '<tr><td>'.$e($r['date'].' '.$r['name']).'</td><td>'.$num(isset($m['pullback_volume_ratio'])?$m['pullback_volume_ratio']*100:null).'% / &lt; '.$num(isset($m['required_volume_ratio_lt'])?$m['required_volume_ratio_lt']*100:null).'%</td><td>'.$e($r['final'].' · '.implode(', ',$r['blockers'])).'</td><td>'.$e(implode(', ',$r['not_evaluated'])).'</td></tr>';
    }
    echo '</tbody></table><h3>최종 손익비 탈락 → 기존 후속 추적</h3><p>판정 다음 완료봉 기준입니다. 추적 연결은 원본 해시·파일·종목·판정봉이 모두 같은 경우만 허용합니다. —는 미완료 또는 연결 없음이며 0%가 아닙니다. 아래 봉 수익률은 종가 관찰값이고, 지정가 손익은 기존 비용 반영 모의 결과입니다.</p><table><thead><tr><th>기록일·종목</th><th>연결 상태</th>';
    foreach(PaperFollowup::HORIZONS as $n)echo '<th>'.$n.'봉</th>';
    echo '<th>지정가 상태 / 순손익</th></tr></thead><tbody>';
    foreach($report['rr'] as $r){$f=$r['followup'];
        echo '<tr><td>'.$e($r['date'].' '.$r['name']).'</td><td>'.$e($r['followup_status']).'</td>';
        foreach(PaperFollowup::HORIZONS as $n){$h=$f['horizons'][$n]??[];echo '<td>'.(($h['status']??'')==='complete'?$num($h['return_pct']).'%':'—').'</td>';}
        $trades=[];foreach($f['trades']??[] as $t)if($t['kind']==='limit')$trades[]=$t['outcome']['status'].' / '.$num($t['outcome']['net_return_pct']??null).'%';
        echo '<td>'.$e($trades?implode(', ',$trades):'추적 결과 없음 · 후보 '.$r['limit_status']).'</td></tr>';
    }
    echo '</tbody></table><p>완료 표본 수: ';
    $samples=PaperFollowup::horizonSamples(array_values(array_filter(array_column($report['rr'],'followup'))));
    foreach($samples as $n=>$count)echo $n.'봉 '.$count.'건 · ';
    echo '</p><p>기록일별 원본은 아래 로그 선택에서 확인하세요. 종목군·전략 버전이 다른 기간의 합계는 성과 비교 근거로 사용하지 않습니다.</p>';
    foreach($report['errors'] as $error)echo '<p>읽기 실패: '.$e($error['file'].' · '.$error['error']).'</p>';
    echo '</section>';
}
