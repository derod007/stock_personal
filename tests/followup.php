<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/Followup.php';
require __DIR__.'/../bin/paper/Weekly.php';
function ok(bool $v,string $name):void{if(!$v)throw new RuntimeException($name);echo "OK $name\n";}
$bars=[];$start=new DateTimeImmutable('2025-01-02 15:30:00',new DateTimeZone('Asia/Seoul'));
for($i=0;$i<83;$i++){$t=$start->modify('+'.$i.' weekdays')->getTimestamp();$c=$i<60?100:101+$i-60;
$bars[]=['available_at'=>$t,'time'=>$t,'open'=>$c,'high'=>$c+2,'low'=>$c-2,'close'=>$c,'volume'=>1000];}
$old=array_slice($bars,0,60);$session=end($old)['available_at'];$now=end($bars)['available_at'];
$r=['status'=>'evaluated','symbol'=>'005930.KS','name'=>'<fixture>','session'=>$session,'bars'=>$old,'input_hash'=>hash('sha256',PaperRrAudit::encode($old)),
 'analysis'=>['plan'=>['ready'=>false,'status'=>'context_wait']],
 'patterns'=>[['confirmed_rr_rejection'=>true,'exclusion_reasons'=>['context_wait'],'missing_conditions'=>['volume_contracted'=>'거래량']]],
 'source_file'=>'source.json','captured_at'=>$session+60,'observation_hash'=>'fixture'];
