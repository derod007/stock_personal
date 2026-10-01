<?php
declare(strict_types=1);
function paper_target_comparison_panel(string $state,string $id):void
{
    if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account');
    $e=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $n=static fn($v)=>is_numeric($v)?number_format((float)$v,3):'—';
    $labels=['compared'=>'비교 완료','independent_blockers'=>'기존 조건 차단','no_upper_candidate'=>'상단 후보 없음','invalid_levels'=>'가격 관계 부적합',
        'rejected_rr'=>'손익비 미달','context_wait'=>'상위 추세 보류','context_unavailable'=>'추세 근거 부족','eligible'=>'모의 진입 조건 통과',
        'closed'=>'청산','incomplete'=>'체결 후 추적 중','pending'=>'미체결 관찰 중','no_future_bars'=>'후속 봉 없음','unfilled'=>'미체결 만료','cancelled_before_entry'=>'진입 전 취소'];
    echo '<section class="paper-card"><h2>돌파 종목 목표가 모의 비교</h2><p>확인 완료 후 기존 목표 고점을 종가로 돌파한 사례 전체를 비교합니다. 진입·손절·위험·추세·비용·체결 규칙은 고정하고, 가장 가까운 상단 후보만 대안 목표로 사용합니다. 운영 추천은 아닙니다.</p>';
    $file=$state.'/target-comparison/'.$id.'/latest.json';
    if(!is_file($file)){echo '<p><code>php bin/paper_target_comparison.php --account='.$e($id).'</code> 실행 후 확인하세요.</p></section>';return;}
    $r=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if(($r['kind']??'')!=='nearest_resistance_target_comparison_v1'||($r['account']??'')!==$id)throw new RuntimeException('Invalid comparison report');
    $s=$r['summary'];echo '<p>기록일 '.$e(implode(' · ',$r['dates'])).' / 평가 기준 '.$e((new DateTimeImmutable('@'.$r['as_of']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i:s')).' KST</p>';
    echo '<p>대상 '.$s['records'].'건 · 비교 '.($s['statuses']['compared']??0).'건 · 추가 통과 '.$s['added_eligible'].'건</p><ul>';
    foreach($s['statuses'] as $k=>$v)echo '<li>'.$e($labels[$k]??$k).' '.$v.'건</li>';echo '</ul>';
    echo '<table><thead><tr><th>방식</th><th>통과</th><th>체결</th><th>청산</th><th>손절</th><th>목표 도달</th><th>청산 평균 순손익(%)</th></tr></thead><tbody>';
    foreach($s['arms'] as $arm=>$a)echo '<tr><td>'.($arm==='baseline'?'기존 목표':'가장 가까운 상단 후보').'</td><td>'.$a['eligible'].'</td><td>'.$a['filled'].'</td><td>'.$a['closed'].'</td><td>'.$a['stops'].'</td><td>'.$a['targets'].'</td><td>'.$n($a['mean_net_pct']).'</td></tr>';
    echo '</tbody></table><p>양쪽 모두 청산된 동일 사례 '.$s['paired_closed'].'건 · 평균 순손익 차이 '.$n($s['paired_mean_difference_pct']).' %p. 0건이면 개선 효과를 비교할 수 없습니다. 미체결·진행 중 결과는 0% 수익으로 넣지 않습니다.</p>';
    echo '<table><thead><tr><th>날짜·종목</th><th>진단·제외 근거</th><th>기존 목표 / RR / 결과 / 순손익(%)</th><th>대안 목표 / RR / 결과 / 순손익(%)</th></tr></thead><tbody>';
    foreach($r['rows'] as $row){
        $why=array_merge($row['blockers']??[],$row['unknown']??[]);
        echo '<tr><td>'.$e($row['date'].' '.$row['name']).'</td><td>'.$e(($labels[$row['status']]??$row['status']).($why?' · '.implode(', ',array_map([PaperSingleConditionReview::class,'label'],$why)):'' )).'</td>';
        foreach(['baseline','alternative'] as $arm){$v=$row[$arm]??[];$p=$v['plan']??[];$t=$v['outcome']??[];$status=$t['status']??$p['status']??'—';
            echo '<td>'.$n($p['target']??($arm==='alternative'?($row['nearest_upper_resistance']['price']??null):($row['fixed_levels']['target']??null))).' / '.$n($p['reward_risk']??null).' / '.$e($labels[$status]??$status).' / '.$n($t['net_return_pct']??null).'</td>';
        }echo '</tr>';
    }echo '</tbody></table><p>3봉 주문 유효기간·20봉 보유 한도와 기존 일봉 체결 규칙을 사용합니다. 목표와 지정가가 같은 봉에 닿으면 기존의 보수적 진입 전 취소 규칙을 유지합니다. 현재 공식 연구이며 과거 코드 버전 검증은 아닙니다.</p>';
    foreach($r['errors'] as $err)echo '<p>'.$e(json_encode($err,JSON_UNESCAPED_UNICODE)).'</p>';echo '</section>';
}
