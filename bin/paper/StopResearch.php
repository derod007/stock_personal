<?php
declare(strict_types=1);
require_once __DIR__.'/ConditionResearch.php';
use ChartEntryLab\CandleClock;
use ChartEntryLab\PriceCandidate;

/** Fixed v1 hypothesis, defined without future bars or outcome-based parameter selection. */
final class PaperStopResearch
{
    public const VERSION='confirmed_peak_pullback_stop_v1';

    public static function segment(array $bars,float $atr):array
    {
        $n=count($bars);
        if($n<10||!is_finite($atr)||$atr<=0)return ['status'=>'insufficient_segment_input'];
        // Most recent strict 2-left/2-right local high within the last 10 completed bars.
        // Both right bars must exist at decision time. No future pivot confirmation.
        for($i=$n-3;$i>=max(2,$n-10);$i--){
            $peak=$bars[$i]['high'];$pivot=true;
            foreach([-2,-1,1,2] as $offset)if($peak<=$bars[$i+$offset]['high']){$pivot=false;break;}
            if(!$pivot)continue;
            $slice=array_slice($bars,$i+1);$low=min(array_column($slice,'low'));
            $decline=false;
            for($j=$i+1;$j<$n-1;$j++)if($bars[$j]['close']<$bars[$j-1]['close'])$decline=true;
            $out=['status'=>'defined','peak_session'=>$bars[$i]['available_at'],'peak'=>$peak,
                'start_session'=>$bars[$i+1]['available_at'],'end_session'=>$bars[$n-1]['available_at'],
                'bars'=>count($slice),'low'=>$low,'low_sessions'=>array_values(array_column(array_filter($slice,fn($b)=>$b['low']===$low),'available_at')),
                'drawdown_atr'=>($peak-$low)/$atr];
            // Do not fall back to older pivots when the latest confirmed one fails.
            if(!$decline||$peak-$low<0.5*$atr)$out['status']='insufficient_pullback';
            return $out;
        }
        return ['status'=>'no_confirmed_peak'];
    }

    public static function reprice(array $base,float $stop,array $context):array
    {
        $p=$base;$p['stop']=$stop;$p['ready']=false;
        $e=$p['entry'];$t=$p['target'];$rr=$e>$stop?($t-$e)/($e-$stop):null;$p['reward_risk']=$rr;
        $p['status']=!($stop>0&&$stop<$e&&$e<$t)?'invalid_levels':($rr<1.5?'rejected_rr':'eligible');
        if(($context['context_applied']??null)===true){
            $d=$context['context']['daily']??null;$w=$context['context']['weekly']??null;
            if(!is_string($d)||!is_string($w))$p['status']='context_unavailable';
            elseif($d==='down'||($w==='down'&&($d!=='up'||$rr===null||$rr<2)))$p['status']='context_wait';
        }elseif(($context['context_applied']??null)!==false)$p['status']='context_unavailable';
        $p['ready']=$p['status']==='eligible';return $p;
    }

