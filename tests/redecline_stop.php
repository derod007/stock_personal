<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';require __DIR__.'/../bin/paper/RedeclineStudy.php';
use ChartEntryLab\TradeSimulator;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
const SYM='000000.KS';
function bar(int $i,float $o,float $h,float $l,float $c):array{return ['available_at'=>1000000+$i*86400,'open'=>$o,'high'=>$h,'low'=>$l,'close'=>$c,'volume'=>1000];}

// ---------- rule: synthetic series, 130 bars. Big high at 60, low at 90, re-decline high (strict pivot) at 105, last bar 129 ----------
function series(array $over=[],int $n=130):array
{
    $b=[];
    for($i=0;$i<$n;$i++){
        $m=100.0;
        if($i<=60)$m=100+$i;                         // rise to 160
        elseif($i<=90)$m=160-($i-60)*2;              // fall to 100
        elseif($i<=105)$m=100+($i-90)*2;             // rebound to 130 (lower than the big high)
        else $m=130-($i-105)*0.8;                    // new decline
        $b[]=bar($i,$m,$m+1,$m-1,$m);
    }
    $b[60]['high']=200;                              // unique window high
    $b[90]['low']=50;                                // unique low after it
    $b[105]['high']=140;                             // strict pivot high lower than 200
    foreach($over as $i=>$v)$b[$i]=array_replace($b[$i],$v);
    return $b;
}
$bars=series();$atr=2.5;
$r=PaperRedeclineStop::evaluate($bars,$atr,200.0);
check($r['status']==='applicable'&&$r['H_big']['index']===60&&$r['L_big']['index']===90,'big high and the low after it are found');
check($r['H_recent']['index']===105&&$r['rule_found_re_decline']===true&&$r['H_recent']['same_as_H_big']===false,'the last confirmed pivot high after the big low is the recent re-decline high');
$minAfter=min(array_column(array_slice($bars,106),'low'));
check($r['low_after_recent']['price']===$minAfter&&$r['alt_stop']===floor($minAfter-0.2*$atr),'alternative stop = lowest low after that high minus 0.2 ATR, truncated');
check(PaperRedeclineStop::evaluate($bars,$atr,$r['alt_stop'])['reason']==='alt_stop_invalid','an alternative stop at or above the planned entry is not applicable');

$noRe=series([],130);for($i=91;$i<130;$i++){$noRe[$i]=bar($i,100-($i-90)*0.1,102-($i-90)*0.1,99-($i-90)*0.1,100-($i-90)*0.1);}
$r2=PaperRedeclineStop::evaluate($noRe,$atr,200.0);
check($r2['status']==='applicable'&&$r2['H_recent']['same_as_H_big']===true&&$r2['rule_found_re_decline']===false,'without a re-decline pivot the recent high equals the big high');

$d=series([129=>['high'=>500]]);
check(PaperRedeclineStop::evaluate($d,$atr,900.0)['reason']==='h_big_none_manual_A_only','a window high on the signal day gives no automatic high (PR #64 cited the A mark)');
$few=series([128=>['high'=>500]]);
check(PaperRedeclineStop::evaluate($few,$atr,900.0)['reason']==='h_big_hold_few_bars_after','one bar after the window high is hold');
$edge=series([10=>['high'=>500]]);   // n=130 -> first=10, high at index 10 is inside the first 5 window bars
check(PaperRedeclineStop::evaluate($edge,$atr,900.0)['reason']==='h_big_hold_window_edge','a window high in the first 5 window bars is hold');
$short=array_slice(series(),0,100);
check(PaperRedeclineStop::evaluate($short,$atr,200.0)['flags']['window_edge']===false,'a window that starts at the first bar of data has no edge hold (PR #64)');
check(PaperRedeclineStop::evaluate($bars,0.0,200.0)['reason']==='data_insufficient','a missing ATR is data_insufficient');
check(PaperRedeclineStop::evaluate($bars,$atr,200.0,2)['reason']==='quality_problem','dropped rows in the window are a quality problem');

