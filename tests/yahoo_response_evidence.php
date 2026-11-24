<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
use ChartEntryLab\{YahooChartClient,YahooResponseEvidence};

$checks = 0;
function ye(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
$tmp = sys_get_temp_dir().'/yahoo-evidence-'.bin2hex(random_bytes(8));
mkdir($tmp);
$now = strtotime('2026-11-23 20:20:00 +0900');
$fixture = ['chart'=>['result'=>[['meta'=>[], 'timestamp'=>[1795392000],
    'indicators'=>['quote'=>[['open'=>[31000], 'high'=>[31150], 'low'=>[29950], 'close'=>[30300], 'volume'=>[2056121]]]]]]]];
$raw = static fn(array $p): string => " \n".json_encode($p, JSON_PRETTY_PRINT)."\n";
$run = static function(array $p, ?YahooResponseEvidence $evidence) use ($tmp, $now): array {
    $client = new YahooChartClient($tmp.'/cache', chartTransport:static fn($url)=>$p,
        clock:static fn()=>$now, responseEvidence:$evidence);
    return (new ReflectionMethod($client, 'fetchLive'))->invoke($client, '003490.KS', '2y', '1d');
};
try {
    $archive = new YahooResponseEvidence($tmp.'/receipts');
    $archive->record($raw($fixture), '003490.KS', '2y', '1d', $now);
    ye(!file_exists($tmp.'/receipts'), 'normal response creates no folder');
    foreach ([0,null,'',false,'bad',-1] as $n=>$value) {
        $p=$fixture;$p['chart']['result'][0]['indicators']['quote'][0]['open'][0]=$value;
        $body=$raw($p);
        $dir=$tmp.'/case-'.$n;
        $evidence=new YahooResponseEvidence($dir);
        $evidence->record($body,'003490.KS','2y','1d',$now);
        $files=glob($dir.'/*.json');
        ye(count($files)===1,'one receipt for invalid open');
        $saved=json_decode(file_get_contents($files[0]),true,512,JSON_THROW_ON_ERROR);
        ye(base64_decode($saved['body_base64'],true)===$body && $saved['body_sha256']===hash('sha256',$body),'exact response bytes and hash');
        ye($saved['reason']==='invalid_open' && $saved['received_at_utc']==='2026-11-23T11:20:00Z','reason and receive time');
        ye($run($p,null)===$run($p,$evidence),'enabled diagnostics preserve existing conversion');
    }
    $bad=$fixture;$bad['chart']['result'][0]['indicators']['quote'][0]['open'][0]=0;
    $body=$raw($bad);
    $archive->record($body,'003490.KS','2y','5m',$now);
    $archive->record($body,'003490.KS','2y','1d',strtotime('2026-11-23 12:00:00 +0900'));
    ye(!file_exists($tmp.'/receipts'),'intraday and unfinished daily are not captured');
    $archive->record($body,'003490.KS','2y','1d',$now);
    $first=glob($tmp.'/receipts/*.json')[0];$hash=hash_file('sha256',$first);
    $archive->record($body,'003490.KS','2y','1d',$now);
    ye(count(glob($tmp.'/receipts/*.json'))===2 && hash_file('sha256',$first)===$hash,'identical retry keeps both receipts without overwrite');
    ye(glob($tmp.'/receipts/*.pending')===[],'successful receipts have no pending files');
    $archive->record("invalid\xff",'003490.KS','2y','1d',$now);
    $found=false;
    foreach(glob($tmp.'/receipts/*.json') as $f){$d=json_decode(file_get_contents($f),true);if($d['reason']==='invalid_json')$found=base64_decode($d['body_base64'])==="invalid\xff";}
    ye($found,'malformed JSON and invalid UTF8 survive exactly');
    $inconsistent=$fixture;$inconsistent['chart']['result'][0]['indicators']['quote'][0]['high'][0]=30000;
    (new YahooResponseEvidence($tmp.'/ohlc'))->record($raw($inconsistent),'003490.KS','2y','1d',$now);
    ye(json_decode(file_get_contents(glob($tmp.'/ohlc/*.json')[0]),true)['reason']==='inconsistent_ohlc','inconsistent range captured');
    $duplicate=$fixture;$duplicate['chart']['result'][0]['timestamp'][]=1795392000;
    (new YahooResponseEvidence($tmp.'/duplicates'))->record($raw($duplicate),'003490.KS','2y','1d',$now);
    ye(json_decode(file_get_contents(glob($tmp.'/duplicates/*.json')[0]),true)['reason']==='duplicate_latest_timestamp','duplicate latest timestamp captured');
    file_put_contents($tmp.'/blocked','keep');
    $previous=ini_set('error_log',$tmp.'/error.log');
    try { ye($run($bad,null)===$run($bad,new YahooResponseEvidence($tmp.'/blocked/child')),'write failure leaves price result unchanged'); }
    finally { ini_set('error_log',$previous); }
    ye(str_contains(file_get_contents($tmp.'/error.log'),'Yahoo response evidence failed:'),'write failure is visible in error log');
    ye(file_get_contents($tmp.'/blocked')==='keep','write failure preserves existing file');
    echo "YAHOO_RESPONSE_EVIDENCE_OK $checks\n";
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}
    rmdir($tmp);
}
