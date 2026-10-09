<?php
declare(strict_types=1);
require_once __DIR__.'/PatternReplay.php';
use ChartEntryLab\CandleClock;

/**
 * Read-only comparison of already selected pullback trades. Definitions match docs/research/pullback-review.php.
 * Nothing here changes stops, targets, scores or the strategy fingerprint.
 */
final class PaperPullbackCompare
{
    /** @param list<array<string,mixed>> $events closed and non-closed selected pullback events */
    public static function diagnose(string $dir,array $events,string $profile,string $split):array
    {
        $by=[];foreach($events as $e)$by[$e['symbol']][]=$e;
        $rows=[];$statuses=[];
        foreach($by as $symbol=>$wanted){
            $bars=PaperHistoryResearch::readJson($dir.'/bars/'.$symbol.'.json');
            if(($bars['symbol']??null)!==$symbol)throw new RuntimeException('Bars identity mismatch '.$symbol);
            $rowsAll=$bars['rows'];$name=$bars['name']??$symbol;
            foreach($rowsAll as &$b)$b['available_at']=CandleClock::closeTime($b,$symbol);unset($b);
            usort($rowsAll,fn($a,$b)=>$a['available_at']<=>$b['available_at']);
            foreach($wanted as $e){
                $t=$e['outcome'];$status=(string)$t['status'];$statuses[$status]=($statuses[$status]??0)+1;
                if($status!=='closed')continue;
                $at=(int)$e['session'];
                $past=array_values(array_filter($rowsAll,fn($b)=>$b['available_at']<=$at));
                $d=PaperPatternReplay::day($past,$symbol,$at,$profile);
                if(($d['input_hash']??null)!==$e['input_hash']||empty($d['analysis']['plan']['ready']))
                    throw new RuntimeException('Signal mismatch '.$symbol.' '.$e['date']);
                if(isset($e['levels'])&&array_intersect_key($d['analysis']['plan']['diagnostics']['patterns']['trend_pullback'],$e['levels'])!=$e['levels'])
                    throw new RuntimeException('Signal levels mismatch '.$symbol.' '.$e['date']);
                $future=PaperPatternReplay::future($rowsAll,$symbol,$at,(int)$e['cutoff']);
                $again=PaperPatternReplay::trade($d['analysis']['plan'],$future);
                if($again!=$t)throw new RuntimeException('Outcome mismatch '.$symbol.' '.$e['date']);
                $completed=CandleClock::completed($past,$symbol,$at);$n=count($completed);$last=end($completed);$prev=$completed[$n-2];
                $a=$d['analysis'];$p=$a['plan'];$atr=$a['features']['atr14'];
                $ma=array_sum(array_column(array_slice($completed,-20),'close'))/20;
                $oldma=array_sum(array_column(array_slice($completed,-25,20),'close'))/20;
                $baseVol=array_sum(array_column(array_slice($completed,-24,20),'volume'))/20;
                $pullVol=array_sum(array_column(array_slice($completed,-4,3),'volume'))/3;
                $entry=null;$exit=null;$ei=null;
                foreach($future as $i=>$b){if(!$b)continue;if($b['available_at']===$t['entry_at']){$entry=$b;$ei=$i;}if($b['available_at']===$t['exit_at'])$exit=$b;}
                if(!$entry||!$exit)throw new RuntimeException('Fill/exit candle unavailable '.$symbol.' '.$e['date']);
                $between=array_values(array_filter($future,fn($b)=>$b&&$b['available_at']>$t['entry_at']&&$b['available_at']<$t['exit_at']));
                $priorGain=$between?max(0,(max(array_column($between,'high'))/$t['entry_fill']-1)*100):null;
                $gap=$t['first_exit']==='stop'&&$exit['available_at']>$entry['available_at']&&$exit['open']<$p['stop'];
                $rows[]=['id'=>$e['id'],'symbol'=>$symbol,'name'=>$name,'date'=>$e['date'],'session'=>$at,
                    'period'=>$e['date']<$split?'first_half':'second_half','net_return_pct'=>$t['net_return_pct'],'win'=>$t['net_return_pct']>0,
                    'entry_at'=>$t['entry_at'],'exit_at'=>$t['exit_at'],'bars_held'=>$t['bars'],'entry_delay_bars'=>$ei+1,'exit_reason'=>$t['first_exit'],
                    'entry'=>$p['entry'],'stop'=>$p['stop'],'target'=>$p['target'],'entry_fill'=>$t['entry_fill'],'exit_fill'=>$t['exit_fill'],
                    'signal_risk_pct'=>($p['entry']-$p['stop'])/$p['entry']*100,
                    'fill_risk_pct'=>($t['entry_fill']-$p['stop'])/$t['entry_fill']*100,'reward_risk'=>$p['reward_risk'],
                    'gap_stop'=>$gap,'gap_extra_pct'=>$gap?($p['stop']-$exit['open'])/$t['entry_fill']*100:0,
                    'early_stop'=>$t['first_exit']==='stop'&&$t['bars']<=3,
                    'prior_full_session_max_up_pct'=>$priorGain,'rose_3pct_then_stop'=>$t['first_exit']==='stop'&&$priorGain!==null&&$priorGain>=3,
                    'signal_atr_pct'=>$atr/$last['close']*100,'ma20_slope_5bar_pct'=>($ma/$oldma-1)*100,
                    'pullback_volume_ratio'=>$pullVol/$baseVol,'signal_volume_to_previous'=>$prev['volume']>0?$last['volume']/$prev['volume']:null,
                    'input_hash'=>$e['input_hash']];
            }
        }
        usort($rows,fn($a,$b)=>[$a['session'],$a['symbol']]<=>[$b['session'],$b['symbol']]);
        ksort($statuses);
        return ['rows'=>$rows,'statuses'=>$statuses];
    }

