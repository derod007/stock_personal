<?php
declare(strict_types=1);
require_once __DIR__.'/RejectionReview.php';
require_once __DIR__.'/Rediagnosis.php';
require_once __DIR__.'/StrategyVersion.php';
use ChartEntryLab\CandleClock;
use ChartEntryLab\PriceCandidate;
use ChartEntryLab\TradeSimulator;

/** Offline single-gate removal experiment. Never changes the original plan or trading engine. */
final class PaperConditionResearch
{
    public const VERSION='single_gate_close_entry_v1';
    public const GATES=['recovery_close','volume_contracted','fresh_confirmation','bullish_candle','near_ma20'];

    public static function hypothesis(array $bars,float $atr,array $plan,string $gate):array
    {
        if(!in_array($gate,self::GATES,true)||count($bars)<45||!is_finite($atr)||$atr<=0)
            return ['status'=>'invalid_research_input'];
        $last=$bars[count($bars)-1];
        $entry=PriceCandidate::trunc((float)$last['close']);
        $stop=PriceCandidate::trunc(min(array_column(array_slice($bars,-10),'low'))-0.2*$atr);
        $target=PriceCandidate::trunc(max(array_column(array_slice($bars,-21,20),'high')));
        $rr=$entry>$stop?($target-$entry)/($entry-$stop):null;
        $out=['status'=>'eligible','removed_gate'=>$gate,'assumption'=>'remove_one_gate_enter_at_signal_close_limit_next_bar',
            'entry'=>$entry,'stop'=>$stop,'target'=>$target,'reward_risk'=>$rr,'required_rr'=>1.5,
            'rr_basis'=>'before_costs','signal_at'=>$last['available_at'],'order_valid_bars'=>3,'ready'=>false];
        if(!($stop>0&&$stop<$entry&&$entry<$target))$out['status']='invalid_levels';
        elseif($rr<1.5)$out['status']='rejected_rr';
        if(($plan['context_applied']??null)===true){
            $d=$plan['context']['daily']??null;$w=$plan['context']['weekly']??null;
            if(!is_string($d)||!is_string($w))$out['status']='context_unavailable';
            elseif($d==='down'||($w==='down'&&($d!=='up'||$rr===null||$rr<2)))$out['status']='context_wait';
        }elseif(($plan['context_applied']??null)!==false)$out['status']='context_unavailable';
        $out['ready']=$out['status']==='eligible';return $out;
    }

    /** Explain the existing simulator's cancellation; do not alter its fill rules or results. */
    public static function cancellation(array $plan,array $future,array $outcome):?array
    {
        if(($outcome['status']??'')!=='cancelled_before_entry')return null;
        $future=array_values(array_filter($future,fn($b)=>$b['available_at']>$plan['signal_at']));
        usort($future,fn($a,$b)=>$a['available_at']<=>$b['available_at']);
        foreach(array_slice($future,0,(int)($plan['order_valid_bars']??3)) as $b){
            $reason=null;
            if($b['open']<=$plan['stop'])$reason='open_at_or_below_stop';
            elseif($b['open']>=$plan['target'])$reason='open_at_or_above_target';
            elseif($b['high']>=$plan['target'])$reason=$b['low']<=$plan['entry']?'target_and_limit_same_bar_order_unknown':'target_reached_without_limit_touch';
            if($reason!==null)return ['reason'=>$reason,'session'=>$b['available_at'],'open'=>$b['open'],'high'=>$b['high'],'low'=>$b['low'],
                'entry'=>$plan['entry'],'stop'=>$plan['stop'],'target'=>$plan['target']];
            if($b['low']<=$plan['entry'])break; // Filled orders cannot later cancel before entry.
        }
        return ['reason'=>'cancellation_not_reproduced'];
    }

