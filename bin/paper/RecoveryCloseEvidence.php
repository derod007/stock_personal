<?php
declare(strict_types=1);
require_once __DIR__.'/OpportunityResearch.php';

/** Frozen, outcome-selected source audit. No account, cache, network or strategy writes. */
final class PaperRecoveryCloseEvidence
{
    public const REPORT_SHA256='5731b946ab3683bb05a0402211016da84f7bd3190d42f7bedcce76e08eb7c9ea';

    public static function select(array $report):array
    {
        PaperOpportunityResearch::demand(($report['full_period_complete']??false)===true
            &&($report['sessions']??0)===244&&($report['baseline_signals_verified']??0)===103,'Incomplete report');
        $rows=array_values(array_filter($report['near_misses'],static fn($r)=>$r['pattern']==='trend_pullback_v1'
            &&$r['missing_gate']==='recovery_close'&&$r['potential_new']&&($r['shadow']['status']??null)==='closed'));
        PaperOpportunityResearch::demand(count($rows)===69,'Expected 69 closed recovery_close observations');
        usort($rows,static fn($a,$b)=>[-$a['shadow']['net_return_pct'],$a['date'],$a['symbol']]<=>[-$b['shadow']['net_return_pct'],$b['date'],$b['symbol']]);
        return array_slice($rows,0,5);
    }

    /** Relative input paths must resolve to a regular file inside the immutable dataset. */
    public static function inputPath(string $dir,string $relative):string
    {
        $root=realpath($dir);$p=realpath($dir.'/'.$relative);
        PaperOpportunityResearch::demand($root!==false&&$p!==false&&is_file($p)
            &&str_starts_with(str_replace('\\','/',$p),rtrim(str_replace('\\','/',$root),'/').'/'),'Evidence path escapes dataset or is missing');
        return $p;
    }

    /** A separate implementation of fixed long-limit chronology, without calling TradeSimulator. */
    public static function path(array $plan,array $future):array
    {
        $fill=null;$entryAt=null;$held=0;$seen=0;$last=$plan['signal_at'];
        foreach($future as $b){
            if($fill===null&&$seen>=3)return ['status'=>'unfilled'];
            if($b===null)return ['status'=>'future_quality_blocked'];
            PaperOpportunityResearch::demand($b['available_at']>$last,'Future chronology is not strictly increasing');$last=$b['available_at'];
            $seen++;$justFilled=false;
            if($fill===null){
                if($seen>3)return ['status'=>'unfilled'];
                if($b['open']<=$plan['stop']||$b['high']>=$plan['target'])return ['status'=>'cancelled_before_entry'];
                if($b['low']>$plan['entry'])continue;
                $fill=min($plan['entry'],$b['open']*1.0005);$entryAt=$b['available_at'];$justFilled=true;
            }
            $held++;$why=null;$exit=null;
            if(!$justFilled&&$b['open']>=$plan['target']){$why='target';$exit=$plan['target'];}
            elseif($b['low']<=$plan['stop']){$why='stop';$exit=min($plan['stop'],$b['open'])*.9995;}
            elseif($b['high']>=$plan['target']){$why='target';$exit=$plan['target'];}
            elseif($held===20){$why='time';$exit=$b['close']*.9995;}
            if($why!==null)return ['status'=>'closed','entry_at'=>$entryAt,'entry_fill'=>$fill,'exit_at'=>$b['available_at'],
                'exit_fill'=>round($exit,6),'first_exit'=>$why,'bars'=>$held,'net_return_pct'=>round(($exit*.999/($fill*1.001)-1)*100,4)];
        }
        return ['status'=>$fill!==null?'incomplete':($seen>=3?'unfilled':'pending')];
    }

    public static function compare(array $actual,array $expected,array $fields):array
    {
        $diffs=[];
        foreach($fields as $k){$a=$actual[$k]??null;$b=$expected[$k]??null;
            $equal=is_numeric($a)&&is_numeric($b)?abs((float)$a-(float)$b)<1e-6:$a===$b;
            if(!$equal)$diffs[$k]=['actual'=>$a,'expected'=>$b];
        }
        return $diffs;
    }

    public static function marketChecks(array $row,array $past,array $future):array
    {
        $s=$row['shadow'];$range=[];$bad=[];$jumps=[];$repairs=[];$by=[];$last=null;
        foreach(array_merge($past,array_values(array_filter($future,static fn($b)=>$b!==null))) as $b){
            $t=$b['available_at'];$by[$t]=$b;
            if($b['low']>min($b['open'],$b['close'])||$b['high']<max($b['open'],$b['close'])||$b['low']>$b['high'])$bad[]=$t;
            // Relative machine-precision tolerance: exactly +/-30% is not a jump beyond 30%.
            if($last!==null&&$last['close']>0&&abs($b['close']-$last['close'])>.30*$last['close']+1e-12*max(1.0,abs($last['close'])))$jumps[]=['at'=>$t,'previous_close'=>$last['close'],'close'=>$b['close']];
            if(isset($b['historical_close_repair'])||($b['close_source']??'yahoo_daily')!=='yahoo_daily')$repairs[]=$b;
            $last=$b;
        }
        foreach(['entry','exit'] as $k){$b=$by[$s[$k.'_at']]??null;$price=$s[$k.'_fill'];
            $range[$k]=['price'=>$price,'bar'=>$b,'within_daily_range'=>$b!==null&&$price>=$b['low']&&$price<=$b['high']];
        }
        return ['fill_ranges'=>$range,'invalid_ohlc_at'=>$bad,'close_jumps_over_30pct'=>$jumps,'repaired_rows'=>$repairs,
            'range_result'=>($range['entry']['within_daily_range']&&$range['exit']['within_daily_range'])?'within_range':'assumption_dependent',
            'adjustment_basis'=>'unconfirmed; no jumps is not proof of consistent adjustment',
            'intraday_order'=>'daily OHLC cannot prove tick order or actual execution'];
    }

