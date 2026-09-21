<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/Experiment.php';
require __DIR__.'/../bin/paper/EntryExperiment.php';
require __DIR__.'/../bin/paper/EntryGates.php';
use ChartEntryLab\PaperJournal;
use ChartEntryLab\CandleClock;
use ChartEntryLab\ChartPlanEngine;
function ck(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "OK $label\n";}
$bars=[];$start=new DateTimeImmutable('2025-10-06');
for($i=0;$i<60;$i++){
    $close=$i<30?50+$i:80+($i-30)*0.4;
    if($i>=55)$close=[88,86,84.5,84,85.5][$i-55];
    $open=$i===59?84.2:$close-0.2;
    $day=$start->modify('+'.$i.' weekdays')->format('Y-m-d');
    $at=(new DateTimeImmutable($day.' 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();
    $bars[]=['time'=>$at,'available_at'=>$at,'open'=>$open,'high'=>$close+0.8,'low'=>min($open,$close)-0.4,'close'=>$close,'volume'=>$i>=56&&$i<=58?900:($i===59?1400:1000)];
}
$at=end($bars)['available_at'];$analysis=(new ChartPlanEngine())->analyze($bars,'005930.KS',$at);
ck(PaperEntryRelaxation::onlyVolumeMissing($analysis['plan']),'real engine volume-only near miss');
$snapshot=['symbol'=>'005930.KS','session'=>$at,'recorded_at'=>$at+10,'origin'=>'forward','input_hash'=>hash('sha256',PaperJournal::encode($bars)),
    'plan'=>$analysis['plan'],'quality'=>['can_simulate'=>true,'status'=>'warning','reasons'=>[]]];
$changed=PaperEntryRelaxation::apply($snapshot,$bars);
ck(!$snapshot['plan']['ready'] && $changed['plan']['ready'],'real engine .90 volume confirms only candidate');
ck($changed['quality']===$snapshot['quality'] && $changed['input_hash']===$snapshot['input_hash'],'quality and input unchanged');
$future=$bars;$future[]=array_replace(end($bars),['time'=>$at+86400,'available_at'=>$at+86400,'close'=>999]);
ck(PaperEntryRelaxation::apply($snapshot,$future)===$changed,'future candle cannot affect candidate');
$bad=$bars;$bad[0]['close']+=1;
try{PaperEntryRelaxation::apply($snapshot,$bad);ck(false,'tampered history');}catch(RuntimeException $e){ck(true,'tampered history rejected');}
foreach(['risk_blocked','stale_data','blocked'] as $status){$a=$analysis;$a['plan']['status']=$status;ck(PaperEntryRelaxation::fromAnalysis($a,$bars)===$a['plan'],'guard '.$status);}
foreach(PaperEntryRelaxation::GATES as $gate){if($gate==='volume_contracted')continue;$a=$analysis;$a['plan']['diagnostics']['patterns']['trend_pullback']['gates'][$gate]=false;ck(PaperEntryRelaxation::fromAnalysis($a,$bars)===$a['plan'],'other gate stays required '.$gate);}
$a=$analysis;$a['plan']['context']['daily']='down';ck(PaperEntryRelaxation::fromAnalysis($a,$bars)===$a['plan'],'daily down blocked');
$a=$analysis;$a['plan']['context']=['daily'=>'flat','weekly'=>'down'];ck(PaperEntryRelaxation::fromAnalysis($a,$bars)===$a['plan'],'weekly down blocked');
$highVol=$bars;for($i=56;$i<=58;$i++)$highVol[$i]['volume']=1000;
ck(PaperEntryRelaxation::fromAnalysis($analysis,$highVol)===$analysis['plan'],'no contraction still blocked');
$lowRR=$bars;$lowRR[59]['close']=100;ck(PaperEntryRelaxation::fromAnalysis($analysis,$lowRR)===$analysis['plan'],'poor reward risk blocked');
$missing=$snapshot;$missing['plan']['diagnostics']=[];
$catchup=$snapshot;$catchup['origin']='catchup';
$r=PaperEntryGates::summarize([['type'=>'snapshot','payload'=>$snapshot],['type'=>'snapshot','payload'=>$missing],['type'=>'snapshot','payload'=>$catchup]]);
ck($r['snapshots']===2 && $r['volume_only']===1 && $r['gates']['volume_contracted']['unknown']===1 && $r['gates']['volume_contracted']['only_failure']===1,'unknown distinct from failure and catchup separated');
$cfg=['id'=>'research-fixture','currency'=>'KRW','initial_cash'=>100000,'max_positions'=>4,'risk_pct'=>0.01,'position_pct'=>0.2,'sector_pct'=>0.4,'total_risk_pct'=>0.03,'symbols'=>['005930.KS'=>'semi'],'universe'=>['version'=>1]];
$source=['state'=>['mode'=>'replay','config'=>$cfg,'version'=>'v','history'=>['005930.KS'=>$bars],'last_session'=>$at],
    'events'=>[['type'=>'snapshot','payload'=>array_replace($snapshot,['origin'=>'replay']),'hash'=>'h1']]];
$def=['id'=>'volume','source'=>'research-fixture','mode'=>'replay','candidate'=>['pullback_volume_ratio'=>0.95]];
$ev=[];$emit=function($type,$payload)use(&$ev){$ev[]=['type'=>$type,'payload'=>$payload];};$before=serialize($source);
$pair=PaperEntryExperiment::update(null,$source,$def,$at+10,$emit);
ck(count($pair['baseline']['active'])===0 && count($pair['candidate']['active'])===1,'shared replay creates candidate order only');
ck(serialize($source)===$before,'source immutable');
ck(PaperEntryExperiment::update($pair,$source,$def,$at+20,$emit)===$pair,'repeat idempotent');
$id=$def;$id['candidate']=[];$identity=PaperEntryExperiment::update(null,$source,$id,$at+10,$emit);
ck($identity['baseline']===$identity['candidate'],'identity arm states equal');
try{PaperEntryExperiment::update($pair,$source,$id,$at,$emit);ck(false,'changed definition');}catch(RuntimeException $e){ck(true,'definition change rejected');}
$halt=$source;$halt['state']['halted']=true;try{PaperEntryExperiment::update($pair,$halt,$def,$at,$emit);ck(false,'halt');}catch(RuntimeException $e){ck(true,'source halt blocks experiment');}
$quality=$source;$quality['events'][0]['payload']['quality']['can_simulate']=false;
$q=PaperEntryExperiment::update(null,$quality,$def,$at+10,$emit);ck($q['candidate']['active']===[],'quality blocked cannot order even if relaxed signal ready');
// Next completed session can fill; signal session cannot. Both arms retain shared risk limits.
$next=$at+86400;$b=['time'=>$next,'available_at'=>$next,'open'=>85,'high'=>86,'low'=>84,'close'=>85,'volume'=>1000];
$source['state']['history']['005930.KS'][]=$b;$source['state']['last_session']=$next;
$p=$source['events'][0]['payload'];$p['session']=$next;$p['plan']=['ready'=>false,'status'=>'no_setup'];$p['input_hash']='next';
$source['events'][]=['type'=>'snapshot','payload'=>$p,'hash'=>'h2'];
$pair=PaperEntryExperiment::update($pair,$source,$def,$next+10,$emit);
ck($pair['candidate']['active']['005930.KS']['filled'] && $pair['baseline']['active']===[],'next bar fills only candidate');
$forward=$source;$forward['state']['mode']='forward';foreach($forward['events'] as &$e)$e['payload']['origin']='forward';unset($e);$fd=$def;$fd['mode']='forward';
$f=PaperEntryExperiment::update(null,$forward,$fd,$next+10,$emit);ck($f['sessions']===1 && $f['candidate']['active']===[],'forward first run does not buy old signals');
echo "ENTRY_PASS\n";
