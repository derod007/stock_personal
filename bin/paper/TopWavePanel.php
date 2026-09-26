<?php
declare(strict_types=1);
require_once __DIR__.'/Chrome.php';
use ChartEntryLab\TopWaveReview;

function paper_top_wave_rows(array $state,int $asOf):array
{
    $rows=[];
    foreach($state['active']??[] as $symbol=>$order){
        if(empty($order['filled']))continue;
        try{
            $r=(new TopWaveReview())->analyze($state['history'][$symbol]??[],$symbol,$asOf);
            if(!empty($state['halted'])){$r['historical_status']=$r['status'];$r['status']='account_halted';$r['reduce_candidate']=false;}
            $rows[$symbol]=$r;
        }catch(Throwable $e){$rows[$symbol]=['status'=>'data_error','reduce_candidate'=>false,'setup'=>null,'events'=>[]];}
    }
    return $rows;
}
function paper_top_wave_panel(array $state,int $asOf):void
{
    $rows=paper_top_wave_rows($state,$asOf);
    $labels=['none'=>'해당 패턴 없음','retry_failed'=>'고점 재도전 실패 · 저점 확인 중','support_test'=>'L2 지지 시험',
        'reduce_candidate'=>'보유 축소 후보','recovery_watch'=>'L2 회복 관찰','released'=>'경고 해제',
        'peak_reclaimed'=>'전고점 회복 · 높은 저점 확인 대기','stale_data'=>'가격 오래됨 · 판정 보류',
        'data_quality'=>'가격 품질 확인 필요','insufficient_history'=>'일봉 부족','account_halted'=>'계좌 중단 · 판정 보류','data_error'=>'일봉 읽기 오류'];
    $esc=static fn($v)=>paper_esc($v);
    $date=static fn($t)=>$t?(new DateTimeImmutable('@'.$t))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d'):'—';
    echo '<section class="panel"><h2 class="paper-section-title">고점 재시도 · 보유 축소 검토</h2><p class="paper-note">3개 이상 상승 고점 뒤 재도전 실패 → 고정 L2 종가 이탈 때만 축소 후보입니다. 자동 매도나 축소 비율 지시가 아닙니다. L2는 최근 세 상승 고점 중 H2와 H3 사이 조정 저점입니다. 기존 고점 경고와 별도 판독입니다.</p>';
    if(!$rows){echo '<p class="paper-empty">판독할 보유 종목이 없습니다. 지정가 대기 주문은 제외합니다.</p></section>';return;}
    echo '<p class="paper-note">계좌에 저장된 완료 일봉을 현재 규칙으로 재현합니다. 확인일은 당시 봉으로 계산 가능해진 날이며, 실제 알림을 보냈던 날은 아닙니다. 화면 조회는 파일·주문을 수정하지 않습니다.</p><div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>보유 종목</th><th>판독</th><th>일봉 기준일</th><th>상승 고점 수</th><th>고정 L2</th><th>근거</th></tr></thead><tbody>';
    foreach($rows as $symbol=>$r){$s=$r['setup'];
        echo '<tr><td>'.$esc($symbol).'</td><td>'.$esc($labels[$r['status']]??$r['status']).'</td><td>'.$esc($date($r['data_asof']??null)).'</td><td>'.$esc($s['high_count']??'—').'</td><td>'.$esc(isset($s['floor'])?number_format($s['floor'],2):'—').'</td><td>';
        if($s){
            echo '<details><summary>파동·상태 이력</summary><ul>';
            foreach(['H1','L1','H2','L2','H3','L3','retry'] as $k){$p=$s[$k];echo '<li>'.$esc(($k==='retry'?'재도전 고점':$k).' '.$date($p['pivot_at']).' / '.$p['price'].' / 확인 '.$date($p['confirmed_at'])).'</li>';}
            echo '</ul><p>전고점 회복 기준 '.$esc($s['release_peak']).'</p>';
            foreach($r['events'] as $e)echo '<div>'.$esc($date($e['confirmed_at']).' · '.($labels[$e['status']]??$e['status'])).'</div>';
            echo '</details>';
        }else echo '—';
        echo '</td></tr>';
    }
    echo '</tbody></table></div></section>';
}
