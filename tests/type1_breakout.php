<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';require __DIR__.'/../bin/type1/Page.php';
use ChartEntryLab\Type1Breakout;
$n=0;function ok($x,$label){global $n;if(!$x)throw new RuntimeException($label);$n++;}
$t=strtotime('2026-10-01 09:00:00 +09:00');$bars=[];
for($i=0;$i<21;$i++)$bars[]=['time'=>$t+$i*86400,'open'=>90,'high'=>100,'low'=>80,'close'=>95,'volume'=>1];
$bars[20]['high']=115;$bars[20]['close']=110;$now=$t+21*86400;
$c=['matches'=>true,'analysis_date'=>'2026-10-21'];$r=Type1Breakout::evidence($bars,'005930.KS',$c,$now);
ok($r['confirmed']&&$r['level']===100,'same day confirmed');
$future=$bars[20];$future['time']=$t+21*86400;$future['is_complete']=false;$future['high']=200;$r=Type1Breakout::evidence([...$bars,$future],'005930.KS',$c,$now+3600);
ok($r['confirmed']&&$r['level']===100&&$r['day']==='2026-10-21','ignore live incomplete high');
$r=Type1Breakout::evidence($bars,'005930.KS',$c,$t+20*86400+3600);ok(!$r['confirmed'],'before closing');
$r=Type1Breakout::evidence($bars,'005930.KS',['matches'=>true,'analysis_date'=>'2026-10-22'],$now);ok(!$r['confirmed'],'date mismatch');
$r=Type1Breakout::evidence($bars,'005930.KS',['matches'=>false,'analysis_date'=>'2026-10-21'],$now);ok(!$r['confirmed'],'non type1');
$b=$bars;$b[20]['close']=100;ok(!Type1Breakout::evidence($b,'005930.KS',$c,$now)['confirmed'],'strict greater');
$b=$bars;$b[4]['synthetic']=true;ok(!Type1Breakout::evidence($b,'005930.KS',$c,$now)['confirmed'],'invalid prior not replaced');
$b=$bars;foreach($b as &$x){$x['time_kst']=gmdate('Y-m-d H:i:s',$x['time']+32400);unset($x['time']);}unset($x);ok(Type1Breakout::evidence($b,'005930.KS',$c,$now)['confirmed'],'time_kst fixture support');
$row=['rank'=>1,'symbol'=>'005930.KS','name'=>'<script>','analysis_date'=>'2026-10-21','analysis_close'=>110,'breakout20'=>Type1Breakout::evidence($bars,'005930.KS',$c,$now)];
$report=['ok'=>true,'as_of'=>date(DATE_ATOM,$now),'rows'=>[$row],'errors'=>[],'summary'=>['total'=>1,'evaluated'=>1,'matches'=>1,'errors'=>0],'error'=>null,'scope'=>'breakout'];
ob_start();\ChartEntryLab\Type1Scan\render($report);$html=ob_get_clean();ok(str_contains($html,'종가 돌파 확인 1'),'filter count');ok(!str_contains($html,'<script>'),'escaping');ok(str_contains($html,'scope=breakout'),'refresh preserves filter');
$report['rows'][0]['breakout20']['confirmed']=false;ob_start();\ChartEntryLab\Type1Scan\render($report);$html=ob_get_clean();ok(str_contains($html,'종가 돌파 확인 후보가 없습니다'),'empty confirmed distinct');ok(!str_contains($html,'&lt;script&gt;'),'filtered row absent');
$report['source']='saved';$report['saved_day']='2026-10-21';ob_start();\ChartEntryLab\Type1Scan\render($report);$html=ob_get_clean();ok(str_contains($html,'당시 스냅샷'),'saved semantics');ok(!str_contains($html,'refresh=1'),'no live refresh in saved view');
// The two visible tables must reorder the same confirmed rows without changing their ranks.
$fixture=[];
foreach([[7,'028050.KS',0.47],[38,'064400.KS',3.67],[84,'425420.KQ',8.73],[88,'144960.KQ',3.63],[99,'000001.KS',null]] as [$rank,$symbol,$dist]){
    $fixture[]=array_replace($row,['rank'=>$rank,'symbol'=>$symbol,'name'=>$symbol,
        'breakout20'=>['confirmed'=>true,'distance_pct'=>$dist]]);
}
$report['rows']=$fixture;
$htmlFor=static function($r){ob_start();\ChartEntryLab\Type1Scan\render($r);return ob_get_clean();};
$orderOf=static function($html){preg_match_all('/<tr data-near="\d+" data-amount="(\d+)">/',$html,$m);return $m[1];};
$near=['7','88','38','84','99'];$amount=['7','38','84','88','99'];
ok($orderOf($htmlFor($report))===array_merge($amount,$amount),'amount remains default in both tables');
$report['sort']='near';$html=$htmlFor($report);
ok($orderOf($html)===array_merge($near,$near),'near in both tables; missing last');
ok(str_contains($html,'day=2026-10-21&amp;sort=near'),'preserve selected snapshot date');
$report['rows'][1]['breakout20']['distance_pct']=3.63001;$report['rows'][3]['breakout20']['distance_pct']=3.63002;
ok($orderOf($htmlFor($report))===array_merge(['7','38','88','84','99'],['7','38','88','84','99']),'use unrounded distances');
$report['rows'][3]['breakout20']['distance_pct']=3.63001;
ok($orderOf($htmlFor($report))[1]==='38','tied distance uses amount rank');
$report['scope']='all';ok(!str_contains($htmlFor($report),'id="breakout-sort"'),'only confirmed view offers distance sort');
$report['scope']='breakout';$report['sort']='near';
$report['rows'][1]['retest_event']=['status'=>'재이탈','recovery'=>['kst'=>'2026-10-29 09:25'],'retest'=>['kst'=>'2026-10-29 10:10']];
$html=$htmlFor($report);
ok(preg_match('~<tr data-near="[0-9]+" data-amount="38">(?:(?!</tr>).)*09:25 → 2026-10-29 10:10(?:(?!</tr>).)*</tr>~s',$html)===1,'sorted decision table retains events on correct symbol');
echo "$n checks passed\n";
