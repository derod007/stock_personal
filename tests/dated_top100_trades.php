<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require_once __DIR__.'/../bin/paper/DatedTop100Trades.php';
require_once __DIR__.'/../bin/paper/StrategyVersion.php';

function t100check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('FAIL '.$why);echo "PASS $why\n";}

$root=dirname(__DIR__);
$fp='934012285f2ddb3867fff4576dba05fb7df290a36a987c36fc260e8917bbe465';
t100check(PaperStrategyVersion::current($root)===$fp,'operational strategy fingerprint is unchanged');

$tradesPath=$root.'/'.PaperDatedTop100Trades::TRADES;
$summaryPath=$root.'/'.PaperDatedTop100Trades::SUMMARY;
$universePath=$root.'/'.PaperDatedTop100Trades::UNIVERSE;
foreach([$tradesPath,$summaryPath,$universePath] as $p)t100check(is_file($p),'stored file exists '.$p);
$before=[hash_file('sha256',$tradesPath),hash_file('sha256',$summaryPath),hash_file('sha256',$universePath)];

$r=PaperDatedTop100Trades::load($root);
t100check($r['ok']===true && $r['issues']===[],'stored counts and realized sum reconcile');
t100check($r['account']===PaperDatedTop100Trades::ACCOUNT,'research account id is fixed');
t100check($r['order_count']===68 && $r['fill_count']===59 && $r['closed_count']===56 && $r['open_count']===3 && $r['cancel_count']===9,'68 orders split into 59 fills and 9 cancels');
t100check(count($r['fills'])===59 && count($r['cancelled'])===9,'fill and cancel arrays match counts');
$keys=[];
foreach($r['fills'] as $row){
    $key=$row['symbol'].'|'.$row['signal_date'].'|'.$row['buy_date'];
    t100check(!isset($keys[$key]),'no duplicate fill '.$key);
    $keys[$key]=true;
}
t100check(count($keys)===59,'59 unique fill keys');
$closedPnl=0.0;$open=0;$closed=0;
foreach($r['fills'] as $row){
    if($row['status']==='closed'){$closed++;$closedPnl+=(float)$row['net_pnl'];t100check($row['net_pnl']!==null && $row['sell_date']!==null,'closed trade has stored exit');}
    else {$open++;t100check($row['net_pnl']===null && $row['net_return_pct']===null,'open trade is not in realized pnl or return');t100check($row['mark_date']!==null && $row['mark_price']!==null && $row['unrealized_pnl']!==null,'open trade has period-end mark');}
}
t100check($closed===56 && $open===3,'56 closed and 3 open in the fill list');
t100check(abs($closedPnl-(float)$r['summary_realized_pnl'])<=0.01,'closed net pnl equals stored summary realized_pnl');
t100check(abs($closedPnl-$r['closed_net_pnl'])<=1e-9,'view closed sum matches walk');
$openSym=array_column(array_filter($r['fills'],fn($x)=>$x['status']==='open'),'symbol');
sort($openSym);
t100check($openSym===['028050.KS','108490.KQ','217590.KS'],'open symbols match stored summary');
$buyDates=array_column($r['fills'],'buy_date');
$sorted=$buyDates;sort($sorted,SORT_STRING);
t100check($buyDates===$sorted,'default fill order is buy date');
$samsung=null;$first=null;
foreach($r['fills'] as $row){
    if($row['symbol']==='028050.KS')$samsung=$row;
    if($row['symbol']==='054540.KQ' && $row['signal_date']==='2025-10-13')$first=$row;
}
t100check($samsung!==null && $samsung['name']==='삼성E&A' && $samsung['status']==='open','open 삼성E&A uses stored universe name');
t100check($first!==null && abs($first['buy_price']-12366.18)<0.01 && abs($first['plan_entry']-12390)<0.01,'stored fill is used, not the planned entry');
t100check(abs($first['buy_amount']-$first['quantity']*$first['buy_price'])<1e-6,'buy amount is quantity times fill price');
t100check($first['net_return_pct']!==null && abs($first['net_return_pct']-100*$first['net_pnl']/$first['entry_cost'])<1e-9,'return uses cost including buy fee');
foreach($r['cancelled'] as $c)t100check(($c['cancel_date']??'')!=='','cancel rows stay out of the fill list');

