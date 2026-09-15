<?php
declare(strict_types=1);
namespace ChartEntryLab;

final class PaperPortfolio
{
    public static function start(array $config,string $version,string $mode): array
    {
        if(!in_array($mode,['forward','replay'],true) || !in_array($config['currency'],['USD','KRW'],true)) throw new \InvalidArgumentException('Invalid account mode/currency');
        foreach(['initial_cash','max_positions','risk_pct','position_pct','sector_pct','total_risk_pct'] as $k) {
            if(!is_numeric($config[$k]??null) || !is_finite((float)$config[$k]) || $config[$k]<=0) throw new \InvalidArgumentException('Invalid limit: '.$k);
        }
        foreach(['risk_pct','position_pct','sector_pct','total_risk_pct'] as $k) {
            if($config[$k]>1) throw new \InvalidArgumentException('Limits must be fractions');
        }
        if($config['max_positions']!=(int)$config['max_positions']) throw new \InvalidArgumentException('Integer position limit required');
        foreach($config['symbols'] as $symbol=>$sector) {
            $kr=str_ends_with($symbol,'.KS') || str_ends_with($symbol,'.KQ');
            if(($config['currency']==='KRW')!==$kr || $sector==='') throw new \InvalidArgumentException('Separate market/currency accounts required');
        }
        return ['config'=>$config,'version'=>$version,'mode'=>$mode,'cash'=>(float)$config['initial_cash'],
            'realized'=>0.0,'active'=>[],'marks'=>[],'last_session'=>0,'last_snapshots'=>[],
            'peak_equity'=>(float)$config['initial_cash'],'max_drawdown'=>0.0,'equity'=>[],
            'frozen'=>[],'closed_trades'=>0,'wins'=>0,'losses'=>0];
    }
    public static function equity(array $s): float
    {
        $value=$s['cash'];
        foreach($s['active'] as $symbol=>$o) if($o['filled']) $value+=$o['quantity']*($s['marks'][$symbol]['close']??$o['entry_fill']);
        return $value;
    }
    public static function reserved(array $s): float
    {
        $value=0.0;foreach($s['active'] as $o) if(!$o['filled']) $value+=$o['reservation'];
        return $value;
    }
    private static function openTime(int $session,string $currency): int
    {
        $tz=new \DateTimeZone($currency==='KRW'?'Asia/Seoul':'America/New_York');
        $day=(new \DateTimeImmutable('@'.$session))->setTimezone($tz)->format('Y-m-d');
        return (new \DateTimeImmutable($day.($currency==='KRW'?' 09:00:00':' 09:30:00'),$tz))->getTimestamp();
    }
    public static function advance(array &$s,int $session,array $bars,array $snapshots,callable $emit): void
    {
        if($session<=$s['last_session']) return;
        $cfg=$s['config'];ksort($snapshots);
        // Existing reservations are executed before new close-time signals are considered.
        foreach(array_keys($s['active']) as $symbol) {
            $o=&$s['active'][$symbol];
            if(!isset($bars[$symbol]) || empty($snapshots[$symbol]['quality']['can_simulate'])) {
                if(!$o['filled']) {
                    $emit('order_cancelled',['symbol'=>$symbol,'session'=>$session,'reason'=>'data_quality']);
                    unset($s['active'][$symbol]);unset($o);
                }
                continue;
            }
            $bar=$bars[$symbol];
            if($s['mode']==='forward' && self::openTime($session,$cfg['currency'])<=$o['recorded_at']) { unset($o);continue; }
            $o['history'][]=$bar;
            $t=(new TradeSimulator())->simulate($o['plan'],$o['history'],20);
            if($t['filled'] && !$o['filled']) {
                $cost=$o['quantity']*$t['entry_fill']*1.001;
                if($cost>$s['cash']+0.01) throw new \RuntimeException('Cash invariant failed');
                $s['cash']-=$cost;$o['filled']=true;$o['entry_fill']=$t['entry_fill'];$o['entry_cost']=$cost;
                $emit('fill',['symbol'=>$symbol,'session'=>$session,'quantity'=>$o['quantity'],'price'=>$t['entry_fill'],'fee'=>$o['quantity']*$t['entry_fill']*0.001]);
            }
            if($t['status']==='closed') {
                $proceeds=$o['quantity']*$t['exit_fill']*0.999;
                $net=$proceeds-$o['entry_cost'];$s['cash']+=$proceeds;$s['realized']+=$net;
                $s['closed_trades']++;if($net>0)$s['wins']++;if($net<0)$s['losses']++;
                $emit('exit',['symbol'=>$symbol,'session'=>$session,'quantity'=>$o['quantity'],
                    'price'=>$t['exit_fill'],'net_pnl'=>$net,'reason'=>$t['first_exit'],'ambiguous_bar'=>$t['ambiguous_bar']]);
                unset($s['active'][$symbol]);
            } elseif(in_array($t['status'],['unfilled','cancelled_before_entry','invalid_levels'],true)) {
                $emit('order_cancelled',['symbol'=>$symbol,'session'=>$session,'reason'=>$t['status'],'ambiguous_bar'=>$t['ambiguous_bar']]);
                unset($s['active'][$symbol]);
            }
            unset($o);
        }
        foreach($bars as $symbol=>$bar) {
            if(!empty($snapshots[$symbol]['quality']['can_simulate'])) $s['marks'][$symbol]=['close'=>$bar['close'],'session'=>$session];
        }
        $stale=[];
        foreach($s['active'] as $symbol=>$o) if($o['filled'] && ($s['marks'][$symbol]['session']??0)!==$session) $stale[]=$symbol;
        foreach($snapshots as $symbol=>$snapshot) {
            $key=$symbol.':'.$session;
            if(isset($s['frozen'][$key])) throw new \RuntimeException('Snapshot cannot be overwritten');
            $s['frozen'][$key]=['input_hash'=>$snapshot['input_hash'],'snapshot_hash'=>hash('sha256',PaperJournal::encode($snapshot))];
            $s['last_snapshots'][$symbol]=$snapshot;
            $emit('snapshot',$snapshot);
            $p=$snapshot['plan'];$reason=null;
            if(empty($snapshot['quality']['can_simulate'])) $reason='data_quality';
            elseif(empty($p['ready'])) $reason='signal_not_confirmed';
            elseif($snapshot['origin']==='catchup') $reason='retrospective_signal';
            elseif(isset($s['active'][$symbol])) $reason='already_active';
            elseif($stale!==[]) $reason='unpriced_position';
            elseif(count($s['active'])>=$cfg['max_positions']) $reason='position_limit';
            if($reason!==null) {
                $emit('decision',['symbol'=>$symbol,'session'=>$session,'status'=>'no_order','reason'=>$reason]);continue;
            }
            $entry=(float)($p['entry']??0);$stop=(float)($p['stop']??0);$target=(float)($p['target']??0);
            if(!($stop>0 && $entry>$stop && $target>$entry)) {
                $emit('decision',['symbol'=>$symbol,'session'=>$session,'status'=>'no_order','reason'=>'invalid_plan']);continue;
            }
            $equity=self::equity($s);$sector=$cfg['symbols'][$symbol];$sectorUsed=0.0;$riskUsed=0.0;
            foreach($s['active'] as $sym=>$o) {
                $riskUsed+=$o['planned_risk'];
                if($cfg['symbols'][$sym]===$sector) {
                    $sectorUsed+=$o['filled']?$o['quantity']*max($o['plan']['entry'],$s['marks'][$sym]['close']??0):$o['reservation'];
                }
            }
            $risk=max(0,min($equity*$cfg['risk_pct'],$equity*$cfg['total_risk_pct']-$riskUsed));
            $capacity=max(0,min($s['cash']-self::reserved($s),$equity*$cfg['position_pct'],$equity*$cfg['sector_pct']-$sectorUsed));
            $unitRisk=$entry*1.001-$stop*0.9995*0.999;
            $qty=(int)floor(min($risk/$unitRisk,$capacity/($entry*1.001)));
            if($qty<1) {
                $emit('decision',['symbol'=>$symbol,'session'=>$session,'status'=>'no_order','reason'=>'cash_or_risk_or_sector_limit']);continue;
            }
            $s['active'][$symbol]=['plan'=>$p,'history'=>[],'quantity'=>$qty,'reservation'=>$qty*$entry*1.001,
                'planned_risk'=>$qty*$unitRisk,'filled'=>false,'entry_fill'=>null,'entry_cost'=>null,
                'recorded_at'=>$snapshot['recorded_at'],'snapshot_key'=>$key];
            $emit('order',['symbol'=>$symbol,'session'=>$session,'quantity'=>$qty,'entry'=>$entry,'stop'=>$stop,
                'target'=>$target,'planned_risk'=>$qty*$unitRisk,'reserved_cash'=>$qty*$entry*1.001]);
        }
        $equity=self::equity($s);
        // Stale marks are estimates; do not use them to update measured drawdown.
        $drawdown=null;
        if($stale===[]) {
            $s['peak_equity']=max($s['peak_equity'],$equity);
            $drawdown=1-$equity/$s['peak_equity'];$s['max_drawdown']=max($s['max_drawdown'],$drawdown);
        }
        $point=['session'=>$session,'equity'=>$equity,'cash'=>$s['cash'],'reserved_cash'=>self::reserved($s),
            'unrealized'=>$equity-$cfg['initial_cash']-$s['realized'],'realized'=>$s['realized'],
            'drawdown'=>$drawdown,'stale_positions'=>$stale];
        $s['equity'][]=$point;$emit('equity',$point);$s['last_session']=$session;
    }
}
