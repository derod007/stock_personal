<?php
declare(strict_types=1);
require_once __DIR__.'/EnvelopeResearch.php';
function paper_envelope_research_panel(string $state,string $id):void
{
    $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $num=fn($v)=>is_numeric($v)?number_format((float)$v,2):'—';
    $date=fn($v)=>$v?(new DateTimeImmutable('@'.$v))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i'):'—';
    $labels=['evaluated'=>'관찰 가능','insufficient_history'=>'240봉 미만','quality_blocked'=>'일봉 품질 보류',
        'registered'=>'등록','pending'=>'후속 관찰 중','complete'=>'20봉 완료','no_future_bars'=>'다음 완료봉 대기','update_error'=>'갱신 오류',
        'lower_touch'=>'하단 접촉','below_lower'=>'하단 아래 전 구간','center_touch'=>'중심선 접촉','other'=>'그 외',
        'new_episode'=>'새 하단 진입 구간','continuing_episode'=>'하단 연속 체류','outside_lower'=>'하단 밖',
        'first_in_saved_range'=>'저장 범위 첫 하단 구간','repeat_episode'=>'하단 재진입 구간'];
    $label=function($v)use($labels){
        if(isset($labels[$v]))return $labels[$v];
        $parts=explode(':',$v,2);if(count($parts)!==2)return $v;
        [$axis,$value]=$parts;
        $axes=['zone'=>'일별 상태','center_close_held'=>'중심선 접촉 후 종가 지지','close_reclaimed_lower'=>'하단 접촉 후 종가 복귀','ordered'=>'정배열','higher_low'=>'확정 저점 상향',
            'breakout_retest_proxy'=>'돌파 후 재지지 대용 조건','ordered_higher_retest'=>'정배열·저점 상향·재지지 조합',
            'repeat_within_5'=>'5봉 내 재진입','repeat_within_10'=>'10봉 내 재진입','repeat_within_20'=>'20봉 내 재진입'];
        return ($axes[$axis]??$axis).' / '.($labels[$value]??(['yes'=>'예','no'=>'아니오','unknown'=>'미확인'][$value]??$value));
    };
    echo '<section><h2>엔벨로프 눌림 관찰 연구</h2><p>20:20 KR 예약 작업 뒤에 갱신합니다. 운영 추천·점수·주문에는 반영하지 않습니다. 당시 완료 일봉의 SMA20 ±9%, SMA20 &gt; SMA60 &gt; SMA240을 연구 가정으로 사용합니다. 이격 과다·강한 상승·첫 박스는 확정 판정하지 않습니다.</p>';
    try{$report=PaperEnvelopeResearch::load($state,$id);}catch(Throwable $ex){echo '<p>연구 읽기 실패: '.$e($ex->getMessage()).'</p></section>';return;}
    $latest=null;foreach(PaperFollowup::readRuns($state.'/runs/'.$id.'-forward') as $run){$r=$run['envelope_research']??null;if($r&&($latest===null||$r['started_at']>$latest['started_at']))$latest=$r;}
    if($latest)echo '<p>최근 예약 연구 실행 '.$e($date($latest['started_at'])).' / '.$e($latest['status']).' '.$e($latest['error_type']??'').'</p>';
    if(!$report){echo '<p>저장 결과가 없습니다. <code>php bin/paper_envelope_research.php --account='.$e($id).'</code></p></section>';return;}
    $s=PaperEnvelopeResearch::summarize($report['rows']);
    echo '<p>마지막 저장 '.$e($date($report['generated_at'])).' / '.$e($report['status']).' · 보존 '.$s['retained'].'건 · 이번 추가 '.($report['added']??0).'건 · 원본 미평가 '.($report['unavailable_observations']??0).'건 · 원본 중복 '.($report['duplicates']??0).'건</p><p>';
    foreach($s['statuses'] as $k=>$n)echo $e($label($k)).' '.$n.'건 · ';
    echo '</p><p>하단 구간은 저가 ≤ 하단일 때 시작하며, 한 완료봉 전체가 하단 위로 벗어난 뒤 다시 진입해야 별도 구간입니다. 연속 체류는 재접촉으로 세지 않습니다. 5·10·20봉 내 반복 횟수는 관찰값이며 위험 차단 기준이 아닙니다. 하단 위 종가 복귀와 이후 반등 성과를 구분합니다.</p>';
    echo '<details><summary>보존 종목·판정 근거·가격 반영 상태</summary><div class="table-wrap"><table><thead><tr><th>판정일·종목</th><th>기존 판정</th><th>엔벨로프·이격</th><th>구간·구조</th><th>추적·실제 완료봉</th></tr></thead><tbody>';
    foreach(array_reverse($report['rows'],true) as $row){
        $f=$row['frozen'];$r=$f['record'];$v=$f['features'];
        echo '<tr><td>'.$e($date($r['session'])).'<br>'.$e($r['name']??$r['symbol']).'</td><td>'.$e($f['final_status']).'<br>'.$e(implode(', ',$f['selected_patterns'])).'</td><td>';
        if($v['status']!=='evaluated')echo $e($label($v['status'])).' ('.$v['completed_bars'].'봉)';
        else{
            echo $e($label($v['zone'])).'<br>하단 '.$num($v['lower']).' / 중심 '.$num($v['ma20']).'<br>60–240 이격 '.$num($v['gap60_240_pct']).'%<br>정배열 '.($v['ordered_ma20_60_240']?'예':'아니오');
        }
        echo '</td><td>';
        if($v['status']==='evaluated'){
            echo $e($label($v['episode'])).'<br>5·10·20봉 구간 수 '.implode(' / ',$v['episodes_in_last_bars']);
            echo '<br>구간 시작 간격 '.($v['bars_between_episode_starts']??'—').'봉';
            echo '<br>하단 접촉 후 종가 복귀 '.($v['close_reclaimed_lower']?'예':'아니오');
            echo '<br>중심선 접촉 후 종가 지지 '.($v['center_close_held']?'예':'아니오');
            echo '<br>확정 저점 상향 '.($v['higher_confirmed_low']===null?'미확인':($v['higher_confirmed_low']?'예':'아니오'));
            echo '<br>20봉 고점 돌파 후 재지지 대용 조건 '.($v['prior20_breakout_retest_proxy']?'예':'아니오');
        }
        echo '</td><td>'.$e($label($row['status'])).'<br>평가 '.$e($date($row['last_success_at'])).'<br>완료봉 '.$e($date($row['latest_session']??null)).'<br>후속 '.($row['observed_bars']??0).'봉';
        if(isset($row['error']))echo '<br>'.$e($row['error']);
        echo '</td></tr>';
    }
    echo '</tbody></table></div></details><h3>조건별 후속 종가 관찰</h3><p>1·3·5·10·20봉 완료 표본만 평균에 포함합니다. 매매 손익·승률이 아니며 비용을 적용하지 않습니다. 전략 버전·선택 패턴·기존 판정별로 분리합니다. 버전 미기록은 관찰용입니다. 그룹은 중복될 수 있고 다른 날짜의 같은 종목도 독립 표본은 아닙니다.</p><p>첫 접촉·재접촉 및 구조 비교는 새 하단 접촉 구간의 시작 봉만 사용합니다. zone 그룹은 매일의 상태 관찰이므로 연속 체류도 포함합니다. 대용 조건은 최근 20봉 안의 이전 20봉 고점 종가 돌파 후 현재 봉이 그 수준을 저가로 접촉하고 종가로 지킨 경우입니다. 원문의 첫 박스 여부는 미확인입니다.</p>';
    foreach($s['groups'] as $g){
        $st=$g['stratum'];echo '<details><summary>'.$e($label($g['cohort'])).' · '.$g['signals'].'건 / '.$e($st['final_status']).' / '.$e(implode(', ',$st['patterns'])).'</summary>';
        echo '<p>연구 '.$e($st['policy']).' / 계산 '.substr($st['execution_version'],0,12).' / 전략 '.$e($st['strategy_version']??'미기록 · 관찰용').'</p>';
        echo '<table><thead><tr><th>완료봉</th><th>완료 / 대상</th><th>상승 비율</th><th>평균 종가 수익률</th><th>평균 최대 상승폭</th><th>평균 최대 하락폭</th></tr></thead><tbody>';
        foreach($g['horizons'] as $n=>$h)echo '<tr><td>'.$n.'</td><td>'.$h['n'].' / '.$g['signals'].'</td><td>'.$num($h['n']?$h['up']/$h['n']*100:null).'</td><td>'.$num($h['mean_return_pct']).'</td><td>'.$num($h['mean_max_up_pct']).'</td><td>'.$num($h['mean_max_down_pct']).'</td></tr>';
        echo '</tbody></table><p>비율·수익률·상승·하락폭 단위: %</p></details>';
    }
    if($report['errors'])echo '<details><summary>원본 등록 오류 '.count($report['errors']).'건</summary><pre>'.$e(PaperRrAudit::encode($report['errors'])).'</pre></details>';
    echo '<p>갱신 오류 시 마지막 성공 결과를 유지합니다. 평가 시각과 실제 반영된 완료봉 날짜는 다릅니다. 20봉 완료 결과는 고정합니다. TOP100에서 빠져도 미완료 원본을 보존하여 추적합니다.</p></section>';
}