$x=PaperFollowup::evaluate($r,$bars,$now);
ok($x['complete'] && abs($x['horizons'][1]['return_pct']-1)<1e-8 && abs($x['horizons'][20]['return_pct']-20)<1e-8,'1/20 forward returns use signal close');
ok(abs($x['horizons'][3]['max_up_pct']-5)<1e-8 && abs($x['horizons'][3]['max_down_pct']+1)<1e-8,'future high/low excursions');
$early=PaperFollowup::evaluate($r,$bars,$bars[62]['available_at']);
ok($early['horizons'][3]['status']==='complete'&&$early['horizons'][5]['return_pct']===null,'as-of excludes future and incomplete horizon is null');
$stale=$early;$stale['status']='pending';$stale['latest_session']=$session;$stale['as_of']=$now;
$failed=['symbol'=>'000660.KS','status'=>'price_or_evaluation_error','rejected'=>true,'error'=>'Yahoo HTTP 404','horizons'=>[]];
$health=PaperFollowup::health(['generated_at'=>$now,'as_of'=>$now,'status'=>'partial','rows'=>['a'=>$x,'b'=>$stale,'c'=>$failed],'errors'=>[['file'=>'bad.json','error'=>'Invalid observation']]],[
 ['started_at'=>$now-86400,'finished_at'=>$now-86300,'status'=>'success','followup'=>['status'=>'saved','finished_at'=>$now-86200]],
 ['started_at'=>$now-3600,'finished_at'=>$now-3500,'status'=>'failed','followup'=>['status'=>'failed','finished_at'=>$now-3500,'error_type'=>'RuntimeException']],
],$now);
ok($health['latest_start']===$now-3600&&$health['latest_success_at']===$now-86300&&$health['followup_success_status']==='saved','last run and last success stay distinct');
ok($health['tracking_rows']===1&&$health['complete_rows']===1&&$health['tracking_symbols']===1&&$health['stale_rows']===1,'open tracking and stale prices are counted');
ok($health['price_failures']===1&&$health['rejected_samples'][20]===1&&$health['rejected_samples'][5]===1&&count($health['errors'])===2,'price failure and 5/20 completed samples');
$s=PaperFollowup::summarize([$x,$early]);
ok($s['groups']['rr']['horizons'][20]['n']===1 && $s['groups']['rr']['signals']===2,'completed denominators only');
ok(count($s['groups'])===3 && $s['observations']===2,'overlap groups without multiplying observations');
$changed=$bars;$changed[59]['close']=99;
ok(PaperFollowup::evaluate($r,$changed,$now)['status']==='historical_revision_or_missing','historical revision blocks');
$bad=$bars;$bad[]=$bars[70];ok(PaperFollowup::evaluate($r,$bad,$now)['status']==='future_quality_blocked','duplicate bar blocked');
$bad=$r;$bad['input_hash']='wrong';ok(PaperFollowup::evaluate($bad,$bars,$now)['status']==='input_hash_mismatch','tampered source blocked');
$bad=$r;$bad['captured_at']=$bars[61]['available_at'];ok(PaperFollowup::evaluate($bad,$bars,$now)['status']==='late_observation','late signals not backdated');
$trade=$r;$trade['patterns'][]=['status'=>'added','candidate'=>['ready'=>true,'entry'=>102,'stop'=>95,'target'=>112,'signal_at'=>$session,'order_valid_bars'=>3]];
$t=PaperFollowup::evaluate($trade,$bars,$now);$out=$t['trades'][0]['outcome'];
ok($out['filled']&&$out['status']==='closed'&&$out['hit_target']&&$out['fee_bps_per_side']===10.0,'candidate reuses costed simulator');
$unfilled=$trade;$unfilled['patterns'][1]['candidate']['entry']=98;
ok(PaperFollowup::evaluate($unfilled,$bars,$now)['trades'][0]['outcome']['status']==='unfilled','three-bar order expiration');
$cancel=$trade;$cancel['patterns'][1]['candidate']['target']=102.5;
ok(PaperFollowup::evaluate($cancel,$bars,$now)['complete'],'cancelled-before-entry becomes terminal');
$w=['start'=>$session+1,'end'=>$now+1];ok(PaperFollowup::summarize([$x],$w)['observations']===0,'week groups by original decision session');
$root=sys_get_temp_dir().'/followup-test-'.bin2hex(random_bytes(6));mkdir($root);$audit=$root.'/rr-audit/test';mkdir($audit,0770,true);
try{
 $b=['version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only','recorded_at'=>$session+60,'records'=>[$r]];
 $f=$audit.'/20250101-000000-000000000001.json';file_put_contents($f,PaperRrAudit::encode($b));
 file_put_contents($audit.'/20250101-000001-000000000002.json',PaperRrAudit::encode($b));
 $obs=PaperFollowup::observations($audit);ok(count($obs['records'])===1&&$obs['duplicates']===1,'reruns deduplicate symbol-session');
 $before=hash_file('sha256',$f);mkdir($root.'/prices');file_put_contents($root.'/prices/005930.KS.json',PaperRrAudit::encode($bars));
 putenv('PAPER_STATE_DIR='.$root);
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/paper_followup.php').' --account=test --prices='.escapeshellarg($root.'/prices');
 exec($cmd,$output,$code);ok($code===0,'offline CLI publishes report');
 $report=PaperFollowup::load($root,'test');ok($report['summary']['observations']===1,'persisted report loads');
 unlink($root.'/prices/005930.KS.json');exec($cmd,$output,$code);
 ok($code===0&&PaperFollowup::load($root,'test')['status']==='saved','completed records frozen without re-fetch');
 ok(hash_file('sha256',$f)===$before,'source audit immutable');
 $weekly=PaperWeekly::load($root,'test','forward',(new DateTimeImmutable('@'.$session))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('o-\WW'));
 ok($weekly['summary']===null&&$weekly['followup']['summary']['observations']===1,'weekly followup works without account orders');
 require __DIR__.'/../bin/paper/FollowupPanel.php';ob_start();paper_followup_panel($report);$html=ob_get_clean();
 ok(str_contains($html,'&lt;fixture&gt;')&&!str_contains($html,'<fixture>'),'read-only panel escapes names');
 ok(str_contains($html,'마지막 실행·성공 시각')&&str_contains($html,'추적 중·관측 완료 종목 수')&&str_contains($html,'가격 갱신 실패·오래된 데이터')&&str_contains($html,'기간별 완료 표본 수')&&str_contains($html,'php bin/paper_followup.php --account=test'),'status panel shows rerun guidance');
 $new=$r;$new['symbol']='000660.KS';$b['records']=[$new];file_put_contents($audit.'/20250101-000002-000000000003.json',PaperRrAudit::encode($b));
 exec($cmd,$output,$code);$partial=PaperFollowup::load($root,'test');ok($partial['status']==='partial'&&$partial['summary']['statuses']['price_or_evaluation_error']===1,'missing off-scan price visible not zero return');
}finally{
 putenv('PAPER_STATE_DIR');$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($root);
}
