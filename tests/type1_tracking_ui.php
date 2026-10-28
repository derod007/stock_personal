<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
foreach(['Classifier','Tracker','Store','Charts','TrackingView'] as $f)require __DIR__.'/../bin/type1/'.$f.'.php';
use ChartEntryLab\Type1Scan\{Classifier,Tracker,Store,Charts};
$count=0;
function ok(bool $v,string $label): void {global $count;$count++;if(!$v)throw new RuntimeException($label);}
function rejected(callable $fn,string $label): void {try{$fn();}catch(Throwable $e){ok(true,$label);return;}ok(false,$label);}
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/type1-synthetic.json'),true)[1]['bars'];
$c=new Classifier(__DIR__.'/../data/type1/centers.json');$symbol='000001.KS';$day='2026-10-23';
$snapshot=['day'=>$day,'as_of'=>strtotime($day.' 20:20:00 +09:00'),'days'=>[$day],
    'leaders'=>[['yahoo'=>$symbol,'name'=>'<script>unsafe</script>','rank'=>1,'amount_won'=>100]],'prices'=>[$symbol=>$fixture]];
$state=Tracker::advance(Tracker::empty(),$snapshot,$c);$key=$day.'|'.$symbol;
$entry=['time_kst'=>'2026-10-26 09:00:00','open'=>100,'high'=>101,'low'=>99,'close'=>100,'volume'=>100];
$exit=['time_kst'=>'2026-10-27 09:00:00','open'=>100,'high'=>120,'low'=>99,'close'=>115,'volume'=>300];
$next=['day'=>'2026-10-27','as_of'=>strtotime('2026-10-27 20:20:00 +09:00'),'days'=>[$day,'2026-10-26','2026-10-27'],'leaders'=>[],'prices'=>[$symbol=>[...$fixture,$entry,$exit]]];
$state=Tracker::advance($state,$next,$c);$d=Charts::detail($state,$snapshot,$symbol);
ok(count($d['pre'])===60 && array_key_last($d['pre'])===$day,'60 completed bars through T');
ok(array_keys($d['post'])===['2026-10-26','2026-10-27'],'post separate');
ok($d['trade']['exit']['reason']==='target','actual frozen trade');
$pre=Charts::svg($d['pre'],'pre');$post=Charts::svg($d['post'],'post',$d['trade']);
ok(!str_contains($pre,'진입')&&!str_contains($pre,'목표'),'no future levels in pre chart');
ok(str_contains($post,'진입')&&str_contains($post,'청산')&&str_contains($post,'손절')&&str_contains($post,'목표'),'post markers and levels');
ok(str_contains($pre,'거래량(주)')&&str_contains($pre,'<rect'),'candles and volume');
$poison=$snapshot;$poison['prices'][$symbol][]=['time_kst'=>'2026-10-28 09:00:00','open'=>999999,'high'=>999999,'low'=>1,'close'=>999999,'volume'=>999999];
ok(Charts::detail($state,$poison,$symbol)['pre']===$d['pre'],'future bars cannot change pre');
$gap=$state;$gap['trades'][$key]['path']['2026-10-26']=null;$gapDetail=Charts::detail($gap,$snapshot,$symbol);
ok(array_key_exists('2026-10-26',$gapDetail['post'])&&$gapDetail['post']['2026-10-26']===null,'missing slot retained');
ok(str_contains(Charts::svg($gapDetail['post'],'gap'),'價格')===false && str_contains(Charts::svg($gapDetail['post'],'gap'),'가격 미확정'),'gap annotated without interpolation');
$late=$state;$late['days'][]='2026-10-28';$late['trades'][$key]['path']['2026-10-28']=$entry;
ok(!isset(Charts::detail($late,$snapshot,$symbol)['post']['2026-10-28']),'cut at actual exit');
$invalid=$snapshot;$invalid['prices'][$symbol][0]['close']=0;
ok(Charts::detail($state,$invalid,$symbol)['pre']===[],'invalid pre not substituted');
rejected(fn()=>Charts::detail($state,$snapshot,'../bad'),'symbol traversal');
rejected(fn()=>Charts::detail($state,$snapshot,'999999.KS'),'unrecorded symbol');
$tmp=sys_get_temp_dir().'/type1-ui-'.bin2hex(random_bytes(5));mkdir($tmp);
try{
 Store::atomic($tmp.'/snapshot-'.$day.'.json',$snapshot);$path=$tmp.'/snapshot-'.$day.'.json';$before=file_get_contents($path);$mtime=filemtime($path);
 $loaded=Charts::snapshot($tmp,$state,$day);ok($loaded===$snapshot,'original evidence read');
 ok(file_get_contents($path)===$before&&filemtime($path)===$mtime,'read only');
 rejected(fn()=>Charts::snapshot($tmp,$state,'../../x'),'date traversal');
 rejected(fn()=>Charts::snapshot($tmp,$state,'2026-10-26'),'missing capture');
 Store::atomic($path,$poison);rejected(fn()=>Charts::snapshot($tmp,$state,$day),'hash mismatch blocks chart');
 unlink($path);rejected(fn()=>Charts::snapshot($tmp,$state,$day),'missing snapshot explicit');
}finally{foreach(glob($tmp.'/*') as $f)unlink($f);rmdir($tmp);}
$quotes=Charts::latestQuotes($state,$next);ok($quotes[$symbol]['close']===115,'snapshot close and date');
$stale=$next;$stale['prices'][$symbol]=[$entry];ok(Charts::latestQuotes($state,$stale)===[],'no stale close fallback');
$list=$state;$list['last_day']=$day;$old=$list['captures'][$day]['rows'][0];$old['name']='observing-only';$old['symbol']='000002.KS';$old['episode_state']='observing';$list['captures'][$day]['rows'][]=$old;
ob_start();\ChartEntryLab\Type1Scan\renderTracking($list);$new=ob_get_clean();
ob_start();\ChartEntryLab\Type1Scan\renderTracking($list,null,'all',$quotes);$all=ob_get_clean();
ok(!str_contains($new,'observing-only')&&str_contains($all,'observing-only'),'default new and all filter');
ok(str_contains($new,'&lt;script&gt;')&&!str_contains($new,'<script>'),'list escaping');
ok(str_contains($all,'2026-10-27')&&str_contains($all,'실시간 가격·매매 수익률이 아닙니다'),'dated comparison');
$list['captures'][$day]['rows']=[$old];ob_start();\ChartEntryLab\Type1Scan\renderTracking($list);$none=ob_get_clean();ok(str_contains($none,'신규 후보가 없습니다'),'zero new explicit');
ob_start();Charts::render($d);$html=ob_get_clean();
ok(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'detail escaped');
ok(str_contains($html,'<details class="after">')&&!str_contains($html,'<details class="after" open'),'post hidden by default');
ok(strpos($html,'판단 당시')<strpos($html,'판단 이후'),'before and after ordered');
$waiting=Tracker::advance(Tracker::empty(),$snapshot,$c);$w=Charts::detail($waiting,$snapshot,$symbol);ok($w['post']===[]&&$w['trade']['entry']===null,'waiting has no invented price');
ok(!str_contains(file_get_contents(__DIR__.'/../type1_tracking.php'),'Store::update'),'endpoint not collecting');
echo "PASS $count UI checks\n";
