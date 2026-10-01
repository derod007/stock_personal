<?php
declare(strict_types=1);
require_once __DIR__.'/StopResearch.php';
use ChartEntryLab\CandleClock;
use ChartEntryLab\PriceCandidate;

/** Descriptive evidence audit, not a replacement target or entry strategy. */
final class PaperTargetResearch
{
    public const VERSION='target_basis_audit_v1';

    public static function inspect(array $input,string $symbol,int $session,float $entry,float $stop,float $target):array
    {
        $bars=CandleClock::completed($input,$symbol,$session);$n=count($bars);
        if($n<21||$bars[$n-1]['available_at']!==$session)return ['status'=>'insufficient_original_bars'];
        $window=array_slice($bars,-21,20);$high=max(array_column($window,'high'));
        $expected=PriceCandidate::trunc((float)$high);$last=$bars[$n-1];
        $relation=$last['close']>$high?'closed_above':($last['close']==$high?'closed_at':
            ($last['high']>$high?'wick_above_close_below':($last['high']==$high?'touched_close_below':'below')));
        $sources=array_values(array_column(array_filter($window,fn($b)=>$b['high']==$high),'available_at'));
        $levels=[];
        // All available original history; a pivot needs two completed bars on BOTH sides.
        // No future evidence is accepted by this method.
        for($i=2;$i<=$n-3;$i++){
            $h=(float)$bars[$i]['high'];$pivot=true;
            foreach([-2,-1,1,2] as $j)if($h<=$bars[$i+$j]['high']){$pivot=false;break;}
            if(!$pivot||$h<=$entry)continue;
            $broken=false;$touches=[];
            for($j=$i+1;$j<$n;$j++){
                if($bars[$j]['close']>$h){$broken=true;break;}
                if($bars[$j]['high']>=$h)$touches[]=$bars[$j]['available_at'];
            }
            if($broken)continue;
            $key=(string)$h;
            if(!isset($levels[$key]))$levels[$key]=['price'=>$h,'peak_sessions'=>[],
                'rr_at_original_stop'=>$entry>$stop?($h-$entry)/($entry-$stop):null,'test_sessions'=>[]];
            $levels[$key]['peak_sessions'][]=$bars[$i]['available_at'];
            $levels[$key]['test_sessions']=array_values(array_unique(array_merge($levels[$key]['test_sessions'],$touches)));
        }
        $levels=array_values($levels);usort($levels,fn($a,$b)=>$a['price']<=>$b['price']);
        return ['status'=>'diagnosed','expected_target'=>$expected,'target_matches_formula'=>$target==$expected,
            'target_window'=>['start'=>$window[0]['available_at'],'end'=>$window[19]['available_at']],
            'target_high_raw'=>$high,'target_sessions'=>$sources,'decision_relation'=>$relation,
            'target_vs_entry'=>$target<$entry?'below_entry':($target==$entry?'at_entry':'above_entry'),
            'entry'=>$entry,'stop'=>$stop,'target'=>$target,'decision_close'=>$last['close'],
            'upper_resistance_status'=>$levels?'candidates_in_saved_history':'none_in_saved_history',
            'upper_resistance_candidates'=>$levels,'nearest_upper_resistance'=>$levels[0]??null,
            'history'=>['bars'=>$n,'start'=>$bars[0]['available_at'],'end'=>$last['available_at']],
            'bars'=>array_map(fn($b)=>array_intersect_key($b,array_flip(['available_at','open','high','low','close'])),$bars)];
    }

