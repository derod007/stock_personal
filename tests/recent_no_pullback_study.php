<?php
declare(strict_types=1);
require __DIR__.'/no_pullback_study.php';
require __DIR__.'/../bin/paper/RecentNoPullbackStudy.php';
test(PaperStrategyVersion::fileHash($root.'/'.PaperRecentNoPullbackStudy::FIXED)===PaperRecentNoPullbackStudy::FIXED_SHA,'recent frozen definition pinned');
$frozen=PaperRecoveryCloseAccount::read($root.'/'.PaperRecentNoPullbackStudy::FIXED);
foreach($frozen['repository_lf_sha256'] as $file=>$sha)test(PaperStrategyVersion::fileHash($root.'/'.$file)===$sha,'frozen repository input '.$file);
test(PaperRecentNoPullbackStudy::provider(false)===null,'baseline uses original engine path');
$recentProvider=PaperRecentNoPullbackStudy::provider(true);
test($recentProvider($sym,$pb,$at)===$ec->analyze($pb,$sym,$at,'account1',true,true)['plan'],'variant uses unchanged PR97 selection engine');
$rc=PaperRecentNoPullbackStudy::config($root,'baseline');$oc=PaperDatedTop100Replay::configs($root,PaperDatedTop100Replay::RESEARCH_CONFIG_YEAR)['base'];
unset($rc['id'],$oc['id']);test($rc===$oc,'recent account changes ID only');
$recentRun=static function(string $name,bool $variant,array $extra=[],?array $input=null)use($root,$tmp,$data,$cand):array{
 $d=$input??$data;$p=$tmp.'/recent-'.$name.'/account-replay.json';
 $cfg=PaperRecentNoPullbackStudy::config($root,$variant?'variant':'baseline');
 $opts=['start'=>$d['start_day'],'through'=>$d['as_of_day'],'runtime_code_extra'=>PaperRecentNoPullbackStudy::FILES,'plan_provider'=>PaperRecentNoPullbackStudy::provider($variant)];
 $run=PaperDatedTop100Replay::run($d,$cfg,$p,$cand,array_replace($opts,$extra));
 return [$run,(new \ChartEntryLab\PaperJournal($p))->read(),$p];
};
[$run,$rb]=$recentRun('base',false);[$run,$rv]=$recentRun('variant',true);
test($rb['state']['research']['plan_source']==='engine'&&$rv['state']['research']['plan_source']==='injected_test','both replay provenance labels correct');
foreach([false=>$rb,true=>$rv] as $arm=>$j)test(PaperRecentNoPullbackStudy::verify($j,['data'=>$data,'candidates'=>$cand],(bool)$arm)['pass'],'recent route books and as-of orders '.(int)$arm);
$reference=['summary'=>PaperAccountReplay::summary($rb),'trades'=>PaperAccountReplay::trades($rb),'daily'=>PaperAccountReplay::dailyLog($rb)];
test(PaperRecentNoPullbackStudy::baselineMatch($rb,$reference)['pass'],'baseline prefix matches all daily fields');
$mut=$reference;$mut['daily'][0]['cash']++;
test(!PaperRecentNoPullbackStudy::baselineMatch($rb,$mut)['pass'],'prefix cash mutation fails');
// Full-horizon branch, using fixture daily events; this is a gate test, not a performance run.
$full=$rb;$dailyEvents=array_values(array_filter($full['events'],fn($e)=>$e['type']==='day_log'));
if(!$dailyEvents)throw new RuntimeException('Missing fixture day logs');
while(count($dailyEvents)<244){$e=end($dailyEvents);$e['payload']['date']='fixture-'.count($dailyEvents);$full['events'][]=$e;$dailyEvents[]=$e;}
$fullRef=['summary'=>PaperAccountReplay::summary($full),'trades'=>PaperAccountReplay::trades($full),'daily'=>PaperAccountReplay::dailyLog($full)];
test(PaperRecentNoPullbackStudy::baselineMatch($full,$fullRef)['pass'],'full 244 branch compares stored summary/trades/daily');
$fullRef['trades'][0]['quantity']++;
test(!PaperRecentNoPullbackStudy::baselineMatch($full,$fullRef)['pass'],'full gate rejects trade quantity mutation');
$recentRun('split',true,['stop_after_sessions'=>3]);[$run,$rs,$rp]=$recentRun('split',true);
test(PaperAccountReplay::normalized($rs)===PaperAccountReplay::normalized($rv),'recent split and once agree');
$before=hash_file('sha256',$rp);[$run]=$recentRun('split',true);
test($run['processed_sessions']===0&&hash_file('sha256',$rp)===$before,'recent repeat no journal writes');
$changed=$data;$changed['files']['test']='changed';
try{$recentRun('split',true,[],$changed);test(false,'recent changed inputs');}catch(RuntimeException $e){test(str_contains($e->getMessage(),'Research input changed'),'recent input drift refuses resume');}
$mut=$rv;foreach($mut['events'] as &$e)if($e['type']==='order'){$e['payload']['pattern']='trend_pullback_v1';break;}unset($e);
test(!PaperRecentNoPullbackStudy::verify($mut,['data'=>$data,'candidates'=>$cand],true)['pass'],'recent forbidden pattern mutation rejected');
// Original recent missing-filled-bar safety path retained; never infer a missing execution.
$missing=$data;array_splice($missing['symbols'][$sym]['rows'],71,1);$missing['symbols'][$sym]['bars_sha256']=PaperKrxSignalStudy::digest($missing['symbols'][$sym]['rows']);
[$run,$halt]=$recentRun('missing',true,[],$missing);
test($run['halted']&&$halt['state']['halt_reason']==='unpriced_position','recent original missing position rule halts');
try{PaperRecentNoPullbackStudy::load($root,$tmp);test(false,'missing frozen dataset');}catch(RuntimeException $e){test(str_contains($e->getMessage(),'Frozen dataset missing/changed'),'missing real dataset refuses without substitute');}
test(!is_dir($tmp.'/'.PaperRecentNoPullbackStudy::OUTPUT),'missing inputs create no account folder');
$cli=static function(array $args)use($root):array{
 $p=proc_open(array_merge([PHP_BINARY,'-n',$root.'/bin/paper_recent_no_pullback_study.php'],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($p))throw new RuntimeException('Cannot launch CLI');
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out.$err];
};
[$exit,$text]=$cli(['baseline','--sessions=all','--state-dir='.$tmp]);
test($exit===1&&str_contains($text,'Full run confirmation missing'),'CLI full run requires explicit gate');
[$exit,$text]=$cli(['validate','--state-dir='.$tmp]);
test($exit===1&&str_contains($text,'Frozen dataset missing/changed'),'CLI missing dataset fails before writes');
test(!is_dir($tmp.'/'.PaperRecentNoPullbackStudy::OUTPUT),'failed CLI leaves output absent');
echo "PASS $n total checks\n";
