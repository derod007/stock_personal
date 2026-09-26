<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Display-only, chronological replay. Never participates in operational signals. */
final class TopWaveReview
{
    public const VERSION='top_wave_review_v2';
    public const RULES=['pivot_right_bars'=>2,'min_pivot_gap'=>3,'min_swing_pct'=>3.0,
        'min_swing_atr'=>1.0,'min_high_growth_pct'=>0.5,'min_rising_highs'=>3,
        'retest_min_ratio'=>0.97,'retest_max_ratio'=>1.02,'max_pattern_bars'=>120];

    public function analyze(array $raw,string $symbol,int $asOf):array
    {
        $bars=CandleClock::completed($raw,$symbol,$asOf);
        $out=['version'=>self::VERSION,'rules'=>self::RULES,'status'=>'none','reduce_candidate'=>false,
            'basis'=>'completed_daily_close','as_of'=>$asOf,'data_asof'=>$bars?end($bars)['available_at']:null,
            'freshness'=>['status'=>'unverified','calendar_verified'=>false],
            'setup'=>null,'events'=>[],'pivots'=>[],'price_hash'=>hash('sha256',PaperJournal::encode($raw))];
        if(count($bars)<60){$out['status']='insufficient_history';return $out;}
        $session=end($bars)['available_at'];
        $quality=PaperQuality::inspect($raw,$symbol,$session,['sha256'=>$out['price_hash']]);
        if(!$quality['can_simulate']){$out['status']='data_quality';$out['quality']=$quality;return $out;}
        $pivots=[];$setup=null;$events=[];$phase='none';$everBroken=false;$breakout=null;
        $emit=static function(string $status,int $at,array $detail=[])use(&$events,&$phase):void{
            if($phase!==$status){$events[]=['status'=>$status,'confirmed_at'=>$at]+$detail;$phase=$status;}
        };
        foreach($bars as $i=>$bar){
            $new=null;
            if($i>=4){
                $j=$i-2;$b=$bars[$j];$high=true;$low=true;
                foreach([-2,-1,1,2] as $off){$high=$high&&$b['high']>$bars[$j+$off]['high'];$low=$low&&$b['low']<$bars[$j+$off]['low'];}
                // Outside bars cannot tell us intrabar turning order.
                if($high xor $low){
                    $p=['kind'=>$high?'H':'L','index'=>$j,'price'=>(float)$b[$high?'high':'low'],
                        'pivot_at'=>$b['available_at'],'confirmed_at'=>$bar['available_at']];
                    $last=$pivots?end($pivots):null;
                    if(!$last){$pivots[]=$p;$new=$p;}
                    elseif($last['kind']===$p['kind']){
                        if(($high&&$p['price']>$last['price'])||($low&&$p['price']<$last['price'])){array_pop($pivots);$pivots[]=$p;$new=$p;}
                    }elseif($j-$last['index']>=self::RULES['min_pivot_gap'] && abs($p['price']-$last['price'])>=max($last['price']*0.03,$this->atr($bars,$j))){
                        $pivots[]=$p;$new=$p;
                    }
                }
            }
            if($i>=59 && $setup===null && $new && $new['kind']==='H' && count($pivots)>=7){
                $w=array_slice($pivots,-7);[$h1,$l1,$h2,$l2,$h3,$l3,$retry]=$w;
                if(array_column($w,'kind')===['H','L','H','L','H','L','H']
                    && $retry['index']-$h1['index']<=120
                    && $h2['price']>$h1['price']*1.005 && $h3['price']>$h2['price']*1.005
                    && $l2['price']>$l1['price']
                    && $retry['price']>=$h3['price']*0.97 && $retry['price']<=$h3['price']*1.02
                    && $bar['close']<$h3['price']){
                    $count=3;
                    for($k=count($pivots)-9;$k>=0;$k-=2){
                        if($pivots[$k]['kind']!=='H'||$pivots[$k+2]['price']<=$pivots[$k]['price']*1.005
                            ||$pivots[$k+3]['price']<=$pivots[$k+1]['price']||$retry['index']-$pivots[$k]['index']>120)break;
                        $count++;
                    }
                    $setup=['high_count'=>$count,'higher_high_breaks'=>$count-1,'H1'=>$h1,'L1'=>$l1,'H2'=>$h2,'L2'=>$l2,'H3'=>$h3,'L3'=>$l3,'retry'=>$retry,
                        'floor'=>$l2['price'],'peak'=>$h3['price'],'release_peak'=>max($h3['price'],$retry['price']),
                        'failure_confirmed_at'=>$bar['available_at']];
                    $priorBreach=null;
                    for($k=$l2['index']+1;$k<=$i;$k++){
                        if($bars[$k]['close']<$l2['price']){$priorBreach=$bars[$k]['available_at'];break;}
                    }
                    $setup['prior_breach_at']=$priorBreach;
                    $setup['ineligible_reason']=$l3['price']<$l2['price']?'invalid_structure':
                        ($priorBreach!==null?($priorBreach===$bar['available_at']?'same_day_breach':'preexisting_breach'):null);
                    $everBroken=false;$breakout=null;
                    $emit('retry_failed',$bar['available_at'],['floor'=>$setup['floor'],'high_count'=>$count]);
                }
            }
            if($setup===null)continue;
            // Once frozen, neither floor nor peak follows later lows/highs.
            $close=(float)$bar['close'];
            if($close>$setup['release_peak'] && $breakout===null){
                $lows=array_values(array_filter($pivots,fn($p)=>$p['kind']==='L'));
                $lastLow=$lows?end($lows):$setup['L3'];
                $breakout=['index'=>$i,'at'=>$bar['available_at'],'reference_low'=>$lastLow];
                // Current phase is emitted below after higher-priority breach checks.
            }
            if($breakout && $new && $new['kind']==='L' && $new['index']>$breakout['index']
                && $new['price']>$breakout['reference_low']['price'] && $close>=$setup['floor']){
                $setup['release']=['breakout'=>$breakout,'higher_low'=>$new,'confirmed_at'=>$bar['available_at']];
                $emit('released',$bar['available_at']);$out['setup']=$setup;$setup=null;$everBroken=false;$breakout=null;
                // Require fresh rising waves for a subsequent episode.
                $pivots=[$new];continue;
            }
            if($breakout && $close<$breakout['reference_low']['price'])$breakout=null;
            if($setup['ineligible_reason']!==null){$emit($setup['ineligible_reason'],$bar['available_at']);continue;}
            if($close<$setup['floor'] && $bar['available_at']>$setup['failure_confirmed_at']){$everBroken=true;$emit('reduce_candidate',$bar['available_at'],['close'=>$close,'floor'=>$setup['floor']]);}
            elseif($breakout)$emit('peak_reclaimed',$bar['available_at'],['reference_low'=>$breakout['reference_low']['price']]);
            elseif($everBroken)$emit('recovery_watch',$bar['available_at']);
            elseif($bar['low']<=$setup['floor'])$emit('support_test',$bar['available_at']);
            else $emit('retry_failed',$bar['available_at']);
        }
        $out['status']=$phase;$out['reduce_candidate']=$phase==='reduce_candidate';$out['setup']=$setup??$out['setup'];
        $out['events']=$events;$out['pivots']=$pivots;
        // This is the chart verdict at data_asof, not a claim of live freshness.
        $out['freshness']['age_seconds']=max(0,$asOf-$session);
        return $out;
    }
    private function atr(array $bars,int $end):float
    {
        $sum=0;$n=0;
        for($i=max(1,$end-13);$i<=$end;$i++){$b=$bars[$i];$c=$bars[$i-1]['close'];$sum+=max($b['high']-$b['low'],abs($b['high']-$c),abs($b['low']-$c));$n++;}
        return $n?$sum/$n:0.0;
    }
}
