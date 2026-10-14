<?php
declare(strict_types=1);
/**
 * Research only: dated KRX amount TOP100 bar prep.
 * Default profile is 2025-10-08..2026-10-08. --profile=20241008-20251002 is a separate dataset.
 * Never writes operational accounts, caches, strategy files, or another period's dataset.
 * Does not run an account replay.
 */
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/DatedTop100Prep.php';

$cmd=$argv[1]??'';$o=[];
foreach(array_slice($argv,2) as $a){
    if($a==='--retry-failed'){$o['retry-failed']='1';continue;}
    if(!preg_match('/^--([a-z-]+)=(.*)$/s',$a,$m))throw new InvalidArgumentException('Unknown argument '.$a);
    $o[$m[1]]=$m[2];
}
if(!in_array($cmd,['universe','collect','quality','coverage','yahoo-requests','provider-review','universe-v2','collect-v2','sector-v2','sector-map-full'],true))
    throw new InvalidArgumentException('Commands: universe collect quality coverage yahoo-requests provider-review universe-v2 collect-v2 sector-v2 sector-map-full');
if(isset($o['state-dir']))putenv('PAPER_STATE_DIR='.$o['state-dir']);
$root=dirname(__DIR__);
$spec=PaperDatedTop100Prep::spec($o['profile']??PaperDatedTop100Prep::PROFILE_RECENT);
$enc=static fn(array $v):string=>PaperHistoryResearch::encode($v,true)."\n";
$out=static function(array $v)use($enc):void{echo $enc($v);};
$docsDir=$root.'/'.$spec['docs'];
$candPath=$o['candidates']??$root.'/'.$spec['candidates'];
$uniPath=$o['universe']??$root.'/'.$spec['universe'];
$stateRoot=PaperAccountReplay::stateRoot();
$datasetDir=$o['dataset-dir']??PaperHistoryResearch::datasetDir($stateRoot,$o['dataset']??$spec['dataset']);

