<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/ConditionResearch.php';
use ChartEntryLab\TradeSimulator;
function check(bool $ok,string $s):void{if(!$ok)throw new RuntimeException($s);echo "PASS $s\n";}
$bars=[];foreach(range(1,50) as $i)$bars[]=['available_at'=>$i,'open'=>101,'close'=>102,'high'=>110,'low'=>100,'volume'=>100];
$context=['context_applied'=>true,'context'=>['daily'=>'up','weekly'=>'up']];
$p=PaperConditionResearch::hypothesis($bars,5,$context,'recovery_close');
check($p['entry']===102.0&&$p['stop']===99.0&&$p['target']===110.0&&$p['status']==='eligible','frozen close entry and historical structural levels');
$changed=$bars;$changed[49]['close']=108;
check(PaperConditionResearch::hypothesis($changed,5,$context,'volume_contracted')['status']==='rejected_rr','relaxed gate cannot bypass RR');
$changed[49]['close']=111;
check(PaperConditionResearch::hypothesis($changed,5,$context,'near_ma20')['status']==='invalid_levels','target below entry cannot trade');
$context['context']['daily']='down';
check(PaperConditionResearch::hypothesis($bars,5,$context,'bullish_candle')['status']==='context_wait','context remains enforced');
check(PaperConditionResearch::hypothesis($bars,5,[],'fresh_confirmation')['status']==='context_unavailable','unknown context fails closed');
check(PaperConditionResearch::hypothesis($bars,5,[],'spike_dump')['status']==='invalid_research_input','risk relaxation not supported');
$plan=['ready'=>true,'entry'=>100,'stop'=>90,'target'=>120,'signal_at'=>50,'order_valid_bars'=>3];
foreach([
    [89,95,85,'open_at_or_below_stop'],[120,125,99,'open_at_or_above_target'],
    [105,121,99,'target_and_limit_same_bar_order_unknown'],[105,121,101,'target_reached_without_limit_touch'],
] as [$open,$high,$low,$reason]){
    $future=[['available_at'=>51,'open'=>$open,'high'=>$high,'low'=>$low,'close'=>$open,'volume'=>100]];
    $result=(new TradeSimulator())->simulate($plan,$future,20);
    check($result['status']==='cancelled_before_entry'&&PaperConditionResearch::cancellation($plan,$future,$result)['reason']===$reason,$reason);
}
check(PaperConditionResearch::cancellation($plan,[],['status'=>'pending'])===null,'pending not cancellation');
// Regression: unavailable records lack session; they must never deduplicate each other.
$rows=[['symbol'=>'X','final'=>'unavailable'],['symbol'=>'X','final'=>'unavailable']];
$s=PaperSingleConditionReview::summarize($rows);
check($s['unavailable']===2&&$s['duplicates']===0&&$s['statuses']===[],'unavailable records counted separately');
$s=PaperSingleConditionReview::summarize([['symbol'=>'X','final'=>'no_setup','session'=>null]]);
check($s['missing_session']===1&&$s['duplicates']===0,'missing candle never deduplicated');
// Full frozen evidence fixture: hash all original and referenced price payloads.
$root=dirname(__DIR__).'/docs/paper-kr-5d-source';
$r=PaperConditionResearch::run($root,'paper-kr');
check($r['input_validation']['input_hashes_verified']===467,'all 467 original hashes verified');
check($r['input_validation']['evidence_hashes_verified']===173,'all 173 price hashes verified');
check($r['target_count']===13&&count($r['rows'])===13&&$r['errors']===[],'all 13 targets evaluated with exact evidence');
check($r['unavailable']===33,'33 unavailable observations preserved');
foreach($r['rows'] as $row)check(($row['replay_status']??'')==='same_compared_fields','baseline replay agrees '.$row['date'].' '.$row['symbol']);
// Identity and time guards are exercised on one true stored target.
$row=$r['rows'][0];$b=PaperRrView::load($root.'/rr-audit/paper-kr/'.$row['source_file']);
foreach($b['records'] as $raw)if(($raw['symbol']??'')===$row['symbol']){$record=PaperRediagnosis::identity($raw,$b,$row['source_file']);break;}
$prices=json_decode(file_get_contents($root.'/followup/paper-kr/evidence/'.$row['price_hash'].'.json'),true);
$copy=$record;$copy['bars'][0]['close']+=1;
check(PaperConditionResearch::evaluate($copy,$prices,$row['as_of'],$row['gate'],$r['strategy_fingerprint'])['status']==='input_hash_mismatch','tampered input rejected');
$copy=$record;$copy['analysis_symbol']='WRONG';
check(PaperConditionResearch::evaluate($copy,$prices,$row['as_of'],$row['gate'],$r['strategy_fingerprint'])['status']==='analysis_symbol_mismatch','wrong symbol rejected');
check(PaperConditionResearch::evaluate($record,$prices,$record['captured_at']-1,$row['gate'],$r['strategy_fingerprint'])['status']==='not_yet_observed','no future observation leakage');
$copy=$record;$copy['analysis']['plan']['status']='invented';
check(PaperConditionResearch::evaluate($copy,$prices,$row['as_of'],$row['gate'],$r['strategy_fingerprint'])['status']==='baseline_replay_mismatch','changed baseline blocks experiment');
$report=$r;foreach($report['rows'] as &$x)unset($x['measurements']);unset($x);
echo 'RESEARCH_REPORT='.PaperRrAudit::encode($report)."\n";
