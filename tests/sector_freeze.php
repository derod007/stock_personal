<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/SectorFreeze.php';
use ChartEntryLab\SectorMap;

function sf(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('FAIL '.$why);echo "PASS $why\n";}
$root=sys_get_temp_dir().'/sector-freeze-test-'.getmypid();
function sfrm(string $dir):void{
    if(!is_dir($dir)||!str_contains($dir,'sector-freeze-test-'))return;
    foreach(scandir($dir) as $n){if($n==='.'||$n==='..')continue;$p=$dir.'/'.$n;is_dir($p)?sfrm($p):unlink($p);}
    rmdir($dir);
}
sfrm($root);mkdir($root,0770,true);register_shutdown_function(fn()=>sfrm($root));

function status(string $dir,string $symbol,string $quality='ok'):void{
    $path=$dir.'/status/'.$symbol.'.json';
    if(!is_dir(dirname($path)))mkdir(dirname($path),0770,true);
    file_put_contents($path,json_encode(['state'=>'done','name'=>$symbol,'quality'=>['status'=>$quality]],JSON_UNESCAPED_UNICODE));
}
function scan(string $path,array $symbols):void{
    $records=[];foreach($symbols as $s)$records[]=['symbol'=>$s];
    if(!is_dir(dirname($path)))mkdir(dirname($path),0770,true);
    file_put_contents($path,json_encode(['records'=>$records]));
}
function cache(string $dir,string $code,array $row):void{
    if(!is_dir($dir))mkdir($dir,0770,true);
    file_put_contents($dir.'/sector_'.$code.'.json',json_encode($row,JSON_UNESCAPED_UNICODE));
}

$prior=$root.'/prior';$recent=$root.'/recent';$cacheDir=$root.'/cache';
status($prior,'005930.KS');status($prior,'000660.KS');status($prior,'035420.KS','hold');
status($recent,'005930.KS');status($recent,'051910.KS');
$scanFile=$root.'/scans/one.json';
scan($scanFile,['005930.KS','000660.KS','051910.KS','999999.KS']);
cache($cacheDir,'005930',['sector'=>'반도체와반도체장비','sector_bucket'=>'semi','sector_label'=>'반도체·전자','name'=>'삼성전자']);
cache($cacheDir,'000660',['sector'=>'기타','sector_bucket'=>'other','sector_label'=>'기타','name'=>null]);
cache($cacheDir,'051910',['sector'=>'화학','sector_bucket'=>'energy','sector_label'=>'에너지·소재','name'=>'LG화학']);
$sentinel=$cacheDir.'/sector_005930.json';$before=hash_file('sha256',$sentinel);$finger=PaperSectorFreeze::fingerprint($cacheDir);
$rules=new SectorMap($root.'/rule-only');
$built=PaperSectorFreeze::build($cacheDir,['prior'=>$prior,'recent'=>$recent],[$scanFile]);
sf(hash_file('sha256',$sentinel)===$before&&PaperSectorFreeze::fingerprint($cacheDir)===$finger,'reading the cache does not rewrite it');
sf($built['sectors']['005930.KS']===$rules->bucketOf('반도체와반도체장비','삼성전자'),'a stored industry uses the operational bucket rule');
sf($built['symbols']['005930.KS']['status']==='secured'&&$built['symbols']['005930.KS']['periods']===['prior','recent'],'the same symbol has one classification for both periods');
sf($built['sectors']['000660.KS']==='unclassified'&&$built['symbols']['000660.KS']['reason']==='cache_placeholder','a placeholder cache entry is not turned into 기타');
sf(!isset($built['sectors']['035420.KS']),'a quality-hold symbol is outside the replay map');
sf($built['sectors']['051910.KS']===$rules->bucketOf('화학','LG화학')&&$built['symbols']['051910.KS']['periods']===['recent'],'the second period uses the same map, not a second classification');
$unclassified=array_unique(array_map(fn($s)=>$built['sectors'][$s],array_column($built['unconfirmed'],'symbol')));
sf($unclassified===['unclassified'],'every unconfirmed symbol shares the one unclassified bucket');
$bad=$built;$bad['sectors']['000660.KS']='unclassified-000660.KS';
sf((function()use($bad){try{PaperSectorFreeze::assertReplayMap($bad);return false;}catch(Throwable){return true;}})(),'an unconfirmed symbol cannot be given its own sector');
echo "SECTOR_FREEZE_PASS\n";