if($cmd==='universe'){
    $ds=PaperDatedTop100Prep::initDataset($stateRoot,$uniPath,$spec);
    $out(['dataset'=>$ds['dataset'],'dir'=>$ds['dir'],'universe_sha256'=>$ds['universe_sha256'],
        'as_of'=>$ds['as_of']['day'],'evaluation_start'=>$ds['evaluation_start_day'],
        'prep_bars'=>PaperHistoryResearch::PREP_BARS,'min_bars'=>PaperHistoryResearch::MIN_BARS,
        'note'=>'No account replay. Union is a collection list only.']);
    exit(0);
}
if($cmd==='quality'){
    $manifest=PaperHistoryResearch::manifest($datasetDir);
    $out(['dataset'=>$spec['dataset'],'summary'=>$manifest['summary'],
        'window'=>$manifest['window']??null,'quality_at_as_of_distinct_from_dataset_warnings'=>true]);
    exit(0);
}
if($cmd==='yahoo-requests'){
    $ev=PaperDatedTop100Prep::yahooRequestEvidence($datasetDir,(string)$spec['dataset']);
    $dest=$o['out']??$docsDir.'/yahoo-request-evidence.json';
    PaperHistoryResearch::writeFile($dest,PaperHistoryResearch::encode($ev,true));
    $out($ev+['wrote'=>$dest]);
    exit(0);
}
if($cmd==='provider-review'){
    $uni=PaperHistoryResearch::readJson($uniPath)??throw new RuntimeException('universe.json missing');
    $cand=PaperDatedTop100Prep::loadCandidates($candPath);
    $barsSummary=json_decode((string)file_get_contents($docsDir.'/bars-summary.json'),true,512,JSON_THROW_ON_ERROR);
    $unmapped=array_values(array_filter($uni['rows'],fn($u)=>($u['status']??'')==='provider_symbol_unmapped'));
    $failed=array_map(fn($f)=>['kind'=>'yahoo_404','code'=>explode('.',$f['symbol'])[0],'symbol'=>$f['symbol'],'name'=>$f['name']??null,'market'=>$f['market']??null],$barsSummary['failed']??[]);
    foreach($unmapped as &$u)$u['kind']='unmapped';
    unset($u);
    $probe=$o['probe-root']??$root.'/data/raw/research/historical-amount-top100-'.$spec['id'].'-provider-probe-v1';
    $review=PaperDatedTop100Prep::probeProviders(array_merge($unmapped,$failed),$probe,(int)($o['delay-ms']??800));
    $review['unmapped_appearances']=PaperDatedTop100Prep::appearanceCounts($cand,array_map(fn($u)=>$u['symbol'],$unmapped));
    $review['yahoo_404_appearances']=PaperDatedTop100Prep::appearanceCounts($cand,array_map(fn($f)=>$f['symbol'],$failed));
    $dest=$o['out']??$docsDir.'/provider-review.json';
    PaperHistoryResearch::writeFile($dest,PaperHistoryResearch::encode($review,true));
    $out(['wrote'=>$dest,'request_count'=>$review['request_count'],'outcomes'=>$review['outcomes'],
        'unmapped_appearances'=>$review['unmapped_appearances']['appearances']??null,
        'unmapped_days'=>$review['unmapped_appearances']['distinct_days']??null]);
    exit(0);
}
if($cmd==='coverage'){
    $uni=PaperHistoryResearch::readJson($datasetDir.'/universe.json')??throw new RuntimeException('universe.json missing');
    $cand=PaperDatedTop100Prep::loadCandidates($candPath);
    $state=[];
    foreach($uni['rows'] as $u){
        $symbol=(string)$u['symbol'];
        $row=['status'=>$u['status']??'','name'=>$u['name']??$symbol,'state'=>'missing','rows'=>[],'bars_sha256'=>null];
        if(($u['status']??'')==='included'){
            $st=PaperHistoryResearch::readStatus($datasetDir,$symbol);
            $row['state']=$st['state']??'missing';
            $row['bars_path']=$st['bars_path']??null;
            $row['bars_sha256']=$st['bars_sha256']??null;
        }
        $state[$symbol]=$row;
    }
    $nHydrate=0;
    $hydrate=static function(string $symbol,array $st)use($datasetDir,&$nHydrate):array{
        $nHydrate++;
        if($nHydrate%50===0)fwrite(STDERR,"coverage $nHydrate\n");
        if(($st['status']??'')!=='included'||($st['state']??'')!=='done'||empty($st['bars_path']))return $st;
        $bars=PaperHistoryResearch::readJson($datasetDir.'/'.$st['bars_path']);
        $st['rows']=$bars['rows']??[];
        return $st;
    };
    $cov=PaperDatedTop100Prep::sessionCoverage($cand,$state,(string)$spec['eval_start'],$hydrate);
    $dsName=$o['dataset']??$spec['dataset'];
    $dest=$o['out']??$docsDir.'/'.($dsName===$spec['dataset_v2']?'asof-coverage-v2.json':'asof-coverage.json');
    PaperHistoryResearch::writeFile($dest,PaperHistoryResearch::encode($cov,true));
    $out(['wrote'=>$dest,'day_count'=>$cov['day_count'],'partition'=>$cov['partition'],
        'blocked_tags'=>$cov['blocked_tags'],
        'potential_holding_or_pending_bar_gaps'=>$cov['potential_holding_or_pending_bar_gaps'],
        'later_jump_does_not_wipe_earlier'=>$cov['later_jump_does_not_wipe_earlier']]);
    exit(0);
}
if($cmd==='universe-v2'){
    $uni=json_decode((string)file_get_contents($uniPath),true,512,JSON_THROW_ON_ERROR);
    $review=json_decode((string)file_get_contents($docsDir.'/provider-review.json'),true,512,JSON_THROW_ON_ERROR);
    $v2=PaperDatedTop100Prep::universeV2FromReview($uni,$review,$spec);
    $dest=$o['out']??$root.'/'.$spec['universe_v2'];
    PaperHistoryResearch::writeFile($dest,PaperHistoryResearch::encode($v2,true));
    $ds=PaperDatedTop100Prep::initDatasetV2($stateRoot,$dest,$spec);
    $out(['wrote'=>$dest,'dataset'=>$ds['dataset'],'dir'=>$ds['dir'],'universe_sha256'=>$ds['universe_sha256'],
        'admitted'=>count($v2['counts']['v2_admitted']??[]),'still_unmapped'=>$v2['counts']['v2_still_unmapped']??[],
        'included'=>$v2['counts']['included'],'note'=>'v1 universe and dataset were not overwritten.']);
    exit(0);
}
if($cmd==='sector-v2'){
    $v2path=$o['universe']??$root.'/'.$spec['universe_v2'];
    $v2=json_decode((string)file_get_contents($v2path),true,512,JSON_THROW_ON_ERROR);
    $admitted=$v2['counts']['v2_admitted']??[];
    if($admitted===[])throw new RuntimeException('universe-v2 has no admitted symbols');
    $outDir=$o['out-dir']??PaperAccountReplay::stateRoot().'/history-sector/dated-top100-'.$spec['id'].'-v2';
    $report=$o['report']??$root.'/'.$spec['sector_collection_v2'];
    $cmdLine=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/paper_sector_collect.php')
        .' --out-dir='.escapeshellarg($outDir).' --report='.escapeshellarg($report)
        .' --symbols='.escapeshellarg(implode(',',$admitted));
    passthru($cmdLine,$code);
    if($code!==0)exit($code);
    $col=json_decode((string)file_get_contents($report),true,512,JSON_THROW_ON_ERROR);
    $map=['schema'=>1,'kind'=>'research_sector_map_v2','assumption'=>'현재 네이버 모바일 업종 JSON을 과거에 같이 적용한다. 당시 업종을 복원한 것이 아니다.',
        'dataset'=>$spec['dataset_v2'],'symbols'=>$admitted,'sectors'=>[],'unconfirmed'=>[]];
    foreach($col['symbols']??[] as $sym=>$row){
        if(($row['status']??'')==='secured')$map['sectors'][$sym]=$row['bucket'];
        else $map['unconfirmed'][]=['symbol'=>$sym,'reason'=>$row['reason']??null];
    }
    $mapPath=$root.'/'.$spec['sector_map_v2'];
    PaperHistoryResearch::writeFile($mapPath,PaperHistoryResearch::encode($map,true));
    $out(['wrote'=>$mapPath,'secured'=>count($map['sectors']),'unconfirmed'=>count($map['unconfirmed']),'operational_caches_unchanged'=>true]);
    exit(0);
}
if($cmd==='sector-map-full'){
    $cand=PaperDatedTop100Prep::loadCandidates($candPath);
    $basePath=$o['base']??$root.'/'.$spec['sector_collection'];
    $admitPath=$o['admitted']??$root.'/'.$spec['sector_map_v2'];
    $colV2Path=$root.'/'.$spec['sector_collection_v2'];
    $dest=$o['out']??$root.'/'.$spec['sector_map_full'];
    if(is_file($dest))throw new RuntimeException('Refusing to overwrite existing full sector map '.$dest);
    foreach([$basePath,$admitPath] as $p){
        if(!is_file($p))throw new RuntimeException('Missing sector source '.$p);
    }
    $baseBefore=hash_file('sha256',$basePath);
    $admitBefore=hash_file('sha256',$admitPath);
    $colV2Before=is_file($colV2Path)?hash_file('sha256',$colV2Path):null;
    $base=json_decode((string)file_get_contents($basePath),true,512,JSON_THROW_ON_ERROR);
    $admit=json_decode((string)file_get_contents($admitPath),true,512,JSON_THROW_ON_ERROR);
    $colV2=is_file($colV2Path)?json_decode((string)file_get_contents($colV2Path),true,512,JSON_THROW_ON_ERROR):['symbols'=>[]];
    $offline=PaperDatedTop100Prep::verifyAdmittedOffline($admit,$colV2);
    if(!$offline['pass'])throw new RuntimeException('Admitted code/sector offline check failed: '.json_encode($offline['issues'],JSON_UNESCAPED_UNICODE));
    $map=PaperDatedTop100Prep::mergeUnionSectorMap($cand['union'],$base,$admit,[
        ['path'=>$spec['sector_collection'],'sha256'=>$baseBefore,'role'=>'secured_base'],
        ['path'=>$spec['sector_map_v2'],'sha256'=>$admitBefore,'role'=>'admitted_v2'],
        ['path'=>$spec['candidates'],'sha256'=>$cand['file_sha256'],'role'=>'candidate_union'],
    ],$spec);
    $bytes=PaperHistoryResearch::encode($map,true);
    PaperHistoryResearch::writeFile($dest,$bytes);
    if(hash_file('sha256',$basePath)!==$baseBefore||hash_file('sha256',$admitPath)!==$admitBefore)
        throw new RuntimeException('A source sector file changed while the full map was written');
    if($colV2Before!==null&&hash_file('sha256',$colV2Path)!==$colV2Before)
        throw new RuntimeException('Admitted collection changed while the full map was written');
    $out(['wrote'=>$dest,'sha256'=>hash('sha256',$bytes),'counts'=>$map['counts'],
        'execution_path'=>$spec['sector_map_full'],
        'offline_admitted'=>$offline,'sources_unchanged'=>true,'account_replay'=>false]);
    exit(0);
}
if($cmd==='collect-v2'){
    $v1Dir=PaperHistoryResearch::datasetDir($stateRoot,$spec['dataset']);
    $v2Dir=PaperHistoryResearch::datasetDir($stateRoot,$spec['dataset_v2']);
    if($v1Dir===$v2Dir)throw new RuntimeException('v2 dataset must not be v1');
    $before=PaperDatedTop100Prep::datasetFingerprint($v1Dir);
    $ds=PaperHistoryResearch::readJson($v2Dir.'/dataset.json')??throw new RuntimeException('Run universe-v2 first');
    $uni=PaperHistoryResearch::readJson($v2Dir.'/universe.json')??throw new RuntimeException('universe.json missing');
    if(($ds['dataset']??'')!==$spec['dataset_v2'])throw new RuntimeException('Refusing to write a different dataset');
    $as=$ds['as_of']+['evaluation_start_day'=>$ds['evaluation_start_day'],'primary_range'=>$ds['primary_range']??'5y'];
    PaperHistoryResearch::resetHttpAttempts();
    $http=static fn(string $url):array=>PaperHistoryResearch::httpGet($url);
    $deps=PaperHistoryResearch::makeClient($v2Dir,$http);
    $delay=max(0,(int)($o['delay-ms']??300))*1000;
    $maxAttempts=max(1,min(5,(int)($o['max-attempts']??3)));
    $count=['copied'=>0,'reused'=>0,'source_failed'=>0,'collected'=>0,'failed'=>0,'absent'=>0,'source_hash_mismatch'=>0,'unmapped'=>0];
    $started=microtime(true);$bytes=0;$consecutive=0;
    $say=static fn(string $s)=>fwrite(STDERR,$s."\n");
    $admitted=array_fill_keys($uni['counts']['v2_admitted']??[],true);
    $included=array_values(array_filter($uni['rows'],fn($u)=>($u['status']??'')==='included'));
    $count['unmapped']=count(array_filter($uni['rows'],fn($u)=>($u['status']??'')==='provider_symbol_unmapped'));
    foreach($included as $i=>$u){
        $sym=(string)$u['symbol'];
        if(isset($admitted[$sym])){
            $r=PaperHistoryResearch::collectSymbol($v2Dir,$u,$as,$deps,$maxAttempts,isset($o['retry-failed']));
            $action=$r['action'];
            if($action==='failed'){$consecutive++;$say('FAIL '.$sym.' '.($r['status']['error']??''));}
            else $consecutive=0;
            if($action==='collected')$bytes+=(int)($r['status']['bars_bytes']??0);
            if($delay>0&&$action==='collected')usleep($delay);
            $count[$action]=($count[$action]??0)+1;
        }else{
            $action=PaperDatedTop100Prep::copyVerifiedBars($v2Dir,$v1Dir,$u);
            if($action==='source_failed'){
                $src=PaperHistoryResearch::readStatus($v1Dir,$sym);
                if(is_array($src)){
                    $src['imported_from']=$spec['dataset'];
                    PaperHistoryResearch::writeFile($v2Dir.'/status/'.$sym.'.json',PaperHistoryResearch::encode($src,true));
                }
            }
            $count[$action]=($count[$action]??0)+1;
            $consecutive=0;
        }
        if($consecutive>=8)break;
        if($i%50===49)$say(sprintf('[%d/%d] %s',$i+1,count($included),json_encode($count)));
    }
    $after=PaperDatedTop100Prep::datasetFingerprint($v1Dir);
    if($after!==$before)throw new RuntimeException('v1 dataset changed during v2 collect');
    $manifest=PaperHistoryResearch::manifest($v2Dir);
    PaperHistoryResearch::writeFile($v2Dir.'/manifest.json',PaperHistoryResearch::encode($manifest,true));
    $out(['dataset'=>$spec['dataset_v2'],'actions'=>$count,'seconds'=>round(microtime(true)-$started,1),
        'http_attempts'=>count(PaperHistoryResearch::$httpAttempts),'bars_bytes'=>$bytes,
        'v1_fingerprint_unchanged'=>true,'v1'=>$before,'summary'=>$manifest['summary'],
        'note'=>'No account replay. v1 bars were hash-copied. Only identity-ok alphanumeric names were fetched.']);
    exit(($manifest['summary']['failed']??0)>0||($manifest['summary']['not_attempted']??0)>0?2:0);
}