    public static function evaluate(array $r,array $prices,int $asOf,string $gate,string $fingerprint):array
    {
        $out=['symbol'=>$r['symbol'],'name'=>$r['name']??$r['symbol'],'session'=>$r['session'],
            'source_file'=>$r['source_file'],'observation_hash'=>$r['observation_hash'],'input_hash'=>$r['input_hash'],
            'gate'=>$gate,'status'=>'not_evaluated','basis'=>'current_formula_on_hash_verified_saved_bars',
            'historical_code_verified'=>false,'strategy_fingerprint'=>$fingerprint,'as_of'=>$asOf];
        $probe=PaperFollowup::evaluate($r,$prices,$asOf);
        $out['tracking_status']=$probe['status'];
        if(!in_array($probe['status'],['pending','complete','no_future_bars'],true)){
            $out['status']=$probe['status'];return $out;
        }
        $replay=PaperRediagnosis::evaluate($r,null,$asOf,$fingerprint);
        $out['replay_status']=$replay['replay']['status'];
        if($out['replay_status']!=='same_compared_fields'){$out['status']='baseline_replay_mismatch';return $out;}
        $selected=null;
        foreach($r['patterns'] as $p)if(!in_array('not_selected_pattern',$p['exclusion_reasons']??[],true)){
            if($selected!==null){$out['status']='ambiguous_selected_pattern';return $out;}$selected=$p;
        }
        $a=PaperSingleConditionReview::assess($r,$selected);
        if(($selected['pattern']??'')!=='trend_pullback'||$a['status']!=='single_unverified'
            ||$a['failed']!==[$gate]||$a['unknown']!==['rr']){$out['status']='not_single_gate_rr_unknown';return $out;}
        $bars=CandleClock::completed($r['bars'],$r['symbol'],(int)$r['session']);
        $analysis=$replay['replay']['analysis'];
        $plan=self::hypothesis($bars,(float)$analysis['features']['atr14'],$analysis['plan'],$gate);
        $out['hypothesis']=$plan;$out['status']=$plan['status'];
        $out['measurements']=PaperRediagnosis::metrics($bars);
        if(empty($plan['ready']))return $out;
        $trade=PaperRrAudit::outcome($r,$plan,$prices,$asOf);
        $future=array_values(array_filter(CandleClock::completed($prices,$r['symbol'],$asOf),fn($b)=>$b['available_at']>$r['session']));
        // Keep only outcome fields, not repeated full reconciliation payload.
        unset($trade['reconciliation']);$out['outcome']=$trade;
        $out['cancellation']=self::cancellation($plan,$future,$trade);
        return $out;
    }

