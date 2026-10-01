<?php
declare(strict_types=1);
function paper_target_chart(array $r,?int $recent=80):string
{
    $bars=$r['bars']??[];if(!$bars)return '';
    if($recent!==null)$bars=array_slice($bars,-$recent);
    $e=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $date=static fn($t)=>(new DateTimeImmutable('@'.$t))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
    $levels=['진입'=>$r['entry'],'손절'=>$r['stop'],'기존 목표'=>$r['target']];
    if($r['nearest_upper_resistance'])$levels['가장 가까운 상단 후보']=$r['nearest_upper_resistance']['price'];
    $lo=min(min(array_column($bars,'low')),min($levels));$hi=max(max(array_column($bars,'high')),max($levels));
    $span=max(1,$hi-$lo);$lo-=$span*.06;$hi+=$span*.06;
    $y=static fn($p)=>round(230-($p-$lo)/($hi-$lo)*210,2);$step=690/count($bars);
    $s='<svg viewBox="0 0 960 270" role="img" aria-label="판정일까지의 저장 일봉과 진입 손절 목표 저항 후보" style="width:100%;min-width:650px;background:#fff;color:#111">';
    foreach($bars as $i=>$b){$x=round(12+($i+.5)*$step,2);$color=$b['close']>=$b['open']?'#b42318':'#175cd3';
        $s.='<g><title>'.$e($date($b['available_at']).' 시가 '.$b['open'].' 고가 '.$b['high'].' 저가 '.$b['low'].' 종가 '.$b['close']).'</title>';
        $s.='<line x1="'.$x.'" x2="'.$x.'" y1="'.$y($b['high']).'" y2="'.$y($b['low']).'" stroke="'.$color.'"/>';
        $s.='<line x1="'.$x.'" x2="'.$x.'" y1="'.$y($b['open']).'" y2="'.$y($b['close']).'" stroke="'.$color.'" stroke-width="'.max(1,round($step*.55,2)).'"/></g>';
    }
    $colors=['진입'=>'#111','손절'=>'#b42318','기존 목표'=>'#087f5b','가장 가까운 상단 후보'=>'#9333ea'];$labelY=20;
    foreach($levels as $label=>$price){$py=$y($price);$s.='<line x1="12" x2="705" y1="'.$py.'" y2="'.$py.'" stroke="'.$colors[$label].'" stroke-dasharray="4 3"/><text x="720" y="'.$labelY.'" font-size="12" fill="'.$colors[$label].'">'.$e($label.' '.number_format($price,3)).'</text>';$labelY+=22;}
    $s.='<text x="12" y="255" font-size="12">'.$e($date($bars[0]['available_at'])).'</text><text x="580" y="255" font-size="12">'.$e($date($bars[count($bars)-1]['available_at'])).'</text></svg>';
    return $s;
}
function paper_target_research_panel(string $state,string $id):void
{
    if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account');
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $n=static fn($v)=>is_numeric($v)?number_format((float)$v,3):'—';
    $date=static fn($t)=>(new DateTimeImmutable('@'.$t))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
    $labels=['closed_above'=>'종가 돌파','closed_at'=>'종가 일치','wick_above_close_below'=>'장중 돌파·종가 미회복','touched_close_below'=>'고가 접촉·종가 미회복','below'=>'고점 아래',
        'below_entry'=>'진입가 아래','at_entry'=>'진입가와 같음','above_entry'=>'진입가 위',
        'candidates_in_saved_history'=>'상단 후보 있음','none_in_saved_history'=>'저장 범위에서 상단 후보 없음'];
    echo '<section class="paper-card"><h2>목표가 산정 근거 진단</h2><p>이전 20봉 고점의 계산·돌파 상태와 상단 저항 후보를 확인합니다. 저항 후보는 새 목표가나 추천이 아닙니다. 운영 가격과 매수 조건은 그대로입니다.</p>';
    $path=$state.'/target-research/'.$id.'/latest.json';
    if(!is_file($path)){echo '<p><code>php bin/paper_target_research.php --account='.$e($id).'</code> 실행 후 확인하세요.</p></section>';return;}
    $r=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if(($r['kind']??'')!=='target_basis_audit_v1'||($r['account']??'')!==$id)throw new RuntimeException('Invalid target report');
    echo '<p>기록일 '.$e(implode(' · ',$r['dates'])).'. 당시 완료 일봉만 사용합니다. 과거 코드 버전을 검증한 결과는 아닙니다.</p>';
    echo '<p>상단 후보: 저장된 전체 과거 봉에서 좌우 2봉보다 엄격하게 높은 고점 중, 진입가 위에 있고 이후 종가가 그 고점을 넘지 않은 가격입니다. 접촉 이력도 표시합니다. 후보가 없다는 뜻은 저장 범위와 이 정의에서 찾지 못했다는 뜻입니다.</p>';
    foreach(['confirmed'=>'패턴 확인 완료','single_gate_hypothesis'=>'단일 조건 제거 가정'] as $cohort=>$title){
        $s=$r['summary'][$cohort]??null;if(!$s)continue;
        echo '<h3>'.$title.'</h3><p>대상 '.$s['records'].'건 · 진단 '.$s['diagnosed'].'건 · 목표 공식 불일치 '.$s['formula_mismatch'].'건 · 저장 가격 불일치 '.$s['stored_levels_mismatch'].'건</p>';
        echo '<table><thead><tr><th>진단 항목</th><th>건수</th></tr></thead><tbody>';
        foreach(['relations','target_vs_entry','upper_resistance'] as $category)foreach($s[$category] as $key=>$count)echo '<tr><td>'.$e($labels[$key]??$key).'</td><td>'.$count.'</td></tr>';
        echo '</tbody></table>';
        foreach($r['rows'] as $row){if($row['cohort']!==$cohort)continue;
            echo '<details><summary>'.$e($row['date'].' '.$row['name'].' · '.($labels[$row['decision_relation']??'']??$row['status'])).'</summary>';
            if($row['status']!=='diagnosed'){echo '<p>'.$e($row['status']).'</p></details>';continue;}
            echo '<p>진입 '.$n($row['entry']).' / 손절 '.$n($row['stop']).' / 목표 '.$n($row['target']).' · '.$e($labels[$row['target_vs_entry']]).'</p>';
            echo '<p>목표 산정 구간 '.$e($date($row['target_window']['start']).' ~ '.$date($row['target_window']['end'])).' / 최고가 발생일 '.$e(implode(', ',array_map($date,$row['target_sessions']))).'</p>';
            echo '<p>공식 대조 '.($row['target_matches_formula']?'일치':'불일치').' / 저장 가격 대조 '.($row['original_levels_match']===null?'가정 사례로 해당 없음':($row['original_levels_match']?'일치':'불일치')).'. 기존 차단을 해제한 진단이 아닙니다.</p>';
            echo '<p>최근 최대 80봉 · 마지막 봉이 판정봉입니다.</p><div style="overflow-x:auto">'.paper_target_chart($row).'</div>';
            echo '<details><summary>저장된 전체 일봉 차트</summary><div style="overflow-x:auto">'.paper_target_chart($row,null).'</div></details>';
            echo '<p>저장 범위 '.$e($date($row['history']['start']).' ~ '.$date($row['history']['end'])).' · '.$row['history']['bars'].'봉 / '.$e($labels[$row['upper_resistance_status']]).'</p>';
            if($row['upper_resistance_candidates']){
                echo '<table><thead><tr><th>상단 후보 가격</th><th>고점 날짜</th><th>이후 고가 접촉 날짜</th><th>기존 손절 기준 RR</th></tr></thead><tbody>';
                foreach($row['upper_resistance_candidates'] as $p)echo '<tr><td>'.$n($p['price']).'</td><td>'.$e(implode(', ',array_map($date,$p['peak_sessions']))).'</td><td>'.$e(implode(', ',array_map($date,$p['test_sessions']))?:'없음').'</td><td>'.$n($p['rr_at_original_stop']).'</td></tr>';
                echo '</tbody></table><p>가장 가까운 후보부터 가격순입니다. 높은 후보만 골라 손익비를 통과시키지 않습니다. RR은 비용 전 산술값이며 확인 신호·추세·위험 조건을 통과했다는 뜻이 아닙니다.</p>';
            }echo '</details>';
        }
    }
    foreach($r['errors'] as $err)echo '<p>'.$e(json_encode($err,JSON_UNESCAPED_UNICODE)).'</p>';
    echo '</section>';
}
