<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/PullbackYearReview.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}

$closed=[];
for($i=0;$i<42;$i++)$closed[]=['symbol'=>sprintf('%06d.KS',$i),'signal_date'=>sprintf('2025-%02d-%02d',10+intdiv($i,28),1+$i%28),
    'pattern'=>'trend_pullback_v1','status'=>'closed','entry'=>1000+$i,'stop'=>900,'target'=>1300,
    'exit'=>['net_pnl'=>$i%2===0?1000.0+$i:-500.0-$i,'date'=>'2025-11-01','price'=>1100,'reason'=>'time']];
$extra=[
    ['symbol'=>'999999.KS','signal_date'=>'2025-10-01','pattern'=>'trend_pullback_v1','status'=>'open','exit'=>null],
    ['symbol'=>'888888.KS','signal_date'=>'2025-10-01','pattern'=>'trend_pullback_v1','status'=>'cancelled','exit'=>null],
    ['symbol'=>'777777.KS','signal_date'=>'2025-10-01','pattern'=>'breakout_retest_v1','status'=>'closed','exit'=>['net_pnl'=>9e9]],
];
$got=PaperPullbackYearReview::extract(['trades'=>array_merge($extra,$closed)]);
check(count($got)===42,'extract keeps every closed pullback and drops other rows');
check($got[0]['symbol']==='000000.KS'&&$got[41]['symbol']==='000041.KS','extract orders by date then symbol, not PnL');
$ids=array_column($got,'case_id');
check(count($ids)===count(array_unique($ids)),'case ids are unique');
check($ids===array_map(fn($t)=>PaperPullbackYearReview::caseId($t['symbol'],$t['signal_date']),$got),'case ids ignore later PnL');

$diff=PaperPullbackYearReview::diffs(['entry'=>100,'stop'=>90,'target'=>130,'pattern'=>'trend_pullback_v1'],
    ['entry'=>110,'stop'=>90,'target'=>130,'version'=>'trend_pullback_v1','ready'=>true,'reward_risk'=>2.0],
    ['pattern'=>'trend_pullback_v1','ready'=>true]);
check(in_array('entry',array_column($diff,'field'),true),'a changed stored entry is recorded, not patched');
$ready=PaperPullbackYearReview::diffs(['entry'=>100,'stop'=>90,'target'=>130,'pattern'=>'trend_pullback_v1'],
    ['entry'=>100,'stop'=>90,'target'=>130,'version'=>'trend_pullback_v1','ready'=>false,'reward_risk'=>3.0],
    ['pattern'=>'trend_recovery_v1','ready'=>false]);
check(in_array('ready',array_column($ready,'field'),true)&&in_array('selected_pattern',array_column($ready,'field'),true),'ready=false and another pattern are recorded');

$kst=new DateTimeZone('Asia/Seoul');$bars=[];
for($i=0;$i<80;$i++){
    $day=(new DateTimeImmutable('2025-06-02',$kst))->modify('+'.$i.' days');
    if((int)$day->format('N')>=6)continue;
    $t=$day->setTime(15,30)->getTimestamp();$p=1000+$i;
    $bars[]=['available_at'=>$t,'open'=>$p,'high'=>$p+5,'low'=>$p-5,'close'=>$p+1,'volume'=>1000+$i*10,'is_complete'=>true];
}
$asOf=(int)end($bars)['available_at'];
$completed=PaperReviewCharts::completedThrough($bars,$asOf);
$before=PaperReviewCharts::view($completed,$asOf,120);
$extraBar=$bars;$extraBar[]=['available_at'=>$asOf+86400,'open'=>9,'high'=>9,'low'=>9,'close'=>9,'volume'=>9,'is_complete'=>true];
$after=PaperReviewCharts::view(PaperReviewCharts::completedThrough($extraBar,$asOf),$asOf,120);
check($before===$after,'a later bar does not change the A window');
$win=PaperPullbackYearReview::windows($completed,['atr14'=>10]);
check(($win['pullback_interval_start']??'')==='unrecorded','windows do not invent a pullback start');
check(($win['stop_window']['bar_count']??0)===10&&($win['target_window']['bar_count']??0)===20,'code windows keep 10-bar stop and 20-bar target');
$svg=PaperReviewCharts::svg($before,[],PaperHistoryResearch::day($asOf).' 까지 · 120거래일',true,'005930.KS');
check(str_contains($svg,PaperHistoryResearch::day($asOf)),'chart prints the decision date');
check(PaperPullbackYearReview::volumeCheck(),'volume height stays proportional');
$a=['case_id'=>'x','symbol'=>'005930.KS','name'=>'삼성전자','session_date'=>'2025-10-13','session'=>$asOf,'completed_bars'=>count($completed),'last_available_at'=>$asOf];
foreach(PaperPullbackYearReview::A_FORBIDDEN as $k)check(!array_key_exists($k,$a),'A fixture has no '.$k);
echo "OK\n";
