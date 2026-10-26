<?php
declare(strict_types=1);
require_once __DIR__.'/IndependentTradeDiagnostic.php';
$o=getopt('',['input:','dataset:','output:']);
foreach(['input','dataset','output'] as $k)if(empty($o[$k]))throw new InvalidArgumentException($k);
if(file_exists($o['output']))throw new RuntimeException('New output required');
$input=json_decode(file_get_contents($o['input']),true,512,JSON_THROW_ON_ERROR);
if($input['price_basis']!=='krx_tdd_ohlcv_adjustment_unverified')throw new RuntimeException('KRX input required');
$by=[];foreach($input['signals'] as $s)$by[$s['symbol']][]=$s;
$out=[];
foreach($by as $sym=>$signals){
 if(!preg_match('/^[0-9A-Z]+\.(KS|KQ)$/',$sym))throw new RuntimeException('Symbol');
 $p=$o['dataset'].'/bars/'.$sym.'.json';$sha=hash_file('sha256',$p);
 if($sha!==$input['hashes']['bars'][$sym])throw new RuntimeException('Hash mismatch');
 $d=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
 if($d['price_basis']!==$input['price_basis'])throw new RuntimeException('Basis');
 foreach($signals as $s)$out[]=['signal'=>$s,'result'=>IndependentTradeDiagnostic::run($s,$input['days'],$d['rows'],$input['reference_events'][$sym]??[],$sha)];
 if(hash_file('sha256',$p)!==$sha)throw new RuntimeException('Input changed');
}
$f=fopen($o['output'],'x');if(!$f)throw new RuntimeException('Output collision');
fwrite($f,json_encode(['input_sha256'=>hash_file('sha256',$o['input']),'input_skips'=>$input['skips'],'account_execution'=>false,'records'=>$out],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fclose($f);
echo 'records='.count($out)."\n";