    /** Hash-verified provider response excerpts, not a new fetch or a price correction. */
    public static function providerExcerpt(string $dir,array $request,array $rows):array
    {
        $p=self::inputPath($dir,$request['raw_path']);$bytes=file_get_contents($p);
        PaperOpportunityResearch::demand(hash('sha256',$bytes)===$request['raw_sha256'],'Provider response hash mismatch');
        $j=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);$r=$j['chart']['result'][0]??null;
        PaperOpportunityResearch::demand(is_array($r),'Missing Yahoo result');
        $wanted=[];foreach($rows as $b)$wanted[PaperHistoryResearch::day($b['available_at'])]=$b;
        $quotes=[];$diffs=[];$seen=[];$q=$r['indicators']['quote'][0]??[];
        foreach($r['timestamp']??[] as $i=>$ts){$day=PaperHistoryResearch::day($ts);if(!isset($wanted[$day]))continue;
            $raw=['timestamp'=>$ts,'date'=>$day,'adjclose'=>$r['indicators']['adjclose'][0]['adjclose'][$i]??null];
            foreach(['open','high','low','close','volume'] as $k){$raw[$k]=$q[$k][$i]??null;$stored=$wanted[$day][$k]??null;
                if(!is_numeric($raw[$k])||!is_numeric($stored)||abs($raw[$k]-$stored)>max(1e-6,abs((float)$stored)*1e-8))$diffs[]=['date'=>$day,'field'=>$k,'provider'=>$raw[$k],'stored'=>$stored];
            }
            $quotes[]=$raw;$seen[$day]=($seen[$day]??0)+1;
        }
        $events=[];$days=array_keys($wanted);sort($days);
        foreach($r['events']??[] as $kind=>$entries)foreach($entries as $e){$day=PaperHistoryResearch::day((int)($e['date']??0));
            if($days&&$day>=$days[0]&&$day<=end($days))$events[]=['kind'=>$kind,'event'=>$e];
        }
        return ['raw_path'=>$request['raw_path'],'raw_sha256'=>$request['raw_sha256'],'fetched_at'=>$request['fetched_at']??null,
            'provider_symbol'=>$r['meta']['symbol']??null,'currency'=>$r['meta']['currency']??null,'exchange'=>$r['meta']['exchangeName']??null,
            'rows'=>$quotes,'events'=>$events,'stored_provider_differences'=>$diffs,'missing_dates'=>array_values(array_diff(array_keys($wanted),array_keys($seen))),
            'duplicate_dates'=>array_keys(array_filter($seen,static fn($n)=>$n>1)),
            'note'=>'Differences may be existing repairs; do not overwrite or infer adjustment validity. Events are provider evidence, not independently verified corporate actions.'];
    }

    public static function auditCase(array $row,array $info,array $sessions,string $dataset):array
    {
        $date=$row['session'];$symbol=$row['symbol'];
        $past=array_values(array_filter($info['rows'],static fn($b)=>$b['available_at']<=$date));
        $future=PaperOpportunityResearch::future($info['rows'],$symbol,$date,$sessions);
        $check=PaperOpportunityResearch::inspect($info,$symbol,$date,$dataset);$probe=$check['probe'];$a=$check['analysis'];
        $problems=[];
        if($a===null||!PaperOpportunityResearch::single($probe)||$probe['failed']!==['recovery_close'])$problems['single_gate']='Not exactly recovery_close';
        else{
            $problems+=self::compare($probe['plan'],$row['plan'],['entry','stop','target','reward_risk','signal_at','order_valid_bars']);
            if($probe['gates']!==$row['gates'])$problems['gates']='Stored and recomputed gates differ';
            if(PaperPatternReplay::blockers($a,$probe['plan'])!==[]||$a['plan']['ready'])$problems['eligibility']='Common block or existing final recommendation';
        }
        $sameEngine=PaperOpportunityResearch::shadow($row['plan'],$future);
        $fields=['status','complete','entry_at','entry_fill','exit_at','exit_fill','first_exit','net_return_pct','bars','ambiguous_bar'];
        $simDiff=self::compare($sameEngine,$row['shadow'],$fields);
        $independent=self::path($row['plan'],$future);
        $pathDiff=self::compare($independent,$row['shadow'],['status','entry_at','entry_fill','exit_at','exit_fill','first_exit','bars','net_return_pct']);
        // Preserve gaps and only include the observed path to the stored exit. No later prices needed.
        $end=$row['shadow']['exit_at'];$needed=array_values(array_filter($sessions,static fn($t)=>$t>$date&&$t<=$end));
        $futureNeeded=array_slice($future,0,count($needed));
        return ['case'=>$row,'as_of_rows'=>$past,'future_session_times'=>$needed,'future_rows'=>$futureNeeded,
            'signal_differences'=>$problems,'same_simulator_differences'=>$simDiff,'independent_path'=>$independent,'independent_path_differences'=>$pathDiff,
            'model_match'=>$problems===[]&&$simDiff===[]&&$pathDiff===[],
            'market_checks'=>self::marketChecks($row,$past,$futureNeeded),
            'verification_basis'=>['independent_strategy_implementation'=>false,'independent_fill_path_implementation'=>true,'same_daily_data_and_rule_assumptions'=>true]];
    }
}
