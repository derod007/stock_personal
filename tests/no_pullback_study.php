<?php
declare(strict_types=1);
// Reuse the real account fixture and its 31 safety/regression checks.
require __DIR__.'/krx_account_study.php';
require __DIR__.'/../bin/paper/NoPullbackStudy.php';
PaperNoPullbackStudy::assertMirror($root);test(true,'no-pullback static engine only declared selection edit');
$ec=new \NoPullbackStudy\ChartPlanEngine();$op=new \ChartEntryLab\ChartPlanEngine();
foreach([false,true] as $context)foreach([40,50,60,70] as $length){
    $b=array_slice($bars,0,$length);$ts=end($b)['available_at'];
    test($ec->analyze($b,$sym,$ts,'account1',$context,false)===$op->analyze($b,$sym,$ts,'account1',$context),'disabled exclusion exactly equals operating engine '.$length.' context '.(int)$context);
}
$pb=[];$start=new DateTimeImmutable('2025-10-06');
for($i=0;$i<60;$i++){
    $close=$i<30?50+$i:80+($i-30)*.4;if($i>=55)$close=[88,86,84.5,84,85.5][$i-55];$close+=1000;
    $open=$i===59?1084.2:$close-.2;$day=$start->modify('+'.$i.' weekdays')->format('Y-m-d');$at=PaperDatedTop100Replay::sessionClose($day);
    $pb[]=['time_kst'=>$day.' 15:30:00','available_at'=>$at,'open'=>$open,'high'=>$close+.8,'low'=>min($open,$close)-.4,'close'=>$close,'volume'=>$i>=56&&$i<=58?500:($i===59?1400:1000)];
}
$old=$op->analyze($pb,$sym,$at);$new=$ec->analyze($pb,$sym,$at);
test($old['plan']['pattern']==='trend_pullback_v1'&&$old['plan']['ready'],'fixture baseline actually selects ready pullback');
test($new['plan']['pattern']!=='trend_pullback_v1','pullback excluded before final selection');
foreach(['breakout_retest','trend_recovery'] as $p)test($new['plan']['diagnostics']['patterns'][$p]===$old['plan']['diagnostics']['patterns'][$p],'independent detector unchanged '.$p);
$future=$pb;$future[]=['time_kst'=>'2030-01-01 15:30:00','available_at'=>$at+99999999,'open'=>1,'high'=>99999,'low'=>1,'close'=>99998,'volume'=>999999];
test($ec->analyze($future,$sym,$at)===$new,'future bars do not change no-pullback plan');
test(!$ec->analyze($pb,$sym,$at+7*86400)['plan']['ready'],'stale common guard retained');
$nc=PaperNoPullbackStudy::config($root,'baseline');$orig=$cfg;unset($nc['id'],$orig['id']);test($nc===$orig,'new account config changes ID only');
$nr=static function(string $name,bool $exclude,array $extra=[],?array $input=null)use($tmp,$root,$data,$cand,$ref):array{
    $d=$input??$data;$cfg=PaperNoPullbackStudy::config($root,$exclude?'variant':'baseline');
    $opts=['start'=>$d['start_day'],'through'=>$d['as_of_day'],'runtime_code_extra'=>PaperNoPullbackStudy::FILES,
        'plan_provider'=>PaperNoPullbackStudy::provider($exclude,$d['breaks'],$exclude?[]:$ref)];
    $p=$tmp.'/np-'.$name.'/account-replay.json';
    $run=PaperKrxAccountReplay::run($d,$cfg,$p,$cand,array_replace($opts,$extra));return [$run,(new \ChartEntryLab\PaperJournal($p))->read(),$p];
};
[$rr,$nb]=$nr('baseline',false);
test(PaperNoPullbackStudy::baselineMatch($nb,$once)['pass'],'baseline dated events equal PR96 despite new account ID');
$mut=$nb;foreach($mut['events'] as &$e)if($e['type']==='equity'){$e['payload']['cash']+=1;break;}unset($e);
test(!PaperNoPullbackStudy::baselineMatch($mut,$once)['pass'],'baseline match detects cash mutation');
[$rr,$nv,$np]=$nr('variant',true);
test(!$rr['halted']&&count(PaperAccountReplay::trades($nv))>0,'variant creates genuine recovery orders without PR95 membership restriction');
test(PaperNoPullbackStudy::verify($nv,['data'=>$data,'reference'=>[],'candidates'=>$cand],true)['pass'],'variant recomputed order audit and books pass');
foreach(PaperAccountReplay::trades($nv) as $t)test($t['pattern']!=='trend_pullback_v1','no excluded pattern order');
$nr('split',true,['stop_after_sessions'=>3]);[$rr,$ns,$sp]=$nr('split',true);
test(PaperAccountReplay::normalized($ns)===PaperAccountReplay::normalized($nv),'variant split equals once');
$hash=hash_file('sha256',$sp);[$rr]=$nr('split',true);test($rr['processed_sessions']===0&&hash_file('sha256',$sp)===$hash,'variant rerun does not write');
$changed=$data;$changed['files']['test']='modified';try{$nr('split',true,[],$changed);test(false,'input identity');}catch(RuntimeException $e){test(str_contains($e->getMessage(),'Research input changed'),'changed input refuses resume');}
$bad=$data;$bad['breaks'][$sym]=[['from'=>$data['start_day'],'to'=>PaperHistoryResearch::day($bars[70]['available_at'])]];
[$rr,$nh]=$nr('break',true,[],$bad);test($rr['halted'],'variant active break still halts entire session');
test(count(array_filter($nh['events'],fn($e)=>$e['type']==='fill'))===0,'variant halt has no partial fills');
$plan=(PaperNoPullbackStudy::provider(true,[$sym=>$early],[]))($sym,\ChartEntryLab\CandleClock::completed($bars,$sym,$signal),$signal);
test(!$plan['ready']&&$plan['status']==='hold_cross_break_source','new recommendation old target still held');
$mu=$nv;foreach($mu['events'] as &$e)if($e['type']==='order'){$e['payload']['pattern']='trend_pullback_v1';break;}unset($e);
test(!PaperNoPullbackStudy::verify($mu,['data'=>$data,'reference'=>[],'candidates'=>$cand],true)['pass'],'forbidden pattern mutation rejected');
$mirror=$tmp.'/np-mirror';mkdir($mirror.'/src',0777,true);mkdir($mirror.'/bin/paper',0777,true);
foreach(['src/ChartPlanEngine.php','bin/paper/NoPullbackEngine.php'] as $f)file_put_contents($mirror.'/'.$f,str_replace("\n","\r\n",file_get_contents($root.'/'.$f)));
PaperNoPullbackStudy::assertMirror($mirror);test(true,'CRLF engine source comparison passes');
file_put_contents($mirror.'/bin/paper/NoPullbackEngine.php',"\n// changed",FILE_APPEND);
try{PaperNoPullbackStudy::assertMirror($mirror);test(false,'mirror drift');}catch(RuntimeException $e){test(str_contains($e->getMessage(),'mirror changed'),'extra engine edit rejected');}

