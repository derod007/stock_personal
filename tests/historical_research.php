<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/HistoricalResearch.php';
use ChartEntryLab\ChartPlanEngine;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$bars=[];$start=new DateTimeImmutable('2025-10-06');
for($i=0;$i<60;$i++){
 $close=$i<30?50+$i:80+($i-30)*0.4;if($i>=55)$close=[88,86,84.5,84,89.8][$i-55];
 $close+=1000;$open=$i===59?1084.2:$close-0.2;$day=$start->modify('+'.$i.' weekdays')->format('Y-m-d');
 $at=(new DateTimeImmutable($day.' 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();
 $bars[]=['time'=>$at,'time_kst'=>$day.' 15:30:00','available_at'=>$at,'open'=>$open,'high'=>$close+0.8,'low'=>min($open,$close)-0.4,
 'close'=>$close,'volume'=>$i>=56&&$i<=58?500:($i===59?1400:1000)];
}
$at=end($bars)['available_at'];$a=(new ChartPlanEngine())->analyze($bars,'005930.KS',$at,'custom');
$original=PaperRrAudit::capture(['yahoo'=>'005930.KS','name'=>'<script>'],['ok'=>true,'research_input'=>['bars'=>$bars,'analysis'=>$a]]);
$file='20251226-063000-aaaaaaaaaaaa.json';$bundle=['schema'=>1,'version'=>PaperRrAudit::VERSION,
 'membership'=>'observed_scan_only','recorded_at'=>$at,'records'=>[$original]];
$r=PaperRediagnosis::identity($original,$bundle,$file);$before=PaperRrAudit::encode($r);

$x=PaperHistoricalResearch::evaluate($r,$bars,$at,'test');
check($x['status']==='evaluated'&&count($x['patterns'])===3,'three independent patterns');
$future=$bars;$b=end($bars);$b['available_at']+=86400;$b['time']+=86400;$future[]=$b;
$y=PaperHistoricalResearch::evaluate($r,$future,$at+86400,'test');
check($x['plan']===$y['plan'],'future prices cannot change current-rule historical decision');
check($y['followup']['horizons'][1]['status']==='complete','mature horizon available');
$z=PaperHistoricalResearch::evaluate($r,$future,$at+86400,'test',$at);
check($z['followup']['status']==='no_future_bars','saved evidence cutoff enforced');
$changed=$r;$changed['analysis']['plan']['ready']=true;$changed['analysis']['plan']['entry']=999999;
$z=PaperHistoricalResearch::evaluate($changed,$future,$at+86400,'test');
check($z['plan']===$y['plan']&&$z['followup']['trades']===$y['followup']['trades'],'old plan not used for research trades');
$bad=$r;$bad['bars'][0]['close']++;
check(PaperHistoricalResearch::evaluate($bad,null,$at,'test')['status']==='input_hash_mismatch','tampered inputs blocked');
$bad=$r;$bad['analysis_symbol']='OTHER';
check(PaperHistoricalResearch::evaluate($bad,null,$at,'test')['status']==='analysis_symbol_mismatch','original identity checked before decision replacement');
check(PaperHistoricalResearch::evaluate($r,null,$at-1,'test')['status']==='not_yet_observed','observation cannot precede source');
check(PaperRrAudit::encode($r)===$before,'original remains byte identical');
$s=PaperHistoricalResearch::summarize([$x,$y]);
check($s['horizons'][1]['n']===1,'pending horizons excluded from denominator');

$excluded=$r;$excluded['name']='KODEX AI전력핵심설비';
check(PaperHistoricalResearch::evaluate($excluded,null,$at,'test')['status']==='excluded_instrument','current universe excludes archived ETF');
$root=sys_get_temp_dir().'/historical-research-'.bin2hex(random_bytes(5));
mkdir($root.'/rr-audit/paper-kr',0770,true);mkdir($root.'/followup/paper-kr/evidence',0770,true);
$remove=function($dir)use(&$remove){foreach(scandir($dir) as $f){if($f==='.'||$f==='..')continue;$p=$dir.'/'.$f;is_dir($p)?$remove($p):unlink($p);}rmdir($dir);};
try{
 $source=$root.'/rr-audit/paper-kr/'.$file;file_put_contents($source,PaperRrAudit::encode($bundle));$sourceHash=hash_file('sha256',$source);
 $hash=hash('sha256',PaperRrAudit::encode($future));$evidence=$root.'/followup/paper-kr/evidence/'.$hash.'.json';file_put_contents($evidence,PaperRrAudit::encode($future));
 $f=PaperFollowup::evaluate($r,$future,$at);$f['price_hash']=$hash;
 $fp=$root.'/followup/paper-kr/latest.json';file_put_contents($fp,PaperRrAudit::encode(['rows'=>[$f]]));$fh=hash_file('sha256',$fp);
 $cmd=escapeshellarg(PHP_BINARY).' -n '.escapeshellarg(__DIR__.'/../bin/paper_historical_research.php').' --source-dir='.escapeshellarg($root).' --as-of='.escapeshellarg(gmdate('c',$at+86400));
 exec($cmd,$lines,$code);$result=json_decode(implode("\n",$lines),true,512,JSON_THROW_ON_ERROR);
 check($code===0&&$result['summary']['tracking']['no_future_bars']===1,'CLI caps outcome at matched evidence timestamp');
 check(hash_file('sha256',$source)===$sourceHash&&hash_file('sha256',$fp)===$fh,'CLI leaves originals and operational followup unchanged');
 file_put_contents($evidence,'[]');$lines=[];exec($cmd,$lines,$code);$result=json_decode(implode("\n",$lines),true,512,JSON_THROW_ON_ERROR);
 check(count($result['evidence_errors'])===1&&$result['summary']['tracking']['evidence_unavailable']===1,'CLI rejects corrupt evidence without fabricating outcomes');
}finally{$remove($root);}
