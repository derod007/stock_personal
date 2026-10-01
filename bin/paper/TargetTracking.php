<?php
declare(strict_types=1);
require_once __DIR__.'/TargetComparison.php';

/** Append-only candidate identities with frozen plans; outcomes are separate mutable observations. */
final class PaperTargetTracking
{
    public const KIND='persistent_target_comparison_v1';
    public static function executionVersion():string
    {
        $root=dirname(__DIR__,2);$hashes=[];
        foreach(['src/TradeSimulator.php','src/CandleClock.php','src/PaperQuality.php','bin/paper/RrAudit.php','bin/paper/TrackingInput.php'] as $p)
            $hashes[$p]=PaperStrategyVersion::fileHash($root.'/'.$p);
        return hash('sha256',PaperRrAudit::encode($hashes));
    }
    public static function load(string $state,string $id):?array
    {
        if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account');
        $p=$state.'/target-tracking/'.$id.'/latest.json';if(!is_file($p))return null;
        $r=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
        if(($r['kind']??'')!==self::KIND||($r['account']??'')!==$id||!is_array($r['rows']??null))throw new RuntimeException('Invalid tracking ledger');
        foreach($r['rows'] as $key=>$row){
            if(!is_array($row['frozen']??null)||!hash_equals($row['frozen_hash']??'',hash('sha256',PaperRrAudit::encode($row['frozen'])))
                ||$key!==$row['frozen']['record']['symbol'].'@'.$row['frozen']['record']['session'])throw new RuntimeException('Frozen ledger integrity mismatch');
        }return $r;
    }
    public static function freeze(array $r,int $now,string $fingerprint,string $execution):?array
    {
        $selected=array_values(array_filter($r['patterns']??[],fn($p)=>!in_array('not_selected_pattern',$p['exclusion_reasons']??[],true)));
        if(count($selected)!==1||($selected[0]['pattern']??'')!=='trend_pullback'||!in_array($selected[0]['raw_status']??'',['ready','rejected_rr'],true))return null;
        if(!hash_equals($r['input_hash'],hash('sha256',PaperRrAudit::encode($r['bars']))))throw new RuntimeException('Original hash mismatch');
        $bars=ChartEntryLab\CandleClock::completed($r['bars'],$r['symbol'],(int)$r['session']);
        if(count($bars)<45)return null;
        $last=$bars[count($bars)-1];$prior=max(array_column(array_slice($bars,-21,20),'high'));
        if($last['available_at']!==$r['session']||$last['close']<=$prior)return null;
        $replay=PaperRediagnosis::evaluate($r,null,$now,$fingerprint);
        if($replay['replay']['status']!=='same_compared_fields')throw new RuntimeException('Original replay mismatch');
        $analysis=$replay['replay']['analysis'];
        $base=PaperConditionResearch::hypothesis($bars,(float)$analysis['features']['atr14'],$analysis['plan'],'recovery_close');
        unset($base['removed_gate'],$base['assumption']);
        foreach(['entry','stop','target'] as $k)if(!is_numeric($selected[0]['original'][$k]??null)||$selected[0]['original'][$k]!=$base[$k])throw new RuntimeException('Original levels mismatch');
        $diag=PaperTargetResearch::inspect($r['bars'],$r['symbol'],(int)$r['session'],(float)$base['entry'],(float)$base['stop'],(float)$base['target']);
        $assessment=PaperSingleConditionReview::assess($r,$selected[0]);
        $plans=PaperTargetComparison::plans($base,$analysis['plan'],$assessment,$diag['nearest_upper_resistance']);
        return ['policy'=>PaperTargetComparison::VERSION,'strategy_fingerprint'=>$fingerprint,'execution_version'=>$execution,
            'historical_code_verified'=>false,'stored_strategy_version'=>$r['strategy_version']??null,
            'registered_at'=>$now,'record'=>$r,'nearest_upper_resistance'=>$diag['nearest_upper_resistance'],
            'fixed_levels'=>$base,'assessment'=>$assessment,'comparison'=>$plans];
    }
    public static function register(array $report,array $observations,int $now,string $fingerprint,string $execution):array
    {
        foreach($observations['errors'] as $err)$report['errors'][]=$err;
        foreach($observations['records'] as $key=>$r){
            if((int)$r['session']>$now||(int)$r['captured_at']>$now)continue;
            if(isset($report['rows'][$key])){
                if(($report['rows'][$key]['frozen']['record']['observation_hash']??null)!==$r['observation_hash'])
                    $report['errors'][]=['symbol'=>$r['symbol'],'error'=>'changed_observation_preserved_original'];
                continue;
            }
            try{$f=self::freeze($r,$now,$fingerprint,$execution);if($f===null)continue;
                $report['rows'][$key]=['frozen'=>$f,'frozen_hash'=>hash('sha256',PaperRrAudit::encode($f)),
                    'status'=>'registered','outcomes'=>[],'last_success_at'=>null];$report['added']++;
            }catch(Throwable $e){$report['errors'][]=['symbol'=>$r['symbol'],'session'=>$r['session'],'error'=>$e->getMessage()];}
        }return $report;
    }
    public static function refresh(array $row,callable $provider,string $execution,int $now):array
    {
        try{
            $f=$row['frozen'];
            if(!hash_equals($row['frozen_hash'],hash('sha256',PaperRrAudit::encode($f))))throw new RuntimeException('Frozen candidate checksum mismatch');
            if($f['policy']!==PaperTargetComparison::VERSION||!hash_equals($f['execution_version'],$execution))throw new RuntimeException('Frozen execution version changed');
            $c=$f['comparison'];$ready=[];
            if($c['status']==='compared')foreach(['baseline','alternative'] as $arm)if($c[$arm]['ready'])$ready[]=$arm;
            if(!$ready){$row['status']='excluded';unset($row['error']);return $row;}
            if(!array_filter($ready,fn($arm)=>empty($row['outcomes'][$arm]['complete']))){$row['status']='complete';unset($row['error']);return $row;}
            $evidence=$provider($f['record'],$row);$raw=$evidence['raw'];$asOf=(int)$evidence['as_of'];
            if($asOf>$now||$asOf<(int)($row['last_success_at']??0))throw new RuntimeException('Price cutoff regression or future cutoff');
            $probe=PaperFollowup::evaluate($f['record'],$raw,$asOf);
            if(!in_array($probe['status'],['pending','complete','no_future_bars'],true))throw new RuntimeException('Price observation blocked: '.$probe['status']);
            $outcomes=[];
            foreach($ready as $arm){
                $t=PaperRrAudit::outcome($f['record'],$c[$arm],$raw,$asOf);unset($t['reconciliation']);
                if(!in_array($t['status'],['closed','unfilled','cancelled_before_entry','pending','incomplete','no_future_bars'],true))throw new RuntimeException('Price evaluation blocked: '.$t['status']);
                $t['cancellation']=PaperConditionResearch::cancellation($c[$arm],ChartEntryLab\CandleClock::completed($raw,$f['record']['symbol'],$asOf),$t);
                $outcomes[$arm]=$t;
            }
            $row['outcomes']=$outcomes;$row['last_success_at']=$asOf;$row['price_hash']=$evidence['price_hash'];
            $row['status']=array_filter($outcomes,fn($t)=>empty($t['complete']))?'tracking':'complete';unset($row['error']);
        }catch(Throwable $e){$row['status']='update_error';$row['error']=$e->getMessage();}
        return $row;
    }
    public static function summarize(array $rows,?array $window=null):array
    {
        $inside=fn($t)=>is_numeric($t)&&($window===null||($t>=$window['start']&&$t<$window['end']));
        $out=['candidates'=>0,'registered'=>0,'total_retained'=>count($rows),'update_errors'=>0,'currently_tracking'=>0,'groups'=>[]];
        $empty=['eligible'=>0,'filled'=>0,'closed'=>0,'stops'=>0,'targets'=>0,'mean_net_pct'=>null];
        foreach($rows as $row){
            $f=$row['frozen'];$out['candidates']+=(int)$inside($f['record']['session']);$out['registered']+=(int)$inside($f['registered_at']);
            $out['update_errors']+=(int)($row['status']==='update_error');$out['currently_tracking']+=(int)($row['status']==='tracking');
            $key=$f['policy'].'/'.$f['strategy_fingerprint'].'/'.($f['stored_strategy_version']??'unrecorded');
            $out['groups'][$key]??=['policy'=>$f['policy'],'strategy_fingerprint'=>$f['strategy_fingerprint'],
                'stored_strategy_version'=>$f['stored_strategy_version'],'candidates'=>0,'arms'=>['baseline'=>$empty,'alternative'=>$empty]];
            $g=&$out['groups'][$key];$g['candidates']+=(int)$inside($f['record']['session']);
            foreach(['baseline','alternative'] as $arm){$a=&$g['arms'][$arm];
                $a['eligible']+=(int)($inside($f['record']['session'])&&!empty($f['comparison'][$arm]['ready']));
                // Events use their own dates: earlier candidates can close in the selected week.
                $t=$row['outcomes'][$arm]??[];$a['filled']+=(int)$inside($t['entry_at']??null);
                if(($t['status']??'')==='closed'&&$inside($t['exit_at']??null)){
                    $n=++$a['closed'];$a['stops']+=(int)!empty($t['hit_stop']);$a['targets']+=(int)!empty($t['hit_target']);
                    $a['mean_net_pct']=(($a['mean_net_pct']??0)*($n-1)+$t['net_return_pct'])/$n;
                }unset($a);
            }unset($g);
        }$out['groups']=array_values($out['groups']);return $out;
    }
}
