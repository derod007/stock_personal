<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;
function renderTracking(?array $state,?string $error=null,string $scope="new",array $quotes=[]): void
{
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$n=static fn($v,$d=0)=>is_numeric($v)?number_format((float)$v,$d):'—';
    echo '<h1>마감 고정 후보 · 유형1 관찰</h1><p>수익성이 검증된 추천이나 자동 주문이 아닙니다. 각 후보를 독립 추적하며, 자금 한도를 적용한 계좌 수익률과 다릅니다.</p>';
    if($error!==null){echo '<p role="alert">'.$h($error).'</p>';return;}
    if(!$state||!$state['last_day']){echo '<p>아직 저장된 마감 기록이 없습니다. 병합·적용 후 한국 daily 실행에서 자동 저장됩니다.</p>';return;}
    echo '<p><a href="type1_breakout.php">마감 고정 · 유형1 + 20일 고가 돌파 후보</a></p>';
    $day=$state['last_day'];$capture=$state['captures'][$day];
    echo '<p>마지막 저장일 <strong>'.$h($day).'</strong> · 시작 전 TOP100 이력은 미확정입니다.</p>';
    if(!$capture['ranking_session_verified'])echo '<p class="notice">마감 후 수집한 순위입니다. 제공 자료에 거래일이 없어 당일 최종 TOP100 여부는 미확정입니다.</p>';
    if($capture['coverage_gap'])echo '<p class="notice">직전 저장 이후 빠진 거래일의 후보는 복원하지 않았습니다.</p>';
    $matches=array_filter($capture['rows'],static fn($r)=>$r['classification']['matches']??false);$bad=array_filter($capture['rows'],static fn($r)=>$r['error']!==null);
    echo '<p>저장 대상 '.count($capture['rows']).' · 유형1 '.count($matches).' · 평가 불가 '.count($bad).'종목</p>';
    $allMatches=$matches;
    $newCount=count(array_filter($matches,static fn($r)=>$r['episode_state']==='new'));
    if($scope!=='all')$matches=array_filter($matches,static fn($r)=>$r['episode_state']==='new');
    echo '<nav class="filters" aria-label="후보 필터"><a href="type1_tracking.php?scope=new"'.($scope!=='all'?' aria-current="page"':'').'>신규만 '.$newCount.'</a><a href="type1_tracking.php?scope=all"'.($scope==='all'?' aria-current="page"':'').'>유형1 전체 '.count($allMatches).'</a></nav>';
    if(!$matches)echo '<p class="notice">'.$h($day).' 저장 기록에 '.($scope==='all'?'유형1 후보':'신규 후보').'가 없습니다. 관찰 중인 종목은 전체 보기에서 확인하세요.</p>';
    echo '<div class="table-wrap"><table><thead><tr><th>대금 순위</th><th>고정 후보</th><th>구분</th><th>관찰 시작</th><th>판단일 종가</th><th>판단 종가 대비 변화</th><th>진행 상태</th><th>차트</th></tr></thead><tbody>';
    foreach($matches as $r){
        $key=$r['episode_anchor'].'|'.$r['symbol'];$trade=$state['trades'][$key]??null;
        $detailDay=$trade?$r['episode_anchor']:$day;
        $url='type1_tracking.php?day='.rawurlencode($detailDay).'&symbol='.rawurlencode($r['symbol']);
        $quote=$quotes[$r['symbol']]??null;$basis=$trade['analysis_close']??$r['classification']['analysis_close'];
        $delta=$quote&&$basis>0?$n(($quote['close']/$basis-1)*100,2).'%':'—';
        $labels=['waiting'=>'진입 대기','open'=>'관찰 중','closed'=>'종료','unknown'=>'가격 미확정'];
        echo '<tr><td>'.$h($r['rank']).'</td><td><a href="index.php?symbol='.$h(rawurlencode($r['symbol'])).'">'.$h($r['name'].' '.$r['symbol']).'</a></td><td>'.($r['episode_state']==='new'?'신규 관찰':'관찰 중 · 추가 진입 없음').'</td><td>'.$h($r['episode_anchor']).'</td><td>'.$n($r['classification']['analysis_close']).'<small>'.$h($day).'</small></td><td>'.$delta.'<small>'.($quote?$h($quote['day']).' · '.$n($quote['close']).'원':'당일 유효 가격 없음').'</small><small>기준 '.$h($trade['anchor']??$day).' 종가</small></td><td>'.$h($trade?$labels[$trade['status']]:'신규 진입 없음').'</td><td><a href="'.$h($url).'">판단 차트 · 이후 경로</a></td></tr>';
    }
    echo '</tbody></table></div><p>TOP100 첫 관찰부터 T~T+20 구간을 유지합니다. 첫날 유형1이 아니었다가 나중에 유형1이 된 종목은 이 구간의 신규 진입으로 세지 않습니다. 정렬은 거래대금 순이며 수익 가능성 순위가 아닙니다.</p>';
    if($bad){echo '<details><summary>평가 불가 사유</summary><ul>';foreach($bad as $r)echo '<li>'.$h($r['name'].' '.$r['symbol'].' · '.$r['error']).'</li>';echo '</ul></details>';}
    echo '<p>가격 변화는 최초 진입 판단일(진입 없는 종목은 표시된 판단일) 종가 대비 최근 저장 종가입니다. 실시간 가격·매매 수익률이 아닙니다. 실시간 비교는 <a href="type1_scan.php">유형1 스캔</a>에서 확인하세요.</p>';
    $counts=array_count_values(array_column($state['trades'],'status'));
    echo '<details class="history"><summary>전체 독립 모의 경로 보기</summary><h2>독립 모의 경로</h2><p>진입 대기 '.($counts['waiting']??0).' · 관찰 중 '.($counts['open']??0).' · 청산 '.($counts['closed']??0).' · 가격 미확정 '.($counts['unknown']??0).'</p>';
    echo '<p>다음 거래일 시가 / −5% 손절 / +15% 목표 / T+20 종가 종료. 같은 봉에서 손절·목표 접촉 시 손절 우선이며 장중 순서는 미확정입니다. 매수·매도 각각 0.15% 비용을 반영합니다.</p>';
    echo '<div class="table-wrap"><table><thead><tr><th>판단일</th><th>종목</th><th>상태</th><th>모형 매수가</th><th>손절 / 목표</th><th>종료일·가격</th><th>순손익률</th></tr></thead><tbody>';
    $labels=['waiting'=>'다음 시가 대기','open'=>'관찰 중','closed'=>'청산','unknown'=>'가격 미확정'];
    foreach(array_slice(array_reverse(array_values($state['trades'])),0,100) as $t){
        echo '<tr><td>'.$h($t['anchor']).'</td><td><a href="type1_tracking.php?day='.$h(rawurlencode($t['anchor'])).'&amp;symbol='.$h(rawurlencode($t['symbol'])).'">'.$h($t['name'].' '.$t['symbol']).'</a></td><td>'.$h($labels[$t['status']]).'<small>'.$h($t['error']??($t['exit']['reason']??'')).'</small>'.($t['same_bar_order_unknown']?'<small>장중 순서 미확정</small>':'').'</td><td>'.$n($t['entry']['price']??null).'<small>'.$h($t['entry']['day']??'').'</small></td><td>'.$n($t['entry']['stop']??null).' / '.$n($t['entry']['target']??null).'</td><td>'.$h($t['exit']['day']??'—').' / '.$n($t['exit']['price']??null).'</td><td>'.$n($t['net_pct'],2).($t['net_pct']!==null?'%':'').'</td></tr>';
    }
    echo '</tbody></table></div><p>최근 100개 관찰을 표시합니다. <a href="type1_tracking.php?format=json">전체 고정 기록·일별 경로 JSON</a></p></details>';
}
