<?php
declare(strict_types=1);
/**
 * Research only: fixed-universe historical account replay with the paper-kr-recovery-v1 account rules.
 * It writes only <state>/history-account/<research account id>/ . It never writes an operational account, a dataset or a cache.
 *
 *   plan    show window, sessions, symbols, account ID, paths and hashes; writes nothing
 *   run     advance the research account one session at a time up to --through (resumable; the same call twice adds nothing)
 *   status  summary of a research account
 *   verify  journal integrity, books rebuilt from events, independent re-simulation of every order
 *   log     day-by-day rows (recommendations and their outcome, orders, fills, exits, cash and holdings)
 *   quality-scan  how often the existing quality rule blocks a symbol on a session (no orders, no writes except --out)
 *   crosscheck    compare the account's pullback signals with the PR #65 population
 *   export  summary.json, daily-log.jsonl, trades.json, reconcile.json, provenance.json into --out
 *
 * Full runs (no --smoke-label) are refused until --confirm-full-run=after-code-review is given and a sector map is chosen.
 */
require __DIR__.'/bootstrap.php';require __DIR__.'/paper/AccountReplay.php';
use ChartEntryLab\PaperJournal;

$cmd=$argv[1]??'';$o=[];
foreach(array_slice($argv,2) as $a){
    if(!preg_match('/^--([a-z-]+)=(.*)$/s',$a,$m))throw new InvalidArgumentException('Unknown argument '.$a);
    $o[$m[1]]=$m[2];
}
if(!in_array($cmd,['plan','run','status','verify','log','export','crosscheck','quality-scan','compare'],true))throw new InvalidArgumentException('Commands: plan run status verify log export crosscheck quality-scan compare');
if(isset($o['state-dir']))putenv('PAPER_STATE_DIR='.$o['state-dir']);
$root=dirname(__DIR__);
$cfgs=PaperAccountReplay::configs($root);$research=$cfgs['research'];
$period=$o['period']??'';if(!isset($research['periods'][$period]))throw new InvalidArgumentException('--period=prior|recent required');
$smoke=$o['smoke-label']??null;
$start=$o['start']??null;$through=$o['through']??null;
foreach(['start','through'] as $k)if(isset($o[$k])&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$o[$k]))throw new InvalidArgumentException('--'.$k.' must be YYYY-MM-DD');
if($start!==null&&$smoke===null)throw new InvalidArgumentException('--start is only for smoke accounts (--smoke-label)');
$id=PaperAccountReplay::accountId($research,$period,$smoke);
$config=PaperAccountReplay::accountConfig($cfgs['base'],$id,$research);
$stateRoot=PaperAccountReplay::stateRoot();
$dir=PaperAccountReplay::accountDir($stateRoot,$research,$id);$journalPath=PaperAccountReplay::journalPath($dir);
$enc=static fn(array $v):string=>PaperHistoryResearch::encode($v,true)."\n";
$out=static function(array $v)use($enc):void{echo $enc($v);};
$needData=static function()use($o,$research,$period):array{
    if(empty($o['dataset-dir'])||!is_dir($o['dataset-dir']))throw new InvalidArgumentException('--dataset-dir required (verified history dataset)');
    $data=PaperAccountReplay::loadDataset($o['dataset-dir']);
    if($data['dataset']!==$research['periods'][$period]['dataset'])throw new RuntimeException('Dataset '.$data['dataset'].' is not the one configured for period '.$period);
    return $data;
};
$sectorMap=null;
if(isset($o['sector-map'])){
    $sectorMap=json_decode((string)file_get_contents($o['sector-map']),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($sectorMap['sectors']??null))throw new InvalidArgumentException('Sector map needs {"sectors":{symbol:bucket}}');
}
$readJournal=static function()use($journalPath):array{
    $j=(new PaperJournal($journalPath))->read();
    if($j===null)throw new RuntimeException('Research account has no journal yet: '.$journalPath);
    return $j;
};
$provenance=static function(?array $data,array $j=null)use($root,$config,$id,$period,$smoke,$journalPath):array{
    $s=$j['state']??null;
    return ['schema'=>1,'kind'=>PaperAccountReplay::VERSION,'label'=>PaperAccountReplay::LABEL,'account'=>$id,'period'=>$period,'smoke'=>$smoke!==null,
        'strategy_fingerprint'=>PaperStrategyVersion::current(),'code_sha256_lf'=>PaperAccountReplay::codeHashes($root),
        'config_hash'=>hash('sha256',PaperJournal::encode(ChartEntryLab\PaperScanUniverse::pinned($config))),
        'dataset'=>$data?['name'=>$data['dataset'],'files'=>$data['files'],'start_day'=>$data['start_day'],'as_of_day'=>$data['as_of_day'],
            'symbols_used'=>count($data['symbols']),'symbols_excluded'=>count($data['excluded']),
            'bars_set_sha256'=>hash('sha256',PaperJournal::encode(array_map(fn($d)=>$d['bars_sha256'],$data['symbols'])))]:null,
        'journal_sha256'=>is_file($journalPath)?hash_file('sha256',$journalPath):null,
        'journal_normalized_sha256'=>$j?PaperAccountReplay::normalizedHash($j):null,
        'state_hash'=>$j['state_hash']??null,'research'=>$s['research']??null];
};

