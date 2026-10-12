<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
use ChartEntryLab\{CandleClock,ChartPlanEngine,PaperQuality};

/** Read-only audit. Engine recomputation is separate from stored-log consistency, not an independent strategy implementation. */
final class PaperDatedOrderAudit
{
    public const FIELDS=['pattern','entry','stop','target','signal_at','order_valid_bars','reward_risk'];

    private static function same(mixed $a,mixed $b,string $field):bool
    {
        if($a===null||$b===null)return false;
        if($field==='pattern')return is_string($a)&&$a!==''&&$a===$b;
        return is_numeric($a)&&is_numeric($b)&&is_finite((float)$a)&&is_finite((float)$b)
            &&abs((float)$a-(float)$b)<=1e-8;
    }

    /** Validate a single order against exactly one log signal and a separately recomputed engine result. */
    public static function compare(array $order,array $signals,?array $evidence,bool $inTop):array
    {
        $errors=[];
        if(!$inTop)$errors[]='not_in_signal_day_top100';
        if(count($signals)!==1)$errors[]='stored_signal_missing_or_duplicate';
        else{
            $signal=$signals[0];
            if(($signal['outcome']??null)!=='order')$errors[]='stored_signal_not_order';
            foreach(self::FIELDS as $f)if(!self::same($order[$f]??null,$signal[$f]??null,$f))$errors[]='stored_signal_mismatch:'.$f;
        }
        if($evidence===null)$errors[]='engine_evidence_missing';
        else{
            if(($evidence['quality']['can_simulate']??null)!==true)$errors[]='quality_blocked';
            if(($evidence['plan']['ready']??null)!==true)$errors[]='engine_not_ready';
            foreach(self::FIELDS as $f)if(!self::same($order[$f]??null,$evidence['plan'][$f]??null,$f))$errors[]='engine_mismatch:'.$f;
        }
        return $errors;
    }

    /** This path never accepts injected plans. It always uses ChartPlanEngine with completed bars through the order session. */
    public static function recompute(string $symbol,int $session,array $info):array
    {
        $past=[];
        foreach($info['rows']??[] as $bar){
            $at=CandleClock::closeTime($bar,$symbol);
            if($at<=$session){$bar['available_at']=$at;$past[]=$bar;}
        }
        usort($past,fn($a,$b)=>$a['available_at']<=>$b['available_at']);
        $valid=CandleClock::completed($past,$symbol,$session);
        $quality=PaperQuality::inspect($past,$symbol,$session,
            ['sha256'=>$info['bars_sha256']??null,'price_basis'=>'yahoo_chart_quote_ohlcv_daily_adjustment_unverified'],[]);
        $plan=count($valid)>=40?(new ChartPlanEngine())->analyze($valid,$symbol,$session)['plan']:['ready'=>false];
        return ['quality'=>$quality,'plan'=>$plan];
    }

    public static function audit(array $journal,array $candidates,?array $data):array
    {
        $logs=[];$orders=[];
        foreach($journal['events']??[] as $event){
            $p=$event['payload']??[];
            if(($event['type']??'')==='day_log')$logs[(int)($p['session']??0)][]=$p;
            if(($event['type']??'')==='order')$orders[]=$p;
        }
        $mismatches=[];$seen=[];$recomputed=0;$logPassed=0;
        foreach($orders as $order){
            $symbol=(string)($order['symbol']??'');$session=(int)($order['session']??0);
            $key=$symbol.'@'.$session;$errors=[];$signals=[];
            if(isset($seen[$key]))$errors[]='duplicate_order';
            $seen[$key]=true;
            $dayLogs=$logs[$session]??[];
            if(count($dayLogs)!==1)$errors[]='day_log_missing_or_duplicate';
            else foreach($dayLogs[0]['signals']??[] as $s)if(($s['symbol']??null)===$symbol)$signals[]=$s;
            $inTop=in_array($symbol,$candidates['by_session'][$session]['symbols']??[],true);
            $evidence=null;
            if($data!==null&&isset($data['symbols'][$symbol])){
                try{$evidence=self::recompute($symbol,$session,$data['symbols'][$symbol]);$recomputed++;}
                catch(Throwable $e){$errors[]='engine_recompute_failed:'.$e->getMessage();}
            }
            $errors=array_merge($errors,self::compare($order,$signals,$evidence,$inTop));
            $storedErrors=array_filter($errors,fn($e)=>str_starts_with($e,'stored_')||str_starts_with($e,'day_log_'));
            if($storedErrors===[])$logPassed++;
            if($errors!==[])$mismatches[]=['symbol'=>$symbol,'session'=>$session,'reasons'=>$errors];
        }
        return ['pass'=>$mismatches===[]&&$data!==null,'orders'=>count($orders),'stored_log_matches'=>$logPassed,
            'engine_recomputed'=>$recomputed,'mismatches'=>$mismatches,
            'scope'=>'stored_log_comparison_and_same_engine_recomputation_from_asof_bars',
            'independent_strategy_implementation'=>false,'writes'=>false,
            'audit_code_sha256_lf'=>hash('sha256',str_replace("\r\n","\n",(string)file_get_contents(__FILE__)))];
    }
}
