<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/HistoricalResearch.php';
require __DIR__.'/paper/EnvelopeStore.php';
$o=getopt('',['account:','source-dir:','as-of:']);
$id=$o['account']??'paper-kr';$source=$o['source-dir']??null;$asOf=isset($o['as-of'])?strtotime($o['as-of']):false;
if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id)||!is_dir((string)$source)||!$asOf||$asOf>time())
    throw new InvalidArgumentException('Required: --source-dir, --as-of (past cutoff); optional --account');
$fingerprint=PaperStrategyVersion::current();
$report=['schema'=>1,'kind'=>'current_rule_historical_research','account'=>$id,'as_of'=>$asOf,
    'strategy_fingerprint'=>$fingerprint,'membership'=>'observed_scan_only','rows'=>[],'evidence_errors'=>[]];
$codeFiles=[];
foreach(['bin/paper_historical_research.php','bin/paper/HistoricalResearch.php','bin/paper/Rediagnosis.php',
    'bin/paper/Followup.php','bin/paper/RrAudit.php','bin/paper/TrackingInput.php','bin/paper/EnvelopeStore.php',
    'src/KrAmountLeadersClient.php','src/CandleClock.php','src/PaperQuality.php','src/TradeSimulator.php'] as $path)
    $codeFiles[$path]=PaperStrategyVersion::fileHash(dirname(__DIR__).'/'.$path);
$report['research_files']=$codeFiles;
$report['execution_version']=hash('sha256',PaperRrAudit::encode(['strategy'=>$fingerprint,'files'=>$codeFiles]));
$index=PaperEnvelopeStore::savedIndex($source.'/followup/'.$id.'/latest.json');
$stream=PaperFollowup::observationStream($source.'/rr-audit/'.$id);
foreach($stream as $key=>$r){
    $prices=null;$cutoff=$asOf;$f=$index[$r['observation_hash']]??null;
    try{
        if(!$f||$f['source_file']!==$r['source_file']||$f['symbol']!==$r['symbol']||$f['session']!==$r['session'])throw new RuntimeException('Matching saved evidence unavailable');
        $hash=$f['price_hash']??'';
        if(!preg_match('/^[a-f0-9]{64}$/',$hash))throw new RuntimeException('Invalid price hash');
        $bytes=file_get_contents($source.'/followup/'.$id.'/evidence/'.$hash.'.json');
        $prices=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($prices)||!array_is_list($prices)||!hash_equals($hash,hash('sha256',PaperRrAudit::encode($prices))))throw new RuntimeException('Price hash mismatch');
        $cutoff=min($asOf,(int)$f['as_of']);
    }catch(Throwable $e){$prices=null;$report['evidence_errors'][$key]=$e->getMessage();}
    // Decision availability uses the requested cutoff; price outcome is capped by saved evidence time.
    $row=PaperHistoricalResearch::evaluate($r,$prices,$asOf,$fingerprint,$cutoff);
    $row['evidence_as_of']=$prices!==null?$cutoff:null;$row['price_hash']=$prices!==null?$f['price_hash']:null;
    $report['rows'][$key]=$row;
}
$report['source_summary']=$stream->getReturn();$report['summary']=PaperHistoricalResearch::summarize($report['rows']);
// stdout only: no network, operational state, directories, orders or original files are written.
echo PaperRrAudit::encode($report)."\n";
