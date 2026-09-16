<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';require __DIR__.'/../bin/paper/Weekly.php';require __DIR__.'/../bin/paper/Experiment.php';
use ChartEntryLab\PaperJournal;
use ChartEntryLab\PaperPortfolio;
function wc(bool $v,string $message):void{if(!$v)throw new RuntimeException($message);echo "OK $message\n";}
function wrm(string $p):void{if(is_dir($p)){foreach(scandir($p) as $f)if($f!=='.'&&$f!=='..')wrm($p.'/'.$f);rmdir($p);}elseif(file_exists($p))unlink($p);}
$window=PaperWeekly::window('2026-W38');$start=$window['start'];$end=$window['end'];
wc(gmdate('Y-m-d H:i',$start)==='2026-09-13 15:00','Monday KST boundary');
try{PaperWeekly::window('2021-W53');wc(false,'invalid week');}catch(InvalidArgumentException $e){wc(true,'invalid ISO week rejected');}
$cfg=['id'=>'fixture','currency'=>'USD','initial_cash'=>10000,'max_positions'=>4,'risk_pct'=>.01,'position_pct'=>.2,'sector_pct'=>.4,'total_risk_pct'=>.03,'symbols'=>['MU'=>'semi']];
$state=PaperPortfolio::start($cfg,'test','forward');$state['last_session']=$start+200;
$events=[];$add=function($t,$p,$recorded=null)use(&$events,$start){$events[]=['type'=>$t,'payload'=>$p,'recorded_at'=>$recorded??$start+300];};
$point=fn($t,$v)=>['session'=>$t,'equity'=>$v,'cash'=>9500,'reserved_cash'=>100,'unrealized'=>5,'realized'=>10,'stale_positions'=>[]];
$add('equity',$point($start-100,10000));
$add('snapshot',['symbol'=>'MU','session'=>$start,'origin'=>'forward','plan'=>['ready'=>true]]);
$add('order',['symbol'=>'MU','session'=>$start]);
$add('snapshot',['symbol'=>'MU','session'=>$start+100,'origin'=>'catchup','plan'=>['ready'=>true]]);
$add('decision',['symbol'=>'MU','session'=>$start+100,'reason'=>'retrospective_signal']);
$add('fill',['symbol'=>'MU','session'=>$start+200]);
$add('exit',['symbol'=>'MU','session'=>$start+200,'net_pnl'=>10,'reason'=>'target']);
$add('equity',$point($start+200,10015));
$add('exit',['symbol'=>'MU','session'=>$end,'net_pnl'=>900,'reason'=>'target']);
$add('data_revision',[], $start+400);
$d=['state'=>$state,'events'=>$events];$copy=serialize($d);$r=PaperWeekly::summarize($d,$window,$start+500);
wc($r['groups']['forward']['orders']===1 && $r['groups']['catchup']['excluded']===1,'separate forward/catchup');
wc($r['realized_pnl']===10.0 && $r['execution']['exit']===1,'end boundary excluded');
wc($r['equity_change']==15,'equity difference includes unrealized');
wc($r['valuation']['unrealized']===5,'week closing mark used');
wc(!$r['week_complete'] && count($r['account_alerts'])===1,'partial week and real-time alerts');
wc(serialize($d)===$copy,'summary leaves input unchanged');
$empty=PaperWeekly::summarize($d,PaperWeekly::window('2026-W40'),$end+86400);
wc($empty['valuation']===null && $empty['equity_change']===null,'no valuation not reported as zero');
$stale=$d;$stale['events'][7]['payload']['stale_positions']=['MU'];
wc(PaperWeekly::summarize($stale,$window,$end)['equity_change']===null,'stale closing valuation withheld');
$dir=sys_get_temp_dir().'/weekly-'.bin2hex(random_bytes(5));mkdir($dir);$old=getenv('PAPER_STATE_DIR');putenv('PAPER_STATE_DIR='.$dir);
try{
 $j=new PaperJournal($dir.'/fixture-forward.json');$stored=$j->transact(function(&$s,$emit)use($d){$s=$d['state'];foreach($d['events'] as $e)$emit($e['type'],$e['payload']);});
 mkdir($dir.'/runs/fixture-forward',0700,true);
 file_put_contents($dir.'/runs/fixture-forward/a.json',json_encode(['started_at'=>$start,'status'=>'success']));
 file_put_contents($dir.'/runs/fixture-forward/b.json',json_encode(['started_at'=>$end,'status'=>'failed']));
 file_put_contents($dir.'/runs/fixture-forward/c.json','bad');
 $runs=PaperWeekly::runs($dir.'/runs/fixture-forward',$window,$end);
 wc($runs['total']===1 && $runs['invalid_files']===1,'run window and corruption');
 $pair=['definition'=>['id'=>'identity','source'=>'fixture','mode'=>'forward','candidate'=>[]],'created_at'=>$start,'first_session'=>$start,'last_session'=>$state['last_session'],'sessions'=>1,'blocked_snapshots'=>0,'baseline'=>$state,'candidate'=>$state,'source_cursor'=>count($stored['events']),'source_hash'=>$stored['events'][array_key_last($stored['events'])]['hash']];
 (new PaperJournal($dir.'/experiments/identity-forward.json'))->transact(function(&$s,$emit)use($pair){$s=$pair;});
 $result=PaperWeekly::load($dir,'fixture','forward','2026-W38',$end);
 wc($result['comparisons_current'][0]['identity_check']==='matched' && $result['comparisons_current'][0]['source_sync'],'current comparison synced');
 $j->transact(function(&$s,$emit){$emit('test',[]);});
 wc(!PaperWeekly::load($dir,'fixture','forward','2026-W38',$end)['comparisons_current'][0]['source_sync'],'stale experiment not shown as synchronized');
 $hash=hash_file('sha256',$dir.'/fixture-forward.json');
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/paper_weekly.php').' --account=fixture --week=2026-W38';
 exec($cmd,$output,$code);$json=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
 wc($code===0 && $json['summary']['execution']['exit']===1,'CLI JSON export');
 $_GET=['account'=>'fixture','week'=>'2026-W38'];ob_start();require __DIR__.'/../paper_weekly.php';$html=ob_get_clean();
 wc(str_contains($html,'선택 주 성과') && str_contains($html,'원본과 갱신 불일치'),'HTML populated report');
 wc(hash_file('sha256',$dir.'/fixture-forward.json')===$hash,'CLI and HTML preserve original journal');
}finally{putenv($old===false?'PAPER_STATE_DIR':'PAPER_STATE_DIR='.$old);wrm($dir);}
echo "WEEKLY_PASS (16 checks)\n";
