<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';require __DIR__.'/../bin/paper/EnvelopeResearchPanel.php';
function em(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "MEMORY PASS $label\n";}
$tmp=sys_get_temp_dir().'/envelope-memory-'.bin2hex(random_bytes(5));mkdir($tmp);$folder=$tmp.'/envelope-research/paper-kr';mkdir($folder,0770,true);
$bars=[];for($i=0;$i<500;$i++)$bars[]=['available_at'=>1600000000+$i*86400,'open'=>100,'high'=>101,'low'=>99,'close'=>100,'volume'=>10000];
$r=['symbol'=>'000001.KS','name'=>'test " 한글 \\ braces {}','session'=>end($bars)['available_at'],'captured_at'=>end($bars)['available_at']+60,
    'bars'=>$bars,'input_hash'=>hash('sha256',PaperRrAudit::encode($bars)),'analysis'=>['plan'=>['ready'=>false,'status'=>'no_setup']],
    'patterns'=>[],'source_file'=>'test.json','status'=>'evaluated'];$r['observation_hash']=hash('sha256',PaperRrAudit::encode($r));
$execution=PaperEnvelopeResearch::executionVersion();$now=1790794963;$key=$r['symbol'].'@'.$r['session'];
$legacy=PaperEnvelopeResearch::register(['schema'=>1,'kind'=>PaperEnvelopeResearch::KIND,'account'=>'paper-kr','as_of'=>$now,'generated_at'=>$now,'errors'=>[],'added'=>0,'rows'=>[]],['records'=>[$key=>$r],'errors'=>[]],$now,$execution);
$legacy['status']='saved';$legacy['summary']=PaperEnvelopeResearch::summarize($legacy['rows']);
try{
    PaperFollowup::save($folder,$legacy);$bytes=file_get_contents($folder.'/latest.json');
    $read=PaperEnvelopeStore::load($tmp,'paper-kr');em(!is_dir($folder.'/frozen'),'legacy dashboard read never writes');
    $migrated=PaperEnvelopeStore::load($tmp,'paper-kr',true);$full=PaperEnvelopeStore::hydrate($folder,$migrated['rows'][$key]);
    em(PaperRrAudit::encode($full)===PaperRrAudit::encode($legacy['rows'][$key]),'legacy migration retains exact frozen hash version and results');
    em(file_get_contents($folder.'/latest.json')===$bytes,'migration prepares without overwriting legacy latest');
    PaperEnvelopeStore::save($folder,$migrated);$loaded=PaperEnvelopeStore::load($tmp,'paper-kr');
    em($loaded['rows']===$migrated['rows'],'compact round trip');
    em(!isset($loaded['rows'][$key]['frozen']['record']['bars']),'dashboard does not decode source bars');
    $path=$folder.'/frozen/'.$full['frozen_hash'].'.json';$snapshot=file_get_contents($path);file_put_contents($path,'corrupt');
    $error=PaperEnvelopeStore::refresh($folder,$loaded['rows'][$key],fn()=>throw new RuntimeException('should not fetch'),$execution,$now);
    em($error['status']==='update_error'&&$error['frozen_hash']===$full['frozen_hash'],'corrupt frozen source quarantined');file_put_contents($path,$snapshot);
    file_put_contents($folder.'/latest.json','{"rows":{"broken":');
    try{PaperEnvelopeStore::load($tmp,'paper-kr');em(false,'truncated report');}catch(Throwable $e){em(true,'truncated JSON rejected');}
    // Cross-buffer escaped strings and Unicode must survive streaming.
    $fixture=$tmp.'/parser.json';$payload=['rows'=>['a'=>['text'=>str_repeat('한글"\\{}[]',12000)]],'account'=>'paper-kr'];
    file_put_contents($fixture,PaperRrAudit::encode($payload));$it=PaperJsonRows::read($fixture);$rows=iterator_to_array($it);
    em($rows===$payload['rows']&&$it->getReturn()['account']==='paper-kr','buffer boundaries and trailing metadata');
    // 2,000 observations x 500 candles exceed the former all-history array budget.
    // Each source bundle has the same TOP100 size as production. The child keeps its 512M limit.
    $stress=$tmp.'/stress';$audit=$stress.'/rr-audit/paper-kr';mkdir($audit,0770,true);
    for($day=0;$day<20;$day++){
        $records=[];
        for($i=0;$i<100;$i++){
            $item=$r;$item['symbol']=sprintf('%06d.KS',$day*100+$i+1);unset($item['observation_hash'],$item['captured_at'],$item['source_file']);$records[]=$item;
        }
        $bundle=['version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only','recorded_at'=>$r['captured_at'],'records'=>$records];
        file_put_contents($audit.'/'.sprintf('202609%02d-112300-%012x.json',$day+1,$day),PaperRrAudit::encode($bundle));unset($bundle,$records);
    }
    putenv('PAPER_STATE_DIR='.$stress);
    $cmd=escapeshellarg(PHP_BINARY).' -d memory_limit=512M '.escapeshellarg(dirname(__DIR__).'/bin/paper_envelope_research.php').' --account=paper-kr --saved-evidence --as-of=2026-10-01T04:02:43+09:00';
    exec($cmd,$lines,$exit);em($exit===0,'2,000-row CLI completes under 512M');$out=json_decode(implode("\n",$lines),true,512,JSON_THROW_ON_ERROR);
    em($out['summary']['retained']===2000&&$out['peak_memory_bytes']<384*1024*1024,'bounded peak memory and all observations retained');
    // Missing prices are expected here; the test never fetches or fabricates prices.
    em(($out['summary']['statuses']['update_error']??0)===2000,'missing evidence stays an explicit update error');
    $s=PaperEnvelopeStore::load($stress,'paper-kr');em(count($s['rows'])===2000,'compact full ledger read');
    // Exercise the ordinary --prices path across more symbols than the eight-entry cache.
    $normal=$tmp.'/normal';mkdir($normal.'/rr-audit/paper-kr',0770,true);mkdir($normal.'/prices');$records=[];
    for($i=0;$i<20;$i++){
        $item=$r;$item['symbol']=sprintf('%06d.KS',$i+1);unset($item['observation_hash'],$item['captured_at'],$item['source_file']);$records[]=$item;
        file_put_contents($normal.'/prices/'.$item['symbol'].'.json',PaperRrAudit::encode($bars));
    }
    file_put_contents($normal.'/rr-audit/paper-kr/20260922-112300-000000000001.json',PaperRrAudit::encode(['version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only','recorded_at'=>$r['captured_at'],'records'=>$records]));
    putenv('PAPER_STATE_DIR='.$normal);
    $normalCmd=escapeshellarg(PHP_BINARY).' -d memory_limit=512M '.escapeshellarg(dirname(__DIR__).'/bin/paper_envelope_research.php').' --account=paper-kr --prices='.escapeshellarg($normal.'/prices').' --as-of=2026-10-01T04:02:43+09:00';
    exec($normalCmd,$normalLines,$normalExit);$normalOut=json_decode(implode("\n",$normalLines),true,512,JSON_THROW_ON_ERROR);
    em($normalExit===0&&($normalOut['summary']['statuses']['no_future_bars']??0)===20,'ordinary price path crosses bounded cache without losing rows');
    echo 'ENVELOPE_MEMORY_PEAK_BYTES='.$out['peak_memory_bytes']."\n";
}finally{
    putenv('PAPER_STATE_DIR');$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($tmp);
}
