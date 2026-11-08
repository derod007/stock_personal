<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';require __DIR__.'/../bin/paper/Revision.php';require __DIR__.'/../bin/paper/Chrome.php';
use ChartEntryLab\PaperJournal;
function rc(bool $v,string $name):void{if(!$v)throw new RuntimeException($name);echo "OK $name\n";}
$b=['available_at'=>100,'open'=>10.0,'high'=>11,'low'=>9,'close'=>10,'volume'=>100];
$s=['config'=>['symbols'=>['MU'=>'semi','AAPL'=>'tech']],'history'=>['MU'=>[$b],'AAPL'=>[$b]],'frozen'=>['MU:100'=>[],'MU:200'=>[],'AAPL:100'=>[]],'active'=>['MU'=>['filled'=>true,'quantity'=>2,'snapshot_key'=>'MU:100']]];
$new=['MU'=>[$b],'AAPL'=>[$b]];$new['MU'][0]['volume']=101;$new['AAPL'][0]['close']=10.5;
$events=[['id'=>1,'type'=>'order','payload'=>['symbol'=>'MU','session'=>100]],['id'=>2,'type'=>'fill','payload'=>['symbol'=>'MU','session'=>200]]];
$r=PaperRevision::describe($s,$new,['MU'=>['source'=>'test']],$events,999);
rc($r['change_count']===2,'all changed symbols');rc($r['changes'][0]['fields'][0]['field']==='volume','volume field');
rc($r['changes'][0]['fields'][0]['delta']===1.0,'numeric difference');rc(count($r['potentially_affected_snapshots'])===3,'affected input snapshots');
rc(count($r['related_trade_events'])===2,'related execution events');rc($r['active_orders_or_positions']['MU']['filled'],'held position link');
$new=['MU'=>[$b],'AAPL'=>[$b]];$new['MU'][0]['open']=10;
rc(PaperRevision::describe($s,$new,[],[],999)['changes'][0]['fields'][0]['representation_only'],'integer float representation');
$new=['MU'=>[$b],'AAPL'=>[$b]];$new['MU'][]=array_replace($b,['available_at'=>300]);
rc(PaperRevision::describe($s,$new,[],[],999)['change_count']===0,'future bars excluded');
$scan=['config'=>['symbols'=>[]],'history'=>['SCAN.KQ'=>[$b]],'frozen'=>['SCAN.KQ:100'=>[]],'active'=>[]];
$vol=['SCAN.KQ'=>[$b]];$vol['SCAN.KQ'][0]['volume']=50;
$got=PaperRevision::describe($scan,$vol,[],[],999);
rc($got['classification']==='input_difference'&&$got['changes'][0]['fields'][0]['field']==='volume','empty scan list still compares volume');
$px=['SCAN.KQ'=>[$b]];$px['SCAN.KQ'][0]['close']=11;
$got=PaperRevision::describe($scan,$px,[],[],999);
rc($got['classification']==='input_difference'&&$got['changes'][0]['fields'][0]['representation_only']===false,'empty scan list still compares price');
$rep=['SCAN.KQ'=>[$b]];$rep['SCAN.KQ'][0]['open']=10;
rc(PaperRevision::describe($scan,$rep,[],[],999)['changes'][0]['fields'][0]['representation_only'],'empty scan list keeps representation difference');
rc(PaperRevision::describe($scan,['SCAN.KQ'=>[$b]],[],[],999)['classification']==='hash_mismatch_without_row_difference','compared scan bars with no row difference');
$missing=['config'=>['symbols'=>[]],'history'=>[],'frozen'=>['SCAN.KQ:100'=>[]],'active'=>[]];
rc(PaperRevision::describe($missing,[],[],[],999)['classification']==='comparison_unavailable','missing scan bars are not called equal');
$mix=$scan;$mix['frozen']['OTHER.KQ:100']=[];
rc(PaperRevision::describe($mix,['SCAN.KQ'=>[$b]],[],[],999)['classification']==='comparison_unavailable','one missing symbol keeps the report unresolved');
rc(paper_ko('comparison_unavailable')==='비교할 봉이 없어 판단할 수 없음','missing comparison label');
$dir=sys_get_temp_dir().'/revision-'.bin2hex(random_bytes(5));mkdir($dir);
try{$bytes=PaperJournal::encode($r);$hash=hash('sha256',$bytes);PaperJournal::archive($dir.'/inputs',$hash,$bytes);rc(PaperRevision::read($dir,$hash)===$r,'report archived and verified');file_put_contents($dir.'/inputs/'.$hash.'.data','bad');try{PaperRevision::read($dir,$hash);rc(false,'tamper');}catch(RuntimeException $e){rc(true,'tamper rejected');}}
finally{foreach(glob($dir.'/inputs/*') as $f)unlink($f);rmdir($dir.'/inputs');rmdir($dir);}
echo "17 revision checks passed\n";
