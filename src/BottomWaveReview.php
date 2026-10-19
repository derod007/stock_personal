<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Chronological price structure, no reflected-price percentages and no score. */
final class BottomWaveReview
{
    public const VERSION='bottom_wave_structure_v1';
    public const RULES=['pivot_right_bars'=>2,'min_pivot_gap'=>3,'min_swing_pct'=>3.0,
        'min_swing_atr'=>1.0,'min_low_decline_pct'=>0.5,'min_falling_lows'=>3,
        'retry_min_ratio'=>0.98,'retry_max_ratio'=>1.03,'max_pattern_bars'=>120];
    public function analyze(array $raw,string $symbol,int $asOf,array $referenceDays=[]):array
    {
        $bars=CandleClock::completed($raw,$symbol,$asOf);$pivots=[];$setup=null;$events=[];$status='none';$lastSetup=null;
        $out=['version'=>self::VERSION,'rules'=>self::RULES,'status'=>'none','entry_candidate'=>false,
            'data_asof'=>$bars?end($bars)['available_at']:null,'setup'=>null,'events'=>[],'pivots'=>[],
            'basis'=>'completed_daily_close','score'=>null,'operational_order'=>false];
        if(count($bars)<60){$out['status']='insufficient_history';return $out;}
        $emit=static function(string $s,int $at,array $detail=[])use(&$events,&$status,&$setup):void {
            $status=$s;$events[]=['status'=>$s,'confirmed_at'=>$at,'setup'=>$setup]+$detail;
        };
        $segment=0;
        foreach($bars as $i=>$bar){
            $day=(new \DateTimeImmutable('@'.$bar['available_at']))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d');
            if(in_array($day,$referenceDays,true)){$setup=null;$pivots=[];$segment=$i;$status='reference_reset';continue;}
            $new=null;
            if($i-$segment>=4){
                $j=$i-2;$b=$bars[$j];$high=true;$low=true;
                foreach([-2,-1,1,2] as $off){$high=$high&&$b['high']>$bars[$j+$off]['high'];$low=$low&&$b['low']<$bars[$j+$off]['low'];}
                if($high xor $low){
                    $p=['kind'=>$high?'H':'L','index'=>$j,'price'=>(float)$b[$high?'high':'low'],'pivot_at'=>$b['available_at'],'confirmed_at'=>$bar['available_at']];
                    $last=$pivots?end($pivots):null;
                    if(!$last){$pivots[]=$p;$new=$p;}
                    elseif($last['kind']===$p['kind']){
                        if(($high&&$p['price']>$last['price'])||($low&&$p['price']<$last['price'])){array_pop($pivots);$pivots[]=$p;$new=$p;}
                    }elseif($j-$last['index']>=3&&abs($p['price']-$last['price'])>=max($last['price']*.03,$this->atr($bars,$j))){$pivots[]=$p;$new=$p;}
                }
            }
            if($i>=59&&$setup===null&&$new&&$new['kind']==='L'&&count($pivots)>=7){
                [$l1,$h1,$l2,$h2,$l3,$h3,$retry]=array_slice($pivots,-7);
                if($l1['kind']==='L'&&$retry['index']-$l1['index']<=120
                    &&$l2['price']<$l1['price']*.995&&$l3['price']<$l2['price']*.995&&$h2['price']<$h1['price']
                    &&$retry['price']>=$l3['price']*.98&&$retry['price']<=$l3['price']*1.03&&$bar['close']>$l3['price']){
                    $setup=['L1'=>$l1,'H1'=>$h1,'L2'=>$l2,'H2'=>$h2,'L3'=>$l3,'H3'=>$h3,'retry'=>$retry,
                        'trigger'=>$h3['price'],'target'=>$h2['price'],'stop'=>min($l3['price'],$retry['price']),
                        'failure_confirmed_at'=>$bar['available_at'],'entry_confirmed_at'=>null,'target_seen'=>false,'low_break'=>null];
                    $prior=null;for($k=$h3['index']+1;$k<=$i;$k++)if($bars[$k]['close']>$h3['price']){$prior=$bars[$k]['available_at'];break;}
                    $setup['ineligible_reason']=$h3['price']>=$h2['price']?'invalid_structure':($prior!==null?'breakout_before_retry_confirmation':null);
                    $emit('retry_failed',$bar['available_at']);
                }
            }
            if($setup===null)continue;
            $close=(float)$bar['close'];
            if($close<$setup['stop']&&$setup['low_break']===null){$setup['low_break']=['index'=>$i,'at'=>$bar['available_at']];$emit('low_broken',$bar['available_at']);}
            if($setup['low_break']&&$new&&$new['kind']==='H'&&$new['index']>$setup['low_break']['index']&&$new['price']<$setup['H3']['price']){
                $emit('released',$bar['available_at']);$lastSetup=$setup;$setup=null;$pivots=[$new];continue;
            }
            if($setup['low_break']!==null)continue;
            if($setup['ineligible_reason']!==null){$status=$setup['ineligible_reason'];continue;}
            if($setup['entry_confirmed_at']===null&&$bar['available_at']>$setup['failure_confirmed_at']&&$close>$setup['trigger']){
                $setup['entry_confirmed_at']=$bar['available_at'];
                $past=array_values(array_filter($raw,fn($b)=>CandleClock::closeTime($b,$symbol)<=$bar['available_at']));
                $q=PaperQuality::inspect($past,$symbol,$bar['available_at'],['sha256'=>hash('sha256',PaperJournal::encode($past))]);
                $eligible=$q['can_simulate']&&$bar['high']<$setup['target']&&$close>$setup['stop'];
                $emit('breakout_confirmed',$bar['available_at'],['signal_eligible'=>$eligible,'quality_reasons'=>$q['reasons'],
                    'target_already_touched'=>$bar['high']>=$setup['target'],'entry_limit'=>$close]);
            }
            if($setup['entry_confirmed_at']!==null&&!$setup['target_seen']&&$bar['high']>=$setup['target']){
                $setup['target_seen']=true;$emit('target_zone',$bar['available_at']);
            }
            if($i-$setup['retry']['index']>120){$emit('expired',$bar['available_at']);$lastSetup=$setup;$setup=null;$pivots=[];}
        }
        $out['status']=$status;$out['setup']=$setup??$lastSetup;$out['events']=$events;$out['pivots']=$pivots;
        $lastEvent=$events?end($events):null;
        $out['entry_candidate']=$lastEvent&&$lastEvent['status']==='breakout_confirmed'&&($lastEvent['signal_eligible']??false)&&$lastEvent['confirmed_at']===$out['data_asof'];
        $quality=PaperQuality::inspect($raw,$symbol,$out['data_asof'],['sha256'=>hash('sha256',PaperJournal::encode($raw))]);
        if(!$quality['can_simulate']){$out['historical_status']=$out['status'];$out['status']='data_quality';$out['entry_candidate']=false;}
        return $out;
    }
    private function atr(array $bars,int $end):float {
        $sum=0;$n=0;for($i=max(1,$end-13);$i<=$end;$i++){$b=$bars[$i];$c=$bars[$i-1]['close'];$sum+=max($b['high']-$b['low'],abs($b['high']-$c),abs($b['low']-$c));$n++;}return $n?$sum/$n:0;
    }
}
