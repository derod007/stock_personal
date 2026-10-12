<?php
declare(strict_types=1);
/**
 * Research only: dated KRX amount TOP100 account replay for 2025-10.
 * Writes <state>/history-research/kr-dated-amount-top100-202510 and <state>/history-account/hist-kr-dated-top100-v1-* .
 * Never writes operational accounts, caches or strategy files.
 */
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/DatedTop100Replay.php';
use ChartEntryLab\PaperJournal;

$cmd=$argv[1]??'';$o=[];
foreach(array_slice($argv,2) as $a){
    if(!preg_match('/^--([a-z-]+)=(.*)$/s',$a,$m))throw new InvalidArgumentException('Unknown argument '.$a);
    $o[$m[1]]=$m[2];
}
if(!in_array($cmd,['plan','universe','collect','run','status','verify','verify-inputs','validate','log','export','compare'],true))
    throw new InvalidArgumentException('Commands: universe collect plan run status verify verify-inputs validate log export compare');
if(isset($o['state-dir']))putenv('PAPER_STATE_DIR='.$o['state-dir']);
$root=dirname(__DIR__);
$profile=PaperDatedTop100Replay::profile($o['profile']??PaperDatedTop100Replay::PROFILE_OCT);
$cfgs=PaperDatedTop100Replay::configs($root,$profile['research_config']);$research=$cfgs['research'];
$enc=static fn(array $v):string=>PaperHistoryResearch::encode($v,true)."\n";
$out=static function(array $v)use($enc):void{echo $enc($v);};
$candPath=$o['candidates']??$root.'/'.$profile['candidates'];
$uniPath=$o['universe']??$root.'/'.$profile['universe'];
$stateRoot=PaperAccountReplay::stateRoot();
$period=$profile['period'];
$smoke=$o['smoke-label']??null;
$id=PaperAccountReplay::accountId($research,$period,$smoke);
$config=PaperAccountReplay::accountConfig($cfgs['base'],$id,$research);
$dir=PaperAccountReplay::accountDir($stateRoot,$research,$id);
$journalPath=PaperAccountReplay::journalPath($dir);

if($cmd==='universe'){
    if($profile['name']!==PaperDatedTop100Replay::PROFILE_OCT)throw new RuntimeException('universe is only for the October 2025 dataset');
    $ds=PaperDatedTop100Replay::initDataset($stateRoot,$uniPath);
    $out(['dataset'=>$ds['dataset'],'dir'=>$ds['dir'],'universe_sha256'=>$ds['universe_sha256'],'as_of'=>$ds['as_of']['day'],'evaluation_start'=>$ds['evaluation_start_day']]);
    exit(0);
}
if($cmd==='collect'){
    if($profile['name']!==PaperDatedTop100Replay::PROFILE_OCT)throw new RuntimeException('collect is only for the October 2025 dataset; the year v2 bars are already prepared');
    $dsDir=PaperHistoryResearch::datasetDir($stateRoot,PaperDatedTop100Replay::DATASET);
    $ds=PaperHistoryResearch::readJson($dsDir.'/dataset.json')??throw new RuntimeException('Run universe first');
    $uni=PaperHistoryResearch::readJson($dsDir.'/universe.json')??throw new RuntimeException('universe.json missing');
    $cand=PaperDatedTop100Replay::loadCandidates($candPath);
    $needed=[];
    foreach($cand['by_session'] as $rec){
        foreach($rec['rows'] as $r)$needed[$r['symbol']][]=$rec['day'];
    }
    $as=$ds['as_of']+['evaluation_start_day'=>$ds['evaluation_start_day'],'primary_range'=>$ds['primary_range']??'5y'];
    $reuse=[];
    $reuseNames=isset($o['reuse-from'])?array_values(array_filter(array_map('trim',explode(',',$o['reuse-from'])))):['kr-saved-scan-20261009','kr-saved-scan-20251008'];
    foreach($reuseNames as $name){
        $p=PaperHistoryResearch::datasetDir($stateRoot,$name);
        if(is_dir($p))$reuse[]=$p;
    }
    $http=static fn(string $url):array=>PaperHistoryResearch::httpGet($url);
    $deps=PaperHistoryResearch::makeClient($dsDir,$http);
    $delay=max(0,(int)($o['delay-ms']??300))*1000;
    $maxAttempts=max(1,min(5,(int)($o['max-attempts']??3)));
    $count=['reused'=>0,'imported'=>0,'collected'=>0,'failed'=>0,'skipped_attempt_limit'=>0,'incomplete_import'=>0];
    $started=microtime(true);$bytes=0;$requests=0;$consecutive=0;
    $say=static fn(string $s)=>fwrite(STDERR,$s."\n");
    $included=array_values(array_filter($uni['rows'],fn($u)=>($u['status']??'')==='included'));
    foreach($included as $i=>$u){
        $action='collect';
        foreach($reuse as $src){
            $imp=PaperDatedTop100Replay::importSymbol($dsDir,$src,$u,$as,$needed[$u['symbol']]??$cand['days']);
            if($imp==='reused'){$action='reused';break;}
            if($imp==='imported'){$action='imported';break;}
            if($imp==='incomplete'){$count['incomplete_import']++;}
        }
        if($action==='collect'){
            $r=PaperHistoryResearch::collectSymbol($dsDir,$u,$as,$deps,$maxAttempts,isset($o['retry-failed']));
            $action=$r['action'];
            if($action==='failed'){$consecutive++;$say(sprintf('[%d/%d] FAIL %s %s',$i+1,count($included),$u['symbol'],$r['status']['error']??''));}
            else $consecutive=0;
            if($action==='collected'){
                $requests+=count($r['status']['requests']??[]);
                $bytes+=(int)($r['status']['bars_bytes']??0);
                if($delay>0)usleep($delay);
            }
        }else $consecutive=0;
        $count[$action]=($count[$action]??0)+1;
        if($consecutive>=8)break;
        if($i%20===19)$say(sprintf('[%d/%d] %s',$i+1,count($included),json_encode($count)));
    }
    $manifest=PaperHistoryResearch::manifest($dsDir);
    PaperHistoryResearch::writeFile($dsDir.'/manifest.json',PaperHistoryResearch::encode($manifest,true));
    $out(['dataset'=>PaperDatedTop100Replay::DATASET,'actions'=>$count,'seconds'=>round(microtime(true)-$started,1),
        'requests'=>$requests,'bars_bytes'=>$bytes,'aborted_after_repeated_failures'=>$consecutive>=8,'summary'=>$manifest['summary']]);
    exit(($manifest['summary']['failed']??0)>0||($manifest['summary']['not_attempted']??0)>0?2:0);
}

