<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';require __DIR__.'/../bin/paper/StrategyVersion.php';
use ChartEntryLab\PaperJournal;
use ChartEntryLab\ScanSnapshot;
function vc(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "OK $name\n";}
function rm12(string $p):void{if(is_dir($p)){foreach(scandir($p) as $f)if($f!=='.'&&$f!=='..')rm12($p.'/'.$f);rmdir($p);}elseif(file_exists($p))unlink($p);}
$root=dirname(__DIR__);$current=PaperStrategyVersion::current();$map=json_decode(file_get_contents($root.'/config/paper-legacy-versions.json'),true);
// A historical exemption pins one verified target, not every future strategy.
foreach($map['versions'] as $old=>$record){
 $target=$record['compatible_strategy'];
 vc(PaperStrategyVersion::compatible($old,$target),'legacy maps to its verified target');
 vc(PaperStrategyVersion::compatible($old,$current)===($old===$current || $target===$current),'legacy compatibility follows pinned target');
}
vc(PaperStrategyVersion::compatible($current,$current),'current strategy accepts itself');
$verified=$map['versions'][array_key_first($map['versions'])]['compatible_strategy'];
vc(!PaperStrategyVersion::compatible(str_repeat('0',64),$current),'unknown version rejected');
$s=['version'=>array_key_first($map['versions']),'cash'=>123,'frozen'=>['a'=>1]];$original=$s;$events=[];
PaperStrategyVersion::adopt($s,$verified,function($t,$p)use(&$events){$events[]=[$t,$p];});
vc($s['version']===$original['version'] && $s['cash']===123 && $s['frozen']===$original['frozen'],'adoption preserves original identity and finances');
PaperStrategyVersion::adopt($s,$verified,function($t,$p)use(&$events){$events[]=[$t,$p];});vc(count($events)===1,'adoption recorded once');
$halt=$original;$halt['halted']=true;try{PaperStrategyVersion::adopt($halt,$verified,fn()=>null);vc(false,'halt');}catch(RuntimeException $e){vc(!isset($halt['strategy_fingerprint']),'halted account not migrated');}
// A real strategy change must reject old accounts without mutating them.
$rejected=$original;$rejectBefore=$rejected;$rejectEvents=[];
try {
 PaperStrategyVersion::adopt($rejected,str_repeat('f',64),function($t,$p)use(&$rejectEvents){$rejectEvents[]=[$t,$p];});
 vc(false,'changed strategy must reject adoption');
}catch(RuntimeException $e){
 vc($rejected===$rejectBefore && $rejectEvents===[],'rejected adoption preserves state and emits no event');
}
if(!PaperStrategyVersion::compatible($original['version'],$current)){
 $oldAccount=$original;$blocked=false;
 try{PaperStrategyVersion::verify($oldAccount,$current);}catch(RuntimeException $e){$blocked=true;}
 vc($blocked && $oldAccount===$original,'historical account cannot silently adopt current strategy');
}
$badFingerprint=['version'=>$current,'strategy_fingerprint'=>str_repeat('0',64)];$blocked=false;
try{PaperStrategyVersion::verify($badFingerprint,$current);}catch(RuntimeException $e){$blocked=true;}
vc($blocked,'mismatched account fingerprint rejected');
$dir=sys_get_temp_dir().'/scope-'.bin2hex(random_bytes(5));mkdir($dir);
try {
 $paths=json_decode(file_get_contents($root.'/config/paper-strategy-files.json'),true);
 foreach(array_merge($paths,['config/paper-strategy-files.json','config/paper-legacy-versions.json','src/ScanSnapshot.php']) as $p){$target=$dir.'/'.$p;if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);copy($root.'/'.$p,$target);}
 vc(PaperStrategyVersion::current($dir)===$current,'copied strategy fingerprint stable');
 file_put_contents($dir.'/src/AccountPlaybook.php',str_replace("\n","\r\n",file_get_contents($dir.'/src/AccountPlaybook.php')));
 vc(PaperStrategyVersion::current($dir)===$current,'CRLF does not change fingerprint');
 file_put_contents($dir.'/src/ScanSnapshot.php',"\n// display change",FILE_APPEND);vc(PaperStrategyVersion::current($dir)===$current,'display change excluded');
 file_put_contents($dir.'/src/TradeSimulator.php',"\n// execution change",FILE_APPEND);$changed=PaperStrategyVersion::current($dir);
 vc($changed!==$current && !PaperStrategyVersion::compatible($original['version'],$changed,$dir),'core change cannot use legacy exemption');
 // Every referenced repository class must be part of the declared dependency closure.
 $names=[];foreach(glob($root.'/src/*.php') as $p)$names[basename($p,'.php')]='src/'.basename($p);
 foreach($paths as $p)foreach($names as $name=>$dep)if(preg_match('/\b'.preg_quote($name,'/').'\b/',file_get_contents($root.'/'.$p)))vc(in_array($dep,$paths,true),'dependency covered '.$name);
 $snap=new ScanSnapshot($dir.'/scan');
 $rows=[['code'=>'000001','naver_price'=>'bad','price'=>100,'score'=>40],['code'=>'000002','naver_price'=>0,'price'=>200,'score'=>90],['code'=>'000003','naver_price'=>110,'price'=>90,'score'=>70],['code'=>'000004','naver_price'=>-1,'price'=>null],['code'=>'000099','naver_price'=>50,'price'=>50,'score'=>99]];
 $snap->save(['ok'=>true,'fetched_at'=>'2026-09-15 12:00:00','rows'=>$rows]);
 $today=array_slice($rows,0,4);
 $review=$snap->reviewAgainst($today,'2026-09-16 12:00:00');
 foreach($review['rows'] as $row) {
  if ($row['code']==='000099') {
   vc($row['still_in_scan']===false && $row['since_scan_pct']===null,'dropout stays outside today scan');
   continue;
  }
  vc($row['yesterday_price']===$row['today_price'],'save/review identical fallback '.$row['code']);
 }
 $by=[];foreach($review['rows'] as $row)$by[$row['code']]=$row;
 vc($by['000001']['today_price_source']==='row_price_fallback' && $by['000003']['today_price_source']==='naver_scan','source recorded');
 vc($by['000004']['since_scan_pct']===null,'invalid prices never produce returns');
 vc($by['000003']['yesterday_price_observed_at']==='2026-09-15 12:00:00','scan observation timestamp preserved');
 vc(array_column($review['rows'],'code')===['000099','000002','000003','000001','000004'],'review sorted by yesterday score');
 $filled=$snap->fillOutsideScan($review,static fn(array $row):array=>['naver_price'=>60],12);
 $byFilled=[];foreach($filled['rows'] as $row)$byFilled[$row['code']]=$row;
 vc(($byFilled['000099']['since_scan_pct']??null)===20.0 && !empty($byFilled['000099']['outside_rescanned']),'dropout extra scan fills return');
 vc(($byFilled['000002']['since_scan_pct']??null)===$by['000002']['since_scan_pct'],'in-scan rows not extra scanned');
 $calls=0;
 $snap->fillOutsideScan($review,static function(array $row)use(&$calls):array{$calls++;return ['naver_price'=>60];},12);
 vc($calls===1,'extra scan only dropouts in first 12');
}finally{rm12($dir);}
echo "SCOPE_AND_SCAN_PASS\n";
