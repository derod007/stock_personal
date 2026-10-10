<?php
declare(strict_types=1);
require_once __DIR__.'/PatternReplay.php';
use ChartEntryLab\{CandleClock,PriceCandidate};

/**
 * Research only. One alternative stop for the existing rising-pullback entry: the lowest low after the most recent
 * re-decline high (PR #64 H_recent rule) minus the same 0.2 ATR margin the engine uses. Nothing here is read by the
 * operating strategy. The alternative stop is fixed on the signal day and never moves.
 */
final class PaperRedeclineStop
{
    public const VERSION='recent_redecline_stop_v1';
    public const WINDOW=120;       // PR #64: window of completed bars for the big-correction high
    public const EDGE_BARS=5;      // PR #64: a window high inside the first 5 bars may have a higher bar outside the window -> hold
    public const MIN_AFTER=2;      // PR #64: fewer than 2 completed bars after a high -> hold
    public const PIVOT_SIDE=3;     // PR #64: strict pivot, 3 bars each side, confirmed at the 3rd right bar
    public const ATR_MARGIN=0.2;   // same as src/TrendPullback.php

    /** Reasons for 'not_applicable', in the order they are checked. */
    public const REASONS=[
        'data_insufficient'=>'신호일까지의 완료봉으로 창·고점을 만들 수 없음',
        'quality_problem'=>'창 안에 엔진이 버린 봉(무효 OHLC·거래량, 중복)이 있음',
        'h_big_none_manual_A_only'=>'창 최고가 신호일이라 큰 조정 고점이 없음(PR #64는 A 수동 표시를 인용했던 경우)',
        'h_big_hold_window_edge'=>'창 최고가 창의 첫 5봉 안이라 창 밖이 더 높을 수 있음(보류)',
        'h_big_hold_few_bars_after'=>'창 최고 뒤 완료봉이 2개 미만(보류)',
        'h_recent_hold_few_bars_after'=>'최근 재하락 고점 뒤 완료봉이 2개 미만(보류)',
        'alt_stop_invalid'=>'대안 손절이 0 이하이거나 계획 진입가 이상',
    ];

    /** @param list<array<string,mixed>> $bars completed bars through the signal day, ascending */
    public static function pivotHigh(array $bars,int $i):bool
    {
        $s=self::PIVOT_SIDE;$n=count($bars);
        if($i-$s<0||$i+$s>$n-1)return false;
        $v=(float)$bars[$i]['high'];
        for($k=$i-$s;$k<=$i+$s;$k++){
            if($k===$i)continue;
            if((float)$bars[$k]['high']>=$v)return false;
        }
        return true;
    }

    /** Index of the lowest low after bar $idx through the last bar, earliest on ties. */
    public static function lowAfter(array $bars,int $idx):?int
    {
        $best=null;
        for($i=$idx+1;$i<count($bars);$i++)if($best===null||(float)$bars[$i]['low']<(float)$bars[$best]['low'])$best=$i;
        return $best;
    }

