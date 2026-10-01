<?php
declare(strict_types=1);
require_once __DIR__.'/TargetTracking.php';
function paper_target_tracking_panel(string $state,string $id,?array $window=null):void
{
    $e=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $date=static fn($v)=>$v?(new DateTimeImmutable('@'.$v))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i'):'—';
    $n=static fn($v)=>is_numeric($v)?number_format((float)$v,3):'—';
    echo '<section class="paper-card"><h2>누적 목표가 비교 연구</h2><p>기존 20:20 예약 작업 뒤에 갱신합니다. 원본·진입·손절·목표를 등록 시 고정하며, TOP100에서 빠져도 미완료 연구 거래를 추적합니다. 운영 계좌 성과와 합산하지 않습니다.</p>';
    try{$r=PaperTargetTracking::load($state,$id);}catch(Throwable $ex){echo '<p>'.$e('연구 기록 읽기 실패: '.$ex->getMessage()).'</p></section>';return;}
    $lastRun=null;
    foreach(PaperFollowup::readRuns($state.'/runs/'.$id.'-forward') as $run){
        $t=$run['target_tracking']??null;if(is_array($t)&&($lastRun===null||($t['started_at']??0)>($lastRun['started_at']??0)))$lastRun=$t;
    }
    if($lastRun)echo '<p>최근 예약 연구 실행 '.$e($date($lastRun['started_at']??null)).' / '.$e($lastRun['status']??'unknown').(isset($lastRun['error_type'])?' · '.$e($lastRun['error_type']):'').'</p>';
    if(!$r){echo '<p>아직 누적 연구 실행 기록이 없습니다. 다음 예약 실행 또는 <code>php bin/paper_target_tracking.php --account='.$e($id).'</code> 실행 후 확인하세요.</p></section>';return;}
    $s=PaperTargetTracking::summarize($r['rows'],$window);
    echo '<p>마지막 저장 '.$e($date($r['generated_at'])).' / 상태 '.$e($r['status']).' / 전체 보존 '.$s['total_retained'].'건 / 현재 추적 '.$s['currently_tracking'].'건 / 갱신 오류 '.$s['update_errors'].'건</p>';
    echo '<p>'.($window?'선택 주':'전체 기간').' 판정 후보 '.$s['candidates'].'건 · 등록 '.$s['registered'].'건. 과거 기록을 처음 가져오면 판정일과 등록일이 다릅니다.</p>';
    foreach($s['groups'] as $g){
        echo '<h3>연구 규칙 '.$e($g['policy']).'</h3><p>계산 버전 '.$e(substr($g['strategy_fingerprint'],0,12)).' / 원본 전략 버전 '.$e($g['stored_strategy_version']??'미기록 · 관찰용').'</p>';
        echo '<table><thead><tr><th>방식</th><th>조건 통과</th><th>체결</th><th>청산</th><th>손절</th><th>목표 도달</th><th>청산 평균 순손익(%)</th></tr></thead><tbody>';
        foreach($g['arms'] as $arm=>$a)echo '<tr><td>'.($arm==='baseline'?'기존 목표':'가장 가까운 상단 후보').'</td><td>'.$a['eligible'].'</td><td>'.$a['filled'].'</td><td>'.$a['closed'].'</td><td>'.$a['stops'].'</td><td>'.$a['targets'].'</td><td>'.$n($a['mean_net_pct']).'</td></tr>';
        echo '</tbody></table>';
    }
    echo '<p>조건 통과는 판정일, 체결·청산은 각 발생일 기준입니다. 과거 주에 등록된 후보의 이번 주 청산도 포함합니다. 진행 중 거래는 평균에서 제외하며, 표본이 다른 평균 차이를 개선 효과로 해석하지 않습니다. 아래 상태는 현재 저장 상태입니다.</p>';
    echo '<details><summary>보존 후보와 현재 추적 상태</summary><table><thead><tr><th>판정일·종목</th><th>고정 진입 / 손절 / 대안 목표</th><th>선정·현재 상태</th><th>마지막 가격 평가</th><th>대안 체결·청산</th></tr></thead><tbody>';
    foreach($r['rows'] as $row){$f=$row['frozen'];$record=$f['record'];$p=$f['comparison']['alternative']??[];$t=$row['outcomes']['alternative']??[];
        echo '<tr><td>'.$e($date($record['session']).' '.$record['name']).'</td><td>'.$n($f['fixed_levels']['entry']).' / '.$n($f['fixed_levels']['stop']).' / '.$n($p['target']??$f['nearest_upper_resistance']['price']??null).'</td><td>'.$e($f['comparison']['status'].' / '.$row['status'].(isset($row['error'])?' · '.$row['error']:'')).'</td><td>'.$e($date($row['last_success_at'])).'</td><td>'.$e(($t['status']??'—').' / '.$date($t['entry_at']??null).' / '.$date($t['exit_at']??null)).' / '.$n($t['net_return_pct']??null).'%</td></tr>';
    }echo '</tbody></table></details>';
    foreach($r['errors'] as $err)echo '<p>'.$e(json_encode($err,JSON_UNESCAPED_UNICODE)).'</p>';
    echo '<p>갱신 오류가 있으면 마지막 성공 결과를 보존합니다. 이후 가격이 반영됐다고 해석하지 마세요. 거래 종료 후에는 결과를 고정합니다.</p></section>';
}
