<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/HigherLowPanel.php';
require __DIR__.'/fixtures/prebreak_higher_low_bars.php';
use ChartEntryLab\PrebreakHigherLow;
function hl(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function hlrecord(array $bars):array{
    return ['symbol'=>'005930.KS','name'=>'<script>test</script>','status'=>'evaluated','session'=>end($bars)['available_at'],
        'bars'=>$bars,'input_hash'=>hash('sha256',PaperRrAudit::encode($bars)),'patterns'=>[],
        'analysis'=>['plan'=>['ready'=>false,'status'=>'risk_blocked']]];
}
$b=prebreak_bars();$detector=new PrebreakHigherLow;
$p=$detector->analyze($b);hl($p['status']==='breakout_observed'&&$p['stage']==='breakout','higher low then breakout observed');
hl($p['evidence']['resistance']['price']===110.0&&$p['evidence']['low']['price']===80.0&&$p['evidence']['higher_low']['price']===90.0,'frozen rebound high and two lows');
hl($detector->analyze(array_slice($b,0,72))['status']==='await_higher_low','two right bars required');
$watch=$detector->analyze(array_slice($b,0,73));hl($watch['status']==='await_breakout','watch starts at pivot confirmation close');
hl($detector->analyze(array_slice($b,0,74))['setup_id']===$watch['setup_id'],'same structure retains identity');
foreach(['touch','early','same_close','lower_low','lost_low','same_bar_low_loss'] as $case){
 $x=$b;
 if($case==='touch')$x[74]['close']=110.0;
 if($case==='early'){$x[69]['close']=111.0;$x[69]['high']=112.0;}
 if($case==='same_close'){$x[72]['close']=111.0;$x[72]['high']=112.0;}
 if($case==='lower_low')$x[70]['low']=80.0;
 if($case==='lost_low')$x[73]['low']=90.0;
 if($case==='same_bar_low_loss')$x[74]['low']=90.0;
 hl($detector->analyze($x)['stage']!=='breakout','no premature or invalid signal: '.$case);
}
// Sliding lookback indices must not create duplicate structures.
$prefix=[];for($i=150;$i>0;$i--){$r=$b[0];$r['time']-=$i*86400;$r['available_at']=$r['time'];$prefix[]=$r;}
$extended=array_merge($prefix,$b);$slide=$detector->analyze(array_slice($extended,-180));
hl($slide['setup_id']===$p['setup_id'],'identity ignores sliding array indices');
$r=hlrecord($b);$future=$b[74];$future['available_at']+=86400;$future['time']=$future['available_at'];$future['low']=1;$r['bars'][]=$future;$r['input_hash']=hash('sha256',PaperRrAudit::encode($r['bars']));
hl(PaperHigherLowResearch::inspect($r)['status']==='breakout_observed','future bars excluded before pattern evaluation');
$r=hlrecord($b);$r['bars'][60]['close']=200;$r['input_hash']=hash('sha256',PaperRrAudit::encode($r['bars']));
hl(PaperHigherLowResearch::inspect($r)['status']==='quality_blocked','bad OHLC never supplies pattern pivots');
$tmp=sys_get_temp_dir().'/higher-low-'.bin2hex(random_bytes(5));mkdir($tmp);$folder=$tmp.'/higher-low-research/test';mkdir($folder,0770,true);$source=$tmp.'/rr-audit/test';mkdir($source,0770,true);
function hlbundle(string $path,array $bars):void{file_put_contents($path,PaperRrAudit::encode(['version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only','recorded_at'=>end($bars)['available_at']+60,'records'=>[hlrecord($bars)]]));}
try{
 foreach([73,74,75] as $i=>$n)hlbundle($source.'/2026100'.($i+1).'-000000-aaaaaaaaaaaa.json',array_slice($b,0,$n));
 $now=$b[74]['available_at']+120;$exec=PaperHigherLowResearch::executionVersion();
 $report=['schema'=>1,'kind'=>PaperHigherLowResearch::KIND,'account'=>'test','rows'=>[],'errors'=>[],'added'=>0];
 $report=PaperHigherLowResearch::register($report,$folder,$source,$now,$exec);
 hl(count($report['rows'])===3&&count(array_filter($report['rows'],fn($r)=>$r['frozen']['event_key']))===2,'watch and breakout deduplicated independently');
 $again=PaperHigherLowResearch::register($report,$folder,$source,$now,$exec);hl($again['rows']===$report['rows'],'same scan never overwrites or adds');
 $key='005930.KS@'.$b[74]['available_at'];$original=$report['rows'][$key];$raw=$b;
 for($i=1;$i<=20;$i++)$raw[]=['available_at'=>$b[74]['available_at']+$i*86400,'open'=>112+$i,'high'=>114+$i,'low'=>111+$i,'close'=>112+$i,'volume'=>100000];
 $at=$b[74]['available_at']+3*86400;$hash=hash('sha256',PaperRrAudit::encode($raw));
 $tracked=PaperHigherLowResearch::refresh($folder,$original,fn()=>['raw'=>$raw,'as_of'=>$at,'price_hash'=>$hash],$exec,$at);
 hl($tracked['status']==='pending'&&$tracked['observed_bars']===3&&$tracked['horizons'][5]['return_pct']===null,'only completed future horizons included');
 hl(abs($tracked['horizons'][3]['return_pct']-(115/112-1)*100)<1e-9,'returns from original observation close');
 $fail=PaperHigherLowResearch::refresh($folder,$tracked,fn()=>throw new RuntimeException('offline'),$exec,$at);
 hl($fail['status']==='update_error'&&$fail['horizons']===$tracked['horizons'],'failure preserves last success');
 $changed=$raw;$changed[50]['close']=101;$bad=PaperHigherLowResearch::refresh($folder,$tracked,fn()=>['raw'=>$changed,'as_of'=>$at,'price_hash'=>hash('sha256',PaperRrAudit::encode($changed))],$exec,$at);
 hl($bad['status']==='update_error'&&$bad['error']==='historical_revision_or_missing','historical revisions remain blocked');
 hl(PaperHigherLowResearch::refresh($folder,$tracked,fn()=>[],'new_version',$at)['status']==='update_error','execution version mismatch blocked');
 $last=$b[74]['available_at']+20*86400;$complete=PaperHigherLowResearch::refresh($folder,$tracked,fn()=>['raw'=>$raw,'as_of'=>$last,'price_hash'=>$hash],$exec,$last);
 hl($complete['status']==='complete','20-bar terminal result');
 hl(PaperHigherLowResearch::refresh($folder,$complete,fn()=>throw new RuntimeException('no fetch'),$exec,$last)===$complete,'completed outcomes frozen');
 $report['rows'][$key]=$tracked;$report['as_of']=$at;$report['summary']=PaperHigherLowResearch::summarize($report['rows']);
 PaperEnvelopeStore::save($folder,$report);hl(PaperRrAudit::encode(PaperHigherLowResearch::load($tmp,'test')['rows'])===PaperRrAudit::encode($report['rows']),'store roundtrip');
 ob_start();paper_higher_low_panel($tmp,'test');$html=ob_get_clean();hl(str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<script>test'),'panel escapes source text');
 hl(str_contains($html,'추천·주문·체결이 아닙니다')&&str_contains($html,'위험 차단'),'independent observation and operating status distinct');
 // Actual research page selects this panel and performs no writes.
 putenv('PAPER_STATE_DIR='.$tmp);$digest=hash_file('sha256',$folder.'/latest.json');
 $code='$_GET=["account"=>"test","mode"=>"forward","higher_low_research"=>"1"]; include '.var_export(dirname(__DIR__).'/paper_rr.php',true).';';
 $page=shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
 hl(str_contains($page,'돌파 전 높은 저점형 · 독립 관찰')&&hash_file('sha256',$folder.'/latest.json')===$digest,'actual GET page displays research without changing ledger');
 // Run real CLI offline and then without original audit files: own frozen observations remain trackable.
 mkdir($tmp.'/prices');file_put_contents($tmp.'/prices/005930.KS.json',PaperRrAudit::encode($raw));putenv('PAPER_STATE_DIR='.$tmp);
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/paper_higher_low.php').' --account=test --prices='.escapeshellarg($tmp.'/prices').' --as-of='.gmdate('c',$last);
 $partialCmd=str_replace(gmdate('c',$last),gmdate('c',$at),$cmd);
 exec($partialCmd,$lines,$code);hl($code===0,'real offline CLI runs');
 $partial=PaperHigherLowResearch::load($tmp,'test');$savedCmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/paper_higher_low.php').' --account=test --saved-evidence --as-of='.gmdate('c',$at);
 foreach(glob($source.'/*.json') as $path)unlink($path);
 exec($savedCmd,$lines,$code);$replay=PaperHigherLowResearch::load($tmp,'test');
 hl($code===0&&$replay['rows'][$key]['horizons']==$partial['rows'][$key]['horizons'],'saved-evidence replay without original archive');
 $pricePath=$folder.'/evidence/'.$partial['rows'][$key]['price_hash'].'.json';$priceBytes=file_get_contents($pricePath);file_put_contents($pricePath,'[]');
 exec($savedCmd,$lines,$code);$damaged=PaperHigherLowResearch::load($tmp,'test');
 hl($code===0&&$damaged['status']==='partial'&&$damaged['rows'][$key]['horizons']==$partial['rows'][$key]['horizons'],'corrupt saved price evidence preserves prior results and reports partial');
 file_put_contents($pricePath,$priceBytes);

 exec($cmd,$lines,$code);$saved=PaperHigherLowResearch::load($tmp,'test');hl($code===0&&count($saved['rows'])===3,'TOP100/archive loss does not erase observations');
 $lock=fopen($folder.'/update.lock','c');flock($lock,LOCK_EX);exec($cmd.' 2>/dev/null',$lines,$code);flock($lock,LOCK_UN);fclose($lock);hl($code!==0,'concurrent writer blocked');
 $before=file_get_contents($folder.'/latest.json');exec($cmd.' --as-of='.gmdate('c',$at).' 2>/dev/null',$lines,$code);hl($code!==0&&file_get_contents($folder.'/latest.json')===$before,'older run cannot replace latest');
 $fullPath=$folder.'/frozen/'.$original['frozen_ref'].'.json';file_put_contents($fullPath,'{}');
 hl(PaperHigherLowResearch::refresh($folder,$original,fn()=>[],$exec,$last)['status']==='update_error','tampered frozen evidence rejected');
 echo "PREBREAK_HIGHER_LOW_PASS\n";
}finally{putenv('PAPER_STATE_DIR');$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($tmp);}
