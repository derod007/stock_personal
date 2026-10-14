<?php
declare(strict_types=1);
require __DIR__.'/../bin/paper/KrxAccountStudy.php';
require __DIR__.'/fixtures/trend_recovery_bars.php';
use ChartEntryLab\{PaperJournal,CandleClock,ChartPlanEngine};
$n=0;function test(bool $ok,string $s):void{global $n;if(!$ok)throw new RuntimeException('FAIL '.$s);$n++;echo "OK $s\n";}
$root=dirname(__DIR__);PaperKrxAccountStudy::assertAdapter($root);test(true,'static adapter only declared edits');
$cfg=PaperKrxAccountStudy::config($root,'baseline');$original=PaperDatedTop100Replay::configs($root,PaperDatedTop100Replay::RESEARCH_CONFIG_YEAR)['base'];
test($cfg===array_replace($original,['id'=>$cfg['id']]),'only account ID changed');
$tmp=sys_get_temp_dir().'/krx-account-study-'.bin2hex(random_bytes(5));mkdir($tmp);
register_shutdown_function(function()use($tmp){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($tmp);});
$bars=recovery_bars();$signal=end($bars)['available_at'];
for($i=1;$i<=6;$i++){
 $day=(new DateTimeImmutable('@'.$signal))->setTimezone(new DateTimeZone('Asia/Seoul'))->modify('+'.$i.' weekdays')->format('Y-m-d');
 $at=PaperDatedTop100Replay::sessionClose($day);$bars[]=['time_kst'=>$day.' 09:00:00','available_at'=>$at,'open'=>105,'high'=>108,'low'=>103,'close'=>106,'volume'=>100000];
}
$sym='005930.KS';$other='000660.KS';$data=['dataset'=>'test-krx','files'=>['test'=>'1'],'start_day'=>PaperHistoryResearch::day($signal),'as_of_day'=>PaperKrxContinuityReview::day(end($bars)),
 'missing'=>[],'hold'=>[],'breaks'=>[],'symbols'=>[]];
foreach([$sym,$other] as $s)$data['symbols'][$s]=['name'=>$s,'rows'=>$bars,'bars_sha256'=>PaperKrxSignalStudy::digest($bars)];
$cand=['by_session'=>[],'union'=>[$sym,$other],'file_sha256'=>'test'];$ref=[];
foreach(array_slice($bars,69) as $b){$at=$b['available_at'];$day=PaperHistoryResearch::day($at);$cand['by_session'][$at]=['day'=>$day,'symbols'=>[$sym,$other]];
 foreach([$sym,$other] as $s){$v=CandleClock::completed($bars,$s,$at);$full=(new ChartPlanEngine())->analyze($v,$s,$at,'account1',true);
 $variant=(new \RecoveryCloseStudy\ChartPlanEngine())->analyze($v,$s,$at,'account1',true,true);
 $ref[$s.'@'.$day]=['original'=>['baseline'=>PaperKrxSignalStudy::compact($full['plan']),'baseline_quality_eligible'=>!empty($full['plan']['ready']),
 'variant'=>PaperKrxSignalStudy::compact($variant['plan']),'variant_quality_eligible'=>!empty($variant['plan']['ready'])],
 'arms'=>['baseline'=>PaperKrxContinuityReview::trace($v,$full,[],$day),'variant'=>PaperKrxContinuityReview::trace($v,$variant,[],$day)]];
 }}
$opts=['start'=>$data['start_day'],'through'=>$data['as_of_day'],'runtime_code_extra'=>PaperKrxAccountStudy::FILES,
 'plan_provider'=>PaperKrxAccountStudy::provider(false,[],$ref)];
