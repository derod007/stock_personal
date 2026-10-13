<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/RecoveryCloseEvidence.php';
use ChartEntryLab\TradeSimulator;
function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
function rejects(callable $f,string $m):void{try{$f();}catch(RuntimeException $e){ok(true,$m);return;}throw new RuntimeException('Expected failure '.$m);}
$plan=['ready'=>true,'entry'=>100,'stop'=>90,'target'=>120,'signal_at'=>0,'order_valid_bars'=>3];
$b=static fn(int $at,float $o=100,float $h=110,float $l=99,float $c=105):array=>['available_at'=>$at,'open'=>$o,'high'=>$h,'low'=>$l,'close'=>$c,'volume'=>100];
$paths=[
    'gap improvement'=>[$b(1,95,105,94),$b(2,110,125,100)],
    'limit cap'=>[$b(1,110,115,99),$b(2,121,130,119)],
    'gap stop'=>[$b(1),$b(2,85,90,80,87)],
    'stop wins intrabar tie'=>[$b(1),$b(2,110,130,80)],
    'opening target precedes later stop'=>[$b(1),$b(2,121,130,80)],
    'same day stop'=>[$b(1,100,110,80)],
    'cancel before fill'=>[$b(1,100,125,95)],
    'unfilled ttl'=>[$b(1,110,115,105),$b(2,110,115,105),$b(3,110,115,105)],
    'pending'=>[], 'incomplete'=>[$b(1)],
    'time exit'=>array_map(static fn($i)=>$b($i),range(1,20)),
];
$fields=['status','entry_fill','entry_at','exit_fill','exit_at','first_exit','bars','net_return_pct'];
foreach($paths as $label=>$bars){$got=PaperRecoveryCloseEvidence::path($plan,$bars);$want=(new TradeSimulator(10,5))->simulate($plan,$bars,20);
    $fs=$got['status']==='closed'?$fields:['status'];ok(PaperRecoveryCloseEvidence::compare($got,$want,$fs)===[],$label);}