    /**
     * @param list<array<string,mixed>> $bars completed bars up to and including the signal day. No later bar may be passed.
     * @param int $droppedRows raw rows inside the window time span that the engine's completed-bar filter dropped (0 = clean)
     */
    public static function evaluate(array $bars,float $atr,float $planEntry,int $droppedRows=0):array
    {
        $n=count($bars);
        $out=['version'=>self::VERSION,'status'=>'not_applicable','reason'=>null,'bars_used'=>$n,'window'=>null,'flags'=>[],
            'H_big'=>null,'L_big'=>null,'H_recent'=>null,'rule_found_re_decline'=>null,'low_after_recent'=>null,
            'atr14'=>$atr,'atr_margin'=>self::ATR_MARGIN,'alt_stop'=>null];
        if($n<self::MIN_AFTER+1||!($atr>0)||!is_finite($atr)){$out['reason']='data_insufficient';return $out;}
        $first=max(0,$n-self::WINDOW);
        $out['window']=['first_index'=>$first,'first_date'=>self::day($bars[$first]),'bars'=>$n-$first,'truncated_by_data_start'=>$first===0&&$n<self::WINDOW];
        if($droppedRows>0){$out['reason']='quality_problem';$out['flags']['dropped_rows_in_window']=$droppedRows;return $out;}
        $gi=$first;
        for($i=$first;$i<$n;$i++)if((float)$bars[$i]['high']>(float)$bars[$gi]['high'])$gi=$i;
        $isD=$gi===$n-1;
        $edge=$first>0&&$gi-$first<self::EDGE_BARS;
        $few=!$isD&&$n-1-$gi<self::MIN_AFTER;
        $out['flags']=['window_high_is_signal_day'=>$isD,'window_edge'=>$edge,'fewer_than_2_bars_after_H_big'=>$few];
        $out['H_big']=self::point($bars,$gi,'high');
        if($isD){$out['reason']='h_big_none_manual_A_only';return $out;}
        if($edge){$out['reason']='h_big_hold_window_edge';return $out;}
        if($few){$out['reason']='h_big_hold_few_bars_after';return $out;}
        $lbi=self::lowAfter($bars,$gi);
        $out['L_big']=self::point($bars,$lbi,'low');
        $rec=null;
        for($i=$lbi+1;$i<$n;$i++){
            if((float)$bars[$i]['high']>=(float)$bars[$gi]['high'])continue;
            if(self::pivotHigh($bars,$i))$rec=$i;
        }
        $ri=$rec??$gi;
        $out['rule_found_re_decline']=$rec!==null;
        $out['H_recent']=self::point($bars,$ri,'high');
        $out['H_recent']['same_as_H_big']=$ri===$gi;
        if($n-1-$ri<self::MIN_AFTER){$out['reason']='h_recent_hold_few_bars_after';return $out;}
        $li=self::lowAfter($bars,$ri);
        $out['low_after_recent']=self::point($bars,$li,'low');
        $stop=PriceCandidate::trunc((float)$bars[$li]['low']-self::ATR_MARGIN*$atr);
        $out['alt_stop']=$stop;
        if(!is_finite($stop)||$stop<=0||$stop>=$planEntry){$out['reason']='alt_stop_invalid';return $out;}
        $out['status']='applicable';
        return $out;
    }

    private static function day(array $b):string{return PaperHistoryResearch::day((int)$b['available_at']);}

    private static function point(array $bars,int $i,string $field):array
    {
        return ['index'=>$i,'date'=>self::day($bars[$i]),'price'=>(float)$bars[$i][$field],'available_at'=>(int)$bars[$i]['available_at'],
            'bars_after'=>count($bars)-1-$i];
    }

    /**
     * Everything the signal-day decision may use: rows closed at or before the session. Later rows are dropped here, so a caller
     * that passes the whole file cannot leak the future.
     * @param list<array<string,mixed>> $rows raw dataset rows
     */
    public static function forSignal(array $rows,string $symbol,int $session,float $atr,float $planEntry):array
    {
        $past=[];
        foreach($rows as $b)if(CandleClock::closeTime($b,$symbol)<=$session)$past[]=$b;
        $bars=CandleClock::completed($past,$symbol,$session);
        $n=count($bars);$dropped=0;
        if($n>0){
            $first=$bars[max(0,$n-self::WINDOW)]['available_at'];$seen=[];
            foreach($past as $b){
                $t=CandleClock::closeTime($b,$symbol);
                if($t<$first||!empty($b['synthetic'])||($b['is_complete']??true)===false)continue;
                $seen[$t]=($seen[$t]??0)+1;
            }
            $kept=array_flip(array_column(array_slice($bars,max(0,$n-self::WINDOW)),'available_at'));
            foreach($seen as $t=>$count)if(!isset($kept[$t])||$count>1)$dropped+=$count-(isset($kept[$t])?1:0);
        }
        return self::evaluate($bars,$atr,$planEntry,$dropped);
    }
}

/**
 * Same order, fill, gap and cost rules as src/TradeSimulator.php, except that the stop after the fill may differ from the
 * plan stop. The plan stop still governs the order before the fill (existing cancel rule). With postFillStop === plan stop the
 * result must equal TradeSimulator exactly; tests and the baseline reproduction check that on every case.
 */
final class PaperRedeclineSimulator
{
    public function __construct(private readonly float $feeBps=10.0,private readonly float $slippageBps=5.0){}