    public static function run(string $source,string $id,int $days=5):array
    {
        if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id)||$days<1||$days>365)throw new InvalidArgumentException('Invalid account/days');
        $followup=PaperFollowup::load($source,$id);
        if($followup===null)throw new RuntimeException('Saved followup required');
        $review=PaperRejectionReview::load($source.'/rr-audit/'.$id,$followup,$days);
        if($review['errors'])throw new RuntimeException('Audit read errors; repair evidence before research');
        $targets=[];foreach($review['rows'] as $row){$a=$row['single_condition'];
            if($row['pattern']==='trend_pullback'&&$a['status']==='single_unverified'
                &&$a['unknown']===['rr']&&in_array($a['condition'],self::GATES,true))
                $targets[$row['source_file'].'|'.$row['symbol'].'|'.$row['session']]=$a['condition'];
        }
        $fingerprint=PaperStrategyVersion::current();$tracked=[];
        foreach($followup['rows'] as $f)$tracked[$f['observation_hash']]=$f;
        $out=['schema'=>1,'kind'=>self::VERSION,'account'=>$id,'generated_at'=>time(),
            'as_of'=>$followup['as_of'],'dates'=>$review['dates'],'strategy_fingerprint'=>$fingerprint,
            'historical_code_verified'=>false,'selection'=>'all single-gate pullbacks with RR as sole unknown; no outcome-based selection',
            'input_validation'=>['records'=>0,'unavailable'=>0,'input_hashes_verified'=>0,'evidence_hashes_verified'=>0],
            'review_counts'=>$review['single_condition_review']['statuses'],
            'duplicates'=>$review['single_condition_review']['duplicates'],
            'unavailable'=>$review['single_condition_review']['unavailable'],
            'missing_session'=>$review['single_condition_review']['missing_session'],
            'rows'=>[],'existing_limit_trades'=>[],'retest_stages'=>[],'errors'=>[]];
        // Validate each referenced snapshot even if no hypothesis uses it. No provider calls.
        $prices=[];foreach($followup['rows'] as $f){$hash=$f['price_hash']??'';
            if(!preg_match('/^[a-f0-9]{64}$/',$hash))throw new RuntimeException('Missing/invalid evidence hash');
            if(isset($prices[$hash]))continue;
            $bytes=file_get_contents($source.'/followup/'.$id.'/evidence/'.$hash.'.json');
            $raw=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($raw)||!array_is_list($raw)||!hash_equals($hash,hash('sha256',PaperRrAudit::encode($raw))))throw new RuntimeException('Evidence hash mismatch: '.$hash);
            $prices[$hash]=$raw;$out['input_validation']['evidence_hashes_verified']++;
        }
        $files=PaperRrView::files($source.'/rr-audit/'.$id);sort($files,SORT_STRING);$seen=[];
        foreach($files as $file){
            $b=PaperRrView::load($source.'/rr-audit/'.$id.'/'.$file);
            if(($b['membership']??'')!=='observed_scan_only')continue;
            $date=(new DateTimeImmutable('@'.$b['recorded_at']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
            if(!in_array($date,$review['dates'],true))continue;
            foreach($b['records'] as $raw){
                $out['input_validation']['records']++;
                if(($raw['status']??'')!=='evaluated'){$out['input_validation']['unavailable']++;continue;}
                if(!hash_equals($raw['input_hash'],hash('sha256',PaperRrAudit::encode($raw['bars']))))throw new RuntimeException('Original hash mismatch: '.$file.' '.$raw['symbol']);
                $out['input_validation']['input_hashes_verified']++;
                $r=PaperRediagnosis::identity($raw,$b,$file);$key=$r['symbol'].'@'.$r['session'];
                if(isset($seen[$key]))continue;$seen[$key]=true;
                $f=$tracked[$r['observation_hash']]??null;
                $match=$f&&$f['source_file']===$file&&$f['symbol']===$r['symbol']&&$f['session']===$r['session'];
                $gate=$targets[$file.'|'.$r['symbol'].'|'.$r['session']]??null;
                if($gate!==null){
                    if(!$match){$out['errors'][]=['symbol'=>$r['symbol'],'file'=>$file,'error'=>'exact_followup_unavailable'];continue;}
                    $row=self::evaluate($r,$prices[$f['price_hash']],min((int)$f['as_of'],(int)$followup['as_of']),$gate,$fingerprint);
                    $row['date']=$date;$row['price_hash']=$f['price_hash'];$out['rows'][]=$row;
                }
                foreach($r['patterns'] as $p){
                    if(in_array('not_selected_pattern',$p['exclusion_reasons']??[],true))continue;
                    if($p['pattern']==='breakout_retest'){
                        $stage=$p['raw_status'];$out['retest_stages'][$stage]=($out['retest_stages'][$stage]??0)+1;
                    }
                    if(($p['status']??'')!=='added'||!is_array($p['candidate']??null))continue;
                    if(!$match){$out['errors'][]=['symbol'=>$r['symbol'],'file'=>$file,'error'=>'limit_followup_unavailable'];continue;}
                    $asOf=min((int)$f['as_of'],(int)$followup['as_of']);$pr=$prices[$f['price_hash']];
                    $result=PaperFollowup::evaluate($r,$pr,$asOf);$trade=null;
                    foreach($result['trades'] as $t)if($t['kind']==='limit'){$trade=$t['outcome'];break;}
                    $entry=['date'=>$date,'symbol'=>$r['symbol'],'name'=>$r['name'],'source_file'=>$file,
                        'observation_hash'=>$r['observation_hash'],'price_hash'=>$f['price_hash'],'as_of'=>$asOf,
                        'plan'=>$p['candidate'],'status'=>$trade['status']??$result['status'],
                        'net_return_pct'=>$trade['net_return_pct']??null,'first_exit'=>$trade['first_exit']??null,
                        'cancellation'=>$trade?self::cancellation($p['candidate'],CandleClock::completed($pr,$r['symbol'],$asOf),$trade):null];
                    $out['existing_limit_trades'][]=$entry;
                }
            }
        }
        $out['target_count']=count($targets);$out['status_counts']=[];$out['trade_status_counts']=[];
        foreach($out['rows'] as $r){$s=$r['status'];$out['status_counts'][$s]=($out['status_counts'][$s]??0)+1;
            if(isset($r['outcome'])){$s=$r['outcome']['status'];$out['trade_status_counts'][$s]=($out['trade_status_counts'][$s]??0)+1;}}
        return $out;
    }
}
