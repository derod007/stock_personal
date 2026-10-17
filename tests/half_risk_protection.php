<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
use ChartEntryLab\TradeSimulator;
function hp(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);echo "PASS $why\n";}
function hb(int $t,float $open,float $high,float $low,float $close):array{return ['available_at'=>$t,'open'=>$open,'high'=>$high,'low'=>$low,'close'=>$close,'volume'=>1000];}
$p=['ready'=>true,'signal_at'=>1,'entry'=>100,'stop'=>88,'target'=>130,'order_valid_bars'=>3];
$protected=$p+['protection_mode'=>'half_risk_v1'];$sim=new TradeSimulator();
$b=[hb(2,100,114,90,113),hb(3,100,111,93,100)];
$a=$sim->simulate($protected,[$b[0]],20);
hp($a['status']==='incomplete'&&$a['protection']['trigger_at']===2&&$a['protection']['active_at']===null,'trigger close arms only, no same-bar stop');
$c=$sim->simulate($protected,$b,20);
hp($c['first_exit']==='stop'&&abs($c['exit_fill']-94*.9995)<1e-6&&$c['exit_at']===3,'next session half-risk stop');
hp($c['protection']['trigger_close']===113.0&&$c['protection']['active_at']===3&&$c['protection']['exit_reason']==='half_risk_stop','complete audit metadata');
$base=$sim->simulate($p,$b,20);hp($base['status']==='incomplete'&&!isset($base['protection']),'old execution defaults unchanged');
$intraday=$sim->simulate($protected,[hb(2,100,120,90,110),hb(3,100,111,93,100)],20);hp($intraday['status']==='incomplete'&&$intraday['protection']['trigger_at']===null,'high alone never activates');
$exact=$sim->simulate($protected,[hb(2,100,114,90,112)],20);hp($exact['protection']['trigger_at']===2,'inclusive one-R close threshold');
$gap=$sim->simulate($protected,[$b[0],hb(3,90,99,89,95)],20);hp(abs($gap['exit_fill']-90*.9995)<1e-6,'gap executes at worse open');
$target=$sim->simulate($protected,[$b[0],hb(3,131,135,90,100)],20);hp($target['first_exit']==='target'&&$target['exit_fill']===130.0,'opening target gap keeps original priority');
$tie=$sim->simulate($protected,[$b[0],hb(3,100,132,93,120)],20);hp($tie['first_exit']==='stop'&&$tie['ambiguous_bar'],'intrabar stop wins tie');
$discount=$sim->simulate($protected,[hb(2,95,110,90,108)],20);$fill=95*1.0005;
hp(abs($discount['protection']['initial_risk']-($fill-88))<1e-9&&abs($discount['protection']['trigger_price']-(2*$fill-88))<1e-9,'actual fill is risk basis, not plan entry');
$time=$sim->simulate($protected,[hb(2,100,114,90,113)],1);hp($time['first_exit']==='time'&&$time['protection']['trigger_at']===null,'time exit precedes arming');
$stop=$sim->simulate($protected,[hb(2,100,114,87,113)],20);hp($stop['first_exit']==='stop'&&$stop['protection']['trigger_at']===null,'original stop exits before arming');
try{$sim->simulate($protected+['exit_mode'=>'trailing','trail_distance'=>5],$b,20);throw new RuntimeException('conflict accepted');}catch(InvalidArgumentException $e){hp(true,'conflicting trailing mode refused');}
echo "HALF_RISK_PROTECTION_PASS\n";
// Saved prices and fixed fills from the audited research; does not execute an account.
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/half-risk-56.json'),true,512,JSON_THROW_ON_ERROR);
$delta=0.0;$better=0;$worse=0;
foreach($fixture['cases'] as $case){
    $base=$sim->simulate($case['plan'],$case['bars'],20);
    $alt=$sim->simulate($case['plan']+['protection_mode'=>'half_risk_v1'],$case['bars'],20);
    $q=$case['quantity'];$basePnl=$q*($base['exit_fill']*.999-$base['entry_fill']*1.001);
    $altPnl=$q*($alt['exit_fill']*.999-$alt['entry_fill']*1.001);
    if($base['exit_at']!==$case['baseline']['exit_at']||abs($base['exit_fill']-$case['baseline']['exit_fill'])>1e-6
        ||$alt['exit_at']!==(new DateTimeImmutable($case['protected']['exit_date'].' 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp()
        ||abs($basePnl-$case['baseline']['net_pnl'])>.01
        ||abs($altPnl-$case['protected']['net_pnl'])>.01)throw new RuntimeException('Research parity failed '.$case['symbol']);
    $diff=$altPnl-$basePnl;$delta+=$diff;$better+=(int)($diff>.01);$worse+=(int)($diff<-.01);
}
hp(count($fixture['cases'])===56&&$better===4&&$worse===0&&abs($delta-1583408.8378058672)<.01,'production simulator matches all 56 audited trades and research delta');
