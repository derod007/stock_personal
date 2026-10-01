<?php
declare(strict_types=1);
require_once __DIR__.'/SingleConditionReview.php';
function paper_condition_research_panel(string $state,string $id):void
{
    if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account');
    $e=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $n=static fn($v)=>is_numeric($v)?number_format((float)$v,3):'—';
    $path=$state.'/condition-research/'.$id.'/latest.json';
    echo '<section class="paper-card"><h2>단일 조건 가정 손익비·체결 연구</h2><p>조건 하나를 제거하고 당시 종가를 다음 봉부터의 지정가로 삼은 연구입니다. 당시 추천이 아니며 조건 완화 폭을 최적화한 결과도 아닙니다. 현재 공식 재계산과 저장 판정을 대조하지만 과거 코드 버전을 확인한 것은 아닙니다.</p>';
    if(!is_file($path)){echo '<p>연구 결과 없음. 로컬에서 <code>php bin/paper_condition_research.php --account='.$e($id).'</code> 실행 후 확인하세요.</p></section>';return;}
    $r=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if(($r['kind']??'')!=='single_gate_close_entry_v1'||($r['account']??'')!==$id)throw new RuntimeException('Invalid research report');
    echo '<p>원본 일봉 해시 확인 '.(int)$r['input_validation']['input_hashes_verified'].'건 · 가격 증거 해시 확인 '.(int)$r['input_validation']['evidence_hashes_verified'].'개 · 대상 '.(int)$r['target_count'].'건 · 오류 '.count($r['errors']).'건</p>';
    echo '<table><thead><tr><th>날짜·종목 / 제거 가정 조건</th><th>가정 진입 / 손절 / 목표</th><th>손익비</th><th>연구 판정</th><th>체결 결과 / 비용 반영 손익</th></tr></thead><tbody>';
    foreach($r['rows'] as $row){$p=$row['hypothesis']??[];$t=$row['outcome']??[];
        echo '<tr><td>'.$e($row['date'].' '.$row['name'].' / '.PaperSingleConditionReview::label($row['gate'])).'</td><td>'.$n($p['entry']??null).' / '.$n($p['stop']??null).' / '.$n($p['target']??null).'</td><td>'.$n($p['reward_risk']??null).'</td><td>'.$e($row['status']).'</td><td>'.$e($t['status']??'체결 검증 대상 아님').' / '.$n($t['net_return_pct']??null).'</td></tr>';
    }
    echo '</tbody></table><h3>기존 지정가 후보의 취소 근거</h3><p>취소 사유를 설명하며 기존 체결 규칙과 결과는 바꾸지 않습니다. 같은 일봉에서 목표가와 지정가가 모두 닿은 경우 순서는 알 수 없습니다.</p><table><thead><tr><th>날짜·종목</th><th>기존 결과</th><th>취소 근거</th></tr></thead><tbody>';
    $labels=['open_at_or_below_stop'=>'시가가 손절가 이하','open_at_or_above_target'=>'시가가 목표가 이상','target_and_limit_same_bar_order_unknown'=>'목표·지정가 동시 접촉: 순서 불명','target_reached_without_limit_touch'=>'지정가 미접촉 상태에서 목표가 도달'];
    foreach($r['existing_limit_trades'] as $t){$why=$t['cancellation']['reason']??'';echo '<tr><td>'.$e($t['date'].' '.$t['name']).'</td><td>'.$e($t['status']).'</td><td>'.$e($labels[$why]??$why).'</td></tr>';}
    echo '</tbody></table>';
    foreach($r['errors'] as $error)echo '<p>'.$e(json_encode($error,JSON_UNESCAPED_UNICODE)).'</p>';
    echo '</section>';
}