    /** @param list<array<string,mixed>> $rows */
    public static function median(array $values):?float
    {
        $values=array_values(array_filter($values,fn($v)=>$v!==null));
        $n=count($values);if($n===0)return null;sort($values,SORT_NUMERIC);
        $m=intdiv($n,2);
        return $n%2?$values[$m]:($values[$m-1]+$values[$m])/2;
    }

    /** @param list<array<string,mixed>> $rows closed trades */
    public static function performance(array $rows):array
    {
        $n=count($rows);$rets=array_column($rows,'net_return_pct');
        $wins=array_values(array_filter($rets,fn($r)=>$r>0));$losses=array_values(array_filter($rets,fn($r)=>$r<=0));
        $mean=fn(array $v)=>$v?array_sum($v)/count($v):null;
        $exits=[];foreach($rows as $r)$exits[$r['exit_reason']]=($exits[$r['exit_reason']]??0)+1;ksort($exits);
        return ['n'=>$n,'wins'=>count($wins),'losses'=>count($losses),
            'win_rate_pct'=>$n?count($wins)/$n*100:null,'mean_net_return_pct'=>$mean($rets),'median_net_return_pct'=>self::median($rets),
            'mean_win_pct'=>$mean($wins),'mean_loss_pct'=>$mean($losses),'exits'=>$exits,
            'denominator'=>'closed selected trades'];
    }

    /** Signal-stop buckets fixed at 10% and 15%. */
    public static function stopBuckets(array $rows):array
    {
        $bands=['le_10'=>[],'gt_10_le_15'=>[],'gt_15'=>[]];
        foreach($rows as $r){
            $w=$r['signal_risk_pct'];
            $bands[$w<=10?'le_10':($w<=15?'gt_10_le_15':'gt_15')][]=$r;
        }
        $out=[];foreach($bands as $k=>$g)$out[$k]=self::performance($g)+['median_signal_risk_pct'=>self::median(array_column($g,'signal_risk_pct'))];
        return $out;
    }

    /** R = net percent / initial stop distance from the fill. Not a position-sized account return. */
    public static function meanR(array $rows):?float
    {
        if(!$rows)return null;$s=0;
        foreach($rows as $r){if(!($r['fill_risk_pct']>0))throw new RuntimeException('Non positive fill risk');$s+=$r['net_return_pct']/$r['fill_risk_pct'];}
        return $s/count($rows);
    }

    public static function paths(array $rows):array
    {
        $stops=array_values(array_filter($rows,fn($r)=>$r['exit_reason']==='stop'));
        return ['stops'=>count($stops),'gap_stops'=>count(array_filter($stops,fn($r)=>$r['gap_stop'])),
            'early_stops_within_3_bars'=>count(array_filter($stops,fn($r)=>$r['early_stop'])),
            'rose_3pct_then_stop'=>count(array_filter($stops,fn($r)=>$r['rose_3pct_then_stop'])),
            'mean_gap_extra_pct_over_closed'=>self::performance($rows)['n']?array_sum(array_column($rows,'gap_extra_pct'))/count($rows):null,
            'note'=>'Rows can match more than one path. The 3% rise uses only completed sessions strictly between the fill and the exit.'];
    }

    /** Equal weight per overlapping-holding cluster of the same symbol. A sensitivity check, not an account return. */
    public static function overlap(array $rows):array
    {
        $by=[];foreach($rows as $i=>$r)$by[$r['symbol']][]=$i;
        $parent=range(0,count($rows)-1);
        $find=function(int $x) use (&$find,&$parent):int{return $parent[$x]===$x?$x:$parent[$x]=$find($parent[$x]);};
        foreach($by as $idx){
            $n=count($idx);
            for($a=0;$a<$n;$a++)for($b=$a+1;$b<$n;$b++){
                $x=$rows[$idx[$a]];$y=$rows[$idx[$b]];
                if($x['entry_at']<=$y['exit_at']&&$y['entry_at']<=$x['exit_at'])$parent[$find($idx[$a])]=$find($idx[$b]);
            }
        }
        $groups=[];foreach($parent as $i=>$p)$groups[$find($i)][]=$rows[$i]['net_return_pct'];
        $means=array_map(fn($g)=>array_sum($g)/count($g),$groups);
        return ['closed_trades'=>count($rows),'clusters'=>count($groups),'equal_weight_mean_pct'=>$means?array_sum($means)/count($means):null,
            'meaning'=>'sensitivity of equal weight per overlapping cluster; not a portfolio or account return'];
    }

    public static function pullbackReport(array $rows):array
    {
        $half=fn(string $p)=>array_values(array_filter($rows,fn($r)=>$r['period']===$p));
        $block=function(array $g):array{
            $p=self::performance($g);
            return $p+[
                'median_signal_risk_pct'=>self::median(array_column($g,'signal_risk_pct')),
                'median_signal_atr_pct'=>self::median(array_column($g,'signal_atr_pct')),
                'mean_r'=>self::meanR($g),'stop_buckets'=>self::stopBuckets($g),'paths'=>self::paths($g),'overlap'=>self::overlap($g)];
        };
        return ['all'=>$block($rows),'first_half'=>$block($half('first_half')),'second_half'=>$block($half('second_half'))];
    }
}
