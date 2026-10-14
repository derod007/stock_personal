<?php
declare(strict_types=1);
require_once __DIR__.'/KrxSignalStudy.php';
use ChartEntryLab\PriceCandidate;

/** Selected-plan provenance only. Not a corporate-action or account safety certification. */
final class PaperKrxContinuityReview
{
    public static function day(array $b):string {return substr($b['time_kst'],0,10);}
    private static function equal(float $a,float $b):void {
        if(abs($a-$b)>0.000001)throw new RuntimeException('Source price does not reproduce selected level');
    }
    public static function trace(array $bars,array $full,array $events,string $day):array
    {
        $p=$full['plan'];$sources=[];$hits=[];
        try {
            if(empty($p['ready']))throw new RuntimeException('Selected plan is not ready');
            $n=count($bars);if($n<40)throw new RuntimeException('Insufficient trace input');
            foreach($bars as $b)if(self::day($b)>$day)throw new RuntimeException('Future bar in trace input');
            $add=function(string $role,int $start,int $end,?string $field=null)use(&$sources,$bars,$n):void {
                if($start<0||$end<$start||$end>=$n)throw new RuntimeException('Unresolved source window '.$role);
                $sources[]=['role'=>$role,'from'=>self::day($bars[$start]),'through'=>self::day($bars[$end]),
                    'first_index'=>$start,'last_index'=>$end];
                if($field!==null){$k=array_key_last($sources);$sources[$k]['field']=$field;$sources[$k]['value']=$bars[$start][$field];}
            };
            $atIndex=function(int $at)use($bars):int {
                $matches=array_keys(array_filter($bars,fn($b)=>(int)$b['available_at']===$at));
                if(count($matches)!==1)throw new RuntimeException('Missing/duplicate source timestamp');
                return $matches[0];
            };
            $pattern=$p['pattern']??'';
            if($pattern==='trend_pullback_v1'){
                $target=max(array_column(array_slice($bars,-21,20),'high'));
                $low=min(array_column(array_slice($bars,-10),'low'));
                $atr=self::atr($bars);self::equal(round($atr,4),(float)$full['features']['atr14']);
                self::equal(PriceCandidate::trunc($target),(float)$p['target']);
                self::equal(PriceCandidate::trunc($low-0.2*round($atr,4)),(float)$p['stop']);
                // Covers local 20+20 rising structure, MA slope, volume and ATR windows.
                $add('local_structure_and_gates',$n-40,$n-1);
                $add('target_window',$n-21,$n-2);$add('stop_window',$n-10,$n-1);
                for($i=$n-21;$i<$n-1;$i++)if($bars[$i]['high']==$target)$add('target_high',$i,$i,'high');
                for($i=$n-10;$i<$n;$i++)if($bars[$i]['low']==$low)$add('stop_low',$i,$i,'low');
            }elseif($pattern==='trend_recovery_v1'){
                $e=$p['diagnostics']['patterns']['trend_recovery']['evidence']??[];
                foreach(['resistance','prior_support','reference_low','higher_low','target_source'] as $key){
                    $v=$e[$key]??null;if(!is_array($v))throw new RuntimeException('Missing recovery evidence '.$key);
                    $i=$atIndex((int)$v['at']);
                    if($i!==$v['index']||$i+2>=$n||(int)$bars[$i+2]['available_at']!==(int)$v['confirmed_at'])
                        throw new RuntimeException('Invalid pivot confirmation');
                    $field=$key==='target_source'?'high':'low';self::equal((float)$bars[$i][$field],(float)$v['price']);
                    $add($key,$i,$i,$field);$add($key.'_confirmation_window',$i-2,$i+2);
                }
                self::equal(PriceCandidate::trunc((float)$e['target_source']['price']),(float)$p['target']);
                self::equal(PriceCandidate::trunc((float)$e['higher_low']['price']),(float)$p['stop']);
                foreach(['breakdown','breakout','confirmation'] as $key){
                    $v=$e[$key]??[];$i=$atIndex((int)($v['at']??0));
                    self::equal((float)$bars[$i]['close'],(float)$v['close']);$add($key,$i,$i);
                }
                $i=$atIndex((int)$e['breakdown']['at']);$add('recovery_state_and_ma20',$i-19,$n-1);
            }elseif($pattern==='breakout_retest_v1'){
                $r=$p['diagnostics']['patterns']['breakout_retest']??[];
                $i=$atIndex((int)($r['breakout_at']??0));$j=$atIndex((int)($r['retest_at']??0));
                $box=array_slice($bars,$i-20,20);$level=max(array_column($box,'high'));$floor=min(array_column($box,'low'));
                $atr=self::atr(array_slice($bars,0,$i));self::equal($level,(float)$r['level']);self::equal($atr,(float)$r['atr']);
                $target=2*$level-$floor;$source=null;$entry=(float)$bars[$n-1]['close'];
                for($k=0;$k<$n-1;$k++)if($bars[$k]['high']>$entry+0.1*$atr&&$bars[$k]['high']<$target){$target=(float)$bars[$k]['high'];$source=$k;}
                self::equal(PriceCandidate::trunc($target),(float)$p['target']);
                self::equal(PriceCandidate::trunc(min(array_column(array_slice($bars,$j),'low'))-0.2*$atr),(float)$p['stop']);
                $add('box_and_local_structure',$i-20,$n-1);$add('stop_window',$j,$n-1);
                if($source===null)$add('target_box_projection',$i-20,$i-1);else $add('target_known_high',$source,$source,'high');
            }else throw new RuntimeException('Unsupported selected pattern');
            self::equal(PriceCandidate::trunc((float)$bars[$n-1]['close']),(float)$p['entry']);
            foreach($events as $e){
                if(($e['to']??'9999-99-99')>$day)continue;
                if(!isset($e['from'],$e['to'])||$e['from']>=$e['to'])throw new RuntimeException('Malformed price-break event');
                foreach($sources as $s)if($s['from']<$e['to'])$hits[]=['role'=>$s['role'],'source_from'=>$s['from'],
                    'source_through'=>$s['through'],'break_from'=>$e['from'],'break_to'=>$e['to'],
                    'corporate_action'=>$e['corporate_action']??'미확정'];
            }
            return ['status'=>$hits===[]?'no_crossing_in_traced_scope':'hold_cross_break_source','research_hold'=>$hits!==[],
                'sources'=>$sources,'crossings'=>$hits,'error'=>null,'account_safe'=>false];
        }catch(Throwable $e){return ['status'=>'hold_unresolved_provenance','research_hold'=>true,'sources'=>$sources,
            'crossings'=>$hits,'error'=>$e->getMessage(),'account_safe'=>false];}
    }
    public static function atr(array $bars):float {
        $tr=[];for($i=max(1,count($bars)-14);$i<count($bars);$i++){
            $b=$bars[$i];$c=$bars[$i-1]['close'];$tr[]=max($b['high']-$b['low'],abs($b['high']-$c),abs($b['low']-$c));
        }return $tr===[]?0.0:array_sum($tr)/count($tr);
    }
}
