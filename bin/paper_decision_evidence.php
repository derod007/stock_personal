<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/DecisionEvidence.php';
// Export only saved evidence. No network, analysis engine, journal, or account invocation.
$o=getopt('', ['input:', 'output:']);
if(empty($o['input'])||empty($o['output']))throw new InvalidArgumentException('--input=<saved rr-audit JSON> --output=<new JSON> required');
$input=(string)$o['input'];$output=(string)$o['output'];
$bytes=file_get_contents($input);if($bytes===false)throw new RuntimeException('Cannot read input');
$bundle=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
if(!isset($bundle['records'])||!is_array($bundle['records'])||($bundle['membership']??'')!=='observed_scan_only')throw new RuntimeException('Expected saved rr-audit bundle, not an account journal');
$rows=[];
foreach($bundle['records'] as $r){
    if(!is_array($r)||empty($r['symbol']))throw new RuntimeException('Malformed audit record');
    $rows[]=PaperDecisionEvidence::fromRecord($r);
}
$result=['schema'=>1,'kind'=>'saved_decision_evidence_export','source_sha256'=>hash('sha256',$bytes),
    'source_recorded_at'=>$bundle['recorded_at']??null,
    'source_code_hashes'=>$bundle['decision_code_hashes']??null,
    'source_code_status'=>isset($bundle['decision_code_hashes'])?'recorded':'unknown_not_backfilled',
    'exporter_code_hashes'=>PaperDecisionEvidence::codeHashes(),'records'=>$rows];
$json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
$fh=@fopen($output,'x');if($fh===false)throw new RuntimeException('Output exists or cannot be created');
try{if(fwrite($fh,$json)!==strlen($json))throw new RuntimeException('Output write failed');}finally{fclose($fh);}
echo json_encode(['records'=>count($rows),'output'=>$output,'account_writes'=>false],JSON_THROW_ON_ERROR)."\n";
