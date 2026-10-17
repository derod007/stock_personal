<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/StrategyVersion.php';
use ChartEntryLab\{PaperPortfolio,PaperForwardExitPolicy,PaperJournal};
function fp(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);echo "PASS $why\n";}
function fs(int $day):int{return (new DateTimeImmutable('2026-01-'.sprintf('%02d',$day).' 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();}
function fb(int $day,float $o,float $h,float $l,float $c):array{return ['available_at'=>fs($day),'open'=>$o,'high'=>$h,'low'=>$l,'close'=>$c,'volume'=>1000];}
function fx(int $day,bool $ready=false):array{return ['session'=>fs($day),'recorded_at'=>fs($day)+60,'origin'=>'forward','input_hash'=>'input-'.$day,'plan'=>['ready'=>$ready,'signal_at'=>fs($day),'entry'=>100,'stop'=>88,'target'=>130,'order_valid_bars'=>3],'quality'=>['can_simulate'=>true]];}
$config=json_decode(file_get_contents(__DIR__.'/../config/paper-kr-recovery-v1.json'),true);$config['symbols']=['TEST.KS'=>'fixture'];
$events=[];$emit=function($t,$p)use(&$events){$events[]=['type'=>$t,'payload'=>$p];};
$s=PaperPortfolio::start($config,'fixture','forward');PaperForwardExitPolicy::adopt($s,$emit,fs(1));
PaperPortfolio::advance($s,fs(1),['TEST.KS'=>fb(1,100,102,98,100)],['TEST.KS'=>fx(1,true)],$emit);
$plan=$s['active']['TEST.KS']['plan'];
PaperPortfolio::advance($s,fs(2),['TEST.KS'=>fb(2,100,114,90,113)],['TEST.KS'=>fx(2)],$emit);
fp($s['active']['TEST.KS']['exit_policy']==='half_risk_v1'&&$s['active']['TEST.KS']['protection']['state']==='armed','post-activation fill opts in');
fp($s['active']['TEST.KS']['plan']===$plan,'frozen order plan preserved');
$before=PaperJournal::encode($s);$n=count($events);PaperPortfolio::advance($s,fs(2),[],[],$emit);PaperForwardExitPolicy::adopt($s,$emit,fs(2));
fp(PaperJournal::encode($s)===$before&&count($events)===$n,'repeat session and adoption idempotent');
PaperPortfolio::advance($s,fs(3),['TEST.KS'=>fb(3,100,111,93,100)],['TEST.KS'=>fx(3)],$emit);
$ex=array_values(array_filter($events,fn($e)=>$e['type']==='exit'));
fp(count($ex)===1&&abs($ex[0]['payload']['price']-94*.9995)<1e-6,'portfolio uses protected fill');
fp($ex[0]['payload']['protection']['exit_reason']==='half_risk_stop','exit carries protected reason without breaking stop classification');
foreach(['trade_exit_policy_assigned','protection_armed','protection_activated'] as $t)fp(count(array_filter($events,fn($e)=>$e['type']===$t))===1,'single audit event '.$t);
// A filled position with a prior trigger keeps its old fixed exit after adoption.
$old=PaperPortfolio::start($config,'fixture','forward');$quiet=function($t,$p){};
PaperPortfolio::advance($old,fs(1),['TEST.KS'=>fb(1,100,102,98,100)],['TEST.KS'=>fx(1,true)],$quiet);
PaperPortfolio::advance($old,fs(2),['TEST.KS'=>fb(2,100,114,90,113)],['TEST.KS'=>fx(2)],$quiet);
$existing=$old['active'];PaperForwardExitPolicy::adopt($old,$quiet,fs(2));fp($old['active']===$existing,'adoption does not rewrite existing holdings');
PaperPortfolio::advance($old,fs(3),['TEST.KS'=>fb(3,100,111,93,100)],['TEST.KS'=>fx(3)],$quiet);
fp(isset($old['active']['TEST.KS'])&&!isset($old['active']['TEST.KS']['exit_policy']),'legacy held position not retrofitted');
$catch=PaperPortfolio::start($config,'fixture','forward');PaperForwardExitPolicy::adopt($catch,$quiet,fs(3));
PaperPortfolio::advance($catch,fs(1),['TEST.KS'=>fb(1,100,102,98,100)],['TEST.KS'=>fx(1,true)],$quiet);
PaperPortfolio::advance($catch,fs(2),['TEST.KS'=>fb(2,100,114,90,113)],['TEST.KS'=>fx(2)],$quiet);
fp(!isset($catch['active']['TEST.KS']['exit_policy']),'catchup fill before activation remains legacy');
foreach(['replay','other_account'] as $scope){$q=PaperPortfolio::start($config,'fixture',$scope==='replay'?'replay':'forward');if($scope==='other_account')$q['config']['id']='paper-kr';PaperForwardExitPolicy::adopt($q,$quiet,fs(1));fp(!isset($q['forward_exit_policy']),'scope excluded '.$scope);}
$current=PaperStrategyVersion::current();$known='934012285f2ddb3867fff4576dba05fb7df290a36a987c36fc260e8917bbe465';
$state=$old;$state['version']=$known;$state['strategy_fingerprint']=$known;$state['history']=['original'];$untouched=$state;
fp(PaperStrategyVersion::forwardUpgrade($state,$current),'known forward release eligible');
PaperStrategyVersion::verify($state,$current);fp($state===$untouched,'preflight verification read-only');
PaperStrategyVersion::adopt($state,$current,$emit);
foreach(['active','history','cash','realized','frozen','last_session'] as $key)fp($state[$key]===$untouched[$key],'release preserves '.$key);
fp($state['version']===$current&&$state['strategy_fingerprint']===$current,'release pins new strategy');
$n=count($events);PaperStrategyVersion::adopt($state,$current,$emit);fp(count($events)===$n,'release recorded once');
foreach(['replay','unknown','mismatched_fingerprint','halted'] as $case){$q=$untouched;if($case==='replay')$q['mode']='replay';if($case==='unknown')$q['version']=str_repeat('0',64);if($case==='mismatched_fingerprint')$q['strategy_fingerprint']=str_repeat('0',64);if($case==='halted')$q['halted']=true;try{PaperStrategyVersion::verify($q,$current);throw new LogicException('accepted '.$case);}catch(RuntimeException $e){fp(true,'version refused '.$case);}}
echo "FORWARD_PROTECTION_PASS\n";
// Real preflight process must advertise the upgrade without touching a journal.
$tmp=sys_get_temp_dir().'/forward-protection-'.bin2hex(random_bytes(5));mkdir($tmp);
try {
    $cfg=json_decode(file_get_contents(__DIR__.'/../config/paper-kr-recovery-v1.json'),true);
    $cfgPath=$tmp.'/config.json';file_put_contents($cfgPath,json_encode($cfg));
    $journalPath=$tmp.'/'.$cfg['id'].'-forward.json';$journal=new PaperJournal($journalPath);
    $journal->transact(function(&$s,$emit)use($cfg,$known){$s=PaperPortfolio::start($cfg,$known,'forward');$s['strategy_fingerprint']=$known;$s['config_hash']=hash('sha256',PaperJournal::encode(\ChartEntryLab\PaperScanUniverse::pinned($cfg)));$emit('fixture',[]);});
    $before=file_get_contents($journalPath);putenv('PAPER_STATE_DIR='.$tmp);
    $cmd=escapeshellarg(PHP_BINARY).' -n '.escapeshellarg(__DIR__.'/../bin/paper_account_preflight.php').' --config='.escapeshellarg($cfgPath);
    exec($cmd,$lines,$code);$result=json_decode(implode("\n",$lines),true);
    fp($code===0&&$result['status']==='forward_upgrade_pending'&&$result['writes']===false,'CLI preflight reports declared upgrade');
    fp(file_get_contents($journalPath)===$before,'CLI preflight leaves journal bytes unchanged');
} finally {putenv('PAPER_STATE_DIR');foreach(glob($tmp.'/*') as $file)if(is_file($file))unlink($file);rmdir($tmp);}
