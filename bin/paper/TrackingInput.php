<?php
declare(strict_types=1);
use ChartEntryLab\CandleClock;
use ChartEntryLab\PaperQuality;

/** Reconciliation for frozen-signal price observation only, never strategy replay. */
final class PaperTrackingInput
{
    public const POLICY='frozen_signal_price_tracking_v3';
    public static function identity(array $r):array
    {
        $expected=(string)($r['symbol']??'');$evidence=[];
        if(!empty($r['analysis_symbol']))$evidence['captured_analysis_symbol']=(string)$r['analysis_symbol'];
        // Older records lack an explicit symbol. Recognize only the engine's anchored reason format.
        $reason=(string)($r['analysis']['decision']['reason']??'');
        if(preg_match('/^([A-Z0-9.^=_-]{1,40})(?:\s+\[(?:account1|custom|isa)\]|:)/',$reason,$m))$evidence['decision_reason_symbol']=$m[1];
        $mismatch=array_filter($evidence,fn($symbol)=>$symbol!==$expected);
        return ['status'=>$mismatch?'analysis_symbol_mismatch':($evidence?'consistent':'not_recorded'),
            'expected'=>$expected,'evidence'=>$evidence];
    }
    public static function prepare(array $r,array $raw,int $asOf):array
    {
        $symbol=$r['symbol'];$session=(int)$r['session'];
        $audit=['policy'=>self::POLICY,'basis'=>'original_signal_and_levels_frozen',
            'original_input_hash'=>$r['input_hash']??null,'tracking_input_hash'=>hash('sha256',PaperRrAudit::encode($raw)),
            'identity'=>self::identity($r),'excluded_old_invalid_bars'=>[],'prefix_preserved'=>[],'volume_differences'=>[],'blocking_differences'=>[]];
        $result=['status'=>'ok','audit'=>$audit,'raw'=>[]];
        $a=&$result['audit'];
        if(!isset($r['input_hash'])||!hash_equals($r['input_hash'],hash('sha256',PaperRrAudit::encode($r['bars'])))){
            $result['status']='input_hash_mismatch';return $result;
        }
        if($a['identity']['status']==='analysis_symbol_mismatch'){$result['status']='analysis_symbol_mismatch';return $result;}
        // Derive exclusions from hash-verified original bars, never from editable cached quality metadata.
        $q=PaperQuality::inspect($r['bars'],$symbol,$session,['sha256'=>$r['input_hash']]);
        if(empty($q['can_simulate'])){$result['status']='future_quality_blocked';$a['original_quality']=$q;return $result;}
        $originalExcluded=[];
        foreach($r['bars'] as $bar){
            $t=CandleClock::closeTime($bar,$symbol);
            if($session-$t>90*86400&&in_array($t,$q['invalid_bars'],true)
                &&CandleClock::completed([$bar],$symbol,$session)===[])$originalExcluded[$t]=$bar;
        }
        // Inspect raw input before merging. Only previously excluded old OHLC errors are repeatable exclusions.

        $seen=[];$invalid=[];
        foreach($raw as $bar){
            $t=CandleClock::closeTime($bar,$symbol);
            if($t>$asOf||!empty($bar['synthetic'])||($bar['is_complete']??true)===false)continue;
            if(isset($seen[$t]))$invalid[]=['session'=>$t,'reason'=>'duplicate_session'];$seen[$t]=true;
            if(CandleClock::completed([$bar],$symbol,$asOf)===[]||!is_numeric($bar['volume']??null)
                ||!is_finite((float)$bar['volume'])||$bar['volume']<0){
                if(isset($originalExcluded[$t])&&CandleClock::completed([$bar],$symbol,$asOf)===[]){
                    $a['excluded_old_invalid_bars'][]=['session'=>$t,'reason'=>'already_excluded_in_original',
                        'saved'=>$originalExcluded[$t],'tracking'=>$bar];
                }else $invalid[]=['session'=>$t,'reason'=>'invalid_ohlcv'];
            }

        }
        if($invalid){$result['status']='future_quality_blocked';$a['invalid_tracking_bars']=$invalid;return $result;}
        $old=CandleClock::completed($r['bars'],$symbol,$session);
        $tracking=CandleClock::completed($raw,$symbol,min($session,$asOf));$index=[];
        foreach($tracking as $b)$index[$b['available_at']]=$b;
        $first=$tracking[0]['available_at']??null;$overlap=0;
        foreach($old as $b){
            $t=$b['available_at'];$n=$index[$t]??null;
            if($n===null){
                // Only an old leading prefix is recoverable. Recent, interior and trailing holes stay blocked.
                if($first!==null&&$t<$first&&$session-$t>90*86400)$a['prefix_preserved'][]=$t;
                else $a['blocking_differences'][]=['session'=>$t,'field'=>'bar','kind'=>'missing_inside_or_recent','saved'=>$b,'tracking'=>null];
                continue;
            }
            $overlap++;
            foreach(['open','high','low','close'] as $k)if((float)$b[$k] !== (float)$n[$k])
                $a['blocking_differences'][]=['session'=>$t,'field'=>$k,'kind'=>'price_changed','saved'=>$b[$k],'tracking'=>$n[$k]];
            if((float)($b['volume']??-1)!==(float)$n['volume'])
                $a['volume_differences'][]=['session'=>$t,'saved'=>$b['volume']??null,'tracking'=>$n['volume']];
        }
        if($overlap===0||!isset($index[$session]))$a['blocking_differences'][]=['session'=>$session,'field'=>'bar','kind'=>'signal_anchor_missing'];
        $a['overlap_bars']=$overlap;
        if($a['blocking_differences']){$result['status']='historical_revision_or_missing';return $result;}
        // All overlapping OHLC must match exactly. Keep saved history (including its volume), append raw future.
        $future=array_values(array_filter($raw,fn($b)=>CandleClock::closeTime($b,$symbol)>$session));
        $result['raw']=array_merge($old,$future);
        $a['reconciled_input_hash']=hash('sha256',PaperRrAudit::encode($result['raw']));
        $a['status']=$a['prefix_preserved']||$a['volume_differences']||$a['excluded_old_invalid_bars']?'reconciled':'matched';
        return $result;
    }
}