// ---------- no future bar can change the signal-day decision ----------
$rows=series();$session=$rows[125]['available_at'];
$clean=PaperRedeclineStop::forSignal(array_slice($rows,0,126),SYM,$session,$atr,200.0);
$leak=$rows;for($i=126;$i<130;$i++)$leak[$i]=bar($i,1,9999,0.5,2);   // wild future bars
$withFuture=PaperRedeclineStop::forSignal($leak,SYM,$session,$atr,200.0);
check($clean==$withFuture,'rows after the signal session never change the decision');
$ev=PaperRedeclineStop::evaluate(array_slice($rows,0,126),$atr,200.0);
check($ev==$clean,'forSignal equals evaluate on the same completed bars');
// a pivot needs three completed bars to its right: a spike two bars before the signal is not confirmed
$spike=series([123=>['high'=>131]]);$s=PaperRedeclineStop::forSignal(array_slice($spike,0,126),SYM,$spike[125]['available_at'],$atr,200.0);
check($s['H_recent']['index']!==123,'a high with fewer than three completed bars after it is not a confirmed pivot');
// quality: an invalid row inside the window is reported; an old one is not
$bad=$rows;$bad[100]=array_replace($bad[100],['high'=>10,'low'=>20]);
check(PaperRedeclineStop::forSignal(array_slice($bad,0,126),SYM,$session,$atr,200.0)['reason']==='quality_problem','an invalid row inside the window makes the case not applicable');
$old=$rows;$old[2]=array_replace($old[2],['high'=>10,'low'=>20]);
check(PaperRedeclineStop::forSignal(array_slice($old,0,126),SYM,$session,$atr,200.0)['status']==='applicable','an invalid row before the window does not');
$dup=$rows;$dup[]=array_replace($rows[100],['open'=>101]);usort($dup,fn($a,$b)=>$a['available_at']<=>$b['available_at']);
check(PaperRedeclineStop::forSignal(array_slice($dup,0,127),SYM,$session,$atr,200.0)['reason']==='quality_problem','a duplicated session inside the window is a quality problem');

// ---------- simulator equals the existing engine when the stop is unchanged (randomised differential) ----------
mt_srand(20261010);$diffs=0;$kinds=[];
for($k=0;$k<4000;$k++){
    $e=100;$s=round(100-mt_rand(2,15));$t=100+mt_rand(3,30);$plan=['ready'=>true,'entry'=>(float)$e,'stop'=>(float)$s,'target'=>(float)$t,'signal_at'=>0,'order_valid_bars'=>3];
    $bs=[];$p=100.0;
    for($i=1;$i<=24;$i++){
        $o=$p*(1+mt_rand(-40,40)/1000);$h=max($o,$o*(1+mt_rand(0,40)/1000));$l=min($o,$o*(1-mt_rand(0,40)/1000));$c=$l+($h-$l)*mt_rand(0,100)/100;
        $bs[]=['available_at'=>$i*86400,'open'=>$o,'high'=>$h,'low'=>$l,'close'=>$c,'volume'=>1];$p=$c;
    }
    $bs=array_slice($bs,0,mt_rand(1,24));
    $a=(new TradeSimulator())->simulate($plan,$bs,20);$b=(new PaperRedeclineSimulator())->simulate($plan,$bs,20);$c=(new PaperRedeclineSimulator())->simulate($plan,$bs,20,(float)$s);
    if($a!=$b||$a!=$c)$diffs++;$kinds[$a['status']]=($kinds[$a['status']]??0)+1;
}
check($diffs===0&&count($kinds)>=4,'4000 random cases: identical to TradeSimulator when the stop is unchanged ('.json_encode($kinds).')');