    public function simulate(array $plan,array $bars,int $holdingBars,?float $postFillStop=null):array
    {
        if($holdingBars<1)throw new InvalidArgumentException('Holding bars must be positive');
        if(($plan['exit_mode']??'fixed')==='trailing')throw new InvalidArgumentException('Trailing exits are not supported by this study');
        $out=['status'=>'no_signal','complete'=>false,'bars'=>0,'filled'=>false,'entry_fill'=>null,'entry_at'=>null,'exit_fill'=>null,'exit_at'=>null,
            'first_exit'=>null,'net_return_pct'=>null,'ret_close_pct'=>null,'hit_stop'=>false,'hit_target'=>false,'ambiguous_bar'=>false,
            'fee_bps_per_side'=>$this->feeBps,'slippage_bps'=>$this->slippageBps];
        if(empty($plan['ready']))return $out;
        $entry=(float)($plan['entry']??0);$stop=(float)($plan['stop']??0);$target=(float)($plan['target']??0);
        if(!($stop>0&&$stop<$entry&&$entry<$target))return array_replace($out,['status'=>'invalid_levels']);
        $after=$postFillStop??$stop;
        if(!($after>0&&$after<$entry))return array_replace($out,['status'=>'invalid_levels']);
        $bars=array_values(array_filter($bars,static fn(array $b):bool=>($b['available_at']??0)>($plan['signal_at']??PHP_INT_MAX)));
        usort($bars,static fn(array $a,array $b):int=>$a['available_at']<=>$b['available_at']);
        $ttl=(int)($plan['order_valid_bars']??3);$filledAt=null;$fill=null;
        foreach($bars as $i=>$bar){
            if($filledAt===null){
                if($i>=$ttl)return array_replace($out,['status'=>'unfilled','complete'=>true]);
                // Before the fill the plan stop is used, exactly as in the existing engine.
                if($bar['open']<=$stop||$bar['open']>=$target||$bar['high']>=$target)
                    return array_replace($out,['status'=>'cancelled_before_entry','complete'=>true,'ambiguous_bar'=>$bar['high']>=$target&&$bar['low']<=$entry]);
                if($bar['low']>$entry)continue;
                $filledAt=$i;
                $fill=min($entry,(float)$bar['open']*(1+$this->slippageBps/10000));
                $out['filled']=true;$out['entry_fill']=$fill;$out['entry_at']=$bar['available_at'];
                // An alternative stop at or above the real fill price is not applied or repaired.
                if($after>=$fill)return array_replace($out,['status'=>'alt_stop_not_below_fill','complete'=>true,'bars'=>1]);
                $stop=$after;
            }
            $out['bars']=$i-$filledAt+1;
            $stopHit=$bar['low']<=$stop;$targetHit=$bar['high']>=$target;$exit=null;$why=null;
            if($i>$filledAt&&$bar['open']>=$target){$why='target';$exit=$target;}
            elseif($stopHit){$why='stop';$exit=min($stop,(float)$bar['open'])*(1-$this->slippageBps/10000);$out['ambiguous_bar']=$targetHit;}
            elseif($targetHit){$why='target';$exit=$target;}
            elseif($out['bars']>=$holdingBars){$why='time';$exit=(float)$bar['close']*(1-$this->slippageBps/10000);}
            if($exit!==null){
                $net=(($exit*(1-$this->feeBps/10000))/($fill*(1+$this->feeBps/10000))-1)*100;
                return array_replace($out,['status'=>'closed','complete'=>true,'first_exit'=>$why,'exit_fill'=>round($exit,6),'exit_at'=>$bar['available_at'],
                    'hit_stop'=>$why==='stop','hit_target'=>$why==='target','net_return_pct'=>round($net,4),'ret_close_pct'=>round($net,4)]);
            }
        }
        return array_replace($out,['status'=>$filledAt!==null?'incomplete':(count($bars)>=$ttl?'unfilled':'pending'),
            'complete'=>$filledAt===null&&count($bars)>=$ttl]);
    }

    /** Same wrapper as PaperPatternReplay::trade (a missing future bar stops the run, never compresses time). */
    public function trade(array $plan,array $future,?float $postFillStop=null):array
    {
        $valid=[];$bad=false;
        foreach($future as $bar){if($bar===null){$bad=true;break;}$valid[]=$bar;}
        $out=$this->simulate($plan,$valid,20,$postFillStop);
        if($bad&&!$out['complete'])$out['status']='future_quality_blocked';
        $out['basis']='independent_trade_not_portfolio';
        return $out;
    }
}
