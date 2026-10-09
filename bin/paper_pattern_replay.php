<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require __DIR__.'/paper/PatternReplay.php';
$o=getopt('',['dataset-dir:','profile:']);$dir=rtrim($o['dataset-dir']??'','/\\');$profile=$o['profile']??'account1';
if(!is_dir($dir)||!in_array($profile,['account1','custom','isa'],true))throw new InvalidArgumentException('Required --dataset-dir; optional --profile=account1|custom|isa');
$problems=PaperHistoryResearch::verify($dir);if($problems)throw new RuntimeException(implode('; ',$problems));
$manifest=PaperHistoryResearch::manifest($dir);$ds=PaperHistoryResearch::readJson($dir.'/dataset.json');
$start=PaperHistoryResearch::evalStartDay($ds['as_of']['day']);$end=(int)$ds['as_of']['close_ts'];
$files=[];foreach(['bin/paper/PatternReplay.php','bin/paper_pattern_replay.php','bin/paper/HistoryResearch.php',
    'bin/paper/HigherLowResearch.php','bin/paper/RrAudit.php','bin/paper/Followup.php','src/PrebreakHigherLow.php',
    'src/TradeSimulator.php','src/PaperQuality.php','src/CandleClock.php'] as $p)$files[$p]=PaperStrategyVersion::fileHash(dirname(__DIR__).'/'.$p);
$report=['schema'=>1,'kind'=>PaperPatternReplay::VERSION,'dataset'=>$ds['dataset']??basename($dir),'profile'=>$profile,'context_applied'=>true,
    'start'=>$start,'end'=>$ds['as_of']['day'],'strategy_fingerprint'=>PaperStrategyVersion::current(),'research_files'=>$files,
    'dataset_hash'=>hash_file('sha256',$dir.'/dataset.json'),'universe_hash'=>hash_file('sha256',$dir.'/universe.json'),
    'source_summary'=>$manifest['summary'],'excluded'=>[],'symbols'=>[]];
$report['execution_version']=hash('sha256',PaperRrAudit::encode([$report['strategy_fingerprint'],$files,$profile,true]));
foreach($manifest['symbols'] as $s){
    if(($s['state']??'')!=='done'||($s['quality']??'hold')==='hold'){$report['excluded'][]=$s;continue;}
    $path=$dir.'/'.$s['bars_path'];if(!hash_equals($s['bars_sha256'],hash_file('sha256',$path)))throw new RuntimeException('Changed bars: '.$s['symbol']);
    $b=PaperHistoryResearch::readJson($path);
    if(($b['symbol']??null)!==$s['symbol'])throw new RuntimeException('Bars identity mismatch');
    $result=PaperPatternReplay::symbol($b['rows'],$s['symbol'],$s['name'],$start,$end,$profile);
    $result['bars_sha256']=$s['bars_sha256'];$report['symbols'][]=$result;
    fwrite(STDERR,sprintf("%d %s days=%d events=%d\n",count($report['symbols']),$s['symbol'],$result['days'],count($result['events'])));
}
$report['summary']=PaperPatternReplay::summarize($report['symbols']);$report['peak_memory_bytes']=memory_get_peak_usage(true);
echo PaperRrAudit::encode($report)."\n";
