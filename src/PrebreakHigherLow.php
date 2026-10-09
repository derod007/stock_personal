<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Independent daily observation hypothesis, never an order signal. */
final class PrebreakHigherLow
{
    public const VERSION = 'prebreak_higher_low_v1';
    public function analyze(array $bars): array
    {
        $out=['version'=>self::VERSION,'status'=>'no_setup','stage'=>null,'evidence'=>[],'setup_id'=>null];
        if(count($bars)<60)return array_replace($out,['status'=>'insufficient_history']);
        $lastLow=null;$s=null;
        foreach($bars as $i=>$b){
            $pivot=$this->pivot($bars,$i);
            if($i>=59){
                if($s===null || $s['terminal']){
                    if($s!==null && $out['status']==='breakout_observed')$out['status']='past_breakout';
                    if($lastLow!==null && $lastLow['confirmed_at']<$b['available_at']
                        && $bars[$i-1]['close'] >= $lastLow['price'] && $b['close']<$lastLow['price']){
                        $s=['start'=>$i,'terminal'=>false,'support'=>$lastLow,'low'=>null,'resistance'=>null,'higher'=>null];
                        $out=['version'=>self::VERSION,'status'=>'await_low','stage'=>null,'setup_id'=>null,
                            'evidence'=>['support'=>$lastLow,'breakdown'=>['index'=>$i,'at'=>$b['available_at'],'close'=>$b['close']]]];
                    }
                }
                if($s!==null && !$s['terminal']){
                    if($i-$s['start']>60){$s['terminal']=true;$out['status']='expired';}
                    elseif($s['higher']!==null && $i-$s['higher']['index']>20){$s['terminal']=true;$out['status']='expired';}
                    elseif($s['resistance']===null){
                        if($pivot!==null && $pivot['kind']==='low' && $pivot['index']>$s['start']
                            && $pivot['price']<$s['support']['price']
                            && ($s['low']===null || $pivot['price']<$s['low']['price'])){
                            $s['low']=$pivot;$out['evidence']['low']=$pivot;$out['status']='await_rebound';
                        }
                        if($s['low']!==null && $pivot!==null && $pivot['kind']==='high'
                            && $pivot['index']>$s['low']['index'] && $pivot['price']>$s['support']['price']){
                            $s['resistance']=$pivot;$out['evidence']['resistance']=$pivot;$out['status']='await_higher_low';
                        }
                    }else{
                        if($b['close']<$s['low']['price']){$s['terminal']=true;$out['status']='invalidated';}
                        elseif($s['higher']!==null && $b['low']<=$s['higher']['price']){
                            $s['terminal']=true;$out['status']='higher_low_lost';
                        }elseif($b['close']>$s['resistance']['price']){
                            if($s['higher']!==null && $s['higher']['confirmed_at']<$b['available_at']){
                                $out['status']='breakout_observed';$out['stage']='breakout';
                                $out['evidence']['breakout']=['index'=>$i,'at'=>$b['available_at'],'close'=>$b['close']];
                            }else{$out['status']='breakout_before_confirmation';}
                            $s['terminal']=true;
                        }else{
                            if($s['higher']!==null && $b['low']<=$s['higher']['price']){
                                $s['terminal']=true;$out['status']='higher_low_lost';
                            }elseif($s['higher']===null && $pivot!==null && $pivot['kind']==='low'
                                && $pivot['index']>$s['resistance']['index'] && $pivot['price']>$s['low']['price']
                                && $pivot['price']<$s['resistance']['price']){
                                $s['higher']=$pivot;$out['evidence']['higher_low']=$pivot;
                                $identity=$out['evidence'];foreach($identity as &$part)unset($part['index']);unset($part);
                                $out['setup_id']=hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
                                $out['status']='await_breakout';$out['stage']='watch';
                            }
                            if($s['higher']!==null && $i-$s['higher']['index']>20){$s['terminal']=true;$out['status']='expired';}
                        }
                    }
                }
            }
            // A pivot confirmed at this close is not available for a breakdown earlier in that bar.
            if($pivot!==null && $pivot['kind']==='low')$lastLow=$pivot;
        }
        if(!in_array($out['status'],['await_breakout','breakout_observed'],true))$out['stage']=null;
        $out['reference_close']=(float)end($bars)['close'];
        return $out;
    }
    private function pivot(array $bars,int $i):?array
    {
        if($i<4)return null;$j=$i-2;$p=$bars[$j];$low=true;$high=true;
        foreach([$j-2,$j-1,$j+1,$j+2] as $k){$low=$low&&$p['low']<$bars[$k]['low'];$high=$high&&$p['high']>$bars[$k]['high'];}
        if($low===$high)return null;
        return ['kind'=>$low?'low':'high','index'=>$j,'price'=>(float)$p[$low?'low':'high'],
            'at'=>$p['available_at'],'confirmed_at'=>$bars[$i]['available_at']];
    }
}