    public static function evaluate(array $r,array $prices,int $asOf,string $cohort,?string $gate,string $fingerprint):array
    {
        $row=['symbol'=>$r['symbol'],'name'=>$r['name'],'session'=>$r['session'],'source_file'=>$r['source_file'],
            'observation_hash'=>$r['observation_hash'],'input_hash'=>$r['input_hash'],'cohort'=>$cohort,'removed_gate'=>$gate,
            'status'=>'not_evaluated','as_of'=>$asOf];
        $probe=PaperFollowup::evaluate($r,$prices,$asOf);
        if(!in_array($probe['status'],['pending','complete','no_future_bars'],true)){$row['status']=$probe['status'];return $row;}
        $replay=PaperRediagnosis::evaluate($r,null,$asOf,$fingerprint);
        if($replay['replay']['status']!=='same_compared_fields'){$row['status']='baseline_replay_mismatch';return $row;}
        $selected=array_values(array_filter($r['patterns'],fn($p)=>!in_array('not_selected_pattern',$p['exclusion_reasons']??[],true)));
        if(count($selected)!==1||$selected[0]['pattern']!=='trend_pullback'){$row['status']='not_selected_pullback';return $row;}
        $selected=$selected[0];$a=PaperSingleConditionReview::assess($r,$selected);
        if($cohort==='confirmed'){
            if(!in_array($selected['raw_status'],['ready','rejected_rr'],true)){$row['status']='not_confirmed';return $row;}
            $blocked=array_values(array_diff($a['failed'],['rr','context_wait']));
            if($blocked||$a['unknown']){$row['status']='independent_blockers';$row['blockers']=$blocked;$row['unknown']=$a['unknown'];return $row;}
        }elseif($cohort==='single_gate_hypothesis'){
            if(!in_array($gate,PaperConditionResearch::GATES,true)||$a['failed']!==[$gate]||$a['unknown']!==['rr']){
                $row['status']='not_single_gate';return $row;
            }
        }else throw new InvalidArgumentException('Invalid cohort');
        $analysis=$replay['replay']['analysis'];$plan=$analysis['plan'];
        $bars=CandleClock::completed($r['bars'],$r['symbol'],(int)$r['session']);$atr=(float)$analysis['features']['atr14'];
        $base=PaperConditionResearch::hypothesis($bars,$atr,$plan,$gate??'recovery_close');
        unset($base['removed_gate'],$base['assumption']);
        // Confirmed cohort must retain the exact original entry/stop/target.
        if($cohort==='confirmed')foreach(['entry','stop','target'] as $k)if(($selected['original'][$k]??null)!=$base[$k]){
            $row['status']='original_levels_mismatch';return $row;
        }
        $segment=self::segment($bars,$atr);$row['segment']=$segment;
        $row['baseline']=['plan'=>$base,'outcome'=>null];$row['recent_pullback']=null;
        $row['baseline_low']=min(array_column(array_slice($bars,-10),'low'));$row['atr_buffer']=0.2*$atr;
        if($segment['status']!=='defined'){$row['status']=$segment['status'];return $row;}
        $stop=PriceCandidate::trunc($segment['low']-0.2*$atr);
        $alt=self::reprice($base,$stop,$plan);
        $row['recent_pullback']=['plan'=>$alt,'outcome'=>null];$row['status']='compared';
        $row['added_eligible']=!$base['ready']&&$alt['ready'];
        foreach(['baseline','recent_pullback'] as $arm){$p=$row[$arm]['plan'];
            $row[$arm]['stop_distance_pct']=($p['entry']-$p['stop'])/$p['entry']*100;
            if(!$p['ready'])continue;
            $t=PaperRrAudit::outcome($r,$p,$prices,$asOf);unset($t['reconciliation']);$row[$arm]['outcome']=$t;
        }
        $b=$row['baseline']['outcome'];$t=$row['recent_pullback']['outcome'];
        $row['paired_closed']=$b&&$t&&$b['status']==='closed'&&$t['status']==='closed';
        $row['paired_net_difference_pct']=$row['paired_closed']?$t['net_return_pct']-$b['net_return_pct']:null;
        // A closer stop may cancel or change the fill, so compare exit timing only for identical entries.
        $sameEntry=$b&&$t&&!empty($b['filled'])&&!empty($t['filled'])&&$b['entry_at']===$t['entry_at']&&$b['entry_fill']===$t['entry_fill'];
        $row['earlier_stop_same_entry']=$sameEntry&&!empty($t['hit_stop'])&&($b['exit_at']===null||$t['exit_at']<$b['exit_at']);
        return $row;
    }

    public static function summarize(array $rows):array
    {
        $out=[];
        foreach($rows as $r){$c=$r['cohort'];$out[$c]??=['records'=>0,'statuses'=>[],'added_eligible'=>0,'earlier_stop_same_entry'=>0,
            'paired_closed'=>0,'paired_mean_difference_pct'=>null,'arms'=>[]];$s=&$out[$c];$s['records']++;
            $s['statuses'][$r['status']]=($s['statuses'][$r['status']]??0)+1;
            if($r['status']!=='compared'){unset($s);continue;}
            $s['added_eligible']+=(int)$r['added_eligible'];$s['earlier_stop_same_entry']+=(int)$r['earlier_stop_same_entry'];
            if($r['paired_closed']){$n=++$s['paired_closed'];$s['paired_mean_difference_pct']=(($s['paired_mean_difference_pct']??0)*($n-1)+$r['paired_net_difference_pct'])/$n;}
            foreach(['baseline','recent_pullback'] as $arm){$s['arms'][$arm]??=['eligible'=>0,'filled'=>0,'closed'=>0,'stops'=>0,'mean_net_pct'=>null,'statuses'=>[]];
                $a=&$s['arms'][$arm];$v=$r[$arm];$a['eligible']+=(int)$v['plan']['ready'];$t=$v['outcome'];
                $status=$t['status']??$v['plan']['status'];$a['statuses'][$status]=($a['statuses'][$status]??0)+1;
                $a['filled']+=(int)!empty($t['filled']);$a['stops']+=(int)!empty($t['hit_stop']);
                if(($t['status']??'')==='closed'){$n=++$a['closed'];$a['mean_net_pct']=(($a['mean_net_pct']??0)*($n-1)+$t['net_return_pct'])/$n;}
                unset($a);
            }unset($s);
        }
        return $out;
    }