ok(PaperRecoveryCloseEvidence::path($plan,[$b(1),null])['status']==='future_quality_blocked','missing session blocks path');
ok(PaperRecoveryCloseEvidence::path($plan,array_merge($paths['unfilled ttl'],[null]))['status']==='unfilled','expired before missing session');
rejects(fn()=>PaperRecoveryCloseEvidence::path($plan,[$b(2),$b(1)]),'reject reversed time');
$closed=PaperRecoveryCloseEvidence::path($plan,$paths['gap improvement']);
foreach(['entry_fill','exit_fill','entry_at','exit_at','net_return_pct'] as $k){$wrong=$closed;$wrong[$k]++;ok(PaperRecoveryCloseEvidence::compare($wrong,$closed,$fields)!==[],'detect altered '.$k);}
$wrong=$closed;$wrong['first_exit']='stop';ok(PaperRecoveryCloseEvidence::compare($wrong,$closed,$fields)!==[],'detect altered reason');
$mk=['shadow'=>['entry_at'=>1,'exit_at'=>2,'entry_fill'=>111,'exit_fill'=>120]];
$market=PaperRecoveryCloseEvidence::marketChecks($mk,[],[$b(1),$b(2,110,125,100)]);
ok($market['range_result']==='assumption_dependent','outside high separated from model match');
$mk['shadow']['entry_fill']=100;
ok(PaperRecoveryCloseEvidence::marketChecks($mk,[],[$b(1),$b(2,110,125,100)])['range_result']==='within_range','inside daily range');
$bars=[];$start=new DateTimeImmutable('2025-10-06');
for($i=0;$i<60;$i++){
    $close=$i<30?50+$i:80+($i-30)*.4;if($i>=55)$close=[88,86,84.5,84,85.5][$i-55];$close+=1000;
    $open=$i===59?1084.2:$close-.2;$day=$start->modify('+'.$i.' weekdays')->format('Y-m-d');$at=PaperDatedTop100Replay::sessionClose($day);
    $bars[]=['time_kst'=>$day.' 15:30:00','available_at'=>$at,'open'=>$open,'high'=>$i===58?1086:$close+.8,'low'=>min($open,$close)-.4,'close'=>$close,'volume'=>$i>=56&&$i<=58?500:($i===59?1400:1000)];
}
$at=end($bars)['available_at'];$info=['rows'=>$bars,'bars_sha256'=>'fixture','name'=>'fixture'];
$inspect=PaperOpportunityResearch::inspect($info,'005930.KS',$at,'fixture');$probe=$inspect['probe'];
ok($probe['failed']===['recovery_close'],'fixture has exactly recovery_close missing');
$next=$start->modify('+60 weekdays')->format('Y-m-d');$nextAt=PaperDatedTop100Replay::sessionClose($next);
$fb=['time_kst'=>$next.' 15:30:00','available_at'=>$nextAt,'open'=>$probe['plan']['entry'],'high'=>$probe['plan']['entry']+.1,'low'=>$probe['plan']['stop']-1,'close'=>$probe['plan']['stop'],'volume'=>100];
$info['rows'][]=$fb;
$r=['session'=>$at,'symbol'=>'005930.KS','plan'=>$probe['plan'],'gates'=>$probe['gates'],'shadow'=>PaperOpportunityResearch::shadow($probe['plan'],[$fb])];
$aud=PaperRecoveryCloseEvidence::auditCase($r,$info,[$at,$nextAt],'fixture');
ok($aud['model_match'],'case signal and independent path match');
ok(count($aud['as_of_rows'])===60&&max(array_column($aud['as_of_rows'],'available_at'))===$at,'future excluded from signal input');
$r['plan']['target']++;
ok(!PaperRecoveryCloseEvidence::auditCase($r,$info,[$at,$nextAt],'fixture')['model_match'],'altered level recorded as mismatch');
$tmp=sys_get_temp_dir().'/recovery-evidence-test-'.bin2hex(random_bytes(6));mkdir($tmp);
try{
    $ts=PaperDatedTop100Replay::sessionClose('2026-01-05');$row=$b($ts);
    $raw=['chart'=>['result'=>[['meta'=>['symbol'=>'005930.KS','currency'=>'KRW'],'timestamp'=>[$ts],
        'indicators'=>['quote'=>[['open'=>[100],'high'=>[110],'low'=>[99],'close'=>[105],'volume'=>[100]]]],'events'=>[]]]]];
    $body=json_encode($raw);file_put_contents($tmp.'/raw.json',$body);$req=['raw_path'=>'raw.json','raw_sha256'=>hash('sha256',$body)];
    $ex=PaperRecoveryCloseEvidence::providerExcerpt($tmp,$req,[$row]);ok($ex['stored_provider_differences']===[]&&$ex['missing_dates']===[],'provider quote agrees');
    $row['close']=106;$ex=PaperRecoveryCloseEvidence::providerExcerpt($tmp,$req,[$row]);ok(count($ex['stored_provider_differences'])===1,'provider mismatch preserved');
    $req['raw_sha256']=str_repeat('0',64);rejects(fn()=>PaperRecoveryCloseEvidence::providerExcerpt($tmp,$req,[$row]),'provider tampering rejected');
    rejects(fn()=>PaperRecoveryCloseEvidence::inputPath($tmp,'../outside.json'),'missing path rejected');
    file_put_contents($tmp.'-outside.json','{}');rejects(fn()=>PaperRecoveryCloseEvidence::inputPath($tmp,'../'.basename($tmp).'-outside.json'),'existing outside path rejected');
}finally{foreach(glob($tmp.'/*') as $f)unlink($f);rmdir($tmp);if(is_file($tmp.'-outside.json'))unlink($tmp.'-outside.json');}
ok(PaperStrategyVersion::current()===PaperDatedTop100Replay::STRATEGY_FINGERPRINT,'operating fingerprint unchanged');
