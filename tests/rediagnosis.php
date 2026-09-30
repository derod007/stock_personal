<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/Rediagnosis.php';
require __DIR__.'/../bin/paper/RediagnosisPanel.php';
use ChartEntryLab\ChartPlanEngine;
function rdcheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "REDIAG PASS $label\n";}
$bars=[];$start=new DateTimeImmutable('2025-10-06');
for($i=0;$i<60;$i++){
 $close=$i<30?50+$i:80+($i-30)*0.4;if($i>=55)$close=[88,86,84.5,84,89.8][$i-55];
 $close+=1000;$open=$i===59?1084.2:$close-0.2;$day=$start->modify('+'.$i.' weekdays')->format('Y-m-d');
 $at=(new DateTimeImmutable($day.' 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();
 $bars[]=['time'=>$at,'available_at'=>$at,'open'=>$open,'high'=>$close+0.8,'low'=>min($open,$close)-0.4,
 'close'=>$close,'volume'=>$i>=56&&$i<=58?500:($i===59?1400:1000)];
}
$at=end($bars)['available_at'];$a=(new ChartPlanEngine())->analyze($bars,'005930.KS',$at,'custom');
$original=PaperRrAudit::capture(['yahoo'=>'005930.KS','name'=>'<script>'],['ok'=>true,'research_input'=>['bars'=>$bars,'analysis'=>$a]]);
$file='20251226-063000-aaaaaaaaaaaa.json';$bundle=['schema'=>1,'version'=>PaperRrAudit::VERSION,
 'membership'=>'observed_scan_only','recorded_at'=>$at,'records'=>[$original]];
$r=PaperRediagnosis::identity($original,$bundle,$file);$before=PaperRrAudit::encode($r);
$x=PaperRediagnosis::evaluate($r,$bars,$at,'test');
rdcheck($x['replay']['status']==='same_compared_fields','same saved input and time reproduces compared fields');
rdcheck($x['replay']['profile']==='custom'&&$x['replay']['profile_basis']==='saved_decision','saved profile selected');
rdcheck($x['replay']['historical_code_verified']===false,'matching output is not proof of original code');
rdcheck($x['reconstructed_metrics']['volume']['ratio']===0.5,'reconstructed ratio and means');
rdcheck($x['followup']['status']==='no_future_bars','no future sample is not zero return');
rdcheck(PaperRrAudit::encode($r)===$before,'source observation untouched');
$changed=$bars;$changed[0]['volume']++;
$x=PaperRediagnosis::evaluate($r,$changed,$at,'test');
$d=$x['history']['differences'][0];
rdcheck($d['field']==='volume'&&$d['saved']===1000&&$d['tracking']===1001&&$d['session']===$bars[0]['available_at'],'precise changed field and timestamp');
rdcheck($x['followup']['status']==='historical_revision_or_missing','revision guard not bypassed');
$missing=$bars;array_shift($missing);
rdcheck(PaperRediagnosis::compareHistory($bars,$missing,'005930.KS',$at)['differences'][0]['kind']==='missing','missing historical bar distinguished');
$tamper=$r;$tamper['bars'][0]['volume']++;
rdcheck(PaperRediagnosis::evaluate($tamper,$bars,$at,'test')['status']==='input_hash_mismatch','tampered original blocked');
$future=$bars;$b=end($bars);$b['available_at']+=86400;$b['time']+=86400;$future[]=$b;
$x=PaperRediagnosis::evaluate($r,$future,$at,'test');
rdcheck($x['followup']['status']==='no_future_bars','future price bars respect cutoff');
$x=PaperRediagnosis::evaluate($r,$future,$at+86400,'test');
rdcheck($x['followup']['horizons'][1]['status']==='complete'&&$x['followup']['horizons'][3]['status']==='pending','eligible stored signals recover mature horizon only');
$changedDecision=$r;$changedDecision['analysis']['plan']['status']='forced_old_state';
rdcheck(isset(PaperRediagnosis::evaluate($changedDecision,$bars,$at,'test')['replay']['differences']['status']),'changed saved decision visible');
rdcheck(PaperRediagnosis::evaluate($r,$bars,$at-1,'test')['status']==='not_yet_observed','observation cutoff respected');
$unavailable=PaperRediagnosis::identity(['symbol'=>'X','status'=>'unavailable','detail'=>'HTTP 404'],$bundle,$file);
rdcheck(PaperRediagnosis::evaluate($unavailable,null,$at,'test')['status']==='original_input_unavailable','unavailable original never fabricated');
$report=['schema'=>1,'kind'=>'saved_audit_rediagnosis','account'=>'paper-kr','generated_at'=>$at,'count'=>1,'errors'=>[],'rows'=>[$x]];
ob_start();paper_rediagnosis_panel($report,'paper-kr');$html=ob_get_clean();
rdcheck(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'report escapes original values');
// Full offline CLI: original evidence is read only, reports have separate history, repeat is safe.
$root=sys_get_temp_dir().'/rediag-'.bin2hex(random_bytes(5));mkdir($root);
$prior=getenv('PAPER_STATE_DIR');putenv('PAPER_STATE_DIR='.$root);
$remove=function(string $dir)use(&$remove):void{foreach(scandir($dir) as $f){if($f==='.'||$f==='..')continue;$p=$dir.'/'.$f;is_dir($p)?$remove($p):unlink($p);}rmdir($dir);};
try{
 mkdir($root.'/rr-audit/paper-kr',0770,true);mkdir($root.'/followup/paper-kr/evidence',0770,true);
 $source=$root.'/rr-audit/paper-kr/'.$file;file_put_contents($source,PaperRrAudit::encode($bundle));$sourceHash=hash_file('sha256',$source);
 $priceHash=hash('sha256',PaperRrAudit::encode($changed));$evidence=$root.'/followup/paper-kr/evidence/'.$priceHash.'.json';
 file_put_contents($evidence,PaperRrAudit::encode($changed));
 $follow=PaperFollowup::evaluate($r,$changed,$at);$follow['price_hash']=$priceHash;
 $old=['schema'=>1,'rows'=>['005930.KS@'.$at=>$follow],'as_of'=>$at];
 $fp=$root.'/followup/paper-kr/latest.json';file_put_contents($fp,PaperRrAudit::encode($old));$followHash=hash_file('sha256',$fp);
 $cmd=escapeshellarg(PHP_BINARY).' -n '.escapeshellarg(__DIR__.'/../bin/paper_rediagnose.php').' --account=paper-kr';
 exec($cmd.' 2>&1',$lines,$code);rdcheck($code===0,'offline CLI exits successfully: '.implode("\n",$lines));
 $result=paper_rediagnosis_load($root,'paper-kr');
 rdcheck($result['rows'][0]['price_source']['kind']==='original_followup_evidence','original tracking evidence preferred');
 rdcheck($result['rows'][0]['history']['difference_count']===1,'CLI logs tracking difference');
 rdcheck($result['rows'][0]['followup_as_of']===$at,'tracking snapshot cutoff retained');
 rdcheck(hash_file('sha256',$source)===$sourceHash&&hash_file('sha256',$fp)===$followHash,'audit and operational followup byte identical');
 $lines=[];exec($cmd.' 2>&1',$lines,$code);
 rdcheck($code===0&&count(glob($root.'/rediagnosis/paper-kr/*-*.json'))===2,'rerun appends separate report history');
 file_put_contents($evidence,'[]');$lines=[];exec($cmd.' 2>&1',$lines,$code);$result=paper_rediagnosis_load($root,'paper-kr');
 rdcheck($result['rows'][0]['price_source']['error']==='Price evidence hash mismatch'&&$result['rows'][0]['followup']===null,'tampered tracking evidence not used');
}finally{putenv($prior===false?'PAPER_STATE_DIR':'PAPER_STATE_DIR='.$prior);$remove($root);}
