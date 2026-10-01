<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';require __DIR__.'/../bin/paper/TargetTrackingPanel.php';
function tt(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$root=dirname(__DIR__).'/docs/paper-kr-5d-source';$now=1790794963;
$obs=PaperFollowup::observations($root.'/rr-audit/paper-kr');$engine=PaperTargetTracking::executionVersion();$fp=PaperStrategyVersion::current();
$r=['schema'=>1,'kind'=>PaperTargetTracking::KIND,'account'=>'paper-kr','rows'=>[],'errors'=>[],'added'=>0,'as_of'=>$now,'generated_at'=>$now];
$r=PaperTargetTracking::register($r,$obs,$now,$fp,$engine);
tt(count($r['rows'])===13&&$r['added']===13&&$r['errors']===[],'bootstrap all historical breakout candidates');
$frozen=array_column($r['rows'],'frozen_hash');$r['added']=0;$again=PaperTargetTracking::register($r,$obs,$now+86400,$fp,$engine);
tt($again['added']===0&&array_column($again['rows'],'frozen_hash')===$frozen,'reruns never reprice or duplicate');
$empty=PaperTargetTracking::register($r,['records'=>[],'errors'=>[]],$now+30*86400,$fp,$engine);
tt(count($empty['rows'])===13,'all candidates retained when original scan disappears');
$key=array_key_first($r['rows']);$changed=$obs;$changed['records'][$key]['observation_hash']=str_repeat('a',64);
$conflict=PaperTargetTracking::register($r,$changed,$now+86400,$fp,$engine);
tt(count($conflict['errors'])===1&&$conflict['rows'][$key]['frozen_hash']===$r['rows'][$key]['frozen_hash'],'changed original quarantined');
$source=PaperFollowup::load($root,'paper-kr');$index=[];foreach($source['rows'] as $row)$index[$row['observation_hash']]=$row;
$provider=function($record,$old)use($index,$root):array{$f=$index[$record['observation_hash']];return ['raw'=>json_decode(file_get_contents($root.'/followup/paper-kr/evidence/'.$f['price_hash'].'.json'),true),'as_of'=>$f['as_of'],'price_hash'=>$f['price_hash']];};
foreach($r['rows'] as $key=>$row)$r['rows'][$key]=PaperTargetTracking::refresh($row,$provider,$engine,$now);
$s=PaperTargetTracking::summarize($r['rows']);
tt($s['update_errors']===0&&$s['currently_tracking']===2,'two open simulated trades recovered');
tt($s['groups'][0]['arms']['alternative']['filled']===2,'same two fills as frozen research');
$active=array_values(array_filter($r['rows'],fn($v)=>$v['status']==='tracking'))[0];$record=$active['frozen']['record'];
$bad=PaperTargetTracking::refresh($active,fn()=>throw new RuntimeException('provider down'),$engine,$now+86400);
tt($bad['status']==='update_error'&&$bad['outcomes']===$active['outcomes']&&$bad['last_success_at']===$active['last_success_at'],'provider failure preserves last success');
$version=PaperTargetTracking::refresh($active,$provider,'different',$now);
tt($version['status']==='update_error'&&$version['outcomes']===$active['outcomes'],'execution change cannot silently change frozen trade');
$bad=$active;$bad['frozen']['comparison']['alternative']['target']++;
tt(PaperTargetTracking::refresh($bad,$provider,$engine,$now)['status']==='update_error','frozen plan corruption detected');
// Extend the same original snapshot beyond the ordinary 20-observation horizon.
// Delayed fill on bar 3 must stay active at bar 20, then time-exit on bar 22.
$raw=$record['bars'];$plan=$active['frozen']['comparison']['alternative'];$session=$record['session'];
for($i=1;$i<=22;$i++){$price=$i<3?$plan['entry']+100:$plan['entry'];$raw[]=['available_at'=>$session+$i*86400,'open'=>$price,'high'=>$price+100,'low'=>$i<3?$price:$price-100,'close'=>$price,'volume'=>100000];}
$priceHash=hash('sha256',PaperRrAudit::encode($raw));
$at20=$session+20*86400;$at22=$session+22*86400;
$pending=PaperTargetTracking::refresh($active,fn()=>['raw'=>$raw,'as_of'=>$at20,'price_hash'=>$priceHash],$engine,$at20);
tt($pending['status']==='tracking'&&$pending['outcomes']['alternative']['bars']===18,'continues beyond original 20-bar horizon');
$closed=PaperTargetTracking::refresh($pending,fn()=>['raw'=>$raw,'as_of'=>$at22,'price_hash'=>$priceHash],$engine,$at22);
tt($closed['status']==='complete'&&$closed['outcomes']['alternative']['first_exit']==='time','late entry reaches full holding horizon');
$stable=PaperTargetTracking::refresh($closed,fn()=>throw new RuntimeException('should not fetch'),$engine,$at22+86400);
tt($stable===$closed,'terminal result not fetched or rewritten');
$w=['start'=>$at22,'end'=>$at22+86400];$weekly=PaperTargetTracking::summarize([$closed],$w);
tt($weekly['candidates']===0&&$weekly['groups'][0]['arms']['alternative']['closed']===1,'weekly exit includes old candidate');
$before=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile())$before[$file->getPathname()]=hash_file('sha256',$file->getPathname());
$tmp=sys_get_temp_dir().'/target-ledger-'.bin2hex(random_bytes(5));mkdir($tmp);putenv('PAPER_STATE_DIR='.$tmp);
try{
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/paper_target_tracking.php').' --account=paper-kr --source-dir='.escapeshellarg($root).' --saved-evidence --as-of=2026-10-01T04:02:43+09:00';
    exec($cmd,$lines,$exit);tt($exit===0,'offline bootstrap CLI success');$saved=PaperTargetTracking::load($tmp,'paper-kr');
    tt(count($saved['rows'])===13&&$saved['summary']['currently_tracking']===2,'CLI persists original candidates and fills');
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/paper_target_tracking.php').' --account=paper-kr --saved-evidence --as-of=2026-10-01T04:02:43+09:00';
    exec($cmd,$lines2,$exit);tt($exit===0,'archive-free refresh CLI success');$again=PaperTargetTracking::load($tmp,'paper-kr');
    tt($again['added']===0&&$again['rows']===$saved['rows'],'own snapshots survive source removal');
    // Locking must reject concurrent writers without replacing the ledger.
    $lock=fopen($tmp.'/target-tracking/paper-kr/update.lock','c');flock($lock,LOCK_EX);
    exec($cmd.' 2>/dev/null',$ignored,$exit);flock($lock,LOCK_UN);fclose($lock);
    tt($exit!==0&&PaperTargetTracking::load($tmp,'paper-kr')['rows']===$saved['rows'],'concurrent writer refused');
    ob_start();paper_target_tracking_panel($tmp,'paper-kr',$w);$html=ob_get_clean();tt(str_contains($html,'누적 목표가 비교 연구'),'weekly-compatible panel');
    foreach($before as $path=>$hash)if(hash_file('sha256',$path)!==$hash)throw new RuntimeException('source mutated');tt(true,'original source bytes unchanged');
    echo 'TARGET_LEDGER_SUMMARY='.PaperRrAudit::encode($saved['summary'])."\n";
}finally{
    putenv('PAPER_STATE_DIR');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($tmp);
}
