<?php
declare(strict_types=1);
require __DIR__.'/fixtures/HistoricalResearchBaseline.php';
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/TargetTracking.php';
function tm(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);echo "TARGET_MEMORY_PASS $why\n";}
if(($argv[1]??'')==='--peak'){
    $now=(int)$argv[3];
    $blank=['schema'=>1,'kind'=>PaperTargetTracking::KIND,'account'=>'paper-kr','rows'=>[],'errors'=>[],'added'=>0,'as_of'=>$now,'generated_at'=>$now];
    $report=PaperTargetTracking::registerStream($blank,PaperFollowup::observationStream($argv[2]),$now,PaperStrategyVersion::current(),PaperTargetTracking::executionVersion());
    echo 'PEAK='.memory_get_peak_usage(true)."\nADDED=".$report['added']."\nERRORS=".count($report['errors'])."\nROWS=".count($report['rows'])."\n";
    exit(0);
}
$root=dirname(__DIR__).'/docs/paper-kr-5d-source/rr-audit/paper-kr';
$now=1790794963;$engine=PaperTargetTracking::executionVersion();$fp=PaperStrategyVersion::current();
$blank=['schema'=>1,'kind'=>PaperTargetTracking::KIND,'account'=>'paper-kr','rows'=>[],'errors'=>[],'added'=>0,'as_of'=>$now,'generated_at'=>$now];
$legacy=PaperTargetTracking::register($blank,PaperFollowup::observations($root),$now,$fp,$engine);
$streamed=PaperTargetTracking::registerStream($blank,PaperFollowup::observationStream($root),$now,$fp,$engine);
tm($legacy===$streamed&&count($legacy['rows'])===13&&$legacy['errors']===[],'fixed 5-day candidates match the all-at-once register');
unset($legacy,$streamed);
$tmp=sys_get_temp_dir().'/target-memory-'.bin2hex(random_bytes(5));
try{
    $small=$tmp.'/small';mkdir($small,0770,true);
    $session=1700000000;
    $bar=['available_at'=>$session,'open'=>1,'high'=>2,'low'=>1,'close'=>1,'volume'=>1];
    $keep=['status'=>'evaluated','symbol'=>'000001.KS','name'=>'keep','session'=>$session,'bars'=>[$bar],'input_hash'=>hash('sha256',PaperRrAudit::encode([$bar])),'analysis'=>['plan'=>['ready'=>false,'status'=>'no_setup']],'patterns'=>[]];
    $bad=$keep;$bad['symbol']='000002.KS';$bad['input_hash']=str_repeat('b',64);
    $bad['patterns']=[['pattern'=>'trend_pullback','exclusion_reasons'=>[],'raw_status'=>'ready','original'=>['entry'=>1,'stop'=>1,'target'=>1]]];
    $bundle=['version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only','recorded_at'=>$session,'records'=>[$keep,$bad]];
    file_put_contents($small.'/20260901-112300-000000000001.json',PaperRrAudit::encode($bundle));
    file_put_contents($small.'/20260902-112300-000000000002.json',PaperRrAudit::encode(['version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only','recorded_at'=>$session,'records'=>[$keep]]));
    file_put_contents($small.'/20260903-112300-000000000003.json','{broken');
    $key='000001.KS@'.$session;
    $planted=['schema'=>1,'kind'=>PaperTargetTracking::KIND,'account'=>'paper-kr','added'=>0,'as_of'=>$now,'generated_at'=>$now,
        'errors'=>[['file'=>'prior','error'=>'kept']],'rows'=>[$key=>['frozen'=>['record'=>['symbol'=>'000001.KS','session'=>$session,'observation_hash'=>str_repeat('c',64)]],'frozen_hash'=>'x','status'=>'registered','outcomes'=>[],'last_success_at'=>null]]];
    $old=PaperTargetTracking::register($planted,PaperFollowup::observations($small),$now,$fp,$engine);
    $neu=PaperTargetTracking::registerStream($planted,PaperFollowup::observationStream($small),$now,$fp,$engine);
    tm($old===$neu,'corrupt, duplicate and hash mismatch stay on the old path');
    $text=PaperRrAudit::encode($neu['errors']);
    tm($neu['added']===0&&$neu['rows'][$key]['frozen']['record']['observation_hash']===str_repeat('c',64),'existing frozen row kept');
    tm(count($neu['errors'])===4&&str_contains($text,'kept')&&str_contains($text,'Syntax error')&&str_contains($text,'Original hash mismatch')&&str_contains($text,'changed_observation_preserved_original'),'prior, corrupt file, hash mismatch and changed original all reported');
    $audit=$tmp.'/rr-audit/paper-kr';mkdir($audit,0770,true);
    $bars=[];for($i=0;$i<500;$i++)$bars[]=['available_at'=>1600000000+$i*86400,'open'=>100,'high'=>101,'low'=>99,'close'=>100,'volume'=>10000];
    $bulkSession=end($bars)['available_at'];$price=PaperRrAudit::encode($bars);$hash=hash('sha256',$price);$bytes=0;
    for($day=0;$day<20;$day++){
        $records=[];
        for($n=0;$n<100;$n++)$records[]=['status'=>'evaluated','symbol'=>sprintf('%06d.KS',$day*100+$n+1),'name'=>'test','session'=>$bulkSession,'bars'=>$bars,'input_hash'=>$hash,'analysis'=>['plan'=>['ready'=>false,'status'=>'no_setup']],'patterns'=>[]];
        $body=PaperRrAudit::encode(['version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only','recorded_at'=>$bulkSession,'records'=>$records]);
        $path=$audit.'/'.sprintf('202609%02d-112300-%012x.json',$day+1,$day);
        file_put_contents($path,$body);$bytes+=strlen($body);
    }
    unset($records,$bars,$body,$price);
    $code='require '.var_export(__DIR__.'/../bin/bootstrap.php',true).'; require '.var_export(__DIR__.'/../bin/paper/TargetTracking.php',true).'; PaperFollowup::observations('.var_export($audit,true).');';
    exec(escapeshellarg(PHP_BINARY).' -d memory_limit=512M -r '.escapeshellarg($code).' 2>&1',$legacyLines,$legacyExit);
    tm($legacyExit!==0&&str_contains(implode("\n",$legacyLines),'Allowed memory size'),'all-at-once load still exhausts 512M');
    $cut=$bulkSession+86400;
    exec(escapeshellarg(PHP_BINARY).' -d memory_limit=512M '.escapeshellarg(__FILE__).' --peak '.escapeshellarg($audit).' '.$cut.' 2>&1',$peakLines,$peakExit);
    tm($peakExit===0,'streamed register completes under 512M');
    $peakText=implode("\n",$peakLines);preg_match('/PEAK=(\d+)/',$peakText,$m);$peak=(int)$m[1];
    tm($peak>0&&$peak<512*1024*1024,'peak stays inside the 512M limit');
    echo "TARGET_MEMORY_SCALE files=20 records=2000 bars=500 bytes=$bytes peak=$peak\n";
    $state=$tmp.'/state';mkdir($state);
    $prior=getenv('PAPER_STATE_DIR');putenv('PAPER_STATE_DIR='.$state);
    exec(escapeshellarg(PHP_BINARY).' -d memory_limit=512M '.escapeshellarg(dirname(__DIR__).'/bin/paper_target_tracking.php').' --account=paper-kr --source-dir='.escapeshellarg($tmp).' --as-of='.escapeshellarg(gmdate('c',$cut)).' 2>&1',$cli,$cliExit);
    $prior===false?putenv('PAPER_STATE_DIR'):putenv('PAPER_STATE_DIR='.$prior);
    tm($cliExit===0&&str_contains(implode("\n",$cli),'"added":0'),'tracking CLI completes under 512M and adds no false candidate');
}finally{
    if(is_dir($tmp)){
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());
        rmdir($tmp);
    }
}
