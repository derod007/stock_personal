<?php
declare(strict_types=1);
require_once __DIR__.'/Chrome.php';
use ChartEntryLab\BottomWaveReview;
use ChartEntryLab\CandleClock;
function paper_bottom_wave_panel(array $state,int $asOf,bool $requested,string $url):void {
    $esc=static fn($v)=>paper_esc($v);
    echo '<section class="panel"><h2 class="paper-section-title">저점 파동 구조 · 별도 연구 판독</h2><p class="paper-note">저점 재시도 실패 뒤 중간 고점 돌파를 확인합니다. 기존 역저점 가산점과 별개이며 추천 점수·주문에는 반영하지 않습니다. 저장된 일봉으로만 판독하며 현재 시장의 신규 추천 목록이 아닙니다.</p>';
    if(!$requested){echo '<a href="'.$esc($url).'">저장 종목의 파동 근거 보기</a></section>';return;}
    if(!empty($state['halted'])){echo '<p>계좌 중단 상태이므로 현재 판독을 보류합니다.</p></section>';return;}
    $histories=$state['history']??[];$last=[];
    foreach($histories as $sym=>$bars){$b=CandleClock::completed($bars,$sym,$asOf);if($b)$last[$sym]=end($b)['available_at'];}
    arsort($last);$symbols=array_slice(array_keys($last),0,100);
    echo '<p class="paper-note">저장 일봉이 있는 '.count($last).'종목 중 최근 일봉 기준 최대100종목을 확인합니다. 시장 전체 최신성은 확인하지 않았습니다. 가격 기준 변경 자료가 없는 장부에서는 기업행사 전후 파동 연결 여부를 추가 확인해야 합니다.</p><table class="scan-table"><thead><tr><th>종목</th><th>마지막 저장봉</th><th>구조 상태</th><th>근거</th></tr></thead><tbody>';
    $labels=['none'=>'패턴 없음','retry_failed'=>'저점 재시도 실패 · 돌파 대기','breakout_confirmed'=>'중간 고점 돌파 확인','target_zone'=>'이전 파동 고점 도달','low_broken'=>'저점 이탈 · 상승 가설 보류','released'=>'저점 이탈 후 낮은 고점 · 가설 해제','expired'=>'관찰기간 종료','invalid_structure'=>'목표 구간 구조 불충족','breakout_before_retry_confirmation'=>'재시도 확인 전에 이미 돌파','insufficient_history'=>'이력 부족','data_quality'=>'자료 확인 필요','reference_reset'=>'가격 기준 변경으로 초기화'];
    $date=static fn($t)=>(new DateTimeImmutable('@'.$t))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
    foreach($symbols as $sym){
        try{$r=(new BottomWaveReview())->analyze($histories[$sym],$sym,$asOf);}
        catch(Throwable $e){echo '<tr><td>'.$esc($sym).'</td><td colspan="3">자료 확인 필요</td></tr>';continue;}
        echo '<tr><td>'.$esc($sym).'</td><td>'.$esc($date($last[$sym])).'</td><td>'.$esc($labels[$r['status']]??$r['status']).'</td><td>';
        if($r['setup']){$s=$r['setup'];echo '<details><summary>파동 발생일·확인일</summary>';
            foreach(['L1','H1','L2','H2','L3','H3','retry'] as $k){$p=$s[$k];echo '<div>'.$esc($k.' '.$p['price'].' / 발생 '.$date($p['pivot_at']).' / 확인 '.$date($p['confirmed_at'])).'</div>';}
            echo '<p>'.$esc('돌파 기준 '.$s['trigger'].' / 이전 파동 고점 '.$s['target'].' / 저점 기준 '.$s['stop']).'</p></details>';
        }else echo '—';echo '</td></tr>';
    }
    echo '</tbody></table></section>';
}