    public static function run(string $source,string $id,int $days=5):array
    {
        // Reuse the all-input/all-evidence hash validation, including the original 13-case cohort.
        $validation=PaperConditionResearch::run($source,$id,$days);
        $hyp=[];foreach($validation['rows'] as $r)$hyp[$r['observation_hash']]=$r['gate'];
        $f=PaperFollowup::load($source,$id);$tracked=[];foreach($f['rows'] as $r)$tracked[$r['observation_hash']]=$r;
        $out=['schema'=>1,'kind'=>self::VERSION,'account'=>$id,'generated_at'=>time(),'as_of'=>$f['as_of'],
            'dates'=>$validation['dates'],'input_validation'=>$validation['input_validation'],
            'strategy_fingerprint'=>$validation['strategy_fingerprint'],'historical_code_verified'=>false,
            'rule'=>['lookback'=>10,'pivot_left'=>2,'pivot_right'=>2,'min_drawdown_atr'=>0.5,'buffer_atr'=>0.2],
            'rows'=>[],'errors'=>$validation['errors']];$seen=[];$prices=[];
        $files=PaperRrView::files($source.'/rr-audit/'.$id);sort($files,SORT_STRING);
        foreach($files as $file){$b=PaperRrView::load($source.'/rr-audit/'.$id.'/'.$file);
            if(($b['membership']??'')!=='observed_scan_only')continue;
            $date=(new DateTimeImmutable('@'.$b['recorded_at']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
            if(!in_array($date,$out['dates'],true))continue;
            foreach($b['records'] as $raw){
                if(($raw['status']??'')!=='evaluated')continue;
                $r=PaperRediagnosis::identity($raw,$b,$file);$key=$r['symbol'].'@'.$r['session'];
                if(isset($seen[$key]))continue;$seen[$key]=true;
                $selected=array_values(array_filter($r['patterns'],fn($p)=>!in_array('not_selected_pattern',$p['exclusion_reasons']??[],true)));
                if(count($selected)!==1||$selected[0]['pattern']!=='trend_pullback')continue;
                $gate=$hyp[$r['observation_hash']]??null;
                $cohort=in_array($selected[0]['raw_status'],['ready','rejected_rr'],true)?'confirmed':($gate!==null?'single_gate_hypothesis':null);
                if($cohort===null)continue;
                $track=$tracked[$r['observation_hash']]??null;
                if(!$track||$track['source_file']!==$file||$track['symbol']!==$r['symbol']||$track['session']!==$r['session']){
                    $out['errors'][]=['file'=>$file,'symbol'=>$r['symbol'],'error'=>'exact_followup_unavailable'];continue;}
                $hash=$track['price_hash'];
                if(!isset($prices[$hash])){
                    $pr=json_decode(file_get_contents($source.'/followup/'.$id.'/evidence/'.$hash.'.json'),true,512,JSON_THROW_ON_ERROR);
                    if(!hash_equals($hash,hash('sha256',PaperRrAudit::encode($pr))))throw new RuntimeException('Price hash changed');$prices[$hash]=$pr;
                }
                $row=self::evaluate($r,$prices[$hash],min($track['as_of'],$f['as_of']),$cohort,$gate,$out['strategy_fingerprint']);
                $row['date']=$date;$row['price_hash']=$hash;$out['rows'][]=$row;
            }
        }
        $out['summary']=self::summarize($out['rows']);return $out;
    }
}
