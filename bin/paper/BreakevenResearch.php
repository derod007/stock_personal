<?php
declare(strict_types=1);
require_once __DIR__.'/PatternReplay.php';

/** Research only. All entries and fixed exits come from the existing simulator. */
final class PaperBreakevenResearch
{
    public static function compare(array $plan,array $future):array
    {
        if(($plan['exit_mode']??'fixed')!=='fixed')throw new InvalidArgumentException('Fixed baseline required');
        $base=PaperPatternReplay::trade($plan,$future);
        $variant=$base; $trigger=null; $activeFrom=null; $changed=false;
        if($base['filled']) {
            $fill=(float)$base['entry_fill']; $holding=0;
            foreach($future as $bar) {
                if($bar===null)break; // Never skip a bad session.
                $at=$bar['available_at'];
                if($at<$base['entry_at'])continue;
                $holding++;
                if($base['exit_at']!==null && $at>$base['exit_at'])break;
                if($trigger!==null) {
                    $activeFrom??=$at;
                    // Existing chronology: target opening gap wins; otherwise stop wins a tie.
                    $openingTarget=$at>$base['entry_at'] && $bar['open']>=$plan['target'];
                    if(!$openingTarget && $bar['low']<=$fill) {
                        $exit=min($fill,(float)$bar['open'])*(1-$base['slippage_bps']/10000);
                        $net=($exit*(1-$base['fee_bps_per_side']/10000)/($fill*(1+$base['fee_bps_per_side']/10000))-1)*100;
                        $variant=array_replace($base,['status'=>'closed','complete'=>true,'bars'=>$holding,
                            'exit_at'=>$at,'exit_fill'=>round($exit,6),'first_exit'=>'stop','net_return_pct'=>round($net,4),
                            'ret_close_pct'=>round($net,4),'hit_stop'=>true,'hit_target'=>false,
                            'ambiguous_bar'=>$bar['high']>=$plan['target']]);
                        $changed=true;break;
                    }
                }
                // A trade that already exited cannot arm a stop at that session's close.
                if($at===$base['exit_at'])break;
                if($trigger===null && $bar['close']>=$fill*1.03)$trigger=$at;
            }
        }
        return ['baseline'=>$base,'variant'=>$variant,'trigger_at'=>$trigger,'active_from'=>$activeFrom,
            'breakeven_stop_exit'=>$changed];
    }

    public static function summary(array $rows):array
    {
        $out=['selected'=>count($rows),'triggered'=>0,'activated'=>0,'breakeven_stop_exits'=>0,
            'states'=>[],'paired_closed'=>0,'improved'=>0,'worsened'=>0,'unchanged'=>0,
            'baseline_loss_improved'=>0,'baseline_win_worsened'=>0,'newly_closed'=>0];
        $delta=[];$rDelta=[];$before=[];$after=[];$beforeR=[];$afterR=[];
        foreach($rows as $r) {
            $a=$r['baseline'];$b=$r['variant'];
            $out['triggered']+=(int)($r['trigger_at']!==null);$out['activated']+=(int)($r['active_from']!==null);
            $out['breakeven_stop_exits']+=(int)$r['breakeven_stop_exit'];
            $key=$a['status'].' -> '.$b['status'];$out['states'][$key]=($out['states'][$key]??0)+1;
            if($a['status']!=='closed' && $b['status']==='closed')$out['newly_closed']++;
            if($a['status']!=='closed'||$b['status']!=='closed')continue;
            $x=$a['net_return_pct'];$y=$b['net_return_pct'];$d=$y-$x;
            $delta[]=$d;$before[]=$x;$after[]=$y;
            $risk=($a['entry_fill']-$r['stop'])/$a['entry_fill']*100;
            if($risk<=0)throw new RuntimeException('Non positive initial risk');
            $rDelta[]=$d/$risk;$beforeR[]=$x/$risk;$afterR[]=$y/$risk;
            $out[$d>0?'improved':($d<0?'worsened':'unchanged')]++;
            $out['baseline_loss_improved']+=(int)($x<=0&&$d>0);
            $out['baseline_win_worsened']+=(int)($x>0&&$d<0);
        }
        $mean=fn($v)=>$v?array_sum($v)/count($v):null;
        $out['paired_closed']=count($delta);$out['baseline_mean_pct']=$mean($before);$out['variant_mean_pct']=$mean($after);
        $out['baseline_mean_R']=$mean($beforeR);$out['variant_mean_R']=$mean($afterR);
        $out['mean_delta_pp']=$mean($delta);$out['mean_delta_R']=$mean($rDelta);
        return $out;
    }
}
