<?php
declare(strict_types=1);
require __DIR__.'/v8.php';
require __DIR__.'/../bin/paper/TradeReview.php';
use ChartEntryLab\PaperJournal;
function trcheck(bool $ok,string $name): void {if(!$ok)throw new RuntimeException($name);echo "trade review OK: $name\n";}
function tradeFixture(): array {
    $events=[];$history=[];$add=function($type,$p)use(&$events){$events[]=['id'=>count($events)+1,'type'=>$type,'payload'=>$p];};
    $snapshot=function($at,$origin='forward',$pattern='retest')use($add){$add('snapshot',['symbol'=>'MU','session'=>$at,'recorded_at'=>$at+1,'origin'=>$origin,'plan'=>['pattern'=>$pattern,'reason'=>'test'],'quality'=>['can_simulate'=>true]]);};
    $snapshot(100);
    $add('order',['symbol'=>'MU','session'=>100,'quantity'=>2,'planned_risk'=>10]);
    $add('fill',['symbol'=>'MU','session'=>200,'quantity'=>2,'price'=>100,'fee'=>0.2]);$snapshot(200);
    $snapshot(300);
    $add('exit',['symbol'=>'MU','session'=>400,'quantity'=>2,'price'=>95,'net_pnl'=>-10.39,'reason'=>'stop','ambiguous_bar'=>true]);$snapshot(400);
    // Same symbol re-entry after exit must attach to a separate order and origin.
    $snapshot(400,'replay','pullback');
    $add('order',['symbol'=>'MU','session'=>400,'quantity'=>1,'planned_risk'=>5]);
    $add('fill',['symbol'=>'MU','session'=>500,'quantity'=>1,'price'=>100,'fee'=>0.1]);
    $add('exit',['symbol'=>'MU','session'=>500,'quantity'=>1,'price'=>105,'net_pnl'=>4.795,'reason'=>'target','ambiguous_bar'=>false]);
    for($at=500;$at<=1400;$at+=100)$snapshot($at);
    for($at=100;$at<=1400;$at+=100)$history[]=['available_at'=>$at,'open'=>100,'high'=>110,'low'=>90,'close'=>101];
    $history[1]=['available_at'=>200,'open'=>100,'high'=>140,'low'=>80,'close'=>102];
    $history[2]=['available_at'=>300,'open'=>102,'high'=>108,'low'=>97,'close'=>104];
    $history[3]=['available_at'=>400,'open'=>100,'high'=>150,'low'=>70,'close'=>100];
    return ['state'=>['history'=>['MU'=>$history],'last_session'=>1400,'closed_trades'=>2,'realized'=>-5.595,'config'=>['currency'=>'USD']],'events'=>$events];
}
$d=tradeFixture();$before=serialize($d);$r=PaperTradeReview::build($d);$a=$r['trades'][0];$b=$r['trades'][1];
trcheck(serialize($d)===$before,'analysis does not mutate inputs');
trcheck($r['issues']===[] && $r['closed']===2,'account reconciliation');
trcheck($a['origin']==='forward' && $b['origin']==='replay','same symbol re-entry and origin');
trcheck(abs($a['fees']-0.39)<1e-9,'derive recorded round trip fees');
trcheck(abs($a['net_return_pct']-100*(-10.39)/200.2)<1e-9,'net return uses cost including entry fee');
trcheck(abs($a['net_r']+1.039)<1e-9,'planned risk R');
trcheck(abs($a['known_mfe_pct']-8)<1e-8 && abs($a['known_mae_pct']+5)<1e-8,'exclude boundary extrema from known range');
trcheck(abs($a['envelope_mfe_pct']-50)<1e-8 && abs($a['envelope_mae_pct']+30)<1e-8,'boundary envelope separate');
trcheck(abs($b['known_mfe_pct']-5)<1e-8 && abs($b['known_mae_pct'])<1e-8,'same day exit uses fills only');
trcheck($a['ambiguous_bar']===true,'intrabar ambiguity preserved');
trcheck($a['post_stop'][5]['status']==='complete' && $a['post_stop'][10]['status']==='complete','five and ten subsequent bars');
trcheck(abs($a['post_stop'][5]['max_high_vs_exit_pct']-100*(110/95-1))<1e-8,'exclude stop day rebound');
trcheck($a['post_stop'][5]['recovered_entry_at_close']===true,'Nth close recovery');
trcheck(count($r['groups'])===4,'pattern and exit groups separated by origin');
$short=$d;$short['state']['last_session']=800;$short['events']=array_values(array_filter($short['events'],fn($e)=>$e['payload']['session']<=800));
trcheck(PaperTradeReview::build($short)['trades'][0]['post_stop'][5]['status']==='awaiting_bars','incomplete followup not computed');
$bad=$d;foreach($bad['events'] as &$e)if($e['type']==='snapshot' && $e['payload']['session']===600)$e['payload']['quality']['can_simulate']=false;unset($e);
trcheck(PaperTradeReview::build($bad)['trades'][0]['post_stop'][5]['status']==='missing_or_blocked_data','blocked followup not skipped');
$bad=$d;unset($bad['state']['history']['MU'][2]);
trcheck(PaperTradeReview::build($bad)['trades'][0]['known_mfe_pct']===null,'missing holding bar suppresses excursion');
$bad=$d;$bad['state']['realized']=999;
trcheck(in_array('account_reconciliation_failed',PaperTradeReview::build($bad)['issues'],true),'mismatched realized total flagged');
$open=$d;$open['events']=array_values(array_filter($open['events'],fn($e)=>!($e['type']==='exit' && $e['payload']['session']===500)));$open['state']['closed_trades']=1;$open['state']['realized']=-10.39;
$rr=PaperTradeReview::build($open);
trcheck($rr['closed']===1 && $rr['trades'][1]['status']==='open' && $rr['trades'][1]['net_pnl']===null,'open positions excluded from realized groups');
// Actual file-based read, CLI export and HTML render must leave original journal bytes untouched.
$dir=sys_get_temp_dir().'/trade-review-'.bin2hex(random_bytes(5));mkdir($dir);$path=$dir.'/test-forward.json';
$old=getenv('PAPER_STATE_DIR');putenv('PAPER_STATE_DIR='.$dir);
try {
    $j=new PaperJournal($path);$j->transact(function(&$s,$emit)use($d){$s=$d['state'];foreach($d['events'] as $e)$emit($e['type'],$e['payload']);});
    $hash=hash_file('sha256',$path);
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/paper_trade_report.php').' --account=test';
    exec($cmd,$output,$code);$export=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
    trcheck($code===0 && $export['closed']===2,'CLI reads actual journal');
    $_GET=['account'=>'test','mode'=>'forward'];ob_start();require __DIR__.'/../paper_trades.php';$html=ob_get_clean();
    trcheck(str_contains($html,'손절 이후 흐름') && str_contains($html,'경계일 포함'),'HTML populated report render');
    trcheck(hash_file('sha256',$path)===$hash,'CLI and page leave journal byte-identical');
} finally {putenv($old===false?'PAPER_STATE_DIR':'PAPER_STATE_DIR='.$old);foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
echo "v9: 22 trade review checks passed\n";
