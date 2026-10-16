<?php
declare(strict_types=1);
require __DIR__.'/paper/HistoricalDecisionEvidence.php';
$o=getopt('', ['dataset:','candidates:','output:','baseline:','limit-days:']);
foreach(['dataset','candidates','output'] as $key)if(empty($o[$key]))throw new InvalidArgumentException('--'.$key.' required');
$root=dirname(__DIR__);$dir=realpath($o['dataset']);if($dir===false)throw new RuntimeException('Dataset not found');
$meta=json_decode((string)file_get_contents($dir.'/dataset.json'),true,512,JSON_THROW_ON_ERROR);
$raw=json_decode((string)file_get_contents($o['candidates']),true,512,JSON_THROW_ON_ERROR);
$c=PaperDatedTop100Replay::loadCandidates($o['candidates'],count($raw['days']),(int)$raw['union_unique_symbols']);
$days=array_column($c['by_session'],'day');
if($days!==$raw['days'])throw new RuntimeException('Candidate calendar mismatch');
if(min($days)<$meta['evaluation_start_day']||max($days)>$meta['as_of']['day'])throw new RuntimeException('Candidate range outside dataset');
$data=PaperDatedTop100Replay::loadDataset($dir,$c,$meta['dataset'],$meta['evaluation_start_day'],$meta['as_of']['day']);
$baseline=[];
if(isset($o['baseline']))foreach(file($o['baseline'],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
 $d=json_decode($line,true,512,JSON_THROW_ON_ERROR);if(isset($baseline[$d['date']]))throw new RuntimeException('Duplicate baseline day');$baseline[$d['date']]=$d['signals'];
}
if(isset($o['baseline'])&&array_keys($baseline)!==$days)throw new RuntimeException('Baseline calendar mismatch');
$hashes=PaperDecisionEvidence::codeHashes();
foreach(['bin/paper/HistoricalDecisionEvidence.php','bin/paper_historical_decisions.php','bin/paper/DatedTop100Replay.php','bin/paper/HistoryResearch.php'] as $p)$hashes[$p]=hash_file('sha256',$root.'/'.$p);
$barHashes=[];foreach($data['symbols'] as $sym=>$i)$barHashes[$sym]=$i['bars_sha256'];
$identity=['version'=>PaperHistoricalDecisionEvidence::VERSION,'origin'=>'historical_recomputed_not_contemporaneous',
 'dataset'=>$meta['dataset'],'dataset_files'=>$data['files'],'bar_hashes'=>$barHashes,'candidate_hash'=>hash_file('sha256',$o['candidates']),
 'code_hashes'=>$hashes,'baseline_hash'=>isset($o['baseline'])?hash_file('sha256',$o['baseline']):null,'php'=>PHP_VERSION];
$out=$o['output'];if(!is_dir($out)&&!mkdir($out,0770,true))throw new RuntimeException('Cannot create output');
$out=realpath($out);
if($out===$dir||str_starts_with($out,$dir.DIRECTORY_SEPARATOR))throw new RuntimeException('Output must be outside frozen dataset');
$lock=fopen($out.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Output locked');
$manifest=$out.'/manifest.json';
if(is_file($manifest)){
 if(json_decode((string)file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR)!=$identity)throw new RuntimeException('Input/code changed; use new output directory');
}else{
 if(array_diff(scandir($out),['.','..','.lock'])!==[])throw new RuntimeException('Nonempty output without manifest');
 PaperHistoricalDecisionEvidence::writeNew($manifest,$identity);
}
$limit=isset($o['limit-days'])?(int)$o['limit-days']:count($days);if($limit<1||$limit>count($days))throw new RuntimeException('Invalid day limit');
$n=0;$reused=0;$signals=0;
foreach($c['by_session'] as $ts=>$day){
 if($n>=$limit)break;
 $path=$out.'/day-'.$day['day'].'.json';
 if(is_file($path)){
  $envelope=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
  $payload=$envelope['payload'];if(!hash_equals($envelope['sha256'],hash('sha256',PaperHistoricalDecisionEvidence::encode($payload))))throw new RuntimeException('Corrupt checkpoint');
  if($payload['date']!==$day['day']||array_column($payload['records'],'symbol')!==$day['symbols'])throw new RuntimeException('Checkpoint membership mismatch');
  $rows=$payload['records'];$reused++;
 }else{
  $rows=[];foreach($day['rows'] as $candidate){
   $sym=$candidate['symbol'];$r=PaperHistoricalDecisionEvidence::inspect($data['symbols'][$sym],$sym,(int)$ts,$data['dataset']);
   $r['name']=$candidate['name'];$r['amount_rank']=$candidate['rank'];$rows[]=$r;
  }
  if(isset($o['baseline']))PaperHistoricalDecisionEvidence::verifySignals($rows,$baseline[$day['day']]);
  $payload=['date'=>$day['day'],'source_top100_hash'=>$day['source_sha256'],'baseline_verified'=>isset($o['baseline']),'records'=>$rows];
  PaperHistoricalDecisionEvidence::writeNew($path,['sha256'=>hash('sha256',PaperHistoricalDecisionEvidence::encode($payload)),'payload'=>$payload]);
 }
 if(isset($o['baseline']))PaperHistoricalDecisionEvidence::verifySignals($rows,$baseline[$day['day']]);
 $n++;$signals+=count(array_filter($rows,static fn($r)=>$r['recommendation_eligible']));
 fwrite(STDERR,$day['day'].' '.$n.'/'.$limit."\n");
}
echo PaperHistoricalDecisionEvidence::encode(['dataset'=>$data['dataset'],'days'=>$n,'reused_days'=>$reused,'observations'=>$n*100,'signals'=>$signals,'baseline_verified'=>isset($o['baseline']),'account_writes'=>false,'complete'=>$n===count($days)])."\n";