// Isolated arbitration-only probe: deterministic detector stubs, never an account or performance input.
$probe=$tmp.'/arbitration.php';
$stub= <<<'PROBE'
<?php
namespace ChartEntryLab {
class CandleClock {static function completed($b,$s,$a){return $b;}}
class FeatureEngine {static $blocked=false;function extract($b){return ['spike_dump_status'=>self::$blocked?'warning':'none'];}}
class AccountPlaybook {static function forProfile($p){return new self;}function decide($f,$s){return ['action'=>'wait'];}}
class BreakoutRetest {static $ready=false;function analyze($b){return ['version'=>'breakout_retest_v1','ready'=>self::$ready,'status'=>self::$ready?'ready':'no_setup','reason'=>'test','reward_risk'=>2,'candidate'=>null,'entry'=>100,'stop'=>90,'target'=>120];}}
class TrendPullback {function analyze($b,$f){return ['version'=>'trend_pullback_v1','ready'=>true,'status'=>'ready','reason'=>'test','reward_risk'=>2,'candidate'=>null,'entry'=>100,'stop'=>90,'target'=>120];}}
class TrendRecovery {const VERSION='trend_recovery_v1';function analyze($b){return ['version'=>self::VERSION,'ready'=>true,'status'=>'ready','reason'=>'test','reward_risk'=>2,'candidate'=>null,'entry'=>100,'stop'=>90,'target'=>120];}static function contextBlocked($c,$r){return false;}}
class TrendContext {function analyze($b,$s,$a){return ['daily'=>'up','weekly'=>'up','label'=>'test'];}}
class PriceCandidate {static function build($l,$h,$s,$t,$v){return ['low'=>$l,'high'=>$h,'stop'=>$s,'target'=>$t];}}
}
namespace {
require __ENGINE__;
$engine=new \NoPullbackStudy\ChartPlanEngine();$bars=array_fill(0,40,['available_at'=>100000]);
$a=$engine->analyze($bars,'005930.KS',100000,'account1',true,false)['plan'];
$b=$engine->analyze($bars,'005930.KS',100000,'account1',true,true)['plan'];
if($a['pattern']!=='trend_pullback_v1'||$b['pattern']!=='trend_recovery_v1'||!$b['ready'])throw new \RuntimeException('Masked recovery not reselected');
\ChartEntryLab\BreakoutRetest::$ready=true;
if($engine->analyze($bars,'005930.KS',100000)['plan']['pattern']!=='breakout_retest_v1')throw new \RuntimeException('Retest priority changed');
\ChartEntryLab\FeatureEngine::$blocked=true;
if($engine->analyze($bars,'005930.KS',100000)['plan']['ready'])throw new \RuntimeException('Common risk bypassed');
echo 'ARBITRATION_OK';
}
PROBE;
file_put_contents($probe,str_replace('__ENGINE__',var_export($root.'/bin/paper/NoPullbackEngine.php',true),$stub));
$p=proc_open([PHP_BINARY,'-n',$probe],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($p))throw new RuntimeException('Cannot run arbitration probe');
$po=stream_get_contents($pipes[1]);$pe=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
test($exit===0&&$po==='ARBITRATION_OK','masked recovery reselected, retest priority and common risk retained: '.$pe);
echo "PASS $n total checks\n";
