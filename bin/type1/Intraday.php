<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;

/** Completed five-minute bars only. No orders, persisted signals or portfolio writes. */
final class Intraday
{
    public static function candidates(array $state): array
    {
        $rows=$state['captures'][$state['last_day']??'']['rows']??[];
        return array_values(array_filter($rows,static fn($r)=>($r['episode_state']??'')==='new' && ($r['classification']['matches']??false)));
    }

    public static function evaluate(array $rows,int $now): array
    {
        $tz=new \DateTimeZone('Asia/Seoul');
        $local=(new \DateTimeImmutable('@'.$now))->setTimezone($tz);
        $start=$local->setTime(9,0)->getTimestamp();$end=$local->setTime(15,30)->getTimestamp();
        $result=['status'=>'관심 후보','reason'=>'첫 30분 관찰 중','as_of'=>null,'trigger'=>null,'invalidation'=>null,'confirmed_at'=>null,'close'=>null,'excluded_tail_rows'=>0,'delay_seconds'=>null];
        if($local->format('N')>=6 || $now<$start)return array_replace($result,['status'=>'판단 불가','reason'=>'정규장 시작 전 또는 주말']);
        // Conservatively omit ONLY a terminal zero-volume, flat-price row. It may be
        // a quote-like provider tail or a genuine zero-trade interval: neither proves
        // a completed traded candle. Never round its timestamp or extend freshness.
        $tailTime=null;$tailIndex=null;
        foreach($rows as $i=>$b){$t=$b['time']??0;if($t>=$start && $t<$end && $t<=$now && ($tailTime===null||$t>$tailTime)){$tailTime=$t;$tailIndex=$i;}}
        if($tailIndex!==null){
            $b=$rows[$tailIndex];$flat=true;
            foreach(['open','high','low','close'] as $k)if(!is_numeric($b[$k]??null)||!is_finite((float)$b[$k])||$b[$k]<=0||$b[$k]!=$b['open'])$flat=false;
            $sameTime=count(array_filter($rows,static fn($r)=>($r['time']??0)===$tailTime));
            if($sameTime===1 && $flat && isset($b['volume']) && is_numeric($b['volume']) && (float)$b['volume']===0.0 && empty($b['synthetic'])){
                unset($rows[$tailIndex]);$result['excluded_tail_rows']=1;
            }
        }
        $bars=[];
        foreach($rows as $b){
            $t=$b['time']??0;
            if($t<$start||$t>=$end||$t+300>$now)continue;
            $issue=isset($bars[$t])?'동일 시각 중복 봉':(($t-$start)%300!==0?'5분 경계 밖 행':(!empty($b['synthetic'])?'합성 봉':(($b['is_complete']??true)===false?'미완료 표시 봉':null)));
            if($issue!==null){
                $at=(new \DateTimeImmutable('@'.$t))->setTimezone($tz)->format('H:i:s');
                return array_replace($result,['status'=>'판단 불가','reason'=>$issue.' ('.$at.' KST)']);
            }
            foreach(['open','high','low','close'] as $k)if(!is_numeric($b[$k]??null)||!is_finite((float)$b[$k])||$b[$k]<=0)
                return array_replace($result,['status'=>'판단 불가','reason'=>'유효하지 않은 가격']);
            if($b['low']>min($b['open'],$b['close'])||$b['high']<max($b['open'],$b['close']))
                return array_replace($result,['status'=>'판단 불가','reason'=>'OHLC 모순']);
            $bars[$t]=$b;
        }
        ksort($bars);
        if(!$bars)return array_replace($result,['status'=>'판단 불가','reason'=>'당일 완료 5분봉 없음']);
        $last=array_key_last($bars);$result['as_of']=date('c',$last+300);$result['close']=$bars[$last]['close'];
        $result['delay_seconds']=max(0,min($now,$end)-($last+300));
        if($result['delay_seconds']>900)return array_replace($result,['status'=>'판단 불가','reason'=>'최신 완료봉이 '.(int)floor($result['delay_seconds']/60).'분 지연됨 (허용 15분)']);
        $expected=$start;
        foreach($bars as $t=>$b){if($t!==$expected)return array_replace($result,['status'=>'판단 불가','reason'=>'09:00부터 연속된 5분봉이 아님']);$expected+=300;}
        if(count($bars)<6)return $result;
        $first=array_slice($bars,0,6);$floor=min(array_column($first,'low'));$peak=max(array_column($first,'high'));
        $open=$first[0]['open'];$previous=$first[5]['close'];$pullback=false;
        $result['status']='조정 대기';$result['reason']='첫 30분 이후 상승·조정 형성 대기';
        $result['invalidation']=$floor;
        foreach(array_slice($bars,6,null,true) as $t=>$b){
            if($result['status']==='조건 무효')break;
            if($result['confirmed_at']!==null){
                if($b['low']<=$result['invalidation']){$result['status']='조건 무효';$result['reason']='반등 확인 후 고정 조정 저점 이탈';}
                continue;
            }
            if($b['low']<=$floor){$result['status']='조건 무효';$result['reason']='반등 확인 전 첫 30분 저점 접촉·이탈';break;}
            if(!$pullback){
                // A correction is a lower close after a rise; freeze only the preceding peak.
                if($peak>$open && $b['close']<$previous){
                    $pullback=true;$result['trigger']=$peak;$result['invalidation']=$b['low'];
                    $result['reason']='조정 형성 · 직전 고점 위 5분봉 종가 대기';
                }else{$peak=max($peak,$b['high']);}
            }else{
                // A new low on the breakout bar makes intrabar ordering ambiguous: no confirmation.
                $newLow=$b['low']<$result['invalidation'];
                if($newLow)$result['invalidation']=$b['low'];
                if(!$newLow && $b['close']>$result['trigger']){
                    $result['status']='반등 확인';$result['confirmed_at']=date('c',$t+300);
                    $result['reason']='조정 후 직전 고점 위에서 5분봉 마감 · 체결 신호 아님';
                }
            }
            $previous=$b['close'];
        }
        return $result;
    }
}
