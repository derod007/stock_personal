<?php
declare(strict_types=1);
require_once __DIR__.'/HigherLowResearch.php';
require_once __DIR__.'/Chrome.php';
function paper_higher_low_panel(string $state,string $id):void
{
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $num=static fn($v)=>$v===null?'—':number_format((float)$v,2);
    $day=static fn($v)=>$v?(new DateTimeImmutable('@'.(int)$v))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d'):'—';
    $labels=['watch'=>'높은 저점 확인','breakout'=>'상단 종가 돌파','await_low'=>'낮은 저점 대기','await_rebound'=>'반등 상단 대기',
        'await_higher_low'=>'높은 저점 확정 대기','await_breakout'=>'높은 저점 확인 · 돌파 대기','breakout_observed'=>'상단 종가 돌파 관찰',
        'past_breakout'=>'지난 돌파','no_setup'=>'구조 없음','expired'=>'관찰 만료','invalidated'=>'하단 종가 이탈',
        'higher_low_lost'=>'높은 저점 재접촉·이탈','breakout_before_confirmation'=>'저점 확정 전 돌파',
        'quality_blocked'=>'품질 보류','insufficient_history'=>'봉 부족','unsupported_market'=>'국내 일봉 아님'];
    echo '<section class="card"><h2>돌파 전 높은 저점형 · 독립 관찰</h2><p>저점 이탈 → 반등 상단 고정 → 높은 저점 확정 → 상단 종가 돌파. 국내 완료 일봉에 적용한 연구 가설입니다. 원문은 선물 60분봉 사례입니다.</p>';
    echo '<p><strong>추천·주문·체결이 아닙니다.</strong> 3:7 분할매수와 목표·손절 주문은 적용하지 않습니다. 기존 패턴과 점수·공통 위험 판정은 별도입니다.</p>';
    $report=PaperHigherLowResearch::load($state,$id);
    if($report===null){echo '<p>아직 기록이 없습니다. 한국 예약 실행 뒤 자동 누적됩니다.</p></section>';return;}
    echo '<p>완료봉 기준 '.$e($day($report['as_of'])).' · 보존 관측 '.$report['summary']['observations'].'건 · 중복 제외 단계 관측 '.$report['summary']['events'].'건</p>';
    echo '<p>동일 구조의 높은 저점 확인과 돌파를 각각 최초 관측 1건으로 집계합니다. 두 단계는 겹치는 표본이며 합산 승률이 아닙니다. 과거 저장 원본의 재분석을 포함하며 당시 추천을 뜻하지 않습니다.</p>';
    echo '<div class="table-wrap"><table><thead><tr><th>단계·기존 최종 판정</th><th>표본</th><th>이후 봉</th><th>완료 n / 상승</th><th>평균 등락</th><th>최대 상승 평균</th><th>최대 하락 평균</th></tr></thead><tbody>';
    foreach($report['summary']['groups'] as $g)foreach($g['horizons'] as $n=>$h){
        echo '<tr><td>'.$e($labels[$g['stage']]).' / '.$e(paper_ko($g['final_status'])).'<br><small>연구 '.$e(substr($g['execution_version'],0,10)).' · 전략 '.$e(substr($g['stored_strategy_version']??'기록 없음',0,10)).'</small></td><td>'.$g['observations'].'</td><td>'.$n.'</td><td>'.$h['n'].' / '.$h['up'].'</td>';
        foreach(['mean_return_pct','mean_max_up_pct','mean_max_down_pct'] as $k)echo '<td>'.$num($h[$k]).($h[$k]===null?'':'%').'</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div><p>관측일 종가 대비 이후 1·3·5·10·20 완료봉 가격 변화이며 비용·체결을 적용한 매매 수익률이 아닙니다. 갱신 오류 표본은 현재 집계에서 제외하고 마지막 성공 결과는 보존합니다.</p>';
    echo '<details open><summary>최근 관측 100건 · 구조와 추적 사유</summary><div class="table-wrap"><table><thead><tr><th>관측일·종목</th><th>독립 판정 / 기존 최종 판정</th><th>고정 구조</th><th>추적</th></tr></thead><tbody>';
    $rows=$report['rows'];uasort($rows,fn($a,$b)=>$b['frozen']['record']['session']<=>$a['frozen']['record']['session']);
    foreach(array_slice($rows,0,100,true) as $row){
        $f=$row['frozen'];$r=$f['record'];$v=$f['features'];$ev=$v['evidence']??[];
        echo '<tr><td>'.$e($day($r['session'])).'<br>'.$e($r['name']??$r['symbol']).' '.$e($r['symbol']).'</td><td>'.$e($labels[$v['status']]??$v['status']).'<br>기존: '.$e(paper_ko($f['final_status'])).'</td><td>';
        foreach(['low'=>'이탈 후 저점','resistance'=>'반등 상단','higher_low'=>'높은 저점'] as $key=>$label){
            echo $e($label).' '.$num($ev[$key]['price']??null).' · 확정 '.$e($day($ev[$key]['confirmed_at']??null)).'<br>';
        }
        echo '</td><td>'.$e(paper_ko($row['status'])).'<br>'.($f['event_key']?'단계 최초 관측':'후속 집계 제외').'<br>반영 봉 '.$e($day($row['latest_session']??null));
        if(isset($row['error']))echo '<br>'.$e($row['error']);
        if(isset($v['quality_reasons']))echo '<br>'.$e(implode(', ',$v['quality_reasons']));
        echo '</td></tr>';
    }
    echo '</tbody></table></div></details>';
    if($report['errors'])echo '<details><summary>원본 등록 오류 '.count($report['errors']).'건</summary><pre>'.$e(PaperRrAudit::encode($report['errors'])).'</pre></details>';
    echo '</section>';
}
