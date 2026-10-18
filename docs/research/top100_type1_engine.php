<?php
declare(strict_types=1);
require_once __DIR__.'/Type1EngineReplay.php';
$options=getopt('',['input:','output:']);
if(empty($options['input'])||empty($options['output']))throw new InvalidArgumentException('--input and --output required');
$inputPath=$options['input'];$out=$options['output'];
$input=json_decode(file_get_contents($inputPath),true,512,JSON_THROW_ON_ERROR);
if(($input['schema']??null)!==1)throw new InvalidArgumentException('Wrong input schema');
$root=dirname(__DIR__,2);$cfg=json_decode(file_get_contents($root.'/config/paper-kr-recovery-v1.json'),true,512,JSON_THROW_ON_ERROR);
if(file_exists($out)||!mkdir($out,0777,true))throw new RuntimeException('New research output directory required');
$hashes=[];
foreach(['src/PaperPortfolio.php','src/TradeSimulator.php','src/PaperQuality.php','src/CandleClock.php','src/PaperForwardExitPolicy.php',
    'docs/research/Type1EngineReplay.php','docs/research/top100_type1_engine.php','config/paper-kr-recovery-v1.json'] as $p)$hashes[$p]=hash_file('sha256',$root.'/'.$p);
$loader=function(string $sym)use($input):array{
    if(!preg_match('/^[0-9A-Z]+\.(KS|KQ)$/',$sym))throw new InvalidArgumentException('Invalid symbol');
    $p=$input['dataset'].'/bars/'.$sym.'.json';
    if(!is_file($p)||!hash_equals($input['hashes']['bars'][$sym]??'',hash_file('sha256',$p)))throw new RuntimeException('Source changed: '.$sym);
    $raw=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
    if(($raw['price_basis']??$input['price_basis'])!==$input['price_basis'])throw new RuntimeException('Price basis mismatch');
    return $raw['rows'];
};
$summary=[];
foreach(['all_types','type1'] as $arm){
    $config=$cfg;$config['id']='research-type1-engine-'.str_replace('-','',$input['days'][0]).'-'.$arm;
    $r=Type1EngineReplay::run($input,$config,$arm,$loader);
    $r['input_sha256']=hash_file('sha256',$inputPath);$r['code_hashes']=$hashes;
    file_put_contents($out.'/'.$arm.'.json',json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    $summary[$arm]=array_diff_key($r,array_flip(['state','events','trades','quality']));
}
file_put_contents($out.'/summary.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
exit(count(array_filter($summary,fn($r)=>$r['status']==='completed'))===2?0:2);
