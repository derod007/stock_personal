<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
foreach(['Tracker','Store','Charts','BreakoutRetest'] as $f)require __DIR__.'/../bin/type1/'.$f.'.php';
use ChartEntryLab\Type1Scan\{BreakoutRetest as R,Store};
$n=0;function check($x,$m){global $n;if(!$x)throw new RuntimeException($m);$n++;}
$day='2026-10-30';$start=strtotime($day.' 09:00 +0900');$bars=[];
for($i=0;$i<78;$i++)$bars[]=['time'=>$start+$i*300,'open'=>99,'low'=>98,'close'=>99,'high'=>100,'volume'=>10];
function run($b){global $day;return R::evaluate($day,100,99,$b);}
check(run($bars)['status']==='미회복','no recovery');
$b=$bars;$b[4]['close']=101;$b[4]['high']=102;$b[5]['close']=100;
$r=run($b);check($r['status']==='유지'&&$r['recovery']['kst']===$day.' 09:25'&&$r['retest']['kst']===$day.' 09:30','distinct closes and equality');
$b[5]['close']=99;check(run($b)['status']==='재이탈','first fail');
unset($b[5]);$r=run($b);check($r['status']==='미확정'&&$r['recovery']!==null,'gap after recover');
$b=$bars;unset($b[1]);check(run($b)['status']==='미확정','opening gap');
$b=$bars;$b[]=$b[1];check(run($b)['status']==='미확정','duplicate');
$b=$bars;$b[0]['synthetic']=true;check(run($b)['status']==='미확정','synthetic');
$b=$bars;$b[5]['close']=101;$b[5]['high']=102;for($i=6;$i<78;$i++){$b[$i]['open']=$b[$i]['low']=$b[$i]['close']=$b[$i]['high']=101;}
check(run($b)['status']==='재시험 없음','full day no retest');
array_pop($b);check(run($b)['status']==='미확정','truncated day');
check(R::evaluate($day,100,98,$bars)['status']==='미확정','different open');
$b=$bars;$b[6]['close']=101;$b[6]['high']=102;check(run($b)['status']==='미회복','late recovery ignored');
$dir=sys_get_temp_dir().'/retest-'.bin2hex(random_bytes(8));mkdir($dir);
try{
    $anchor='2026-10-29';$symbol='005930.KS';$preBars=[];
    for($i=0;$i<21;$i++)$preBars[]=['time'=>strtotime($anchor.' 09:00 +0900')-(20-$i)*86400,'open'=>90,'low'=>89,'close'=>95,'high'=>100,'volume'=>10];
    $preBars[20]['close']=$preBars[20]['high']=105;
    $pre=['day'=>$anchor,'as_of'=>strtotime($anchor.' 20:20 +0900'),'prices'=>[$symbol=>$preBars]];
    $cur=['day'=>$day,'as_of'=>$start+12*3600,'days'=>[$anchor,$day],'prices'=>[$symbol=>[$bars[0]]]];
    $state=['last_day'=>$day,'captures'=>[]];
    foreach([$pre,$cur] as $s){Store::atomic($dir.'/snapshot-'.$s['day'].'.json',$s);$state['captures'][$s['day']]=['snapshot_sha256'=>hash('sha256',json_encode($s,JSON_THROW_ON_ERROR))];}
    $state['captures'][$anchor]['rows']=[['symbol'=>$symbol,'classification'=>['analysis_date'=>$anchor,'matches'=>true]]];
    $r=R::record($dir,$state,$day,fn()=>$bars);check($r['rows']===1,'frozen candidates');
    $path=$dir.'/breakout-retest/'.$anchor.'.json';$bytes=file_get_contents($path);$result=json_decode($bytes,true);
    check($result['rows'][$symbol]['status']==='미회복'&&$result['rows'][$symbol]['level']===100,'stored result');
    check(R::record($dir,$state,$day,fn()=>throw new RuntimeException())['status']==='이미 저장'&&$bytes===file_get_contents($path),'idempotent');
}finally{foreach(glob($dir.'/breakout-retest/*')?:[] as $f)unlink($f);if(is_dir($dir.'/breakout-retest'))rmdir($dir.'/breakout-retest');foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
echo "$n checks passed\n";
