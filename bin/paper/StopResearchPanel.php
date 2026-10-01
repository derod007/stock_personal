<?php
declare(strict_types=1);
function paper_stop_research_panel(string $state,string $id):void
{
    if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account');
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $n=static fn($v)=>is_numeric($v)?number_format((float)$v,3):'—';
    echo '<section class="paper-card"><h2>눌림 저점 손절 비교 연구</h2><p>운영 조건은 그대로입니다. 진입가·목표가·유효기간을 고정하고 손절만 비교합니다. 확인 완료 사례와 단일 조건 제거 가정은 합산하지 않습니다. 현재 공식 연구이며 과거 코드 버전 검증은 아닙니다.</p>';
    $file=$state.'/stop-research/'.$id.'/latest.json';
    if(!is_file($file)){echo '<p><code>php bin/paper_stop_research.php --account='.$e($id).'</code> 실행 후 확인하세요.</p></section>';return;}
    $r=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if(($r['kind']??'')!=='confirmed_peak_pullback_stop_v1'||($r['account']??'')!==$id)throw new RuntimeException('Invalid stop report');
    echo '<p>기록일 '.$e(implode(' · ',$r['dates'])).' / 평가 기준 '.$e((new DateTimeImmutable('@'.$r['as_of']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i:s')).' KST</p>';
    echo '<p>최근 10봉 안의 좌우 2봉보다 높은 마지막 고점을 사용합니다. 고점 다음부터 판정일까지의 저점에서 0.2 ATR을 뺍니다. 판정봉 전 종가 하락과 0.5 ATR 이상 조정이 필요합니다. 불명확한 구간은 추정하지 않습니다.</p>';
    foreach(['confirmed'=>'패턴 확인 완료 사례','single_gate_hypothesis'=>'단일 조건 제거 가정 사례'] as $cohort=>$label){
        $s=$r['summary'][$cohort]??null;if($s===null)continue;
        echo '<h3>'.$label.'</h3><p>대상 '.$s['records'].'건 / 비교 '.($s['statuses']['compared']??0).'건 / 추가 통과 '.$s['added_eligible'].'건 / 같은 진입에서 더 이른 손절 '.$s['earlier_stop_same_entry'].'건</p>';
        echo '<p>두 방식 모두 청산된 동일 사례 '.$s['paired_closed'].'건의 평균 순손익 차이(새 방식−기존): '.$n($s['paired_mean_difference_pct']).' %p. 0건이면 비교 불가입니다.</p>';
        echo '<table><thead><tr><th>방식</th><th>통과</th><th>체결</th><th>청산</th><th>손절</th><th>청산 평균 순손익(%)</th></tr></thead><tbody>';
        foreach($s['arms'] as $arm=>$a)echo '<tr><td>'.($arm==='baseline'?'기존 10봉 저점':'최근 눌림 저점').'</td><td>'.$a['eligible'].'</td><td>'.$a['filled'].'</td><td>'.$a['closed'].'</td><td>'.$a['stops'].'</td><td>'.$n($a['mean_net_pct']).'</td></tr>';
        echo '</tbody></table><p>각 방식의 통과·청산 대상은 다를 수 있으므로 위 평균끼리의 차이를 개선 효과로 해석하지 않습니다. 구간을 정의하지 못한 사례는 두 방식 모두 성과 집계에서 제외합니다.</p>';
        echo '<table><thead><tr><th>날짜·종목 / 제거 가정</th><th>구간 고점 / 저점 날짜</th><th>비교 상태</th><th>기존 손절 / RR / 결과 / 순손익(%)</th><th>최근 눌림 손절 / RR / 결과 / 순손익(%)</th></tr></thead><tbody>';
        foreach($r['rows'] as $row){if($row['cohort']!==$cohort)continue;
            $seg=$row['segment']??[];$date=static fn($v)=>$v?(new DateTimeImmutable('@'.$v))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('m-d'):'—';
            echo '<tr><td>'.$e($row['date'].' '.$row['name'].' / '.($row['removed_gate']??'없음')).'</td><td>'.$e($date($seg['peak_session']??null).' / '.implode(', ',array_map($date,$seg['low_sessions']??[]))).'</td><td>'.$e($row['status']).'</td>';
            foreach(['baseline','recent_pullback'] as $arm){$a=$row[$arm]??[];$p=$a['plan']??[];$t=$a['outcome']??[];
                echo '<td>'.$n($p['stop']??null).' / '.$n($p['reward_risk']??null).' / '.$e($t['status']??$p['status']??'—').' / '.$n($t['net_return_pct']??null).'</td>';}
            echo '</tr>';
        }echo '</tbody></table>';
    }
    foreach($r['errors'] as $error)echo '<p>'.$e(json_encode($error,JSON_UNESCAPED_UNICODE)).'</p>';
    echo '</section>';
}
