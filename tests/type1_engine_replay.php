<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/docs/research/Type1EngineReplay.php';
$checks=0;
function check(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function fixture(array $changes=[],string $sym='000001.KS'):array{
    $start=new DateTimeImmutable('2024-11-02');$rows=[];$days=[];
    for($i=0;$i<80;$i++){
        $day=$start->modify('+'.$i.' days')->format('Y-m-d');
        $row=['time_kst'=>$day.' 09:00:00','open'=>100,'high'=>101,'low'=>99,'close'=>100,'volume'=>100,'is_complete'=>true];
        if(isset($changes[$i]))$row=array_replace($row,$changes[$i]);
        if($i>=59)$days[]=$day;
        if(($changes[$i]['remove']??false)!==true)$rows[]=$row;
    }
    $sig=['symbol'=>$sym,'anchor'=>$days[0],'cluster'=>1,'entry'=>100,'stop'=>95,'target'=>115,'end'=>end($days)];
    return [['days'=>$days,'signals'=>[$sig],'sectors'=>[$sym=>'test'],'hashes'=>['bars'=>[$sym=>'fixture']],
        'price_basis'=>'fixture'],[$sym=>$rows]];
}
function runFixture(array $changes=[],?array $limits=null):array{
    [$input,$data]=fixture($changes);
    $cfg=json_decode(file_get_contents(dirname(__DIR__).'/config/paper-kr-recovery-v1.json'),true);
    $cfg['id']='research-type1-engine-fixture';if($limits)$cfg=array_replace($cfg,$limits);
    return Type1EngineReplay::run($input,$cfg,'type1',fn($sym)=>$data[$sym]);
}
$r=runFixture();
check($r['status']==='completed','time exit completes');
check($r['counts']['order']===1&&$r['counts']['fill']===1&&$r['counts']['exit']===1,'one lifecycle');
check($r['trades'][0]['anchor']!==$r['trades'][0]['entry_day'],'no same-day fill');
check($r['trades'][0]['exit']['reason']==='time','20-bar expiry');
check($r['trades'][0]['exit_day']===$r['last_good_day'],'fixed T+20');
check($r['max_reserved_cash']>0&&$r['max_pending_at_close']===1,'actual reservation recorded');
$r=runFixture([60=>['open'=>110,'low'=>109,'high'=>111,'close'=>110]]);
check(($r['counts']['fill']??0)===0&&$r['cancelled']['unfilled']===1,'one-day unfilled order expires');
$r=runFixture([60=>['high'=>116]]);
check(($r['counts']['fill']??0)===0&&$r['cancelled']['cancelled_before_entry']===1,'target before fill cancels');
$r=runFixture([60=>['open'=>94,'low'=>93,'high'=>96,'close'=>95]]);
check(($r['counts']['fill']??0)===0,'opening below stop cancels before entry');
$r=runFixture([60=>['open'=>98,'low'=>97,'high'=>101,'close'=>100]]);
check(abs($r['trades'][0]['fill']['price']-98.049)<.00001,'gap improvement with production slippage');
check($r['trades'][0]['actual_stop_pct']>-5,'actual stop differs from planned percentage');
$r=runFixture([61=>['open'=>90,'low'=>89,'high'=>91,'close'=>90]]);
check($r['trades'][0]['exit']['reason']==='stop','later gap stop');
check(abs($r['trades'][0]['exit']['price']-89.955)<.00001,'stop slippage unchanged');
$r=runFixture([61=>['open'=>116,'low'=>94,'high'=>117,'close'=>100]]);
check($r['trades'][0]['exit']['reason']==='target','later opening above target precedes intraday stop');
$r=runFixture([61=>['remove'=>true]]);
check($r['status']==='halted'&&$r['return_pct']===null,'missing held price halts, no full return');
check($r['state']['halt_reason']==='unpriced_position','operating halt rule');
$r=runFixture([60=>['remove'=>true]]);
check($r['status']==='completed'&&$r['cancelled']['data_quality']===1,'missing pending price cancels');
$r=runFixture([61=>['high'=>90]]);
check($r['status']==='halted','invalid active OHLC halts via PaperQuality');
check(($r['counts']['fill']??0)===1,'future invalid bar does not block earlier order');
[$input,$data]=fixture();$second=$input['signals'][0];$second['symbol']='000002.KS';$input['signals'][]=$second;
$input['sectors']['000002.KS']='test';$input['hashes']['bars']['000002.KS']='fixture';$data['000002.KS']=$data['000001.KS'];
$cfg=json_decode(file_get_contents(dirname(__DIR__).'/config/paper-kr-recovery-v1.json'),true);$cfg['id']='research-type1-engine-limit';$cfg['max_positions']=1;
$r=Type1EngineReplay::run($input,$cfg,'all_types',fn($sym)=>$data[$sym]);
check($r['counts']['order']===1&&$r['blocked']['position_limit']===1,'pending order uses position slot');
check($r['audit']['cash_reconciled']&&$r['audit']['order_limits_pass'],'cash and reservation limits reconcile');
echo "OK $checks checks\n";