$fixture=PaperDatedTop100Trades::build(
    ['trades'=>[
        ['symbol'=>'111111.KS','signal_date'=>'2025-10-10','pattern'=>'trend_pullback_v1','quantity'=>1,'entry'=>100,'fill'=>['date'=>'2025-10-13','price'=>99.95,'fee'=>0.1],'exit'=>['date'=>'2025-10-14','price'=>110,'reason'=>'target','net_pnl'=>9],'status'=>'closed'],
        ['symbol'=>'222222.KS','signal_date'=>'2025-10-10','pattern'=>'trend_recovery_v1','quantity'=>2,'entry'=>50,'fill'=>['date'=>'2025-10-14','price'=>50,'fee'=>0.1],'exit'=>null,'cancelled'=>null,'status'=>'open'],
        ['symbol'=>'333333.KS','signal_date'=>'2025-10-10','pattern'=>'breakout_retest_v1','quantity'=>1,'entry'=>10,'fill'=>null,'exit'=>null,'cancelled'=>['date'=>'2025-10-15','reason'=>'unfilled'],'status'=>'cancelled'],
    ]],
    ['account'=>PaperDatedTop100Trades::ACCOUNT,'realized_pnl'=>9.0,'last_day'=>'2026-10-08','open_positions'=>[['symbol'=>'222222.KS','mark_close'=>51,'mark_session'=>1791441000,'unrealized_pnl_after_sell_cost'=>1.5]]],
    ['rows'=>['111111'=>['symbol'=>'111111.KS','name'=>'닫힘'],'222222'=>['symbol'=>'222222.KS','name'=>'보유'],'333333'=>['symbol'=>'333333.KS','name'=>'취소']]]
);
t100check($fixture['fill_count']===2 && $fixture['cancel_count']===1 && $fixture['closed_net_pnl']===9.0,'fixture keeps cancels out of fills and realized');
t100check($fixture['fills'][0]['buy_date']==='2025-10-13' && $fixture['fills'][1]['status']==='open','fixture default order is buy date, open return excluded');
t100check($fixture['issues']!==[],'fixture expected-count mismatch is reported, not patched');

$dup=false;
try{
    PaperDatedTop100Trades::build(
        ['trades'=>[
            ['symbol'=>'111111.KS','signal_date'=>'2025-10-10','pattern'=>'trend_pullback_v1','quantity'=>1,'fill'=>['date'=>'2025-10-13','price'=>100,'fee'=>0],'exit'=>['date'=>'2025-10-14','price'=>101,'reason'=>'target','net_pnl'=>1],'status'=>'closed'],
            ['symbol'=>'111111.KS','signal_date'=>'2025-10-10','pattern'=>'trend_pullback_v1','quantity'=>1,'fill'=>['date'=>'2025-10-13','price'=>100,'fee'=>0],'exit'=>['date'=>'2025-10-15','price'=>102,'reason'=>'target','net_pnl'=>2],'status'=>'closed'],
        ]],
        ['account'=>PaperDatedTop100Trades::ACCOUNT,'realized_pnl'=>3,'last_day'=>'2026-10-08','open_positions'=>[]],
        ['rows'=>[]]
    );
}catch(Throwable $e){$dup=str_contains($e->getMessage(),'duplicate fill');}
t100check($dup,'duplicate stored fills fail instead of being merged');

ob_start();
require $root.'/paper_top100_trades.php';
$html=ob_get_clean();
t100check(substr_count($html,'data-status="closed"')===56 && substr_count($html,'data-status="open"')===3,'HTML lists 56 closed and 3 open fills');
t100check(str_contains($html,'체결') && str_contains($html,'59') && str_contains($html,'청산') && str_contains($html,'56') && str_contains($html,'보유') && str_contains($html,'3'),'header shows 59 fills / 56 closed / 3 open');
t100check(str_contains($html,'과거 재현 연구 · 실제 거래 아님') && str_contains($html,'KRX 일별 TOP100이며 운영 다음 실시간 TOP100과 다름'),'research disclaimers are visible');
t100check(str_contains($html,'거래 수익률을 더하거나 평균한 값은 계좌 수익률이 아닙니다'),'trade-return average is not presented as account return');
t100check(str_contains($html,'삼성E&amp;A') && str_contains($html,'028050.KS') && str_contains($html,'미청산') && str_contains($html,'미실현'),'open position shows name, unsold, and mark fields');
t100check(str_contains($html,'취소 9건') && str_contains($html,'t100-cancel'),'nine cancels are in a separate table');
t100check(str_contains($html,'id="t100-q"') && str_contains($html,'id="t100-pattern"') && str_contains($html,'id="t100-reason"') && str_contains($html,'id="t100-status"') && str_contains($html,'t100-csv'),'search, filters, and CSV control exist');
t100check(!str_contains($html,'report-244') && !str_contains($html,'opportunity-v1'),'opportunity research is not mixed in');
t100check(str_contains($html,'paper_universe.php') && str_contains($html,'고정 종목군 연구'),'fixed-universe research stays a separate link');
t100check(str_contains($html,'12,366원') && str_contains($html,'536주') && !str_contains($html,'12,390원'),'planned entry is not shown as the fill price');
t100check(str_contains($html,'t100-lines'),'fill cells wrap as one or two lines');
$firstPct=null;foreach($r['fills'] as $row){if($row['symbol']==='054540.KQ' && $row['signal_date']==='2025-10-13'){$firstPct=$row['net_return_pct'];break;}}
t100check($firstPct!==null && str_contains($html,number_format($firstPct,1,'.',',').'%'),'return percent shows one rounded decimal');

ob_start();
require $root.'/paper.php';
$paper=ob_get_clean();
t100check(str_contains($paper,'paper_top100_trades.php'),'account screen links to the research trade list');

t100check([hash_file('sha256',$tradesPath),hash_file('sha256',$summaryPath),hash_file('sha256',$universePath)]===$before,'stored research files are byte-identical after the view');
echo "OK\n";
