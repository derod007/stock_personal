<?php
declare(strict_types=1);
require __DIR__.'/../bin/paper/KrxContinuityReview.php';
require __DIR__.'/fixtures/trend_recovery_bars.php';
use ChartEntryLab\{TrendRecovery,BreakoutRetest,TrendPullback,FeatureEngine,CandleClock};
$n=0;
function ck(bool $v,string $why):void {global $n;if(!$v)throw new RuntimeException('FAIL '.$why);$n++;echo "OK $why\n";}
function wrap(array $p,string $key,array $features=[]):array {
    $p['pattern']=$p['version'];$p['diagnostics']['patterns'][$key]=$p;return ['plan'=>$p,'features'=>$features];
}
$b=recovery_bars();$p=wrap((new TrendRecovery())->analyze($b),'trend_recovery');$day=PaperKrxContinuityReview::day(end($b));
ck($p['plan']['ready'],'recovery fixture ready');
$original=serialize([$b,$p]);$a=PaperKrxContinuityReview::trace($b,$p,[],$day);
ck($a['status']==='no_crossing_in_traced_scope'&&!$a['account_safe'],'clean trace is not account certification');
$e=[['from'=>PaperKrxContinuityReview::day($b[39]),'to'=>PaperKrxContinuityReview::day($b[40])]];
$a=PaperKrxContinuityReview::trace($b,$p,$e,$day);
ck($a['status']==='hold_cross_break_source','old recovery target crosses observed break');
ck(in_array('target_source',array_column($a['crossings'],'role'),true),'target role identified');
ck($a['crossings'][0]['corporate_action']==='미확정','unconfirmed event stays unconfirmed');
ck(serialize([$b,$p])===$original,'input and plan unchanged');
$future=[['from'=>'2030-01-01','to'=>'2030-01-02']];
ck(PaperKrxContinuityReview::trace($b,$p,$future,$day)===PaperKrxContinuityReview::trace($b,$p,[],$day),'future break cannot alter past review');
$early=[['from'=>'2000-01-01','to'=>PaperKrxContinuityReview::day($b[0])]];
ck(!PaperKrxContinuityReview::trace($b,$p,$early,$day)['research_hold'],'sources on or after break are not pre-break');
$q=$p;$q['plan']['target']++;
ck(PaperKrxContinuityReview::trace($b,$q,[],$day)['status']==='hold_unresolved_provenance','target mutation fails closed');
$q=$p;unset($q['plan']['diagnostics']['patterns']['trend_recovery']['evidence']['target_source']);
ck(PaperKrxContinuityReview::trace($b,$q,[],$day)['status']==='hold_unresolved_provenance','missing provenance fails closed');
$q=$p;$q['plan']['diagnostics']['patterns']['trend_recovery']['evidence']['higher_low']['confirmed_at']+=86400;
ck(PaperKrxContinuityReview::trace($b,$q,[],$day)['status']==='hold_unresolved_provenance','wrong pivot confirmation fails closed');
ck(PaperKrxContinuityReview::trace($b,$p,[],PaperKrxContinuityReview::day($b[68]))['research_hold'],'future bar input rejected');
// Box breakout / retest fixture, including an older target outside the local box.
$b=[];$start=new DateTimeImmutable('2026-01-05',new DateTimeZone('Asia/Seoul'));
$make=function(int $i,float $o,float $h,float $l,float $c,int $v=1000)use($start):array{
    $d=$start->modify('+'.$i.' weekdays')->format('Y-m-d');return ['time_kst'=>$d.' 09:00:00',
        'available_at'=>PaperDatedTop100Replay::sessionClose($d),'open'=>$o,'high'=>$h,'low'=>$l,'close'=>$c,'volume'=>$v];
};
for($i=0;$i<45;$i++)$b[]=$make($i,97,$i%5===0?100:98,$i%5===2?94:96,97);
$b[]=$make(45,99.5,101.5,99,101,2000);$b[]=$make(46,101,101.2,99.8,100.5,700);$b[]=$make(47,100.7,102.2,100.4,101.8,1200);
$p=wrap((new BreakoutRetest())->analyze($b),'breakout_retest');$day=PaperKrxContinuityReview::day(end($b));
ck($p['plan']['ready'],'retest fixture ready');
$a=PaperKrxContinuityReview::trace($b,$p,[],$day);
ck($a['status']==='no_crossing_in_traced_scope'&&in_array('target_box_projection',array_column($a['sources'],'role'),true),'box projection reproduced');
$b[0]['high']=105;$b[1]['high']=105;
$p=wrap((new BreakoutRetest())->analyze($b),'breakout_retest');
ck($p['plan']['ready'],'old overhead fixture ready');
$a=PaperKrxContinuityReview::trace($b,$p,[['from'=>PaperKrxContinuityReview::day($b[9]),'to'=>PaperKrxContinuityReview::day($b[10])]],$day);
$hit=array_values(array_filter($a['crossings'],fn($r)=>$r['role']==='target_known_high'));
ck($a['research_hold']&&count($hit)===1&&$hit[0]['source_from']===PaperKrxContinuityReview::day($b[0]),'old target and first equal-high source traced');
// Pullback: real formula output, then independent provenance verification.
$b=[];for($i=0;$i<60;$i++){
    $c=100+$i*0.6;if($i>=55)$c=[132,130.5,129.2,128.8,131.2][$i-55];
    $o=$i===59?129:$c-0.3;$v=$i>=56&&$i<=58?500:($i===59?1600:1000);
    $b[]=$make($i,$o,$c+0.5,min($o,$c)-0.3,$c,$v);
}
$b[48]['high']=200;$b[48]['close']=190;
$b=CandleClock::completed($b,'005930.KS',PHP_INT_MAX);$features=(new FeatureEngine())->extract($b);
$p=wrap((new TrendPullback())->analyze($b,$features),'trend_pullback',$features);$day=PaperKrxContinuityReview::day(end($b));
ck($p['plan']['ready'],'pullback fixture ready');
$a=PaperKrxContinuityReview::trace($b,$p,[],$day);
ck($a['status']==='no_crossing_in_traced_scope','pullback target stop ATR reproduced');
$a=PaperKrxContinuityReview::trace($b,$p,[['from'=>PaperKrxContinuityReview::day($b[44]),'to'=>PaperKrxContinuityReview::day($b[45])]],$day);
ck($a['research_hold']&&in_array('local_structure_and_gates',array_column($a['crossings'],'role'),true),'local rising structure crossing held even if target after break');
$q=$p;$q['features']['atr14']++;
ck(PaperKrxContinuityReview::trace($b,$q,[],$day)['status']==='hold_unresolved_provenance','ATR corruption held');
ck(PaperKrxContinuityReview::trace($b,$p,[],$day)===PaperKrxContinuityReview::trace($b,$p,[],$day),'same plan same arm-independent guard');
echo "PASS $n checks\n";