$candidates=PaperDatedTop100Replay::loadCandidates($candPath,(int)$profile['expected_sessions'],(int)$profile['expected_union']);
$datasetDir=$o['dataset-dir']??PaperHistoryResearch::datasetDir($stateRoot,$profile['dataset']);
$sectorMap=null;$sectorMapFileSha=null;
$sectorPath=$o['sector-map']??($profile['sector_map']!==null?$root.'/'.$profile['sector_map']:null);
if($sectorPath!==null){
    if($profile['sector_map_sha256']!==null){
        $sectorMap=PaperDatedTop100Replay::pinSectorMap($sectorPath,(string)$profile['sector_map_sha256']);
        $sectorMapFileSha=$sectorMap['_file_sha256_lf'];
        unset($sectorMap['_file_sha256_lf']);
    }else{
        $sectorMap=json_decode((string)file_get_contents($sectorPath),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($sectorMap['sectors']??null))throw new InvalidArgumentException('Sector map needs {"sectors":{symbol:bucket}}');
        $sectorMapFileSha=PaperStrategyVersion::fileHash($sectorPath);
    }
    if(in_array($sectorMap['kind']??'',[PaperSectorFreeze::KIND,PaperSectorFreeze::KIND_V2],true))PaperSectorFreeze::assertReplayMap($sectorMap);
}
$start=$o['start']??$profile['run_start'];
$through=$o['through']??$profile['run_through'];
$runtimeExtra=$profile['name']===PaperDatedTop100Replay::PROFILE_YEAR?[PaperDatedTop100Replay::RESEARCH_CONFIG_YEAR]:[];
$readJournal=static function()use($journalPath):array{
    $j=(new PaperJournal($journalPath))->read();
    if($j===null)throw new RuntimeException('Research account has no journal yet: '.$journalPath);
    return $j;
};
if($cmd==='plan'){
    $data=PaperDatedTop100Replay::loadDataset($datasetDir,$candidates,$profile['dataset'],$profile['eval_start'],$profile['as_of']);
    $sessions=array_values(array_filter(PaperDatedTop100Replay::sessions($candidates),function($ts)use($candidates,$start,$through){
        $day=$candidates['by_session'][$ts]['day'];return $day>=$start&&$day<=$through;
    }));
    $out(['account'=>$id,'label'=>PaperDatedTop100Replay::LABEL,'writes'=>false,'journal'=>$journalPath,'dataset'=>$data['dataset'],
        'evaluation_start'=>$start,'through'=>$through,'sessions'=>count($sessions),
        'first_session'=>$sessions?$candidates['by_session'][$sessions[0]]['day']:null,
        'last_session'=>$sessions?$candidates['by_session'][end($sessions)]['day']:null,
        'symbols_in_dataset'=>count($data['symbols']),'missing'=>count($data['missing']),'hold'=>count($data['hold']),
        'union'=>count($candidates['union']),'initial_cash'=>$config['initial_cash'],
        'strategy_fingerprint'=>PaperStrategyVersion::current(),
        'note'=>'운영 다음 TOP100과 동일하다고 보지 않는다. 한 달로 수익성을 검증한 것이 아니다.']);
    exit(0);
}
if($cmd==='run'){
    if($smoke===null){
        if(($o['confirm-full-run']??'')!=='after-code-review')throw new RuntimeException('Pass --confirm-full-run=after-code-review for the '.$profile['confirm_sessions'].'-session research account');
        if($sectorMap===null&&($o['sectors']??'')!=='all-unclassified')throw new RuntimeException('Choose --sector-map= or --sectors=all-unclassified');
        if($profile['sector_map_sha256']!==null&&$sectorMap===null)throw new RuntimeException('This profile requires the pinned full sector map');
    }else{
        if(!isset($o['start'])||!isset($o['through']))throw new InvalidArgumentException('Smoke accounts need --start and --through');
    }
    $data=PaperDatedTop100Replay::loadDataset($datasetDir,$candidates,$profile['dataset'],$profile['eval_start'],$profile['as_of']);
    $guard=static fn():array=>['dataset_tree'=>PaperAccountReplay::treeHash($data['dir']),'operational_top_level'=>PaperAccountReplay::topLevelHash($stateRoot)];
    $before=$guard();
    $r=PaperDatedTop100Replay::run($data,$config,$journalPath,$candidates,[
        'through'=>$through,'start'=>$start,'chunk'=>(int)($o['chunk']??8),
        'sector_map'=>$sectorMap,'sector_map_file_sha256'=>$sectorMapFileSha,
        'runtime_code_extra'=>$runtimeExtra,
        'smoke'=>$smoke!==null]);
    $after=$guard();
    $r['untouched']=['dataset_files'=>$before['dataset_tree']===$after['dataset_tree'],'operational_state_files'=>$before['operational_top_level']===$after['operational_top_level']];
    $r['label']=PaperDatedTop100Replay::LABEL;
    $out($r);
    exit($r['halted']?2:($r['untouched']['dataset_files']&&$r['untouched']['operational_state_files']?0:3));
}
if($cmd==='verify-inputs'){
    $recorded=$o['recorded-sha']??null;
    if($recorded===null){
        $provPath=$o['provenance']??$root.'/docs/historical-top100-replay-202510/account/provenance.json';
        $prov=json_decode((string)file_get_contents($provPath),true,512,JSON_THROW_ON_ERROR);
        $recorded=(string)($prov['candidates_sha256']??'');
    }
    $link=PaperDatedTop100Replay::linkRecordedHash($candPath,$recorded);
    $out($link);exit($link['pass']?0:1);
}
if($cmd==='validate'){
    $fp=PaperStrategyVersion::current($root);
    $mapIssues=[];
    if($profile['sector_map_sha256']!==null){
        if($sectorMap===null)$mapIssues[]='sector map missing';
        else{
            try{PaperSectorFreeze::assertCoversUnion($candidates['union'],$sectorMap);}
            catch(Throwable $e){$mapIssues[]=$e->getMessage();}
        }
    }
    $data=PaperDatedTop100Replay::loadDataset($datasetDir,$candidates,$profile['dataset'],$profile['eval_start'],$profile['as_of']);
    $rep=['profile'=>$profile['name'],'label'=>PaperDatedTop100Replay::LABEL,'account_replay'=>false,
        'strategy_fingerprint'=>$fp,'strategy_fingerprint_ok'=>$fp===PaperDatedTop100Replay::STRATEGY_FINGERPRINT,
        'sessions'=>count($candidates['by_session']),'union'=>count($candidates['union']),
        'expected_sessions'=>$profile['expected_sessions'],'expected_union'=>$profile['expected_union'],
        'candidates_sha256'=>$candidates['file_sha256'],'dataset'=>$data['dataset'],'dataset_files'=>$data['files'],
        'missing'=>count($data['missing']),'hold'=>count($data['hold']),
        'sector_map_sha256_lf'=>$sectorMapFileSha,'sector_map_issues'=>$mapIssues,
        'pass'=>$fp===PaperDatedTop100Replay::STRATEGY_FINGERPRINT&&count($candidates['by_session'])===(int)$profile['expected_sessions']
            &&count($candidates['union'])===(int)$profile['expected_union']&&$mapIssues===[]];
    $out($rep);exit($rep['pass']?0:1);
}
if($cmd==='compare'){
    if(empty($o['other-state-dir']))throw new InvalidArgumentException('--other-state-dir required');
    $a=$readJournal();
    $otherPath=PaperAccountReplay::journalPath(PaperAccountReplay::accountDir($o['other-state-dir'],$research,$id));
    $b=(new PaperJournal($otherPath))->read()??throw new RuntimeException('No journal in the other state folder');
    $r=['account'=>$id,'events'=>[count($a['events']),count($b['events'])],
        'normalized_sha256'=>[PaperAccountReplay::normalizedHash($a),PaperAccountReplay::normalizedHash($b)],
        'state_hash'=>[$a['state_hash'],$b['state_hash']]];
    $r['identical']=$r['normalized_sha256'][0]===$r['normalized_sha256'][1]&&$r['state_hash'][0]===$r['state_hash'][1];
    $out($r);exit($r['identical']?0:1);
}
$j=$readJournal();
if($cmd==='status'){$out(PaperAccountReplay::summary($j));exit(0);}
if($cmd==='log'){
    foreach(PaperAccountReplay::dailyLog($j,$o['from']??null,$o['to']??null) as $r)echo PaperHistoryResearch::encode($r)."\n";
    exit(0);
}
$data=PaperDatedTop100Replay::loadDataset($datasetDir,$candidates,$profile['dataset'],$profile['eval_start'],$profile['as_of']);
$rec=PaperAccountReplay::reconcile($j,$data);
if($cmd==='verify'){
    $recorded=(string)($j['state']['research']['candidates_sha256']??'');
    $rec['candidate_git_link']=PaperDatedTop100Replay::linkRecordedHash($candPath,$recorded);
    $rec['new_orders']=PaperDatedTop100Replay::auditNewOrders($j,$candidates);
    $out($rec);exit($rec['pass']&&$rec['candidate_git_link']['pass']&&$rec['new_orders']['pass']?0:1);
}
if($cmd==='export'){
    $to=rtrim($o['out']??'','/\\');if($to==='')throw new InvalidArgumentException('--out required');
    $norm=str_replace('\\','/',$to);
    if(preg_match('#/data/(ohlcv|raw|cache|paper)#',$norm)||str_contains($norm,'/history-research/'))throw new RuntimeException('Refusing to write into operational or dataset files');
    PaperHistoryResearch::writeFile($to.'/summary.json',$enc($rec['summary']));
    PaperHistoryResearch::writeFile($to.'/reconcile.json',$enc(['pass'=>$rec['pass'],'checks'=>$rec['checks']]));
    PaperHistoryResearch::writeFile($to.'/trades.json',$enc(['trades'=>PaperAccountReplay::trades($j)]));
    $lines='';foreach(PaperAccountReplay::dailyLog($j) as $r)$lines.=PaperHistoryResearch::encode($r)."\n";
    PaperHistoryResearch::writeFile($to.'/daily-log.jsonl',$lines);
    $prov=['schema'=>1,'kind'=>PaperDatedTop100Replay::VERSION,'label'=>PaperDatedTop100Replay::LABEL,'account'=>$id,'period'=>$period,
        'depends_on_pr'=>$profile['depends_on_pr'],'strategy_fingerprint'=>PaperStrategyVersion::current(),
        'code_sha256_lf'=>PaperDatedTop100Replay::runtimeCodeHashes($runtimeExtra),
        'config_hash'=>hash('sha256',PaperJournal::encode(ChartEntryLab\PaperScanUniverse::pinned($config))),
        'candidates_sha256'=>$candidates['file_sha256'],
        'dataset'=>['name'=>$data['dataset'],'files'=>$data['files'],'missing'=>count($data['missing']),'hold'=>count($data['hold'])],
        'journal_sha256'=>is_file($journalPath)?hash_file('sha256',$journalPath):null,
        'journal_normalized_sha256'=>PaperAccountReplay::normalizedHash($j),
        'note'=>$profile['name']===PaperDatedTop100Replay::PROFILE_YEAR
            ?'운영 다음 TOP100과 동일하다고 보지 않는다. 1년 실행으로 수익성이 검증됐다고 보지 않는다.'
            :'운영 다음 TOP100과 동일하다고 보지 않는다. 한 달 실행으로 수익성이 검증됐다고 보지 않는다.'];
    PaperHistoryResearch::writeFile($to.'/provenance.json',$enc($prov));
    $out(['written'=>$to,'reconcile_pass'=>$rec['pass'],'days'=>substr_count($lines,"\n")]);
    exit($rec['pass']?0:1);
}