// ---------- the changed stop ----------
$plan=['ready'=>true,'entry'=>100.0,'stop'=>90.0,'target'=>130.0,'signal_at'=>0,'order_valid_bars'=>3];
function day(int $i,float $o,float $h,float $l,float $c):array{return ['available_at'=>$i*86400,'open'=>$o,'high'=>$h,'low'=>$l,'close'=>$c,'volume'=>1];}
$sim=new PaperRedeclineSimulator();$base=new TradeSimulator();
// wider stop keeps a trade the plan stop would have closed
$bars=[day(1,100,101,99,100),day(2,100,101,88,95),day(3,95,105,94,104)];
$bb=$base->simulate($plan,array_merge($bars,array_map(fn($i)=>day($i,104,105,103,104),range(4,25))),20);
$ww=$sim->simulate($plan,array_merge($bars,array_map(fn($i)=>day($i,104,105,103,104),range(4,25))),20,80.0);
check($bb['first_exit']==='stop'&&$ww['first_exit']==='time'&&$ww['bars']===20,'a wider post-fill stop turns a stop exit into a later exit');
// tighter stop closes earlier
$tt=$sim->simulate($plan,[day(1,100,101,99,100),day(2,100,101,94,97),day(3,97,99,96,98)],20,95.0);
check($tt['first_exit']==='stop'&&abs($tt['exit_fill']-95*(1-0.0005))<1e-6&&$tt['exit_at']===2*86400,'a tighter post-fill stop exits at that level on the first touching bar');
// gap through the changed stop fills at the open
$gg=$sim->simulate($plan,[day(1,100,101,99,100),day(2,92,93,91,92)],20,95.0);
check($gg['first_exit']==='stop'&&abs($gg['exit_fill']-92*(1-0.0005))<1e-6,'an open below the changed stop exits at the open (gap)');
// same bar stop and target: stop wins and the bar is marked ambiguous
$am=$sim->simulate($plan,[day(1,100,101,99,100),day(2,100,131,94,100)],20,95.0);
check($am['first_exit']==='stop'&&$am['ambiguous_bar']===true,'stop and target in the same later bar: stop first, marked ambiguous');
// target gap on a later session
$tg=$sim->simulate($plan,[day(1,100,101,99,100),day(2,131,135,130,133)],20,95.0);
check($tg['first_exit']==='target'&&$tg['exit_fill']===130.0,'a later open at the target exits at the target');
// entry-day stop with the changed stop: fill then stop in the same bar
$ed=$sim->simulate($plan,[day(1,99,100,94,96)],20,95.0);
check($ed['status']==='closed'&&$ed['bars']===1&&$ed['first_exit']==='stop','fill and changed stop in the entry bar: closed on that bar');
// alternative stop at/above the real fill: no fill repair, separate status
$af=$sim->simulate($plan,[day(1,96,100,95,98)],20,97.0);   // fill = min(100, 96*1.0005)=96.048 < 97
check($af['status']==='alt_stop_not_below_fill'&&$af['filled']===true&&$af['net_return_pct']===null,'an alternative stop at or above the fill price is reported, not repaired');
// the plan stop, not the alternative, governs the cancel rule before the fill
$pre=[day(1,96,99,95,97),day(2,96,100,95,98)];   // bar 1 opens above plan stop 90 but below alt 97 and does not reach 100 -> no fill
$pf=$sim->simulate($plan,$pre,20,97.0);
$pb=$base->simulate($plan,$pre,20);
check($pf['status']==='alt_stop_not_below_fill'&&$pf['entry_at']===86400,'an alternative stop above the open does not cancel the order; it fills and is then reported');
check($pb['status']==='incomplete'&&$pb['entry_at']===86400,'(control) the plan-stop engine fills the same bar');
$cancel=$sim->simulate($plan,[day(1,89,95,88,90)],20,80.0);
check($cancel['status']==='cancelled_before_entry'&&$cancel['filled']===false,'an open at or below the plan stop still cancels before the fill, even with a lower alternative stop');
$un=$sim->simulate($plan,[day(1,105,106,102,104),day(2,105,106,102,104),day(3,105,106,102,104),day(4,100,101,99,100)],20,80.0);
check($un['status']==='unfilled','an order that never fills within three bars stays unfilled');
$pe=$sim->simulate($plan,[day(1,100,101,99,100)],20,80.0);
check($pe['status']==='incomplete','a filled trade without enough later bars is incomplete, not closed');
$bad=$sim->trade($plan,[day(1,100,101,99,100),null,day(3,100,101,99,100)],95.0);
check($bad['status']==='future_quality_blocked','a missing future bar blocks instead of compressing time');