$run=static function(string $name,array $d,array $o=[],?array $candidates=null)use($cfg,$cand,$opts,$tmp):array{
 $result=PaperKrxAccountReplay::run($d,$cfg,$tmp.'/'.$name.'/account-replay.json',$candidates??$cand,array_replace($opts,$o));
 return [$result,(new PaperJournal($tmp.'/'.$name.'/account-replay.json'))->read()];
};
[$a,$once]=$run('once',$data,['chunk'=>243]);
test(!$a['halted']&&count(PaperAccountReplay::trades($once))>0,'real engine fixture makes orders');
test(PaperAccountReplay::reconcile($once,$data)['pass'],'event books and simulator reconcile');
$loaded=['data'=>$data,'reference'=>$ref,'candidates'=>$cand];test(PaperKrxAccountStudy::verify($once,$loaded,false)['pass'],'asof order and active safety audit');
$run('split',$data,['through'=>PaperHistoryResearch::day($bars[71]['available_at']),'chunk'=>3]);[$a,$split]=$run('split',$data,['chunk'=>3]);
test(PaperAccountReplay::normalized($once)===PaperAccountReplay::normalized($split),'split resume equals full transaction');
$p=$tmp.'/split/account-replay.json';$hash=hash_file('sha256',$p);$mtime=filemtime($p);[$a]=$run('split',$data);
test($a['processed_sessions']===0&&$hash===hash_file('sha256',$p)&&$mtime===filemtime($p),'repeat does not write journal');
PaperDatedTop100Replay::run($data,$cfg,$tmp.'/legacy/account-replay.json',$cand,$opts);
$legacy=(new PaperJournal($tmp.'/legacy/account-replay.json'))->read();
test(PaperAccountReplay::trades($legacy)===PaperAccountReplay::trades($once)&&PaperAccountReplay::dailyLog($legacy)===PaperAccountReplay::dailyLog($once),'no-hazard cash/trades/day logs equal existing dated replay');
// Existing pending orders on first following session; no partial fill of the other symbol.
$breakDay=PaperHistoryResearch::day($bars[70]['available_at']);$bad=$data;$bad['breaks'][$sym]=[['from'=>$data['start_day'],'to'=>$breakDay]];
[$a,$halt]=$run('pending-break',$bad);
test($a['halted']&&$halt['state']['last_session']===$signal,'pending break halts before session');
test(count(array_filter($halt['events'],fn($e)=>$e['type']==='fill'))===0,'other symbol not partially filled before halt');
test(count($halt['state']['active'])===2,'pending orders preserved, not cancelled');
test(PaperKrxAccountStudy::verify($halt,['data'=>$bad,'reference'=>$ref,'candidates'=>$cand],false)['pass'],'halted event books and safety audit');
$hp=$tmp.'/pending-break/account-replay.json';$hash=hash_file('sha256',$hp);[$a]=$run('pending-break',$bad);test($a['processed_sessions']===0&&hash_file('sha256',$hp)===$hash,'halted rerun leaves journal unchanged');
$d=$data;$d['breaks'][$sym]=[['from'=>$breakDay,'to'=>PaperHistoryResearch::day($bars[71]['available_at'])]];
[$a,$filledBreak]=$run('filled-break',$d);test($a['halted']&&!empty($filledBreak['state']['active'][$sym]['filled']),'filled holding break stops before next execution');
test(PaperKrxAccountStudy::verify($filledBreak,['data'=>$d,'reference'=>$ref,'candidates'=>$cand],false)['pass'],'filled break audit');
// Missing or invalid active bar after TOP100 dropout, filled and pending alike.
$drop=$cand;foreach($drop['by_session'] as $t=>&$row)if($t>$signal)$row['symbols']=[$other];unset($row);
foreach([70,71] as $i){$d=$data;array_splice($d['symbols'][$sym]['rows'],$i,1);$d['symbols'][$sym]['bars_sha256']=PaperKrxSignalStudy::digest($d['symbols'][$sym]['rows']);
 [$a,$j]=$run('missing-'.$i,$d,[],$drop);test($a['halted'],'missing active bar stops '.($i===70?'pending':'filled'));
 test($j['state']['halt_at']===$bars[$i]['available_at'],'halt date exact '.$i);
 test(PaperKrxAccountStudy::verify($j,['data'=>$d,'reference'=>$ref,'candidates'=>$cand],false)['pass'],'missing-session books and audit '.$i);
}
$d=$data;$d['symbols'][$sym]['rows'][70]['open']=0;$d['symbols'][$sym]['rows'][70]['high']=0;$d['symbols'][$sym]['rows'][70]['low']=0;
[$a]=$run('zero',$d,[],$drop);test($a['halted'],'zero OHLC stops pending before cancellation');
$d=$data;$d['breaks'][$sym]=[['from'=>'2030-01-01','to'=>'2030-01-02']];[$a,$future]=$run('future',$d);
test(PaperAccountReplay::normalized($future)===PaperAccountReplay::normalized($once),'future break has no effect');
$d=$data;$d['breaks']['999999.KQ']=[['from'=>$data['start_day'],'to'=>$breakDay]];[$a,$unrelated]=$run('unrelated',$d);
test(!$a['halted'],'unrelated break does not halt account');
$mut=$once;foreach($mut['events'] as &$e)if($e['type']==='order'){$e['payload']['entry']++;break;}unset($e);
test(!PaperKrxAccountStudy::verify($mut,$loaded,false)['pass'],'order corruption detected');
$d=$data;$d['files']['test']='changed';try{$run('once',$d);test(false,'changed input refused');}catch(RuntimeException $e){test(str_contains($e->getMessage(),'Research input changed'),'changed input refuses resume');}
[$a,$variant]=$run('variant',$data,['plan_provider'=>PaperKrxAccountStudy::provider(true,[],$ref)]);
test(PaperKrxAccountStudy::verify($variant,$loaded,true)['pass'],'variant real engine orders verified against references');
// Same PR95 guard blocks old target before it becomes an order.
$early=[['from'=>PaperHistoryResearch::day($bars[39]['available_at']),'to'=>PaperHistoryResearch::day($bars[40]['available_at'])]];
$v=CandleClock::completed($bars,$sym,$signal);$full=(new ChartPlanEngine())->analyze($v,$sym,$signal,'account1',true);
$guardRef=$ref;$key=$sym.'@'.$data['start_day'];$guardRef[$key]['arms']['baseline']=PaperKrxContinuityReview::trace($v,$full,$early,$data['start_day']);
$plan=(PaperKrxAccountStudy::provider(false,[$sym=>$early],$guardRef))($sym,$v,$signal);
test(!$plan['ready']&&$plan['status']==='hold_cross_break_source','old target is held before portfolio');
echo "PASS $n checks\n";
