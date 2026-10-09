<?php
declare(strict_types=1);
function prebreak_bars():array
{
    $bars=[];$start=new DateTimeImmutable('2026-01-05',new DateTimeZone('Asia/Seoul'));
    for($i=0;$i<75;$i++){
        $at=$start->modify('+'.$i.' weekdays')->setTime(15,30)->getTimestamp();
        $bars[]=['time'=>$at,'available_at'=>$at,'open'=>100.0,'high'=>105.0,'low'=>95.0,'close'=>100.0,'volume'=>100000];
    }
    $bars[55]['low']=90.0;
    $tail=[[96,97,87,89],[89,90,84,87],[86,89,80,84],[85,92,83,88],[88,96,86,92],
        [94,102,90,98],[98,110,97,103],[102,106,96,100],[100,103,94,98],[97,100,92,95],
        [95,98,90,94],[94,101,93,97],[97,105,95,100],[100,107,99,103],[103,115,101,112]];
    foreach($tail as $j=>$ohlc)foreach(['open','high','low','close'] as $k=>$key)$bars[60+$j][$key]=(float)$ohlc[$k];
    return $bars;
}
