<?php
declare(strict_types=1);
use ChartEntryLab\{PaperJournal,PaperPortfolio,TradeSimulator};

/**
 * Research-only cash fee overlay. Not part of the operational strategy fingerprint.
 * Default 10bp delegates to PaperPortfolio. Other cash fees stay out of src/PaperPortfolio.php.
 */
final class ResearchCashFee
{
    public const DEFAULT_FEE_BPS=10.0;
    public const SLIP_BPS=5.0;

    public static function feeBps(array $config): float
    {
        $bps=$config['fee_bps']??self::DEFAULT_FEE_BPS;
        if(!is_numeric($bps)||!is_finite((float)$bps)||(float)$bps<0||(float)$bps>=10000){
            throw new InvalidArgumentException('Invalid fee_bps');
        }
        return (float)$bps;
    }

    public static function feeRate(array $config): float
    {
        return self::feeBps($config)/10000;
    }

    public static function isDefault(array $config): bool
    {
        return abs(self::feeBps($config)-self::DEFAULT_FEE_BPS)<1e-12;
    }

    public static function hashes(string $root): array
    {
        $files=['bin/paper/ResearchCashFee.php','bin/paper/AccountReplay.php','bin/paper_account_replay.php'];
        $out=[];
        foreach($files as $p)$out[$p]=PaperStrategyVersion::fileHash($root.'/'.$p);
        return $out;
    }

    public static function advance(array &$s,int $session,array $bars,array $snapshots,callable $emit): void
    {
        if(self::isDefault($s['config']??[])){
            PaperPortfolio::advance($s,$session,$bars,$snapshots,$emit);
            return;
        }
        self::advanceWithFee($s,$session,$bars,$snapshots,$emit);
    }

    private static function advanceWithFee(array &$s,int $session,array $bars,array $snapshots,callable $emit): void
    {
        if($session<=$s['last_session'] || !empty($s['halted'])) return;
        $cfg=$s['config'];ksort($snapshots);
        $fee=self::feeRate($cfg);$slip=self::SLIP_BPS/10000;
        foreach(array_keys($s['active']) as $symbol) {
            $o=&$s['active'][$symbol];
            if(!isset($bars[$symbol]) || empty($snapshots[$symbol]['quality']['can_simulate'])) {
                if($o['filled'] && empty($s['halted'])) {
                    $s['halted']=true;$s['halt_reason']='unpriced_position';
                    $emit('account_halted',['symbol'=>$symbol,'session'=>$session,'reason'=>'unpriced_position','action'=>'do_not_infer_missing_execution_path']);
                }
                if(!$o['filled']) {
                    $emit('order_cancelled',['symbol'=>$symbol,'session'=>$session,'reason'=>'data_quality']);
                    unset($s['active'][$symbol]);unset($o);
                }
                unset($o);
                continue;
            }
            $bar=$bars[$symbol];
            if($s['mode']==='forward' && self::openTime($session,$cfg['currency'])<=$o['recorded_at']) { unset($o);continue; }
            $o['history'][]=$bar;
            $t=(new TradeSimulator($fee*10000,$slip*10000))->simulate($o['plan'],$o['history'],20);
            if($t['filled'] && !$o['filled']) {
                $cost=$o['quantity']*$t['entry_fill']*(1+$fee);
                if($cost>$s['cash']+0.01) throw new RuntimeException('Cash invariant failed');
                $s['cash']-=$cost;$o['filled']=true;$o['entry_fill']=$t['entry_fill'];$o['entry_cost']=$cost;
                $emit('fill',['symbol'=>$symbol,'session'=>$session,'quantity'=>$o['quantity'],'price'=>$t['entry_fill'],'fee'=>$o['quantity']*$t['entry_fill']*$fee]);
            }
            if($t['status']==='closed') {
                $proceeds=$o['quantity']*$t['exit_fill']*(1-$fee);
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
            if(isset($s['frozen'][$key])) throw new RuntimeException('Snapshot cannot be overwritten');
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
            $equity=PaperPortfolio::equity($s);$sector=self::sectorOf($s,$symbol);$sectorUsed=0.0;$riskUsed=0.0;
            foreach($s['active'] as $sym=>$o) {
                $riskUsed+=$o['planned_risk'];
                if(self::sectorOf($s,$sym)===$sector) {
                    $sectorUsed+=$o['filled']?max($o['reservation'],$o['quantity']*($s['marks'][$sym]['close']??0)):$o['reservation'];
                }
            }
            $risk=max(0,min($equity*$cfg['risk_pct'],$equity*$cfg['total_risk_pct']-$riskUsed));
            $capacity=max(0,min($s['cash']-PaperPortfolio::reserved($s),$equity*$cfg['position_pct'],$equity*$cfg['sector_pct']-$sectorUsed));
            $unitRisk=$entry*(1+$fee)-$stop*(1-$slip)*(1-$fee);
            $qty=(int)floor(min($risk/$unitRisk,$capacity/($entry*(1+$fee))));
            if($qty<1) {
                $emit('decision',['symbol'=>$symbol,'session'=>$session,'status'=>'no_order','reason'=>'cash_or_risk_or_sector_limit']);continue;
            }
            $s['active'][$symbol]=['plan'=>$p,'history'=>[],'quantity'=>$qty,'reservation'=>$qty*$entry*(1+$fee),
                'planned_risk'=>$qty*$unitRisk,'filled'=>false,'entry_fill'=>null,'entry_cost'=>null,
                'recorded_at'=>$snapshot['recorded_at'],'snapshot_key'=>$key];
            $emit('order',['symbol'=>$symbol,'session'=>$session,'quantity'=>$qty,'entry'=>$entry,'stop'=>$stop,
                'target'=>$target,'planned_risk'=>$qty*$unitRisk,'reserved_cash'=>$qty*$entry*(1+$fee)]);
        }
        $equity=PaperPortfolio::equity($s);
        $drawdown=null;
        if($stale===[]) {
            $s['peak_equity']=max($s['peak_equity'],$equity);
            $drawdown=1-$equity/$s['peak_equity'];$s['max_drawdown']=max($s['max_drawdown'],$drawdown);
        }
        $point=['session'=>$session,'equity'=>$equity,'cash'=>$s['cash'],'reserved_cash'=>PaperPortfolio::reserved($s),
            'unrealized'=>$equity-$cfg['initial_cash']-$s['realized'],'realized'=>$s['realized'],
            'drawdown'=>$drawdown,'stale_positions'=>$stale];
        $s['equity'][]=$point;$emit('equity',$point);$s['last_session']=$session;
    }

    private static function openTime(int $session,string $currency): int
    {
        $tz=new DateTimeZone($currency==='KRW'?'Asia/Seoul':'America/New_York');
        $day=(new DateTimeImmutable('@'.$session))->setTimezone($tz)->format('Y-m-d');
        return (new DateTimeImmutable($day.($currency==='KRW'?' 09:00:00':' 09:30:00'),$tz))->getTimestamp();
    }

    private static function sectorOf(array $s,string $symbol): string
    {
        $sector=($s['sectors']??[])[$symbol]??($s['config']['symbols']??[])[$symbol]??'unclassified';
        return $sector===''?'unclassified':$sector;
    }
}
