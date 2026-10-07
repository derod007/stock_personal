<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Interactive presentation only; never feeds the scheduled order selector. */
final class ScanEntryView
{
    public static function modeLabel(string $mode):string
    {
        return match($mode){'intraday'=>'장중 잠정','completed_fallback'=>'장중 자료 실패 · 완료봉 대체','completed'=>'완료 일봉',default=>'분석 기준 미확인'};
    }
    public static function decorate(array $r):array
    {
        $c=$r['entry_candidate']??[];$positive=fn($v)=>is_numeric($v)&&is_finite((float)$v)&&(float)$v>0;
        $price=$r['price']??null;$low=$c['low']??null;$high=$c['high']??null;$stop=$c['stop']??null;$target=$c['target']??null;
        $valid=$positive($price)&&$positive($low)&&$positive($high)&&$positive($stop)&&$positive($target)&&$low<=$high&&$stop<$low;
        $v=['confirmed'=>false,'tier'=>0,'group'=>'평가 보류','distance_pct'=>null,'target_exceeded'=>false,'note'=>'가격 후보 또는 현재가 미확인'];
        if($valid){
            $distance=$price>$high?($price-$high)/$price*100:($price<$low?($low-$price)/$price*100:0.0);
            $v['distance_pct']=$distance;$v['target_exceeded']=$price>=$target;
            $v['note']=$price>$high?sprintf('관심 구간 위 · 상단과 거리 %.1f%%',$distance):($price<$low?sprintf('관심 구간 아래 · 하단과 거리 %.1f%%',$distance):'관심 구간 안 · 패턴 확인 별도');
            $status=$r['entry_status']??'';$mode=$r['analysis_mode']??'';
            if($price<=$stop){$v['group']='손절 후보 이탈';$v['note']='현재가가 손절 후보 이하';}
            elseif(in_array($status,['blocked','risk_blocked','context_wait','stale_data','invalidated','expired','unavailable'],true))$v['group']='조건 차단·보류';
            elseif($mode==='completed_fallback'||!in_array($mode,['intraday','completed'],true))$v['group']='분석 확인 필요';
            elseif($price>=$target){$v['group']='목표 초과 · 관찰';$v['tier']=1;}
            elseif(!empty($r['order_ready'])&&$status==='ready'&&$mode==='completed'){
                $v['confirmed']=true;$v['tier']=4;$v['group']='진입 확인 · 지정가 대기';
            }else{$v['tier']=$price>=$low&&$price<=$high?3:2;$v['group']=$mode==='intraday'?'장중 잠정 · 관찰':'눌림·확인 대기';}
            if($v['target_exceeded'])$v['note'].=' · 현재가 기준 목표 초과, 눌림 시나리오용';
        }
        $p=is_array($r['order_plan']??null)?$r['order_plan']:[];
        $mode=$r['analysis_mode']??'';
        $v['pattern_label']=$mode==='intraday'?'장중 잠정 · 종가 확인 전':
            (($p['confirmation_status']??'')==='confirmed'?'완료봉 패턴 확인':'패턴 미확인·보류');
        $v['order_note']='주문 계획 미확정';$v['order_distance_pct']=null;
        $planReady=!empty($r['order_ready'])&&($r['entry_status']??'')==='ready'&&!empty($p['ready'])&&($p['status']??'')==='ready'&&$mode==='completed';
        $levels=$positive($p['entry']??null)&&$positive($p['stop']??null)&&$positive($p['target']??null)
            &&$p['stop']<$p['entry']&&$p['entry']<$p['target']&&$positive($p['reward_risk']??null);
        // Candidate bands are observation levels, not the executable limit plan.
        if($v['confirmed']){$v['confirmed']=false;$v['tier']=0;$v['group']='주문 근거 확인 필요';}
        if($planReady&&$levels){
            $v['pattern_label']='완료봉 패턴 확인';
            $v['order_note']=sprintf('분석상 지정가 %s / 손절 %s / 목표 %s / 손익비 %.3f (비용 전)',
                number_format($p['entry'],3),number_format($p['stop'],3),number_format($p['target'],3),$p['reward_risk']);
            $v['order_note'].=isset($p['order_valid_bars'])?' · 신호 다음 '.(int)$p['order_valid_bars'].'거래봉 유효 규칙':'';
            if(is_numeric($p['signal_at']??null))$v['order_note'].=' · 신호 '.(new \DateTimeImmutable('@'.(int)$p['signal_at']))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i');
            $v['order_note'].=' · 실제 주문·체결 상태 아님';
            if(!$positive($price)){$v['group']='현재가 확인 필요';$v['tier']=0;}
            elseif($price<=$p['stop']){$v['group']='계획 손절가 이하 · 재검토';$v['tier']=0;}
            elseif($price>=$p['target']){$v['group']='계획 목표가 이상 · 재검토';$v['tier']=1;}
            else{
                $v['confirmed']=true;$v['tier']=4;$v['group']='패턴 확인 · 지정가 계획';
                $v['order_distance_pct']=abs($price-$p['entry'])/$price*100;
                $v['order_note'].=$price==$p['entry']?' · 현재가=지정가':sprintf(' · 현재가가 지정가 %s %.1f%%',$price>$p['entry']?'위':'아래',$v['order_distance_pct']);
            }
        }elseif(!empty($r['order_ready'])&&$mode==='completed'){
            $v['order_note']='주문 계획 근거 부족 · 가격·손익비 확인 필요';
        }
        if(($r['analysis_mode']??'')==='completed_fallback')$v['note'].=' · 장중 자료 실패로 완료봉 참고';
        $r['entry_view']=$v;return $r;
    }
    public static function compare(array $a,array $b):int
    {
        $a=self::decorate($a);$b=self::decorate($b);$x=$a['entry_view'];$y=$b['entry_view'];
        if($x['tier']!==$y['tier'])return $y['tier']<=>$x['tier'];
        if($x['tier']>0){$key=$x['tier']===4?'order_distance_pct':'distance_pct';$d=($x[$key]??INF)<=>($y[$key]??INF);if($d!==0)return $d;}
        $d=($b['score']??-1)<=>($a['score']??-1);if($d!==0)return $d;
        return ($a['amount_rank']??999)<=>($b['amount_rank']??999);
    }
    public static function rows(array $rows):array
    {
        $rows=array_map([self::class,'decorate'],$rows);usort($rows,[self::class,'compare']);
        foreach($rows as $i=>&$r)$r['entry_view']['sort_order']=$i;unset($r);return $rows;
    }
}
