<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';require __DIR__.'/../bin/paper/StrategyVersion.php';
use ChartEntryLab\PaperJournal;
use ChartEntryLab\ScanSnapshot;
function vc(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "OK $name\n";}
function rm12(string $p):void{if(is_dir($p)){foreach(scandir($p) as $f)if($f!=='.'&&$f!=='..')rm12($p.'/'.$f);rmdir($p);}elseif(file_exists($p))unlink($p);}
$root=dirname(__DIR__);$current=PaperStrategyVersion::current();$map=json_decode(file_get_contents($root.'/config/paper-legacy-versions.json'),true);
foreach($map['versions'] as $old=>$record)vc(PaperStrategyVersion::compatible($old,$current),'verified legacy maps to current');
vc(!PaperStrategyVersion::compatible(str_repeat('0',64),$current),'unknown version rejected');
$s=['version'=>array_key_first($map['versions']),'cash'=>123,'frozen'=>['a'=>1]];$original=$s;$events=[];
PaperStrategyVersion::adopt($s,$current,function($t,$p)use(&$events){$events[]=[$t,$p];});
vc($s['version']===$original['version'] && $s['cash']===123 && $s['frozen']===$original['frozen'],'adoption preserves original identity and finances');
PaperStrategyVersion::adopt($s,$current,function($t,$p)use(&$events){$events[]=[$t,$p];});vc(count($events)===1,'adoption recorded once');
$halt=$original;$halt['halted']=true;try{PaperStrategyVersion::adopt($halt,$current,fn()=>null);vc(false,'halt');}catch(RuntimeException $e){vc(!isset($halt['strategy_fingerprint']),'halted account not migrated');}
$dir=sys_get_temp_dir().'/scope-'.bin2hex(random_bytes(5));mkdir($dir);
try {
 $paths=json_decode(file_get_contents($root.'/config/paper-strategy-files.json'),true);
 foreach(array_merge($paths,['config/paper-strategy-files.json','config/paper-legacy-versions.json','src/ScanSnapshot.php']) as $p){$target=$dir.'/'.$p;if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);copy($root.'/'.$p,$target);}
 vc(PaperStrategyVersion::current($dir)===$current,'copied strategy fingerprint stable');
 file_put_contents($dir.'/src/ScanSnapshot.php',"\n// display change",FILE_APPEND);vc(PaperStrategyVersion::current($dir)===$current,'display change excluded');
 file_put_contents($dir.'/src/TradeSimulator.php',"\n// execution change",FILE_APPEND);$changed=PaperStrategyVersion::current($dir);
 vc($changed!==$current && !PaperStrategyVersion::compatible($original['version'],$changed,$dir),'core change cannot use legacy exemption');
 // Every referenced repository class must be part of the declared dependency closure.
 $names=[];foreach(glob($root.'/src/*.php') as $p)$names[basename($p,'.php')]='src/'.basename($p);
 foreach($paths as $p)foreach($names as $name=>$dep)if(preg_match('/\b'.preg_quote($name,'/').'\b/',file_get_contents($root.'/'.$p)))vc(in_array($dep,$paths,true),'dependency covered '.$name);
 $snap=new ScanSnapshot($dir.'/scan');
 $rows=[['code'=>'000001','naver_price'=>'bad','price'=>100],['code'=>'000002','naver_price'=>0,'price'=>200],['code'=>'000003','naver_price'=>110,'price'=>90],['code'=>'000004','naver_price'=>-1,'price'=>null]];
 $snap->save(['ok'=>true,'fetched_at'=>'2026-09-15 12:00:00','rows'=>$rows]);
 $review=$snap->reviewAgainst($rows,'2026-09-16 12:00:00');
 foreach($review['rows'] as $row)vc($row['yesterday_price']===$row['today_price'],'save/review identical fallback '.$row['code']);
 $by=[];foreach($review['rows'] as $row)$by[$row['code']]=$row;
 vc($by['000001']['today_price_source']==='row_price_fallback' && $by['000003']['today_price_source']==='naver_scan','source recorded');
 vc($by['000004']['since_scan_pct']===null,'invalid prices never produce returns');
 vc($by['000003']['yesterday_price_observed_at']==='2026-09-15 12:00:00','scan observation timestamp preserved');
}finally{rm12($dir);}
echo "SCOPE_AND_SCAN_PASS\n";