$ds=PaperHistoryResearch::readJson($datasetDir.'/dataset.json')??throw new RuntimeException('Run universe first');
$uni=PaperHistoryResearch::readJson($datasetDir.'/universe.json')??throw new RuntimeException('universe.json missing');
if(($ds['dataset']??'')!==$spec['dataset'])throw new RuntimeException('Refusing to write a different dataset');
$cand=PaperDatedTop100Prep::loadCandidates($candPath);
$neededDays=$cand['days'];
$as=$ds['as_of']+['evaluation_start_day'=>$ds['evaluation_start_day'],'primary_range'=>$ds['primary_range']??'5y'];
$reuse=[];
$reuseNames=isset($o['reuse-from'])?array_values(array_filter(array_map('trim',explode(',',$o['reuse-from']))))
    :$spec['reuse_from'];
foreach($reuseNames as $name){
    if($name===$spec['dataset']||$name===$spec['dataset_v2'])throw new RuntimeException('Refusing to reuse the destination dataset as a source');
    $p=PaperHistoryResearch::datasetDir($stateRoot,$name);
    if(is_dir($p))$reuse[]=$p;
}
PaperHistoryResearch::resetHttpAttempts();
$http=static fn(string $url):array=>PaperHistoryResearch::httpGet($url);
$deps=PaperHistoryResearch::makeClient($datasetDir,$http);
$delay=max(0,(int)($o['delay-ms']??300))*1000;
$maxAttempts=max(1,min(5,(int)($o['max-attempts']??3)));
$count=['reused'=>0,'imported'=>0,'collected'=>0,'failed'=>0,'skipped_attempt_limit'=>0,'incomplete_import'=>0,'unmapped'=>0];
$started=microtime(true);$bytes=0;$requests=0;$consecutive=0;
$say=static fn(string $s)=>fwrite(STDERR,$s."\n");
$unmapped=array_values(array_filter($uni['rows'],fn($u)=>($u['status']??'')==='provider_symbol_unmapped'));
$count['unmapped']=count($unmapped);
$included=array_values(array_filter($uni['rows'],fn($u)=>($u['status']??'')==='included'));
foreach($included as $i=>$u){
    $action='collect';
    foreach($reuse as $src){
        $imp=PaperDatedTop100Replay::importSymbol($datasetDir,$src,$u,$as,$neededDays);
        if($imp==='reused'){$action='reused';break;}
        if($imp==='imported'){$action='imported';break;}
        if($imp==='incomplete'){$count['incomplete_import']++;}
    }
    if($action==='collect'){
        $r=PaperHistoryResearch::collectSymbol($datasetDir,$u,$as,$deps,$maxAttempts,isset($o['retry-failed']));
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
$manifest=PaperHistoryResearch::manifest($datasetDir);
PaperHistoryResearch::writeFile($datasetDir.'/manifest.json',PaperHistoryResearch::encode($manifest,true));
$out(['dataset'=>$spec['dataset'],'actions'=>$count,'seconds'=>round(microtime(true)-$started,1),
    'requests'=>$requests,'http_attempts'=>count(PaperHistoryResearch::$httpAttempts),
    'http_attempts_including_retries'=>PaperHistoryResearch::$httpAttempts===[]?0:count(PaperHistoryResearch::$httpAttempts),
    'bars_bytes'=>$bytes,'aborted_after_repeated_failures'=>$consecutive>=8,
    'unmapped_provider_symbols'=>array_map(fn($u)=>$u['symbol'],$unmapped),
    'summary'=>$manifest['summary'],
    'note'=>'No account replay. Dataset-wide warnings stay distinct from as-of PaperQuality. http_attempts counts every curl in HistoryResearch::httpGet.']);
exit(($manifest['summary']['failed']??0)>0||($manifest['summary']['not_attempted']??0)>0?2:0);
