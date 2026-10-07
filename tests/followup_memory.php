<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';require __DIR__.'/../bin/paper/Followup.php';
function fm(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);echo "FOLLOWUP_MEMORY_PASS $why\n";}
$tmp=sys_get_temp_dir().'/followup-memory-'.bin2hex(random_bytes(5));mkdir($tmp,0700,true);
$prior=getenv('PAPER_STATE_DIR');
try{
    $audit=$tmp.'/rr-audit/paper-kr';mkdir($audit,0770,true);mkdir($tmp.'/prices');
    $bars=[];for($i=0;$i<500;$i++)$bars[]=['available_at'=>1600000000+$i*86400,'open'=>100,'high'=>101,'low'=>99,'close'=>100,'volume'=>10000];
    $session=end($bars)['available_at'];$asOf=$session+60;$price=PaperRrAudit::encode($bars);$hash=hash('sha256',$price);
    $first=null;
    for($day=0;$day<20;$day++){
        $records=[];
        for($i=0;$i<100;$i++){
            $symbol=sprintf('%06d.KS',$day*100+$i+1);
            $record=['status'=>'evaluated','symbol'=>$symbol,'name'=>'test','session'=>$session,'bars'=>$bars,'input_hash'=>$hash,'analysis_symbol'=>$symbol,
                'analysis'=>['plan'=>['ready'=>false,'status'=>'no_setup']],'patterns'=>[]];
            $records[]=$record;$first??=$record;
            file_put_contents($tmp.'/prices/'.$symbol.'.json',$price);
        }
        file_put_contents($audit.'/'.sprintf('202609%02d-112300-%012x.json',$day+1,$day),PaperRrAudit::encode(['version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only','recorded_at'=>$asOf,'records'=>$records]));
    }
    unset($records,$record);
    // Exercise the former all-history collection in a separate process at the same limit.
    $code='require '.var_export(__DIR__.'/../bin/bootstrap.php',true).'; require '.var_export(__DIR__.'/../bin/paper/Followup.php',true).'; PaperFollowup::observations('.var_export($audit,true).');';
    exec(escapeshellarg(PHP_BINARY).' -d memory_limit=512M -r '.escapeshellarg($code).' 2>&1',$legacyLines,$legacyExit);
    fm($legacyExit!==0&&str_contains(implode("\n",$legacyLines),'Allowed memory size'),'former all-history collection reproduces 512M exhaustion');
    putenv('PAPER_STATE_DIR='.$tmp);
    $base=escapeshellarg(PHP_BINARY).' -d memory_limit=512M '.escapeshellarg(__DIR__.'/../bin/paper_followup.php').' --account=paper-kr --as-of='.escapeshellarg(gmdate('c',$asOf));
    exec($base.' --prices='.escapeshellarg($tmp.'/prices').' 2>&1',$lines,$exit);
    fm($exit===0,'ordinary CLI completes under 512M');
    $result=json_decode(implode("\n",$lines),true,512,JSON_THROW_ON_ERROR);
    fm($result['observations']===2000&&$result['summary']['statuses']['no_future_bars']===2000,'all observations and no-future outcomes retained');
    fm($result['peak_memory_bytes']<384*1024*1024,'bounded peak below 384M');echo 'FOLLOWUP_PEAK_BYTES='.$result['peak_memory_bytes']."\n";
    $saved=PaperFollowup::load($tmp,'paper-kr');$first['source_file']='20260901-112300-000000000000.json';$first['captured_at']=$asOf;$first['observation_hash']=hash('sha256',PaperRrAudit::encode($first));
    $expected=PaperFollowup::evaluate($first,$bars,$asOf);$key=$first['symbol'].'@'.$session;$actual=$saved['rows'][$key];
    unset($actual['price_hash'],$actual['price_source']);fm($actual===$expected,'streamed row equals original evaluation and exact observation hash');
    $before=$saved['rows'];unset($saved);
    exec($base.' --saved-evidence 2>&1',$offlineLines,$offlineExit);
    fm($offlineExit===0,'saved evidence CLI completes under 512M');
    $offline=PaperFollowup::load($tmp,'paper-kr');
    foreach($offline['rows'] as $k=>$row){$row['price_source']='provider_or_local_prices';if($row!==$before[$k])throw new RuntimeException('Saved evidence result changed: '.$k);}
    fm(true,'saved evidence preserves every outcome, hash and source link');
    unset($before,$offline);
    // Earliest source and duplicate/unavailable/error metadata remain stable on a small source set.
    $small=$tmp.'/small';mkdir($small);$one=$first;unset($one['source_file'],$one['captured_at'],$one['observation_hash']);
    $bundle=['version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only','recorded_at'=>$asOf,'records'=>[$one,['status'=>'unavailable']]];
    file_put_contents($small.'/20260901-112300-000000000001.json',PaperRrAudit::encode($bundle));
    file_put_contents($small.'/20260901-112301-000000000002.json',PaperRrAudit::encode($bundle));
    file_put_contents($small.'/20260901-112302-000000000003.json','{broken');
    $stream=PaperFollowup::observationStream($small);$rows=iterator_to_array($stream);$meta=$stream->getReturn();
    fm(count($rows)===1&&$meta['duplicates']===1&&$meta['unavailable']===2&&count($meta['errors'])===1,'dedup and unavailable/corrupt-file counters preserved');
    // A failed run must not replace a report evaluated later.
    $latest=$tmp.'/followup/paper-kr/latest.json';$bytes=file_get_contents($latest);
    exec(escapeshellarg(PHP_BINARY).' -d memory_limit=512M '.escapeshellarg(__DIR__.'/../bin/paper_followup.php').' --account=paper-kr --as-of='.escapeshellarg(gmdate('c',$asOf-1)).' --saved-evidence 2>&1',$olderLines,$olderExit);
    fm($olderExit!==0&&file_get_contents($latest)===$bytes,'newer latest protected against older replay');
}finally{
    $prior===false?putenv('PAPER_STATE_DIR'):putenv('PAPER_STATE_DIR='.$prior);
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($tmp);
}
