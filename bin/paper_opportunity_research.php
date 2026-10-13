<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/OpportunityResearch.php';
$cmd=$argv[1]??'';$o=[];
foreach(array_slice($argv,2) as $arg){if(!preg_match('/^--([a-z-]+)=(.*)$/s',$arg,$m))throw new InvalidArgumentException('Use --key=value');$o[$m[1]]=$m[2];}
PaperOpportunityResearch::demand(in_array($cmd,['inventory','run'],true),'Commands: inventory | run');
foreach(array_keys($o) as $k)PaperOpportunityResearch::demand(in_array($k,['state-dir','dataset-dir','run-id','max-sessions'],true),'Unknown option '.$k);
if(isset($o['state-dir']))putenv('PAPER_STATE_DIR='.$o['state-dir']);
$root=dirname(__DIR__);$profile=PaperDatedTop100Replay::profile(PaperDatedTop100Replay::PROFILE_YEAR);
$cpath=$root.'/'.$profile['candidates'];$log=$root.'/'.$profile['docs'].'/account/daily-log.jsonl';
PaperOpportunityResearch::demand(PaperStrategyVersion::current()===PaperDatedTop100Replay::STRATEGY_FINGERPRINT,'Strategy changed');
PaperOpportunityResearch::demand(PaperStrategyVersion::fileHash($cpath)==='ece25f22de2f8e1fd2c99550110568b1769b577abc6dc607b17e5d227b8672b5','Frozen candidates changed');
PaperOpportunityResearch::demand(PaperStrategyVersion::fileHash($log)==='e34c1581b1bf1dc089e206cf112a6ec8d18af5d2df74e041ac135e5c35af750c','Frozen daily log changed');
$c=PaperDatedTop100Replay::loadCandidates($cpath,244,933);$base=PaperOpportunityResearch::baseline($log,$c);
if($cmd==='inventory'){echo PaperOpportunityResearch::encode(['writes'=>false,'baseline'=>$base['counts'],'capacity'=>$base['capacity']])."\n";exit;}
$run=$o['run-id']??'opportunity-v1';PaperOpportunityResearch::demand((bool)preg_match('/^opportunity-[A-Za-z0-9_-]+$/D',$run),'Run ID must start opportunity-');
$limit=filter_var($o['max-sessions']??8,FILTER_VALIDATE_INT);PaperOpportunityResearch::demand($limit!==false&&$limit>=1&&$limit<=244,'max-sessions must be 1..244');
$state=PaperAccountReplay::stateRoot();$ds=$o['dataset-dir']??PaperHistoryResearch::datasetDir($state,$profile['dataset']);
$data=PaperDatedTop100Replay::loadDataset($ds,$c,$profile['dataset'],$profile['eval_start'],$profile['as_of']);
$want=['dataset.json'=>'2151bc4531ae4d39a1fa60010ee5d9f76727c12a98880759c652bea44c6a5fec','universe.json'=>'13840c1a432392f7c8adc0db4602adccdd3864199e3da314864a7a44147b2fa3'];
PaperOpportunityResearch::demand($data['files']===$want,'Dataset metadata differs from PR76');
$out=$state.'/history-research/'.$run;
PaperOpportunityResearch::demand(!is_link($out),'Symlink output is not allowed');
if(!is_dir($out)&&!mkdir($out,0775,true)&&!is_dir($out))throw new RuntimeException('Cannot create output');
$norm=static fn(string $p):string=>strtolower(str_replace('\\','/',(string)realpath($p)));
PaperOpportunityResearch::demand(!str_starts_with($norm($out).'/', $norm($ds).'/')&&!str_starts_with($norm($ds).'/', $norm($out).'/'),'Output overlaps dataset');
PaperOpportunityResearch::demand(!str_starts_with($norm($out).'/', $norm($root).'/'),'Research output must be outside repository');
$lock=fopen($out.'/.lock','c');PaperOpportunityResearch::demand($lock!==false&&flock($lock,LOCK_EX|LOCK_NB),'Another process holds this run');
$bars=[];foreach($data['symbols'] as $sym=>$info)$bars[$sym]=$info['bars_sha256'];ksort($bars);
$code=[];foreach(['bin/paper/OpportunityResearch.php','bin/paper_opportunity_research.php','bin/paper/PatternReplay.php','bin/paper/DatedTop100Replay.php','bin/paper/HistoryResearch.php','bin/paper/StrategyVersion.php','bin/paper/AccountReplay.php','docs/opportunity-research/protocol.md'] as $p)$code[$p]=PaperStrategyVersion::fileHash($root.'/'.$p);
$identity=['version'=>PaperOpportunityResearch::VERSION,'strategy'=>PaperStrategyVersion::current(),'code_sha256_lf'=>$code,'candidates_sha256_lf'=>PaperStrategyVersion::fileHash($cpath),
    'daily_log_sha256_lf'=>PaperStrategyVersion::fileHash($log),'dataset'=>$data['dataset'],'dataset_files'=>$data['files'],'bars_hash'=>PaperOpportunityResearch::hash($bars),
    'php_version'=>PHP_VERSION,'profile'=>'account1','use_context'=>true,'costs'=>['fee_bps_per_side'=>10,'slippage_bps'=>5],'window'=>['ttl'=>3,'holding_bars'=>20]];
$allDays=array_keys($base['days']);$done=PaperOpportunityResearch::checkpoint($out,$identity,$allDays);$processed=0;
foreach($c['by_session'] as $ts=>$rec){$day=$rec['day'];if(isset($done[$day]))continue;if($processed>=$limit)break;
    $result=PaperOpportunityResearch::day($data,$c,$ts,$base['days'][$day]);
    PaperOpportunityResearch::writeNew($out.'/day-'.$day.'.json',['payload'=>$result,'sha256'=>PaperOpportunityResearch::hash($result)]);
    $done[$day]=$result;$processed++;fwrite(STDERR,sprintf("%s complete (%d/244)\n",$day,count($done)));
}
$summary=PaperOpportunityResearch::summarize($done,$allDays);$summary['provenance']=$identity;$report=$out.'/report-'.count($done).'.json';
if(!is_file($report))PaperOpportunityResearch::writeNew($report,$summary);
else PaperOpportunityResearch::demand(PaperOpportunityResearch::read($report)===$summary,'Existing report differs');
flock($lock,LOCK_UN);fclose($lock);
echo PaperOpportunityResearch::encode(['processed_sessions'=>$processed,'completed_sessions'=>count($done),'full_period_complete'=>$summary['full_period_complete'],'report'=>$report,
    'capacity'=>$summary['capacity_summary'],'near_miss_groups'=>$summary['near_miss_groups'],'operational_writes'=>false])."\n";
