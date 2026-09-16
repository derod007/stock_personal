<?php
declare(strict_types=1);
// Read-only reporting outside src preserves the running account's pinned strategy.
final class PaperTradeReview
{
    public static function build(array $journal): array
    {
        $state=$journal['state'];$snap=[];$quality=[];$pending=[];$trades=[];$issues=[];
        foreach($journal['events'] as $e) {
            $p=$e['payload'];$sym=$p['symbol']??null;$type=$e['type'];
            if($type==='snapshot') {
                $snap[$sym.':'.$p['session']]=$p;
                $quality[$sym][(int)$p['session']]=!empty($p['quality']['can_simulate']);
            } elseif($type==='order') {
                if(isset($pending[$sym])) $issues[]='overlapping_order:'.$e['id'];
                $x=$snap[$sym.':'.$p['session']]??[];
                $pending[$sym]=['id'=>$e['id'],'symbol'=>$sym,'order'=>$p,'origin'=>$x['origin']??'unknown',
                    'pattern'=>$x['plan']['pattern']??$x['plan']['version']??'unknown',
                    'signal_reason'=>$x['plan']['reason']??'기록 없음','signal_session'=>$p['session'],
                    'signal_recorded_at'=>$x['recorded_at']??null,'fill'=>null,'exit'=>null];
            } elseif($type==='fill') {
                if(!isset($pending[$sym]) || $pending[$sym]['fill']!==null) {$issues[]='unmatched_fill:'.$e['id'];continue;}
                $pending[$sym]['fill']=$p;
            } elseif($type==='exit') {
                if(!isset($pending[$sym]) || $pending[$sym]['fill']===null) {$issues[]='unmatched_exit:'.$e['id'];continue;}
                $pending[$sym]['exit']=$p;$trades[]=$pending[$sym];unset($pending[$sym]);
            } elseif($type==='order_cancelled') unset($pending[$sym]);
        }
        foreach($pending as $t) if($t['fill']!==null)$trades[]=$t;
        $groups=[];$sum=0.0;$closed=0;
        foreach($trades as &$t) {
            $f=$t['fill'];$x=$t['exit'];$price=(float)$f['price'];$qty=(int)$f['quantity'];
            $cost=$qty*$price+(float)$f['fee'];
            $t['status']=$x?'closed':'open';$t['entry_cost']=$cost;
            $t['net_pnl']=$x?(float)$x['net_pnl']:null;
            $t['net_return_pct']=$x&&$cost>0?100*$t['net_pnl']/$cost:null;
            $risk=(float)($t['order']['planned_risk']??0);
            $t['net_r']=$x&&$risk>0?$t['net_pnl']/$risk:null;
            // Derive total modeled fees from recorded fills/PnL, without assuming current fees.
            $t['gross_pnl']=$x?$qty*((float)$x['price']-$price):null;
            $t['fees']=$x?$t['gross_pnl']-$t['net_pnl']:null;
            $t['ambiguous_bar']=$x?($x['ambiguous_bar']??false):false;
            if($x && (int)$x['quantity']!==$qty)$issues[]='quantity_mismatch:'.$t['id'];
            $bars=[];
            foreach($state['history'][$t['symbol']]??[] as $bar) {
                $at=(int)($bar['available_at']??0);
                if($at>0 && $at<=($state['last_session']??0))$bars[$at]=$bar;
            }
            ksort($bars,SORT_NUMERIC);
            $sessions=$quality[$t['symbol']]??[];ksort($sessions,SORT_NUMERIC);
            $end=$x?(int)$x['session']:(int)($state['last_session']??0);
            $start=(int)$f['session'];$path=[];$valid=true;$observed=0;
            foreach($sessions as $at=>$good) {
                if($at<$start || $at>$end)continue;
                $observed++;
                if(!$good || !isset($bars[$at]) || !self::validBar($bars[$at])) {$valid=false;continue;}
                $path[$at]=$bars[$at];
            }
            if(!isset($path[$start],$path[$end]))$valid=false;
            $t['observed_holding_bars']=$observed;
            $t['excursion_status']=$valid?'available':'missing_or_blocked_data';
            $t['known_mfe_pct']=$t['known_mae_pct']=$t['envelope_mfe_pct']=$t['envelope_mae_pct']=null;
            if($valid) {
                $known=[$price];$envelope=[$price];
                if($x){$known[]=(float)$x['price'];$envelope[]=(float)$x['price'];}
                foreach($path as $at=>$bar) {
                    $envelope[]=(float)$bar['high'];$envelope[]=(float)$bar['low'];
                    if($at>$start && (!$x || $at<$end)) {$known[]=(float)$bar['high'];$known[]=(float)$bar['low'];}
                    // Close of entry day is known to be held only if it did not exit that day.
                    if(!$x || $at<$end)$known[]=(float)$bar['close'];
                }
                $t['known_mfe_pct']=100*(max($known)/$price-1);$t['known_mae_pct']=100*(min($known)/$price-1);
                $t['envelope_mfe_pct']=100*(max($envelope)/$price-1);$t['envelope_mae_pct']=100*(min($envelope)/$price-1);
            }
            $t['post_stop']=[];
            if($x && $x['reason']==='stop')foreach([5,10] as $n) {
                $future=array_slice(array_filter(array_keys($sessions),fn($at)=>$at>$end),0,$n);
                $ok=count($future)===$n;
                foreach($future as $at)if(!$sessions[$at] || !isset($bars[$at]) || !self::validBar($bars[$at]))$ok=false;
                $v=['status'=>$ok?'complete':(count($future)<$n?'awaiting_bars':'missing_or_blocked_data'),'observed'=>count($future),'close_vs_exit_pct'=>null,'recovered_entry_at_close'=>null,'max_high_vs_exit_pct'=>null,'min_low_vs_exit_pct'=>null];
                if($ok) {
                    $subset=array_map(fn($at)=>$bars[$at],$future);$last=$subset[count($subset)-1];$exit=(float)$x['price'];
                    $v['close_vs_exit_pct']=100*((float)$last['close']/$exit-1);
                    $v['recovered_entry_at_close']=(float)$last['close']>=$price;
                    $v['max_high_vs_exit_pct']=100*(max(array_column($subset,'high'))/$exit-1);
                    $v['min_low_vs_exit_pct']=100*(min(array_column($subset,'low'))/$exit-1);
                }
                $t['post_stop'][$n]=$v;
            }
            if($x) {
                $closed++;$sum+=$t['net_pnl'];
                foreach(['exit_reason'=>$x['reason'],'pattern'=>$t['pattern']] as $kind=>$label) {
                    $key=json_encode([$t['origin'],$kind,$label]);
                    if(!isset($groups[$key]))$groups[$key]=['origin'=>$t['origin'],'kind'=>$kind,'label'=>$label,'count'=>0,'wins'=>0,'net_pnl'=>0.0,'sum_return_pct'=>0.0,'sum_r'=>0.0,'r_count'=>0];
                    $g=&$groups[$key];$g['count']++;$g['wins']+=(int)($t['net_pnl']>0);$g['net_pnl']+=$t['net_pnl'];$g['sum_return_pct']+=$t['net_return_pct'];
                    if($t['net_r']!==null){$g['sum_r']+=$t['net_r'];$g['r_count']++;}unset($g);
                }
            }
        }
        unset($t);
        foreach($groups as &$g){$g['mean_return_pct']=$g['sum_return_pct']/$g['count'];$g['mean_r']=$g['r_count']?$g['sum_r']/$g['r_count']:null;}unset($g);
        if($closed!==($state['closed_trades']??0) || abs($sum-(float)($state['realized']??0))>0.01)$issues[]='account_reconciliation_failed';
        return ['trades'=>$trades,'groups'=>array_values($groups),'closed'=>$closed,'net_pnl'=>$sum,'issues'=>$issues,
            'currency'=>$state['config']['currency']??'','last_session'=>$state['last_session']??0,'halted'=>!empty($state['halted'])];
    }
    private static function validBar(array $b): bool
    {
        foreach(['open','high','low','close'] as $k)if(!is_numeric($b[$k]??null)||!is_finite((float)$b[$k])||$b[$k]<=0)return false;
        return $b['high']>=max($b['open'],$b['close'],$b['low']) && $b['low']<=min($b['open'],$b['close']);
    }
}
