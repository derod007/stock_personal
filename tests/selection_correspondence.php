<?php
declare(strict_types=1);
/**
 * Completed-bar correspondence: ChartPlanEngine plan, ChartPlanEngine::apply, KrAmountScanner::candidateFromProposal,
 * PaperScanUniverse::symbols. The expectations are the engines' own outputs. This file does not restate their conditions.
 *
 * Not a reproduction of the daily amount TOP100 or of a separate price collection.
 */
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/fixtures/trend_recovery_bars.php';
use ChartEntryLab\{CandleClock,ChartPlanEngine,KrAmountScanner,PaperScanUniverse};

function sel(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('FAIL '.$why);echo "PASS $why\n";}
function bar(string $day,float $open,float $high,float $low,float $close,int $volume=1000):array{
    $t=new DateTimeImmutable($day.' 09:00:00',new DateTimeZone('Asia/Seoul'));
    return ['time'=>$t->getTimestamp(),'time_kst'=>$t->format('Y-m-d H:i:s'),'open'=>$open,'high'=>$high,'low'=>$low,'close'=>$close,'volume'=>$volume];
}
function retestBars():array{
    $fixture=[];$date=new DateTimeImmutable('2026-01-05');
    for($i=0;$i<45;$i++){
        $day=$date->modify('+'.$i.' weekdays')->format('Y-m-d');
        $fixture[]=bar($day,97,$i%5===0?100:98,$i%5===2?94:96,97);
    }
    $next=new DateTimeImmutable(substr($fixture[44]['time_kst'],0,10));
    $fixture[]=bar($next->modify('+1 weekday')->format('Y-m-d'),99.5,101.5,99,101,2000);
    $fixture[]=bar($next->modify('+2 weekdays')->format('Y-m-d'),101,101.2,99.8,100.5,700);
    $fixture[]=bar($next->modify('+3 weekdays')->format('Y-m-d'),100.7,102.2,100.4,101.8,1200);
    return $fixture;
}
function pullBars(bool $lift):array{
    $bars=[];$start=new DateTimeImmutable('2025-10-06');
    for($i=0;$i<60;$i++){
        $close=100+$i*0.6;
        if($i>=55)$close=[132,130.5,129.2,128.8,131.2][$i-55];
        $open=$i===59?129.0:$close-0.3;
        $vol=$i>=56&&$i<=58?500:($i===59?1600:1000);
        $bars[]=bar($start->modify('+'.$i.' weekdays')->format('Y-m-d'),$open,$close+0.5,min($open,$close)-0.3,$close,$vol);
    }
    if($lift){$bars[48]['high']=200;$bars[48]['close']=190;}
    return $bars;
}
function sharpPull():array{
    $bars=[];$start=new DateTimeImmutable('2025-10-06');
    for($i=0;$i<60;$i++){
        $close=$i<30?50+$i:80+($i-30)*0.4;
        if($i>=55)$close=[88,86,84.5,84,85.5][$i-55];
        $open=$i===59?84.2:$close-0.2;
        $vol=$i>=56&&$i<=58?500:($i===59?1400:1000);
        $bars[]=bar($start->modify('+'.$i.' weekdays')->format('Y-m-d'),$open,$close+0.8,min($open,$close)-0.4,$close,$vol);
    }
    return $bars;
}
/** The operational chain on one completed-bar series. */
function chain(array $bars,string $symbol='005930.KS'):array{
    $bars=CandleClock::completed($bars,$symbol,PHP_INT_MAX);
    $asOf=$bars[array_key_last($bars)]['available_at'];
    $analysis=(new ChartPlanEngine())->analyze($bars,$symbol,$asOf);
    $proposal=(new ChartPlanEngine())->apply([],$analysis['plan']);
    $cand=KrAmountScanner::candidateFromProposal($proposal);
    $row=['yahoo'=>$symbol,'entry_recommend'=>$cand['entry_recommend'],'buy_now'=>$cand['buy_now'],'sector_bucket'=>'semi'];
    $picked=PaperScanUniverse::symbols(['ok'=>true,'rows'=>[$row]]);
    $plan=$analysis['plan'];
    return ['status'=>$plan['status'],'ready'=>!empty($plan['ready']),'pattern'=>$plan['pattern']??null,
        'patterns'=>$plan['diagnostics']['patterns'],'spike'=>$analysis['features']['spike_dump_status']??null,
        'top'=>$analysis['features']['top_pattern_status']??null,'action'=>$cand['action'],
        'order_ready'=>$cand['order_ready'],'buy_now'=>$cand['buy_now'],'entry_recommend'=>$cand['entry_recommend'],
        'selected'=>isset($picked[$symbol]),'row'=>$row];
}
function agree(array $c,string $label,string $key,string $patternStatus,string $final,bool $ready):array{
    $got=$c['patterns'][$key]['status']??null;
    sel($got===$patternStatus,$label.' pattern status is '.$patternStatus.' ('.$got.')');
    sel($c['status']===$final&&$c['ready']===$ready,$label.' published plan is '.$final);
    $same=$c['ready']===$c['order_ready']&&$c['order_ready']===$c['entry_recommend']&&$c['entry_recommend']===$c['selected'];
    sel($same,$label.' plan.ready, order_ready, scanner candidate and universe selection agree');
    sel($c['buy_now']===false,$label.' completed-bar projection leaves buy_now off');
    return ['label'=>$label,'pattern_key'=>$key,'pattern_status'=>$got,'final_status'=>$c['status'],'ready'=>$c['ready'],
        'action'=>$c['action'],'order_ready'=>$c['order_ready'],'entry_recommend'=>$c['entry_recommend'],'buy_now'=>$c['buy_now'],
        'selected'=>$c['selected'],'agree'=>$same,'spike'=>$c['spike'],'top'=>$c['top']];
}