// ---------- summary: unfilled / not applicable / incomplete are never zero returns ----------
function srow(string $id,string $period,string $bs,?string $be,?float $bn,?string $as,?string $ae,?float $an,string $sig='applicable',?string $reason=null,bool $filled=true,float $rb=5.0,float $ra=10.0):array
{
    $row=['id'=>$id,'period'=>$period,'symbol'=>'x','name'=>$id,'date'=>'2026-01-01','session'=>1,'plan'=>[],'alt_stop'=>1,
        'baseline'=>['status'=>$bs,'filled'=>$filled,'first_exit'=>$be,'net_return_pct'=>$bn,'stop'=>90,'risk_signal_pct'=>$rb,'risk_fill_pct'=>$filled?$rb:null,'gap_stop'=>false,'ambiguous_bar'=>false],
        'applicability'=>['signal'=>$sig,'reason'=>$reason,'post_fill'=>null]];
    $row['alt']=$sig==='applicable'?['status'=>$as,'filled'=>$filled,'first_exit'=>$ae,'net_return_pct'=>$an,'stop'=>80,'risk_signal_pct'=>$ra,'risk_fill_pct'=>$filled?$ra:null,'gap_stop'=>false,'ambiguous_bar'=>false]:null;
    return $row;
}
$rows=[srow('a','x','closed','target',10.0,'closed','target',10.0),srow('b','x','closed','stop',-6.0,'closed','stop',-12.0),srow('c','x','closed','target',8.0,'closed','stop',-12.0),
    srow('d','x','closed','stop',-5.0,'incomplete',null,null),srow('e','x','incomplete',null,null,'closed','stop',-9.0),srow('f','x','unfilled',null,null,'unfilled',null,null,filled:false),
    srow('g','x','closed','stop',-5.0,'x',null,null,'not_applicable','h_big_hold_window_edge'),srow('h','x','closed','time',2.0,'alt_stop_not_below_fill',null,null)];
$s=PaperRedeclineStudy::summarize($rows);
check($s['selected']===8&&$s['applicability']['applicable']===7&&$s['applicability']['not_applicable']===1&&$s['applicability']['post_fill_not_applicable']===1,'applicability counts add up');
check($s['common_closed']['n']===3&&$s['common_closed']['improved']===0&&$s['common_closed']['worse']===2&&$s['common_closed']['same']===1,'common closed trades are only those closed on both sides');
check($s['common_closed']['baseline']['mean']===4.0&&$s['common_closed']['alt']['mean']===-4.6667,'unfilled, incomplete and not applicable trades are not averaged as zero');
check($s['exits_common_closed']['baseline_winner_became_alt_stop_exit']===1&&count($s['only_baseline_closed'])===1&&count($s['only_alt_closed'])===1,'a baseline winner turned into a stop exit and one-sided closes are listed separately');
check($s['transitions']['closed']['not_applicable']===1&&$s['transitions']['unfilled']['unfilled']===1,'the transition table keeps not applicable as its own column');
check($s['big_loss']['baseline_count']===0&&$s['big_loss']['alt_count']===2&&count($s['big_loss']['increased_to_threshold'])===2,'big loss counts use the fixed -10% threshold');
check($s['R_common_closed']['common_denominator_baseline_risk']['alt']['mean']===round((10/5-12/5-12/5)/3,4)&&$s['R_common_closed']['own_initial_risk']['alt']['mean']===round((10/10-12/10-12/10)/3,4),'R is reported with the baseline denominator and with each own denominator');
$same=srow('s1','x','closed','target',5.0,'closed','target',5.0);$same['plan']=['stop'=>90];$same['alt_stop']=90;
$tight=srow('s2','x','closed','stop',-4.0,'closed','stop',-3.0);$tight['plan']=['stop'=>90];$tight['alt_stop']=92;
$wide=srow('s3','x','closed','stop',-6.0,'closed','target',7.0);$wide['plan']=['stop'=>90];$wide['alt_stop']=85;
$s2=PaperRedeclineStudy::summarize([$same,$tight,$wide]);
check($s2['applicability']['stop_level_vs_baseline']==['same_level'=>1,'tighter_higher'=>1,'wider_lower'=>1],'stop levels are classified as same, tighter or wider');
check($s2['common_closed_stop_level_changed']['n']===2&&$s2['common_closed_stop_level_changed']['improved']===2&&$s2['common_closed']['same']===1,'the changed-level subset leaves out identical levels');
echo "OK\n";
