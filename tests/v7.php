<?php
declare(strict_types=1);
require __DIR__.'/v6.php';
use ChartEntryLab\PaperJournal;
use ChartEntryLab\PaperPortfolio;
use ChartEntryLab\PaperQuality;

$cfg=['id'=>'test','currency'=>'USD','initial_cash'=>1000,'max_positions'=>2,
 'risk_pct'=>0.02,'position_pct'=>0.5,'sector_pct'=>0.6,'total_risk_pct'=>0.06,
 'symbols'=>['AA'=>'one','BB'=>'two','CC'=>'three']];
$days=[strtotime('2025-01-06 21:00 UTC'),strtotime('2025-01-07 21:00 UTC'),strtotime('2025-01-08 21:00 UTC')];
$fixture=function($date,$ready=true,$origin='replay',$recorded=0)use($cfg) {
    $xs=[];foreach($cfg['symbols'] as $symbol=>$sector)$xs[$symbol]=[
      'symbol'=>$symbol,'session'=>$date,'recorded_at'=>$recorded?:$date,'origin'=>$origin,
      'input_hash'=>hash('sha256',$symbol.$date),'plan'=>['ready'=>$ready,'status'=>$ready?'ready':'wait',
       'entry'=>100,'stop'=>95,'target'=>110,'signal_at'=>$date,'order_valid_bars'=>3],
      'quality'=>['can_simulate'=>true,'status'=>'warning']];
    return $xs;
};
$barsFor=function($date,$low=99,$close=103)use($cfg){
 $b=[];foreach($cfg['symbols'] as $symbol=>$sector)$b[$symbol]=['available_at'=>$date,'open'=>100,'high'=>105,'low'=>$low,'close'=>$close];return $b;};
$events=[];$emit=function($type,$payload)use(&$events){$events[]=[$type,$payload];};
$s=PaperPortfolio::start($cfg,'v1','replay');
PaperPortfolio::advance($s,$days[0],$barsFor($days[0]),$fixture($days[0]),$emit);
check(count($s['active'])===2,'Simultaneous position/reservation limit enforced');
check(PaperPortfolio::reserved($s)<=$s['cash'] && $s['cash']===1000.0,'Pending orders reserve cash without spending twice');
$frozen=$s['frozen'];
PaperPortfolio::advance($s,$days[1],$barsFor($days[1]),$fixture($days[1],false),$emit);
check(count(array_filter($s['active'],fn($o)=>$o['filled']))===2,'Only later session fills pending orders');
check(abs($s['cash']-399.4)<0.001,'Fill deducts principal and fee exactly once');
check(PaperPortfolio::equity($s)>1000,'Mark-to-market includes unrealized gains');
$before=PaperJournal::encode($s);$eventCount=count($events);
PaperPortfolio::advance($s,$days[1],$barsFor($days[1]),$fixture($days[1],false),$emit);
check($before===PaperJournal::encode($s) && count($events)===$eventCount,'Repeated session is idempotent');
PaperPortfolio::advance($s,$days[2],$barsFor($days[2],94,95),$fixture($days[2],false),$emit);
check($s['closed_trades']===2 && $s['cash']<1000 && $s['realized']<0,'Stops realize losses with fees');
check($s['max_drawdown']>0,'Daily equity drawdown includes previous unrealized peak');
foreach($frozen as $key=>$value) check($s['frozen'][$key]===$value,'Older snapshot fingerprint never changes');
$sectorCfg=$cfg;$sectorCfg['sector_pct']=0.3;$sectorCfg['symbols']=['AA'=>'one','BB'=>'one','CC'=>'one'];
$sectorState=PaperPortfolio::start($sectorCfg,'v1','replay');
PaperPortfolio::advance($sectorState,$days[0],$barsFor($days[0]),$fixture($days[0]),$emit);
check(count($sectorState['active'])===1,'Sector cap includes pending order reservations');
$forward=PaperPortfolio::start($cfg,'v1','forward');
$captured=strtotime('2025-01-07 16:00 UTC');
PaperPortfolio::advance($forward,$days[0],$barsFor($days[0]),$fixture($days[0],true,'forward',$captured),$emit);
PaperPortfolio::advance($forward,$days[1],$barsFor($days[1]),$fixture($days[1],false,'forward',$captured),$emit);
check(count(array_filter($forward['active'],fn($o)=>$o['filled']))===0,'Capture after market open cannot use that sessions earlier prices');
PaperPortfolio::advance($forward,$days[2],$barsFor($days[2]),$fixture($days[2],false,'forward',$captured),$emit);
check(count(array_filter($forward['active'],fn($o)=>$o['filled']))===2,'First full future session may fill');
$catchup=PaperPortfolio::start($cfg,'v1','forward');
PaperPortfolio::advance($catchup,$days[0],$barsFor($days[0]),$fixture($days[0],true,'catchup'),$emit);
check($catchup['active']===[],'Retrospective catchup signal cannot create an order');
$activeBeforeMissing=PaperJournal::encode($forward['active']);
$missing=$fixture($days[2]+86400,false);$missing['AA']['quality']['can_simulate']=false;
PaperPortfolio::advance($forward,$days[2]+86400,[],$missing,$emit);
check(PaperJournal::encode($forward['active'])===$activeBeforeMissing,'Missing bars cannot mutate another position through shared references');
check(end($forward['equity'])['drawdown']===null && count(end($forward['equity'])['stale_positions'])===2,'Missing position marks produce estimated equity, not measured drawdown');
$q=PaperQuality::inspect($trendBars,'005930.KS',end($trendBars)['available_at'],[]);
check($q['can_simulate'] && in_array('corporate_action_adjustment_unverified',$q['warnings'],true),'Adjustment uncertainty is preserved as a warning');
$bad=$trendBars;$bad[count($bad)-1]['high']=1;
check(!PaperQuality::inspect($bad,'005930.KS',end($trendBars)['available_at'],[])['can_simulate'],'Recent invalid candle blocks simulated trading');
$dir=sys_get_temp_dir().'/paper-test-'.bin2hex(random_bytes(5));$file=$dir.'/account.json';
$archive=PaperJournal::archive($dir.'/inputs',hash('sha256','[]'),'[]');
check(file_get_contents($archive)==='[]','Source input is archived by content hash');
check(PaperJournal::archive($dir.'/inputs',hash('sha256','[]'),'[]')===$archive,'Identical input reuses the same archive');
$j=new PaperJournal($file);
$j->transact(function(&$state,$append){$state=['x'=>1];$append('snapshot',['value'=>100]);});
$original=file_get_contents($file);
try {$j->transact(function(&$state,$append){$state['x']=2;$append('bad',[]);throw new RuntimeException('abort');});}catch(RuntimeException $e){}
check(file_get_contents($file)===$original,'Failed transaction preserves old state and history');
$j->transact(function(&$state,$append){$state['x']=2;$append('next',[]);});
check(count($j->read()['events'])===2,'Journal hash chain survives update');
$tampered=json_decode(file_get_contents($file),true);$tampered['events'][0]['payload']['value']=99;file_put_contents($file,json_encode($tampered));
$detected=false;try{$j->read();}catch(RuntimeException $e){$detected=true;}
check($detected,'Journal detects modified historical payload');
unlink($file);unlink($file.'.lock');unlink($archive);rmdir($dir.'/inputs');rmdir($dir);
echo 'V7 PASS '.$checks.' total checks'.PHP_EOL;
