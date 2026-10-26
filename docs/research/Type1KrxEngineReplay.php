<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bin/bootstrap.php';
use ChartEntryLab\{PaperPortfolio, PaperQuality, CandleClock};

/** Research adapter only: no replacement of operating execution or quality rules. */
final class Type1KrxEngineReplay
{
    public static function timestamp(string $day):int
    {return (new DateTimeImmutable($day.' 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();}

    public static function usage(array $s,string $symbol):array
    {
        $sector=$s['sectors'][$symbol]??'unclassified';$used=0.;$risk=0.;$held=0;$pending=0;
        foreach($s['active'] as $sym=>$o){
            $risk+=$o['planned_risk'];
            if($o['filled'])$held++;else $pending++;
            if(($s['sectors'][$sym]??'unclassified')===$sector)
                $used+=$o['filled']?max($o['reservation'],$o['quantity']*($s['marks'][$sym]['close']??0)):$o['reservation'];
        }
        return ['cash'=>$s['cash'],'reserved_cash'=>PaperPortfolio::reserved($s),
            'equity'=>PaperPortfolio::equity($s),'held'=>$held,'pending'=>$pending,
            'risk_used'=>$risk,'sector'=>$sector,'sector_used'=>$used];
    }

    /** Row loader returns the original raw rows, including invalid observations. */
    public static function run(array $input,array $config,string $arm,callable $loader):array
    {
        if(!in_array($arm,['all_types','type1'],true))throw new InvalidArgumentException('Invalid arm');
        if(!str_starts_with($config['id']??'','research-type1-engine-'))throw new InvalidArgumentException('Research ID required');
        if(($input['price_basis']??'')!=='krx_tdd_ohlcv_adjustment_unverified')throw new InvalidArgumentException('KRX input required');
        $s=PaperPortfolio::start($config,'type1_engine_v1','replay');
        $s['sectors']=$input['sectors'];
        $byDay=[];$events=[];$trades=[];$orders=[];$counts=[];$quality=[];$cache=[];
        $maxHeld=0;$maxPending=0;$maxReserved=0.;$lastGood=null;
        foreach($input['signals'] as $sig){
            if($arm==='type1'&&$sig['cluster']!==1)continue;
            $byDay[$sig['anchor']][$sig['symbol']]=$sig;
        }
        foreach($input['days'] as $day){
            $affected=[];
            foreach(array_keys($s['active']) as $sym)if(in_array($day,$input['reference_events'][$sym]??[],true))$affected[]=$sym;
            if($affected!==[]){
                $s['halted']=['reason'=>'unresolved_reference_discontinuity','day'=>$day,'symbols'=>$affected];
                $events[]=['day'=>$day,'type'=>'research_halt','payload'=>$s['halted']];
                break;
            }
            $ts=self::timestamp($day);$signals=$byDay[$day]??[];$snapshots=[];$barSet=[];
            $needed=array_unique(array_merge(array_keys($s['active']),array_keys($signals)));sort($needed);
            foreach($needed as $sym){
                if(!isset($cache[$sym]))$cache[$sym]=$loader($sym);
                $past=[];foreach($cache[$sym] as $bar)if(CandleClock::closeTime($bar,$sym)<=$ts)$past[]=$bar;
                $source=['sha256'=>$input['hashes']['bars'][$sym]??null,'price_basis'=>$input['price_basis']];
                $q=PaperQuality::inspect($past,$sym,$ts,$source);
                $completed=CandleClock::completed($past,$sym,$ts);
                $last=$completed===[]?null:end($completed);
                if($last!==null&&$last['available_at']===$ts)$barSet[$sym]=$last;
                $sig=$signals[$sym]??null;
                $plan=$sig===null?['ready'=>false]:['ready'=>true,'pattern'=>'frozen_shape_'.$sig['cluster'],
                    'signal_at'=>$ts,'entry'=>$sig['entry'],'stop'=>$sig['stop'],'target'=>$sig['target'],
                    'order_valid_bars'=>1,'exit_mode'=>'fixed','protection_mode'=>'none'];
                $snapshots[$sym]=['symbol'=>$sym,'session'=>$ts,'recorded_at'=>$ts,'origin'=>'replay',
                    'input_hash'=>hash('sha256',json_encode($past,JSON_THROW_ON_ERROR)),
                    'plan'=>$plan,'quality'=>$q];
                if(!$q['can_simulate'])$quality[]=['day'=>$day,'symbol'=>$sym,'reasons'=>$q['reasons'],
                    'active'=>isset($s['active'][$sym]),'filled'=>$s['active'][$sym]['filled']??false,'signal'=>$sig!==null];
            }
            $before=[];
            $emit=function(string $type,array $p)use(&$s,&$events,&$counts,&$orders,&$trades,&$before,$day):void{
                if($type==='snapshot'){
                    if(!empty($p['plan']['ready']))$before[$p['symbol']]=self::usage($s,$p['symbol']);
                    return;
                }
                if($type==='decision'&&!isset($before[$p['symbol']]))return;
                if(in_array($type,['order','decision'],true))$p['before']=$before[$p['symbol']]??null;
                $events[]=['day'=>$day,'type'=>$type,'payload'=>$p];
                $counts[$type]=($counts[$type]??0)+1;
                if($type==='order')$orders[$p['symbol']]=['symbol'=>$p['symbol'],'anchor'=>$day,'order'=>$p];
                if($type==='fill'){
                    $orders[$p['symbol']]['fill']=$p;$orders[$p['symbol']]['entry_day']=$day;
                    $orders[$p['symbol']]['entry_cost']=$p['quantity']*$p['price']+$p['fee'];
                }
                if($type==='exit'){
                    $t=$orders[$p['symbol']];$t['exit']=$p;$t['exit_day']=$day;
                    $t['net_pct']=$p['net_pnl']/$t['entry_cost']*100;
                    $t['actual_stop_pct']=($t['order']['stop']/$t['fill']['price']-1)*100;
                    $t['actual_target_pct']=($t['order']['target']/$t['fill']['price']-1)*100;
                    $trades[]=$t;unset($orders[$p['symbol']]);
                }
                if($type==='order_cancelled')unset($orders[$p['symbol']]);
            };
            // The actual operating engine, including reservations, fills, stops and quality handling.
            PaperPortfolio::advance($s,$ts,$barSet,$snapshots,$emit);
            $u=self::usage($s,'');$maxHeld=max($maxHeld,$u['held']);$maxPending=max($maxPending,$u['pending']);
            $maxReserved=max($maxReserved,$u['reserved_cash']);
            if(!empty($s['halted']))break;
            $lastGood=$day;
            // Bound memory; re-read immutable source when a later independent episode returns.
            foreach(array_keys($cache) as $sym)if(!isset($s['active'][$sym]))unset($cache[$sym]);
        }
        $complete=empty($s['halted'])&&$s['active']===[];
        $blocked=[];$cancelled=[];
        foreach($events as $e){
            if($e['type']==='decision'){$r=$e['payload']['reason'];$blocked[$r]=($blocked[$r]??0)+1;}
            if($e['type']==='order_cancelled'){$r=$e['payload']['reason'];$cancelled[$r]=($cancelled[$r]??0)+1;}
        }
        $cash=$config['initial_cash'];$realized=0.;
        foreach($events as $e){$p=$e['payload'];
            if($e['type']==='fill')$cash-=$p['quantity']*$p['price']+$p['fee'];
            if($e['type']==='exit'){$cash+=$p['quantity']*$p['price']*.999;$realized+=$p['net_pnl'];}
        }
        if(abs($cash-$s['cash'])>.01||abs($realized-$s['realized'])>.01)throw new RuntimeException('Cash reconciliation failed');
        $limitErrors=[];
        foreach($events as $e)if($e['type']==='order'){
            $p=$e['payload'];$b=$p['before'];$eq=$b['equity'];
            if($b['held']+$b['pending']>=$config['max_positions']||
                $p['reserved_cash']>$b['cash']-$b['reserved_cash']+.01||
                $p['reserved_cash']>$eq*$config['position_pct']+.01||
                $p['reserved_cash']+$b['sector_used']>$eq*$config['sector_pct']+.01||
                $p['planned_risk']>$eq*$config['risk_pct']+.01||
                $p['planned_risk']+$b['risk_used']>$eq*$config['total_risk_pct']+.01)$limitErrors[]=$p['symbol'];
        }
        if($limitErrors!==[])throw new RuntimeException('Order limit reconciliation failed');
        return ['arm'=>$arm,'research_id'=>$config['id'],'status'=>$complete?'completed':(!empty($s['halted'])?'halted':'open_at_end'),
            'last_good_day'=>$lastGood,'return_pct'=>$complete?($s['cash']/$config['initial_cash']-1)*100:null,
            'max_drawdown_pct'=>$s['max_drawdown']*100,'counts'=>$counts,'blocked'=>$blocked,'cancelled'=>$cancelled,
            'max_held_at_close'=>$maxHeld,'max_pending_at_close'=>$maxPending,'max_reserved_cash'=>$maxReserved,
            'audit'=>['cash_reconciled'=>true,'order_limits_pass'=>true],
            'quality'=>$quality,'trades'=>$trades,'events'=>$events,'state'=>$s];
    }
}