$retest=retestBars();
$rows=[];
$rows[]=agree(chain($retest),'breakout ready','breakout_retest','ready','ready',true);
$rows[]=agree(chain(array_slice($retest,0,46)),'breakout waiting','breakout_retest','await_retest','await_retest',false);
$rr=$retest;$rr[0]['high']=103;
$rows[]=agree(chain($rr),'breakout reward/risk rejected','breakout_retest','rejected_rr','rejected_rr',false);
$dump=$retest;
$dump[]=bar('2026-04-01',102,140,100,135,5000);
$dump[]=bar('2026-04-02',120,121,88,90,4000);
$dumpCase=chain($dump);
$rows[]=agree($dumpCase,'breakout common risk block','breakout_retest','invalidated','risk_blocked',false);
sel($dumpCase['spike']==='confirmed','breakout risk block comes from the operational spike guard');

$pull=pullBars(true);
$rows[]=agree(chain($pull),'pullback ready','trend_pullback','ready','ready',true);
$rows[]=agree(chain(array_slice($pull,0,59)),'pullback waiting','trend_pullback','await_confirmation','await_confirmation',false);
$rows[]=agree(chain(pullBars(false)),'pullback reward/risk rejected','trend_pullback','rejected_rr','rejected_rr',false);
$sharp=chain(sharpPull());
$rows[]=agree($sharp,'pullback common risk block','trend_pullback','ready','risk_blocked',false);
sel($sharp['top']==='warning'&&$sharp['ready']===false,'a ready pullback stays unpublished when the operational top guard fires');

$rec=recovery_bars();
$rows[]=agree(chain($rec),'recovery ready','trend_recovery','ready','ready',true);
$wait=$rec;$wait[64]['close']=100.0;
$rows[]=agree(chain(array_slice($wait,0,65)),'recovery waiting','trend_recovery','await_recovery','context_wait',false);
$rows[]=agree(chain(recovery_bars(116)),'recovery reward/risk rejected','trend_recovery','rejected_rr','rejected_rr',false);
$risk=$rec;$risk[48]['high']=125;
$riskCase=chain($risk);
$rows[]=agree($riskCase,'recovery common risk block','trend_recovery','ready','risk_blocked',false);
sel($riskCase['top']==='confirmed'&&$riskCase['patterns']['trend_recovery']['ready']&&!$riskCase['ready'],'a ready recovery stays unpublished when the operational top guard fires');

$waiting=chain(array_slice($retest,0,46));
$withHeld=PaperScanUniverse::symbols(['ok'=>true,'rows'=>[$waiting['row']]],['000660.KS'],['000660.KS'=>'fin']);
sel(!isset($withHeld['005930.KS'])&&($withHeld['000660.KS']??null)==='fin','a holding stays in the evaluated set when it is not a candidate');
$absent=PaperScanUniverse::symbols(['ok'=>true,'rows'=>[]],['000660.KS'],['000660.KS'=>'fin']);
sel($absent===['000660.KS'=>'fin'],'a holding is kept even when the scan list is empty');
$readyRow=chain($retest)['row'];
$non=$waiting['row'];$non['yahoo']='068270.KS';
$both=PaperScanUniverse::symbols(['ok'=>true,'rows'=>[$readyRow,$non]],['035420.KS'],['035420.KS'=>'soft']);
sel(isset($both['005930.KS'],$both['035420.KS'])&&!isset($both['068270.KS']),'a candidate and a holding are kept, and a non-candidate is not');

$doc=['schema'=>1,'kind'=>'selection_correspondence_v1',
    'note'=>'완료봉을 운영 ChartPlanEngine, 그 결과의 apply, 스캐너의 candidateFromProposal, PaperScanUniverse::symbols에 같은 순서로 넣었다. 매일 거래대금 TOP100 선별과 별도의 가격 수집은 재현하지 않았다.',
    'cases'=>$rows,'holdings_remain_without_a_candidate'=>true];
$path=dirname(__DIR__).'/docs/historical-account-replay/selection-check.json';
file_put_contents($path,json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");
echo "SELECTION_PASS\n";
