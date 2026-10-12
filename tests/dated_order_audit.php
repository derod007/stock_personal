<?php
declare(strict_types=1);
require __DIR__.'/../bin/paper/DatedOrderAudit.php';
require __DIR__.'/fixtures/trend_recovery_bars.php';
function auditCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$symbol='005930.KS';$bars=recovery_bars();$ts=end($bars)['available_at'];
$info=['rows'=>$bars,'bars_sha256'=>hash('sha256',json_encode($bars))];
$proof=PaperDatedOrderAudit::recompute($symbol,$ts,$info);
auditCheck($proof['plan']['ready']===true&&$proof['quality']['can_simulate']===true,'real engine fixture has a final recommendation and valid quality');
$order=['symbol'=>$symbol,'session'=>$ts];
foreach(PaperDatedOrderAudit::FIELDS as $f)$order[$f]=$proof['plan'][$f];
$signal=$order+['outcome'=>'order'];
$journal=['events'=>[['type'=>'order','payload'=>$order],['type'=>'day_log','payload'=>['session'=>$ts,'signals'=>[$signal]]]]];
$candidates=['by_session'=>[$ts=>['symbols'=>[$symbol]]]];$data=['symbols'=>[$symbol=>$info]];
auditCheck(PaperDatedOrderAudit::audit($journal,$candidates,$data)['pass'],'stored signal and real engine recomputation both match');
auditCheck(!PaperDatedOrderAudit::audit($journal,$candidates,null)['pass'],'missing dataset never passes');
$fake=['events'=>[['type'=>'order','payload'=>['symbol'=>$symbol,'session'=>$ts,'pattern'=>'invented']]]];
auditCheck(!PaperDatedOrderAudit::audit($fake,$candidates,$data)['pass'],'original pattern-only false positive is rejected');
auditCheck(PaperDatedOrderAudit::compare($order,[$signal],null,true)!==[],'missing engine evidence fails');
foreach(['ready','quality'] as $change){$p=$proof;if($change==='ready')$p['plan']['ready']=false;else $p['quality']['can_simulate']=false;
 auditCheck(PaperDatedOrderAudit::compare($order,[$signal],$p,true)!==[],$change.' false fails even with a matching pattern');}
foreach(PaperDatedOrderAudit::FIELDS as $f){
 $j=$journal;$j['events'][0]['payload'][$f]=$f==='pattern'?'invented':$order[$f]+1;
 auditCheck(!PaperDatedOrderAudit::audit($j,$candidates,$data)['pass'],'altered order '.$f.' fails');
 $j['events'][1]['payload']['signals'][0][$f]=$j['events'][0]['payload'][$f];
 auditCheck(!PaperDatedOrderAudit::audit($j,$candidates,$data)['pass'],'joint order/log alteration '.$f.' still fails engine check');
}
$j=$journal;unset($j['events'][1]);auditCheck(!PaperDatedOrderAudit::audit($j,$candidates,$data)['pass'],'missing day log fails');
$j=$journal;$j['events'][1]['payload']['signals']=[];auditCheck(!PaperDatedOrderAudit::audit($j,$candidates,$data)['pass'],'missing signal fails');
$j=$journal;$j['events'][1]['payload']['signals'][]=$signal;auditCheck(!PaperDatedOrderAudit::audit($j,$candidates,$data)['pass'],'duplicate signal fails');
$j=$journal;$j['events'][1]['payload']['signals'][0]['outcome']='position_limit';auditCheck(!PaperDatedOrderAudit::audit($j,$candidates,$data)['pass'],'non-order outcome fails');
$j=$journal;$j['events'][]=$j['events'][0];auditCheck(!PaperDatedOrderAudit::audit($j,$candidates,$data)['pass'],'duplicate order fails');
$j=$journal;$j['events'][0]['payload']['session']+=86400;auditCheck(!PaperDatedOrderAudit::audit($j,$candidates,$data)['pass'],'altered order date fails');
$c=$candidates;$c['by_session'][$ts]['symbols']=[];auditCheck(!PaperDatedOrderAudit::audit($journal,$c,$data)['pass'],'outside dated TOP100 fails');
$future=end($bars);$future['available_at']=$ts+86400;$future['close']=99999;$future['high']=99999;
$d=$data;$d['symbols'][$symbol]['rows'][]=$future;
auditCheck(PaperDatedOrderAudit::audit($journal,$candidates,$d)['pass'],'future spike does not affect the earlier audit');
$d=$data;$d['symbols'][$symbol]['rows']=array_slice($bars,0,20);auditCheck(!PaperDatedOrderAudit::audit($journal,$candidates,$d)['pass'],'insufficient actual history fails');
// Pattern can be ready while the common risk filter blocks the final plan.
$d=$data;$d['symbols'][$symbol]['rows'][48]['high']=125;
$p=PaperDatedOrderAudit::recompute($symbol,$ts,$d['symbols'][$symbol]);
auditCheck(!$p['plan']['ready'],'fixture common risk filter blocks final recommendation');
auditCheck(!PaperDatedOrderAudit::audit($journal,$candidates,$d)['pass'],'common risk block cannot be replaced by the stored pattern');
echo "OK dated order audit\n";
