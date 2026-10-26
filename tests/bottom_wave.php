<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
use ChartEntryLab\BottomWaveReview;
function ck(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "OK $s\n";}
function fixture(array $points):array{
 $out=[];$start=new DateTimeImmutable('2025-01-02 15:30:00',new DateTimeZone('Asia/Seoul'));
 $values=array_fill(0,60,160.0);$last=160.0;
 foreach($points as $p){for($i=1;$i<=4;$i++)$values[]=$last+($p-$last)*$i/4;$last=$p;}
 foreach($values as $i=>$c){$t=$start->modify('+'.$i.' weekdays')->getTimestamp();$out[]=['available_at'=>$t,'time'=>$t,'open'=>$c,'high'=>$c+1,'low'=>$c-1,'close'=>$c,'volume'=>1000];}
 return $out;
}
function run(array $b):array{return (new BottomWaveReview())->analyze($b,'005930.KS',end($b)['available_at']);}
$points=[130,150,115,135,100,120,101,110];$b=fixture($points);$r=run($b);
ck($r['status']==='retry_failed'&&!$r['entry_candidate'],'retry alone is not entry');
ck($r['setup']['trigger']===121.0&&$r['setup']['target']===136.0&&$r['setup']['stop']===99.0,'intermediate trigger prior-wave target structural stop');
ck($r['setup']['retry']['confirmed_at']>$r['setup']['retry']['pivot_at'],'pivot delayed until knowable');
$entry=fixture([...$points,125]);$e=run($entry);$signals=array_values(array_filter($e['events'],fn($x)=>$x['status']==='breakout_confirmed'));
ck(count($signals)===1&&$signals[0]['signal_eligible'],'first subsequent close breakout qualifies once');
ck($signals[0]['confirmed_at']>$r['setup']['failure_confirmed_at'],'failure precedes breakout');
$same=(new BottomWaveReview())->analyze($entry,'005930.KS',end($b)['available_at']);ck($same['events']===$r['events'],'future append cannot change previous events');
$touch=$b;$touch[count($touch)-1]['high']=125;ck(!run($touch)['entry_candidate'],'wick-only breakout not entry');
$target=fixture([...$points,125,140]);ck(run($target)['status']==='target_zone','prior-wave target is separate event');
$bad=fixture([...$points,95,110,90]);ck(run($bad)['status']==='released','new low then lower high releases hypothesis');
$one=fixture([100,120,101,125]);ck(run($one)['setup']===null,'single bottom not three-wave setup');
$gap=$b;$gap[]=$gap[65];ck(run($gap)['status']==='data_quality','duplicate raw bars block current advice');
$reset=(new BottomWaveReview())->analyze($entry,'005930.KS',end($entry)['available_at'],[(new DateTimeImmutable('@'.$b[80]['available_at']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d')]);
ck($reset['setup']===null,'reference discontinuity resets wave construction');
require __DIR__.'/../bin/paper/BottomWavePanel.php';
$state=['history'=>['005930.KS'=>$entry]];$before=serialize($state);
ob_start();paper_bottom_wave_panel($state,end($entry)['available_at'],false,'paper.php?bottom_wave=1');$html=ob_get_clean();
ck(str_contains($html,'저장 종목의 파동 근거 보기')&&!str_contains($html,'005930.KS'),'default view does not evaluate histories');
ob_start();paper_bottom_wave_panel($state,end($entry)['available_at'],true,'');$html=ob_get_clean();
ck(str_contains($html,'005930.KS')&&str_contains($html,'121'),'requested panel shows fixed evidence');
ck(serialize($state)===$before,'panel does not mutate journal state');
