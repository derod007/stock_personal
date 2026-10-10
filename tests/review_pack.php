<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require_once __DIR__.'/../bin/paper/ReviewPack.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}

$kst=new DateTimeZone('Asia/Seoul');
$bars=[];
for($i=0;$i<40;$i++){
    $day=(new DateTimeImmutable('2026-06-01',$kst))->modify('+'.$i.' days');
    if((int)$day->format('N')>=6)continue;
    $t=$day->setTime(15,30)->getTimestamp();$p=1000+$i;
    $bars[]=['available_at'=>$t,'open'=>$p,'high'=>$p+5,'low'=>$p-5,'close'=>$p+1,'volume'=>1000,'is_complete'=>true];
}
$asOf=(int)end($bars)['available_at'];
$before=PaperReviewCharts::view(PaperReviewCharts::completedThrough($bars,$asOf),$asOf,20);
$extra=$bars;$last=end($bars);$extra[]=['available_at'=>$last['available_at']+86400,'open'=>9,'high'=>9,'low'=>9,'close'=>9,'volume'=>9,'is_complete'=>true];
$after=PaperReviewCharts::view(PaperReviewCharts::completedThrough($extra,$asOf),$asOf,20);
check($before===$after,'a later bar does not change the chart window or its moving averages');
check(!in_array($extra[array_key_last($extra)]['available_at'],array_column($after['bars'],'available_at'),true),'the decision-day axis does not include the later bar');

$bad=$bars[5];$bad['close']=$bad['high']+50;$bad['available_at']=$bars[5]['available_at'];
$with=PaperReviewCharts::completedThrough(array_replace($bars,[5=>$bad]),$asOf);
check(!empty($with[5]['ohlc_invalid']),'an impossible candle is kept and marked instead of being repaired');

$rows=[];
foreach(['2024-10','2024-11','2024-12'] as $month)for($n=0;$n<10;$n++){
    $rows[]=['month'=>$month,'symbol'=>sprintf('%06d.KS',$n%4),'case_id'=>hash('sha256',$month.$n)];
}
$a=PaperReviewPack::sample($rows,9,3);$b=PaperReviewPack::sample($rows,9,3);
check($a===$b&&count($a)===9,'sampling is stable and fills the requested count');
$months=array_count_values(array_column($a,'month'));
check($months['2024-10']===3&&$months['2024-11']===3&&$months['2024-12']===3,'months share the sample before any return is known');

$selected=['pattern'=>'trend_pullback','operational_ready'=>true,'raw'=>['ready'=>true,'status'=>'ready'],'independent_blockers'=>[],'stage'=>null];
$blocked=$selected;$blocked['operational_ready']=false;$blocked['independent_blockers']=['context_wait'];
check(PaperReviewPack::bucket($selected)===null&&PaperReviewPack::bucket($blocked)==='ready_blocked','a selected trade is not also counted as a blocked structure');
check(PaperReviewPack::caseId('d','selected','005930.KS',1,'trend_pullback:selected')===PaperReviewPack::caseId('d','selected','005930.KS',1,'trend_pullback:selected'),'case ids do not depend on the later return');
$order=[['case_id'=>'b'],['case_id'=>'a'],['case_id'=>'c']];
check(array_column(PaperReviewPack::takeByCaseId($order,2),'case_id')===['a','b'],'extra structures are cut by case id, not by a later return');
$dirty=$bars;$dirty[8]['close']=$dirty[8]['high']+40;
$shown=PaperReviewCharts::completedThrough($dirty,$asOf);
$ma=PaperReviewCharts::view($shown,$asOf,count($shown));
$validCloses=[];foreach($shown as $bar)if(empty($bar['ohlc_invalid']))$validCloses[]=$bar['close'];
$plain=PaperReviewCharts::sma($validCloses,20);
$lastValid=null;foreach($ma['ma20'] as $i=>$v)if($v!==null&&empty($shown[$i]['ohlc_invalid']))$lastValid=$v;
check($lastValid===$plain[array_key_last($plain)],'moving averages skip an invalid close the same way the engine drops that bar');
$empty=PaperReviewCharts::svg(['bars'=>[],'ma20'=>[],'ma60'=>[],'last_at'=>0],[],'빈 창',true);
check(str_contains($empty,'이 창에 그릴 완료 봉이 없다'),'an empty window is labeled instead of inventing a candle');

$pane=90.0;
check(PaperReviewCharts::volumeHeight(400,$pane===0?1:400,$pane)/PaperReviewCharts::volumeHeight(100,400,$pane)===4.0,'volume height grows in proportion to volume');
$volBars=[];
foreach([100,400] as $i=>$volume){
    $day=(new DateTimeImmutable('2026-03-02',$kst))->modify('+'.$i.' days')->setTime(15,30);
    $volBars[]=['available_at'=>$day->getTimestamp(),'open'=>10,'high'=>11,'low'=>9,'close'=>10,'volume'=>$volume,'ohlc_invalid'=>false];
}
$volView=['bars'=>$volBars,'ma20'=>[null,null],'ma60'=>[null,null],'last_at'=>$volBars[1]['available_at']];
$volSvg=PaperReviewCharts::svg($volView,[],'거래량',true,'005930.KS');
preg_match_all('/<rect x="[0-9.]+" y="([0-9.]+)" width="[0-9.]+" height="([0-9.]+)"/',$volSvg,$volRects,PREG_SET_ORDER);
$heights=[];
foreach($volRects as $rect)if((float)$rect[1]>300)$heights[]=(float)$rect[2];
check(count($heights)===2&&abs(($heights[1]/$heights[0])-(400/100))<0.001&&$heights[0]!==4.4,'drawn volume bars keep the volume ratio and do not use a fixed height');
check(str_contains($volSvg,'2026-03-02')&&str_contains($volSvg,'2026-03-03'),'the chart prints the bar dates');
check(str_contains($volSvg,'text-anchor="end"')&&str_contains($volSvg,'text-anchor="start"'),'the first and last date labels stay inside the chart');

$spell=[
    ['symbol'=>'A','pattern'=>'breakout_retest','status'=>'await_retest','session'=>1,'breakout_at'=>10],
    ['symbol'=>'A','pattern'=>'breakout_retest','status'=>'await_retest','session'=>2,'breakout_at'=>10],
    ['symbol'=>'A','pattern'=>'breakout_retest','status'=>'invalidated','session'=>3,'breakout_at'=>10],
    ['symbol'=>'A','pattern'=>'trend_pullback','status'=>'wait_pullback','session'=>4],
    ['symbol'=>'A','pattern'=>'trend_pullback','status'=>'wait_pullback','session'=>5],
    ['symbol'=>'A','pattern'=>'trend_pullback','status'=>'await_confirmation','session'=>6],
    ['symbol'=>'A','pattern'=>'trend_recovery','status'=>'no_upper_target','session'=>7,'breakdown_at'=>9],
];
$kept=PaperReviewPack::firstStructures($spell);
check(count($kept)===5,'repeated days of one structure and status stay one case, and a new status is separate');
$upper=['pattern'=>'trend_recovery','operational_ready'=>false,'raw'=>['ready'=>false,'status'=>'no_upper_target'],'independent_blockers'=>[],'stage'=>null];
check(PaperReviewPack::bucket($upper)===null,'no_upper_target is not filed as the whole waiting bucket');
echo "OK\n";
