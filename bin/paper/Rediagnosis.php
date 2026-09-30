<?php
declare(strict_types=1);
require_once __DIR__.'/Followup.php';
use ChartEntryLab\CandleClock;
use ChartEntryLab\ChartPlanEngine;

/** Offline research only. Saved decisions and operational followup are never replaced. */
final class PaperRediagnosis
{
    public static function identity(array $record,array $bundle,string $file):array
    {
        $record['source_file']=$file;
        $record['captured_at']=(int)($bundle['recorded_at']??$record['session']??0);
        $record['observation_hash']=hash('sha256',PaperRrAudit::encode($record));
        return $record;
    }

    public static function compareHistory(array $saved,array $prices,string $symbol,int $session):array
    {
        $old=CandleClock::completed($saved,$symbol,$session);
        $new=CandleClock::completed($prices,$symbol,$session);$index=[];$diff=[];
        foreach($new as $b)$index[$b['available_at']]=$b;
        foreach($old as $b){
            $at=$b['available_at'];$n=$index[$at]??null;
            if($n===null){$diff[]=['session'=>$at,'field'=>'bar','kind'=>'missing','saved'=>$b,'tracking'=>null];continue;}
            foreach(['open','high','low','close','volume'] as $k){
                if((float)($b[$k]??-1)!==(float)($n[$k]??-1))$diff[]=[
                    'session'=>$at,'field'=>$k,'kind'=>'changed','saved'=>$b[$k]??null,'tracking'=>$n[$k]??null];
            }
        }
        return ['status'=>$diff?'historical_revision_or_missing':'matched','saved_completed_bars'=>count($old),
            'tracking_completed_bars'=>count($new),'difference_count'=>count($diff),'differences'=>$diff];
    }

    public static function metrics(array $bars):array
    {
        if(count($bars)<40)return ['status'=>'insufficient_bars','completed_bars'=>count($bars)];
        $last=$bars[count($bars)-1];$prev=$bars[count($bars)-2];
        $base=array_slice($bars,-24,20);$pull=array_slice($bars,-4,3);
        $avg=static fn($v)=>array_sum(array_column($v,'volume'))/count($v);
        $baseVol=$avg($base);$pullVol=$avg($pull);
        return ['basis'=>'reconstructed_from_saved_completed_bars_current_formula',
            'completed_bars'=>count($bars),'last_session'=>$last['available_at'],
            'volume'=>['baseline_mean'=>$baseVol,'pullback_mean'=>$pullVol,'ratio'=>$baseVol>0?$pullVol/$baseVol:null,
                'required_ratio_lt'=>0.85,'baseline_zero_bars'=>count(array_filter($base,fn($b)=>($b['volume']??0)==0)),
                'baseline_sessions'=>array_column($base,'available_at'),'pullback_sessions'=>array_column($pull,'available_at')],
            'confirmation'=>['close'=>$last['close'],'open'=>$last['open'],'previous_high'=>$prev['high'],
                'volume'=>$last['volume']??null,'previous_volume'=>$prev['volume']??null,
                'previous_close'=>$prev['close'],'two_bars_ago_high'=>$bars[count($bars)-3]['high']],
            'structure'=>['ma20'=>array_sum(array_column(array_slice($bars,-20),'close'))/20,
                'ma20_five_bars_ago'=>array_sum(array_column(array_slice($bars,-25,20),'close'))/20,
                'recent20_low'=>min(array_column(array_slice($bars,-20),'low')),
                'prior20_low'=>min(array_column(array_slice($bars,-40,20),'low')),
                'recent20_high'=>max(array_column(array_slice($bars,-20),'high')),
                'prior20_high'=>max(array_column(array_slice($bars,-40,20),'high'))]];
    }

    public static function evaluate(array $r,?array $prices,int $asOf,string $fingerprint,?string $profile=null):array
    {
        $profileBasis=$profile===null?'saved_decision':'operator_override';
        $profile??=$r['analysis']['decision']['profile']??null;
        $out=['symbol'=>$r['symbol']??'','name'=>$r['name']??$r['symbol']??'',
            'source_file'=>$r['source_file'],'captured_at'=>$r['captured_at'],
            'session'=>$r['session']??null,'observation_hash'=>$r['observation_hash'],
            'original'=>['analysis'=>$r['analysis']??null,'patterns'=>$r['patterns']??[],
                'measurements'=>$r['measurements']??[],'quality'=>$r['quality']??null,
                'status'=>$r['status']??null,'reason'=>$r['reason']??null,'detail'=>$r['detail']??null],
            'replay'=>['status'=>'not_run','basis'=>'current_code_on_saved_input',
                'strategy_fingerprint'=>$fingerprint,'profile'=>$profile,'profile_basis'=>$profileBasis,
                'historical_code_verified'=>false],
            'history'=>['status'=>'not_checked'],'followup'=>null];
        if(($r['status']??'')!=='evaluated'||!is_array($r['bars']??null)){
            $out['status']='original_input_unavailable';return $out;
        }
        if(!isset($r['input_hash'])||!hash_equals($r['input_hash'],hash('sha256',PaperRrAudit::encode($r['bars'])))){
            $out['status']='input_hash_mismatch';return $out;
        }
        $plan=$r['analysis']['plan']??[];$at=$plan['asof']??$r['asof']??null;
        if(!is_numeric($at)||!is_numeric($r['session']??null)){$out['status']='original_time_unavailable';return $out;}
        if($at>$asOf||$r['captured_at']>$asOf||$r['session']>$asOf){$out['status']='not_yet_observed';return $out;}
        $out['status']='diagnosed';
        try{
            if(!in_array($profile,['account1','custom','isa'],true))throw new RuntimeException('Original profile unavailable; pass --profile explicitly');
            $completed=CandleClock::completed($r['bars'],$r['symbol'],(int)$at);
            $out['reconstructed_metrics']=self::metrics($completed);
            if(!array_key_exists('context_applied',$plan))throw new RuntimeException('Original context option unavailable');
            $analysis=(new ChartPlanEngine())->analyze($r['bars'],$r['symbol'],(int)$at,$profile,(bool)$plan['context_applied']);
            $out['replay']['analysis']=$analysis;
            $diff=[];
            foreach(['status','ready','pattern','entry','stop','target','reward_risk','data_asof','context','diagnostics'] as $key){
                // Numeric JSON representation (e.g. 1 vs 1.0) is not a strategy difference.
                if((($plan[$key]??null)===null)!==(($analysis['plan'][$key]??null)===null)||($plan[$key]??null)!=($analysis['plan'][$key]??null))$diff[$key]=['saved'=>$plan[$key]??null,'replayed'=>$analysis['plan'][$key]??null];
            }
            if(($r['analysis']['decision']??null)!=$analysis['decision'])$diff['decision']=['saved'=>$r['analysis']['decision']??null,'replayed'=>$analysis['decision']];
            $out['replay']['differences']=$diff;
            $out['replay']['status']=$diff?'different':'same_compared_fields';
        }catch(Throwable $e){$out['replay']['status']='error';$out['replay']['error']=$e->getMessage();}
        if($prices===null){$out['history']['status']='tracking_prices_unavailable';return $out;}
        try{
            $out['history']=self::compareHistory($r['bars'],$prices,$r['symbol'],(int)$r['session']);
            // Original signal and original candidates only; strict hash/revision/quality guards retained.
            $out['followup']=PaperFollowup::evaluate($r,$prices,$asOf);
        }catch(Throwable $e){$out['history']['evaluation_error']=$e->getMessage();}
        return $out;
    }
}