if($cmd==='plan'){
    $data=$needData();$sessions=PaperAccountReplay::sessions($data,$start,$through);
    $sectors=PaperAccountReplay::sectorMap($data,$sectorMap);
    $buckets=array_count_values($sectors);ksort($buckets);
    $out(['account'=>$id,'label'=>PaperAccountReplay::LABEL,'writes'=>false,'journal'=>$journalPath,'dataset'=>$data['dataset'],
        'evaluation_start'=>$start??$data['start_day'],'last_completed_session'=>$data['as_of_day'],
        'through'=>$through,'sessions'=>count($sessions),'first_session'=>$sessions?PaperHistoryResearch::day($sessions[0]):null,
        'last_session'=>$sessions?PaperHistoryResearch::day(end($sessions)):null,
        'symbols_used'=>count($data['symbols']),'symbols_excluded'=>$data['excluded'],'sector_buckets'=>$buckets,
        'sector_source'=>$sectorMap===null?'none_all_unclassified':'explicit_map',
        'initial_cash'=>$config['initial_cash'],'strategy_fingerprint'=>PaperStrategyVersion::current()]);
    exit(0);
}
if($cmd==='compare'){
    // Same account ID in two state folders (for example one run in one go, one run in pieces).
    if(empty($o['other-state-dir']))throw new InvalidArgumentException('--other-state-dir required');
    $a=$readJournal();
    $otherPath=PaperAccountReplay::journalPath(PaperAccountReplay::accountDir($o['other-state-dir'],$research,$id));
    $b=(new PaperJournal($otherPath))->read()??throw new RuntimeException('No journal in the other state folder');
    $r=['account'=>$id,'events'=>[count($a['events']),count($b['events'])],'normalized_sha256'=>[PaperAccountReplay::normalizedHash($a),PaperAccountReplay::normalizedHash($b)],
        'state_hash'=>[$a['state_hash'],$b['state_hash']]];
    $r['identical']=$r['normalized_sha256'][0]===$r['normalized_sha256'][1]&&$r['state_hash'][0]===$r['state_hash'][1];
    $r['note']='Events are compared without their wall-clock write time and hash chain; the state hash covers cash, orders, positions, marks and snapshots.';
    if(isset($o['out']))PaperHistoryResearch::writeFile($o['out'],$enc($r));
    $out($r);exit($r['identical']?0:1);
}
if($cmd==='quality-scan'){
    $data=$needData();$r=PaperAccountReplay::qualityScan($data,$start,$through);
    if(isset($o['out']))PaperHistoryResearch::writeFile($o['out'],$enc($r));
    $out($r);exit(0);
}
if($cmd==='run'){
    if($smoke===null){
        if(($o['confirm-full-run']??'')!=='after-code-review')throw new RuntimeException('Full-period runs are held until the code review: pass --confirm-full-run=after-code-review');
        if($sectorMap===null&&($o['sectors']??'')!=='all-unclassified')throw new RuntimeException('Choose a sector map (--sector-map=) or accept --sectors=all-unclassified explicitly');
    }else{
        if($start===null||$through===null)throw new InvalidArgumentException('Smoke accounts need --start and --through');
    }
    $data=$needData();
    $guard=static fn():array=>['dataset_tree'=>PaperAccountReplay::treeHash($data['dir']),'operational_top_level'=>PaperAccountReplay::topLevelHash($stateRoot)];
    $before=$guard();
    $r=PaperAccountReplay::run($data,$config,$journalPath,['through'=>$through,'start'=>$start,'chunk'=>(int)($o['chunk']??20),
        'sector_map'=>$sectorMap,'smoke'=>$smoke!==null]);
    $after=$guard();
    $r['untouched']=['dataset_files'=>$before['dataset_tree']===$after['dataset_tree'],'operational_state_files'=>$before['operational_top_level']===$after['operational_top_level']];
    $out($r);
    exit($r['halted']?2:($r['untouched']['dataset_files']&&$r['untouched']['operational_state_files']?0:3));
}
$j=$readJournal();
if($cmd==='status'){$out(PaperAccountReplay::summary($j));exit(0);}
if($cmd==='log'){
    $rows=PaperAccountReplay::dailyLog($j,$o['from']??null,$o['to']??null);
    foreach($rows as $r)echo PaperHistoryResearch::encode($r)."\n";
    exit(0);
}
if($cmd==='crosscheck'){
    $pop=json_decode((string)file_get_contents($o['population']??$root.'/docs/pullback-recent-redecline-stop/population.json'),true,512,JSON_THROW_ON_ERROR);
    $r=PaperAccountReplay::crosscheck($j,$pop,$period);$out($r);
    exit($r['levels_or_pattern_differ']===[]&&$r['missing_from_account_signals']===[]?0:1);
}
$data=$needData();
$rec=PaperAccountReplay::reconcile($j,$data);
if($cmd==='verify'){$out($rec);exit($rec['pass']?0:1);}
if($cmd==='export'){
    $to=rtrim($o['out']??'','/\\');if($to==='')throw new InvalidArgumentException('--out required');
    if(str_contains(str_replace('\\','/',$to),'/history-research/'))throw new RuntimeException('Refusing to write inside a dataset folder');
    PaperHistoryResearch::writeFile($to.'/summary.json',$enc($rec['summary']));
    PaperHistoryResearch::writeFile($to.'/reconcile.json',$enc(['pass'=>$rec['pass'],'checks'=>$rec['checks']]));
    PaperHistoryResearch::writeFile($to.'/trades.json',$enc(['trades'=>PaperAccountReplay::trades($j)]));
    $lines='';foreach(PaperAccountReplay::dailyLog($j) as $r)$lines.=PaperHistoryResearch::encode($r)."\n";
    PaperHistoryResearch::writeFile($to.'/daily-log.jsonl',$lines);
    PaperHistoryResearch::writeFile($to.'/provenance.json',$enc($provenance($data,$j)));
    $popPath=$root.'/docs/pullback-recent-redecline-stop/population.json';
    if(is_file($popPath))PaperHistoryResearch::writeFile($to.'/crosscheck-pr65.json',$enc(PaperAccountReplay::crosscheck($j,json_decode((string)file_get_contents($popPath),true,512,JSON_THROW_ON_ERROR),$period)));
    $out(['written'=>$to,'reconcile_pass'=>$rec['pass'],'days'=>substr_count($lines,"\n")]);
    exit($rec['pass']?0:1);
}
