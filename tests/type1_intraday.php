<?php
declare(strict_types=1);
require __DIR__.'/../bin/type1/Intraday.php';
use ChartEntryLab\Type1Scan\Intraday;
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$t=strtotime('2026-10-30 09:00:00 +09:00');
function bar($i,$o=100,$h=105,$l=99,$c=104){global $t;return ['time'=>$t+$i*300,'open'=>$o,'high'=>$h,'low'=>$l,'close'=>$c,'synthetic'=>false];}
$b=[];for($i=0;$i<6;$i++)$b[]=bar($i);
check(Intraday::evaluate(array_slice($b,0,3),$t+900)['status']==='관심 후보','first30');
check(Intraday::evaluate($b,$t+1800)['status']==='조정 대기','after30');
$b[]=bar(6,104,104,101,102);
$r=Intraday::evaluate($b,$t+2100);check($r['trigger']===105&&$r['invalidation']===101,'pullback levels');
$b[]=bar(7,102,107,102,106);$r=Intraday::evaluate($b,$t+2400);
check($r['status']==='반등 확인'&&$r['confirmed_at']!==null,'breakout');
check(Intraday::evaluate($b,$t+2399)['status']==='조정 대기','unfinished breakout excluded');
$b[]=bar(8,106,107,100,103);check(Intraday::evaluate($b,$t+2700)['status']==='조건 무효','fixed low lost');
$b[]=bar(9,103,110,102,109);check(Intraday::evaluate($b,$t+3000)['status']==='조건 무효','no reentry');
$x=$b;unset($x[2]);check(Intraday::evaluate($x,$t+3000)['status']==='판단 불가','missing bar');
$x=$b;$x[]=$b[1];check(Intraday::evaluate($x,$t+3000)['status']==='판단 불가','duplicate');
$x=$b;$x[1]['synthetic']=true;check(Intraday::evaluate($x,$t+3000)['status']==='판단 불가','synthetic');
$x=$b;$x[1]['low']=200;check(Intraday::evaluate($x,$t+3000)['status']==='판단 불가','ohlc');
check(Intraday::evaluate($b,$t+4001)['status']==='판단 불가','stale fallback');
$x=array_slice($b,0,8);$x[7]=bar(7,102,108,100,107);check(Intraday::evaluate($x,$t+2400)['status']==='조정 대기','new low breakout ambiguity');
$x=array_slice($b,0,7);$x[6]=bar(6,104,105,98,102);check(Intraday::evaluate($x,$t+2100)['status']==='조건 무효','opening range low');
$x=array_slice($b,0,6);$x[]=bar(6,104,110,102,109);check(Intraday::evaluate($x,$t+2100)['trigger']===null,'no correction no trigger');
$x=array_slice($b,0,7);$x[]=bar(7,102,108,102,104);check(Intraday::evaluate($x,$t+2400)['status']==='조정 대기','wick not breakout');
check(Intraday::evaluate([],$t+2400)['status']==='판단 불가','empty');
$state=['last_day'=>'2026-10-29','captures'=>['2026-10-29'=>['rows'=>[
 ['symbol'=>'A','episode_state'=>'new','classification'=>['matches'=>true]],
 ['symbol'=>'B','episode_state'=>'observing','classification'=>['matches'=>true]],
 ['symbol'=>'C','episode_state'=>'new','classification'=>['matches'=>false]]]]]];
check(array_column(Intraday::candidates($state),'symbol')===['A'],'new matches only');
check(Intraday::candidates([])===[],'empty state');
echo "$n checks passed\n";
