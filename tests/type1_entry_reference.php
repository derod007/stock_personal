<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/type1/Tracker.php';
require __DIR__.'/../bin/type1/EntryReference.php';
use ChartEntryLab\Type1Breakout;
use ChartEntryLab\Type1Scan\EntryReference;
$n=0;function check($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
$t=strtotime('2026-10-01 09:00 +09:00');$bars=[];
for($i=0;$i<21;$i++)$bars[]=['time'=>$t+86400*$i,'open'=>90,'high'=>100,'low'=>80,'close'=>95,'volume'=>10];
$bars[17]['low']=70;$bars[20]['close']=110;$bars[20]['high']=115;
$c=['analysis_date'=>'2026-10-21','matches'=>true];$as=$t+21*86400;
$e=Type1Breakout::evidence($bars,'005930.KS',$c,$as);
check($e['pivot_low']===70&&$e['pivot_day']==='2026-10-18','known pivot');
check(abs($e['pivot_distance_pct']-(70/110-1)*100)<1e-8,'close downside');
$b=$bars;$b[20]['low']=60;$x=Type1Breakout::evidence($b,'005930.KS',$c,$as);check($x['pivot_low']===70,'unconfirmed recent low excluded');
$b=$bars;$b[17]['low']=80;check(Type1Breakout::evidence($b,'005930.KS',$c,$as)['pivot_low']===null,'flat lows not invented');
$b=$bars;$b[]=['time'=>$t+21*86400,'open'=>60,'high'=>70,'low'=>50,'close'=>60,'volume'=>1];check(Type1Breakout::evidence($b,'005930.KS',$c,$as+86400)['pivot_low']===70,'future prices do not rewrite pivot');
$next=['time'=>$t+21*86400,'open'=>105,'high'=>110,'low'=>90,'close'=>100,'volume'=>10];
$later=['day'=>'2026-10-23','days'=>['2026-10-21','2026-10-22','2026-10-23'],'as_of'=>$as+2*86400,'prices'=>['005930.KS'=>[$next]]];
$r=EntryReference::nextOpen('2026-10-21','005930.KS',$e,110,$later);
check($r['day']==='2026-10-22'&&$r['status']==='기준선 위 출발','exact next date');check(abs($r['gap_pct']-(105/110-1)*100)<1e-8&&abs($r['level_pct']-5)<1e-8,'gap and level denominators');
check(abs($r['pivot_pct']-(70/105-1)*100)<1e-8,'open downside');
$later['prices']['005930.KS'][0]['time']+=86400;check(EntryReference::nextOpen('2026-10-21','005930.KS',$e,110,$later)['open']===null,'missing next not replaced');
check(EntryReference::nextOpen('2026-10-21','005930.KS',$e,110,null)['open']===null,'waiting');
$later['prices']['005930.KS']=[$next,$next];check(EntryReference::nextOpen('2026-10-21','005930.KS',$e,110,$later)['open']===null,'duplicate unknown');
$later['prices']['005930.KS']=[$next];$later['prices']['005930.KS'][0]['synthetic']=true;check(EntryReference::nextOpen('2026-10-21','005930.KS',$e,110,$later)['open']===null,'synthetic unknown');
check(EntryReference::nextOpen('2026-10-21','005930.KS',$e,110,$later,'hash error')['open']===null,'hash error blocks');
echo "$n checks passed\n";
