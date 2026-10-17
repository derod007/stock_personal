<?php
declare(strict_types=1);
require __DIR__.'/../bin/paper/RecoveryCloseAccount.php';
use ChartEntryLab\{ChartPlanEngine,TrendPullback,PaperJournal};
function rcheck(bool $v,string $label):void{if(!$v)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
function rthrows(callable $f,string $label):void{try{$f();}catch(RuntimeException $e){rcheck(true,$label);return;}throw new RuntimeException('Expected exception '.$label);}
$root=dirname(__DIR__);PaperRecoveryCloseAccount::assertMirror($root);rcheck(true,'static mirrors only contain the declared edit');
rcheck(PaperDatedTop100Replay::supportsRuntime(PaperStrategyVersion::current()),'recognized runtime; historical fingerprint stays pinned');
$fixed=PaperRecoveryCloseAccount::read($root.'/docs/recovery-close-account/frozen-inputs.json');
foreach($fixed['repository_lf_sha256'] as $f=>$h)rcheck(PaperStrategyVersion::fileHash($root.'/'.$f)===$h,'frozen LF input '.$f);
$bars=[];$start=new DateTimeImmutable('2025-10-06');
for($i=0;$i<60;$i++){
    $close=$i<30?50+$i:80+($i-30)*.4;if($i>=55)$close=[88,86,84.5,84,85.5][$i-55];$close+=1000;
    $open=$i===59?1084.2:$close-.2;$day=$start->modify('+'.$i.' weekdays')->format('Y-m-d');$at=PaperDatedTop100Replay::sessionClose($day);
    $bars[]=['time_kst'=>$day.' 15:30:00','available_at'=>$at,'open'=>$open,'high'=>$close+.8,'low'=>min($open,$close)-.4,'close'=>$close,'volume'=>$i>=56&&$i<=58?500:($i===59?1400:1000)];
}
$base=new ChartPlanEngine();$e=new \RecoveryCloseStudy\ChartPlanEngine();$raw=new \RecoveryCloseStudy\TrendPullback();
foreach([false,true] as $context)for($n=40;$n<=60;$n++){
    $b=array_slice($bars,0,$n);$ts=end($b)['available_at'];
    rcheck($base->analyze($b,'005930.KS',$ts,'account1',$context)===$e->analyze($b,'005930.KS',$ts,'account1',$context,false),'disabled equals engine '.$n.' context '.(int)$context);
}
$miss=$bars;$miss[58]['high']=1086;
$a=$base->analyze($miss,'005930.KS',$at);$r=$raw->analyze($miss,$a['features']);$old=(new TrendPullback())->analyze($miss,$a['features']);
rcheck(!$old['ready']&&$r['ready']&&!$r['gates']['recovery_close'],'only recovery_close failure becomes a raw ready plan');
rcheck($old['gates']===$r['gates'],'actual primitive gate values retained');
foreach(['volume_contracted','bullish_candle','volume_recovery','fresh_confirmation'] as $gate){
    $b=$miss;
    if($gate==='volume_contracted')for($i=56;$i<=58;$i++)$b[$i]['volume']=1000;
    if($gate==='bullish_candle')$b[59]['open']=$b[59]['close'];
    if($gate==='volume_recovery')$b[59]['volume']=400;
    if($gate==='fresh_confirmation'){$b[58]['close']=1085.4;$b[58]['high']=1086;}
    $p=$raw->analyze($b,$a['features']);rcheck(!$p['ready'],'other mandatory gate retained: '.$gate);
}
$far=$miss;for($i=54;$i<=58;$i++)$far[$i]=array_replace($far[$i],['low'=>1095,'open'=>1096,'close'=>1096,'high'=>1097]);
$p=$raw->analyze($far,$a['features']);rcheck(!$p['ready']&&!$p['gates']['near_ma20'],'MA20 proximity still required with valid OHLC');
$b=$miss;$b[59]['close']=1091;$b[59]['high']=1092;
$p=$raw->analyze($b,$a['features']);rcheck(!$p['ready']&&$p['status']==='rejected_rr','minimum RR retained');
$before=$e->analyze($miss,'005930.KS',$at);$future=$miss;$future[]=['available_at'=>$at+86400,'open'=>1,'high'=>90000,'low'=>1,'close'=>50000,'volume'=>99999999];
rcheck($e->analyze($future,'005930.KS',$at)===$before,'future bars do not alter research engine');
$p=$e->analyze($miss,'005930.KS',$at+7*86400)['plan'];rcheck(!$p['ready']&&$p['status']==='stale_data','common stale guard remains after relaxed detector');
rcheck(!(PaperRecoveryCloseAccount::provider())('005930.KS',array_slice($miss,0,20),$at)['ready'],'short history does not throw or recommend');
foreach([['pass'=>false,'sessions'=>244],['pass'=>true,'sessions'=>8]] as $g)rthrows(fn()=>PaperRecoveryCloseAccount::requireBaseline($g,['pass'=>true]),'failed/incomplete baseline blocks variant');
rthrows(fn()=>PaperRecoveryCloseAccount::requireBaseline(['pass'=>true,'sessions'=>244],['pass'=>false]),'failed books block variant');
PaperRecoveryCloseAccount::requireBaseline(['pass'=>true,'sessions'=>244],['pass'=>true]);
rcheck(PaperRecoveryCloseAccount::differences(['qty'=>1],['qty'=>2])!==[],'quantity mutation detected');
rcheck(PaperRecoveryCloseAccount::differences(['cash'=>1e8],['cash'=>1e8+0.01])!==[],'one-cent cash mutation detected');
rcheck(PaperRecoveryCloseAccount::differences(['price'=>1],['price'=>1,'extra'=>1])!==[],'extra field detected');

// Real research engine through the unchanged portfolio; no manually injected recommendation.
$tmp=sys_get_temp_dir().'/recovery-close-account-test-'.bin2hex(random_bytes(5));mkdir($tmp);
register_shutdown_function(static function()use($tmp):void{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($tmp);});
$rows=$miss;
for($i=1;$i<=5;$i++)$rows[]=['time_kst'=>PaperHistoryResearch::day($at+$i*86400).' 15:30:00','available_at'=>$at+$i*86400,'open'=>1085,'high'=>1094,'low'=>1084,'close'=>1090,'volume'=>1000];
$info=['name'=>'fixture','rows'=>$rows,'bars_sha256'=>hash('sha256',PaperJournal::encode($rows)),'quality'=>['status'=>'ok'],'failed'=>false];
$data=['dataset'=>'synthetic','dir'=>'','start_day'=>PaperHistoryResearch::day($at),'as_of_day'=>PaperHistoryResearch::day($at+5*86400),'symbols'=>['005930.KS'=>$info],'missing'=>[],'hold'=>[],'files'=>['dataset.json'=>'fixture','universe.json'=>'fixture']];
$by=[];for($i=0;$i<=5;$i++)$by[$at+$i*86400]=['day'=>PaperHistoryResearch::day($at+$i*86400),'symbols'=>['005930.KS'],'rows'=>[]];
$cand=['by_session'=>$by,'union'=>['005930.KS'],'file_sha256'=>'fixture'];
$cfg=PaperDatedTop100Replay::configs($root)['base'];$cfg['id']='hist-rc-account-v1-fixture';
$opts=['start'=>$data['start_day'],'through'=>$data['as_of_day'],'plan_provider'=>PaperRecoveryCloseAccount::provider(),'runtime_code_extra'=>PaperRecoveryCloseAccount::FILES];
$one=$tmp.'/one.json';$split=$tmp.'/split.json';
PaperDatedTop100Replay::run($data,$cfg,$one,$cand,$opts);
PaperDatedTop100Replay::run($data,$cfg,$split,$cand,$opts+['stop_after_sessions'=>2]);
PaperDatedTop100Replay::run($data,$cfg,$split,$cand,$opts);
$j=(new PaperJournal($one))->read();$k=(new PaperJournal($split))->read();
rcheck(PaperAccountReplay::normalized($j)===PaperAccountReplay::normalized($k),'real research engine split resume equals all-at-once');
$hash=hash_file('sha256',$split);$run=PaperDatedTop100Replay::run($data,$cfg,$split,$cand,$opts);
rcheck($run['processed_sessions']===0&&hash_file('sha256',$split)===$hash,'repeat processes zero and preserves journal bytes');
rcheck(PaperAccountReplay::reconcile($j,$data)['pass'],'real research engine portfolio books reconcile');
rcheck(PaperRecoveryCloseAccount::orderAudit($j,$data,$cand,true)['pass'],'as-of research orders match');
rcheck(count(PaperAccountReplay::trades($j))>0,'fixture actually produces an order');
$ref=['summary'=>PaperAccountReplay::summary($j),'trades'=>PaperAccountReplay::trades($j),'daily'=>PaperAccountReplay::dailyLog($j)];
rcheck(PaperRecoveryCloseAccount::baselineCheck($j,$ref)['pass'],'full field baseline comparator accepts same books');
$ref['trades'][0]['quantity']++;rcheck(!PaperRecoveryCloseAccount::baselineCheck($j,$ref)['pass'],'baseline gate detects trade mutation');
$changed=$opts;$changed['runtime_code_extra']=array_merge(PaperRecoveryCloseAccount::FILES,['tests/recovery_close_account.php']);
rthrows(fn()=>PaperDatedTop100Replay::run($data,$cfg,$split,$cand,$changed),'changed research code refuses resume');
$c=PaperRecoveryCloseAccount::compare($j,$j);rcheck($c['equity_delta']===0.0&&($c['change_counts']['unchanged']??0)>0,'self-comparison preserves account and trade values');

$ref=['summary'=>PaperAccountReplay::summary($j),'trades'=>PaperAccountReplay::trades($j),'daily'=>PaperAccountReplay::dailyLog($j)];
$ref['daily'][0]['cash']+=0.01;rcheck(!PaperRecoveryCloseAccount::baselineCheck($j,$ref)['pass'],'daily cash mutation fails baseline');
$ref['daily']=PaperAccountReplay::dailyLog($j);$ref['summary']['equity']+=0.01;
rcheck(!PaperRecoveryCloseAccount::baselineCheck($j,$ref)['pass'],'final equity mutation fails baseline');
$altered=$j;foreach($altered['events'] as &$ev)if($ev['type']==='order')$ev['payload']['pattern']='trend_recovery_v1';unset($ev);
$cmp=PaperRecoveryCloseAccount::compare($j,$altered);rcheck(($cmp['change_counts']['pattern_replaced']??0)>0,'pattern arbitration replacement reported separately');
mkdir($tmp.'/mirror/src',0770,true);mkdir($tmp.'/mirror/bin/paper',0770,true);
foreach(['src/TrendPullback.php','src/ChartPlanEngine.php','bin/paper/RecoveryClosePullback.php','bin/paper/RecoveryCloseEngine.php'] as $f)copy($root.'/'.$f,$tmp.'/mirror/'.$f);
file_put_contents($tmp.'/mirror/bin/paper/RecoveryClosePullback.php',"\n// unapproved drift",FILE_APPEND);
rthrows(fn()=>PaperRecoveryCloseAccount::assertMirror($tmp.'/mirror'),'extra mirror change refused');