    public static function run(string $source,string $id,int $days=5):array
    {
        // Same outcome-independent 29 + 13 cohort selection as the stop study; includes its exclusions.
        $study=PaperStopResearch::run($source,$id,$days);$original=[];
        foreach(array_unique(array_column($study['rows'],'source_file')) as $file){
            $batch=PaperRrView::load($source.'/rr-audit/'.$id.'/'.$file);
            foreach($batch['records'] as $raw){if(($raw['status']??'')!=='evaluated')continue;
                $r=PaperRediagnosis::identity($raw,$batch,$file);$original[$r['observation_hash']]=$r;}
        }
        $out=['schema'=>1,'kind'=>self::VERSION,'account'=>$id,'generated_at'=>time(),'as_of'=>$study['as_of'],
            'dates'=>$study['dates'],'input_validation'=>$study['input_validation'],
            'strategy_fingerprint'=>$study['strategy_fingerprint'],'historical_code_verified'=>false,
            'selection'=>'same cohorts as stop study, including excluded rows; original price diagnosis only',
            'rule'=>['target_prior_bars'=>20,'pivot_left'=>2,'pivot_right'=>2,'resistance_window'=>'all_saved_completed_original_bars',
                'resistance_invalidated_by'=>'later_close_strictly_above','nearest_selection'=>'lowest_price_above_entry'],
            'rows'=>[],'summary'=>[],'errors'=>$study['errors']];
        foreach($study['rows'] as $s){
            $row=array_intersect_key($s,array_flip(['date','symbol','name','session','source_file','observation_hash','input_hash','cohort','removed_gate']));
            $row['stop_study_status']=$s['status'];$row['stop_study_blockers']=$s['blockers']??[];
            $r=$original[$s['observation_hash']]??null;
            if(!$r||!hash_equals($r['input_hash'],hash('sha256',PaperRrAudit::encode($r['bars']))))throw new RuntimeException('Original identity/hash mismatch');
            $replay=PaperRediagnosis::evaluate($r,null,$out['as_of'],$out['strategy_fingerprint']);
            if($replay['replay']['status']!=='same_compared_fields'){$row['status']='baseline_replay_mismatch';}
            else{
                $analysis=$replay['replay']['analysis'];$bars=CandleClock::completed($r['bars'],$r['symbol'],(int)$r['session']);
                $base=PaperConditionResearch::hypothesis($bars,(float)$analysis['features']['atr14'],$analysis['plan'],$s['removed_gate']??'recovery_close');
                $row['basis']=$s['cohort']==='confirmed'?'stored_confirmed_levels':'single_gate_close_entry_hypothesis';
                $selected=array_values(array_filter($r['patterns'],fn($p)=>!in_array('not_selected_pattern',$p['exclusion_reasons']??[],true)));
                if($s['cohort']==='confirmed'){
                    $stored=$selected[0]['original'];$row['stored_levels']=$stored;
                    $row['original_levels_match']=true;
                    foreach(['entry','stop','target'] as $k)if(!is_numeric($stored[$k]??null)||$stored[$k]!=$base[$k])$row['original_levels_match']=false;
                }else $row['original_levels_match']=null;
                $row['original_final_status']=$r['analysis']['plan']['status']??null;
                $row['current_formula_status']=$base['status'];
                $row+=self::inspect($r['bars'],$r['symbol'],(int)$r['session'],(float)$base['entry'],(float)$base['stop'],(float)$base['target']);
            }
            $out['rows'][]=$row;
            $c=$row['cohort'];$out['summary'][$c]??=['records'=>0,'diagnosed'=>0,'formula_mismatch'=>0,'stored_levels_mismatch'=>0,
                'relations'=>[],'target_vs_entry'=>[],'upper_resistance'=>[]];$g=&$out['summary'][$c];$g['records']++;
            if($row['status']==='diagnosed'){
                $g['diagnosed']++;$g['formula_mismatch']+=(int)!$row['target_matches_formula'];
                $g['stored_levels_mismatch']+=(int)($row['original_levels_match']===false);
                foreach(['relations'=>'decision_relation','target_vs_entry'=>'target_vs_entry','upper_resistance'=>'upper_resistance_status'] as $k=>$field){$v=$row[$field];$g[$k][$v]=($g[$k][$v]??0)+1;}
            }unset($g);
        }
        return $out;
    }
}
