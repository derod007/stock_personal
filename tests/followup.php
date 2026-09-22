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
 $new=$r;$new['symbol']='000660.KS';$b['records']=[$new];file_put_contents($audit.'/20250101-000002-000000000003.json',PaperRrAudit::encode($b));
 exec($cmd,$output,$code);$partial=PaperFollowup::load($root,'test');ok($partial['status']==='partial'&&$partial['summary']['statuses']['price_or_evaluation_error']===1,'missing off-scan price visible not zero return');
}finally{
 putenv('PAPER_STATE_DIR');$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($root);
}
