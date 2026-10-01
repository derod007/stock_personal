<?php
declare(strict_types=1);
require_once __DIR__.'/TargetResearch.php';
use ChartEntryLab\CandleClock;
use ChartEntryLab\PriceCandidate;
final class PaperTargetComparison
{
    public const VERSION='nearest_resistance_target_comparison_v1';
    public static function plans(array $base,array $context,array $assessment,?array $nearest):array
    {
        // RR-linked context is reevaluated using the SAME daily/weekly rules for each arm.
        $blocked=array_values(array_diff($assessment['failed'],['rr','context_wait']));
        $unknown=array_values(array_diff($assessment['unknown'],['context_wait']));
        if($blocked||$unknown)return ['status'=>'independent_blockers','blockers'=>$blocked,'unknown'=>$unknown];
        if($nearest===null)return ['status'=>'no_upper_candidate'];
        $alt=$base;$alt['target']=PriceCandidate::trunc((float)$nearest['price']);
        return ['status'=>'compared','baseline'=>PaperStopResearch::reprice($base,(float)$base['stop'],$context),
            'alternative'=>PaperStopResearch::reprice($alt,(float)$base['stop'],$context)];
    }
    public static function run(string $source,string $id,int $days=5):array
    {
        $audit=PaperTargetResearch::run($source,$id,$days);
        $selected=array_values(array_filter($audit['rows'],fn($r)=>$r['cohort']==='confirmed'&&$r['status']==='diagnosed'&&$r['decision_relation']==='closed_above'));
        $original=[];foreach(array_unique(array_column($selected,'source_file')) as $file){
            $batch=PaperRrView::load($source.'/rr-audit/'.$id.'/'.$file);
            foreach($batch['records'] as $raw)if(($raw['status']??'')==='evaluated'){$r=PaperRediagnosis::identity($raw,$batch,$file);$original[$r['observation_hash']]=$r;}
        }
        $followup=PaperFollowup::load($source,$id);$tracked=[];
        foreach($followup['rows'] as $r)$tracked[$r['observation_hash']]=$r;
        $out=['schema'=>1,'kind'=>self::VERSION,'account'=>$id,'generated_at'=>time(),'as_of'=>$audit['as_of'],
            'dates'=>$audit['dates'],'input_validation'=>$audit['input_validation'],'strategy_fingerprint'=>$audit['strategy_fingerprint'],
            'historical_code_verified'=>false,'selection'=>'all confirmed close breakouts; no hypothesis-gate removals',
            'rows'=>[],'errors'=>$audit['errors'],'summary'=>['records'=>count($selected),'statuses'=>[],'added_eligible'=>0,'paired_closed'=>0,'paired_mean_difference_pct'=>null,
                'arms'=>array_fill_keys(['baseline','alternative'],['eligible'=>0,'filled'=>0,'closed'=>0,'stops'=>0,'targets'=>0,'mean_net_pct'=>null,'statuses'=>[]])]];
        $prices=[];
        foreach($selected as $diag){
            $row=array_intersect_key($diag,array_flip(['date','symbol','name','session','source_file','observation_hash','input_hash','original_final_status']));
            $row['nearest_upper_resistance']=$diag['nearest_upper_resistance'];$row['status']='not_evaluated';
            $r=$original[$diag['observation_hash']];$f=$tracked[$diag['observation_hash']]??null;
            if(!$diag['target_matches_formula']||$diag['original_levels_match']!==true){$row['status']='original_levels_mismatch';}
            elseif(!$f||$f['source_file']!==$row['source_file']||$f['symbol']!==$row['symbol']||$f['session']!==$row['session']){$row['status']='exact_followup_unavailable';}
            else{
                $hash=$f['price_hash'];
                if(!isset($prices[$hash])){
                    $p=json_decode(file_get_contents($source.'/followup/'.$id.'/evidence/'.$hash.'.json'),true,512,JSON_THROW_ON_ERROR);
                    if(!hash_equals($hash,hash('sha256',PaperRrAudit::encode($p))))throw new RuntimeException('Price evidence mismatch');$prices[$hash]=$p;
                }
                $row['price_hash']=$hash;$row['as_of']=min((int)$f['as_of'],(int)$out['as_of']);
                $probe=PaperFollowup::evaluate($r,$prices[$hash],$row['as_of']);
                if(!in_array($probe['status'],['pending','complete','no_future_bars'],true))$row['status']=$probe['status'];
                else{
                    $patterns=array_values(array_filter($r['patterns'],fn($p)=>!in_array('not_selected_pattern',$p['exclusion_reasons']??[],true)));
                    $assessment=PaperSingleConditionReview::assess($r,$patterns[0]);
                    $base=['entry'=>$diag['entry'],'stop'=>$diag['stop'],'target'=>$diag['target'],'signal_at'=>$row['session'],'order_valid_bars'=>3];
                    $plans=self::plans($base,$r['analysis']['plan'],$assessment,$diag['nearest_upper_resistance']);
                    $row=array_replace($row,$plans);$row['fixed_levels']=$base;
                    if($row['status']==='compared'){
                        $row['added_eligible']=!$row['baseline']['ready']&&$row['alternative']['ready'];
                        foreach(['baseline','alternative'] as $arm){
                            $plan=$row[$arm];$row[$arm]=['plan'=>$plan,'outcome'=>null,'cancellation'=>null];
                            if(!$plan['ready'])continue;
                            $t=PaperRrAudit::outcome($r,$plan,$prices[$hash],$row['as_of']);unset($t['reconciliation']);$row[$arm]['outcome']=$t;
                            $row[$arm]['cancellation']=PaperConditionResearch::cancellation($plan,CandleClock::completed($prices[$hash],$r['symbol'],$row['as_of']),$t);
                        }
                        $a=$row['baseline']['outcome'];$b=$row['alternative']['outcome'];
                        $row['paired_closed']=($a['status']??'')==='closed'&&($b['status']??'')==='closed';
                        $row['paired_net_difference_pct']=$row['paired_closed']?$b['net_return_pct']-$a['net_return_pct']:null;
                    }
                }
            }
            $out['rows'][]=$row;$s=&$out['summary'];$s['statuses'][$row['status']]=($s['statuses'][$row['status']]??0)+1;
            if($row['status']==='compared'){
                $s['added_eligible']+=(int)$row['added_eligible'];
                if($row['paired_closed']){$n=++$s['paired_closed'];$s['paired_mean_difference_pct']=(($s['paired_mean_difference_pct']??0)*($n-1)+$row['paired_net_difference_pct'])/$n;}
                foreach(['baseline','alternative'] as $arm){$v=$row[$arm];$t=$v['outcome'];$a=&$s['arms'][$arm];$a['eligible']+=(int)$v['plan']['ready'];
                    $status=$t['status']??$v['plan']['status'];$a['statuses'][$status]=($a['statuses'][$status]??0)+1;
                    $a['filled']+=(int)!empty($t['filled']);$a['stops']+=(int)!empty($t['hit_stop']);$a['targets']+=(int)!empty($t['hit_target']);
                    if(($t['status']??'')==='closed'){$n=++$a['closed'];$a['mean_net_pct']=(($a['mean_net_pct']??0)*($n-1)+$t['net_return_pct'])/$n;}unset($a);
                }
            }unset($s);
        }
        return $out;
    }
}
