<?php
declare(strict_types=1);
/**
 * Research only: dated KRX amount TOP100 bar prep for 2025-10-08..2026-10-08.
 * Writes <state>/history-research/kr-dated-amount-top100-20251008-20261008.
 * Never writes operational accounts, caches, strategy files, or the Oct 2025 dataset.
 * Does not run an account replay.
 */
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/DatedTop100Prep.php';

$cmd=$argv[1]??'';$o=[];
foreach(array_slice($argv,2) as $a){
    if(!preg_match('/^--([a-z-]+)=(.*)$/s',$a,$m))throw new InvalidArgumentException('Unknown argument '.$a);
    $o[$m[1]]=$m[2];
}
if(!in_array($cmd,['universe','collect','quality','coverage','yahoo-requests','provider-review'],true))
    throw new InvalidArgumentException('Commands: universe collect quality coverage yahoo-requests provider-review');
if(isset($o['state-dir']))putenv('PAPER_STATE_DIR='.$o['state-dir']);
$root=dirname(__DIR__);
$enc=static fn(array $v):string=>PaperHistoryResearch::encode($v,true)."\n";
$out=static function(array $v)use($enc):void{echo $enc($v);};
$candPath=$o['candidates']??$root.'/'.PaperDatedTop100Prep::CANDIDATES;
$uniPath=$o['universe']??$root.'/'.PaperDatedTop100Prep::UNIVERSE;
$stateRoot=PaperAccountReplay::stateRoot();
$datasetDir=$o['dataset-dir']??PaperHistoryResearch::datasetDir($stateRoot,PaperDatedTop100Prep::DATASET);

if($cmd==='universe'){
    $ds=PaperDatedTop100Prep::initDataset($stateRoot,$uniPath);
    $out(['dataset'=>$ds['dataset'],'dir'=>$ds['dir'],'universe_sha256'=>$ds['universe_sha256'],
        'as_of'=>$ds['as_of']['day'],'evaluation_start'=>$ds['evaluation_start_day'],
        'prep_bars'=>PaperHistoryResearch::PREP_BARS,'min_bars'=>PaperHistoryResearch::MIN_BARS,
        'note'=>'No account replay. Union is a collection list only.']);
    exit(0);
}
if($cmd==='quality'){
    $manifest=PaperHistoryResearch::manifest($datasetDir);
    $out(['dataset'=>PaperDatedTop100Prep::DATASET,'summary'=>$manifest['summary'],
        'window'=>$manifest['window']??null,'quality_at_as_of_distinct_from_dataset_warnings'=>true]);
    exit(0);
}
if($cmd==='yahoo-requests'){
    $ev=PaperDatedTop100Prep::yahooRequestEvidence($datasetDir);
    $dest=$o['out']??$root.'/docs/historical-top100-20251008-20261008/yahoo-request-evidence.json';
    PaperHistoryResearch::writeFile($dest,PaperHistoryResearch::encode($ev,true));
    $out($ev+['wrote'=>$dest]);
    exit(0);
}
if($cmd==='provider-review'){
    $uni=PaperHistoryResearch::readJson($uniPath)??throw new RuntimeException('universe.json missing');
    $cand=PaperDatedTop100Prep::loadCandidates($candPath);
    $barsSummary=json_decode((string)file_get_contents($root.'/docs/historical-top100-20251008-20261008/bars-summary.json'),true,512,JSON_THROW_ON_ERROR);
    $unmapped=array_values(array_filter($uni['rows'],fn($u)=>($u['status']??'')==='provider_symbol_unmapped'));
    $failed=array_map(fn($f)=>['kind'=>'yahoo_404','code'=>explode('.',$f['symbol'])[0],'symbol'=>$f['symbol'],'name'=>$f['name']??null,'market'=>$f['market']??null],$barsSummary['failed']??[]);
    foreach($unmapped as &$u)$u['kind']='unmapped';
    unset($u);
    $probe=$o['probe-root']??$root.'/data/raw/research/historical-amount-top100-20251008-20261008-provider-probe-v1';
    $review=PaperDatedTop100Prep::probeProviders(array_merge($unmapped,$failed),$probe,(int)($o['delay-ms']??800));
    $review['unmapped_appearances']=PaperDatedTop100Prep::appearanceCounts($cand,array_map(fn($u)=>$u['symbol'],$unmapped));
    $review['yahoo_404_appearances']=PaperDatedTop100Prep::appearanceCounts($cand,array_map(fn($f)=>$f['symbol'],$failed));
    $dest=$o['out']??$root.'/docs/historical-top100-20251008-20261008/provider-review.json';
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
    $cov=PaperDatedTop100Prep::sessionCoverage($cand,$state,PaperDatedTop100Prep::EVAL_START,$hydrate);
    $dest=$o['out']??$root.'/docs/historical-top100-20251008-20261008/asof-coverage.json';
    PaperHistoryResearch::writeFile($dest,PaperHistoryResearch::encode($cov,true));
    $out(['wrote'=>$dest,'day_count'=>$cov['day_count'],'occurrence'=>$cov['occurrence'],
        'unique_symbols'=>$cov['unique_symbols'],'post_dropout'=>$cov['post_dropout'],
        'later_jump_does_not_wipe_earlier'=>$cov['later_jump_does_not_wipe_earlier']]);
    exit(0);
}

$ds=PaperHistoryResearch::readJson($datasetDir.'/dataset.json')??throw new RuntimeException('Run universe first');
$uni=PaperHistoryResearch::readJson($datasetDir.'/universe.json')??throw new RuntimeException('universe.json missing');
if(($ds['dataset']??'')!==PaperDatedTop100Prep::DATASET)throw new RuntimeException('Refusing to write a different dataset');
$cand=PaperDatedTop100Prep::loadCandidates($candPath);
$neededDays=$cand['days'];
$as=$ds['as_of']+['evaluation_start_day'=>$ds['evaluation_start_day'],'primary_range'=>$ds['primary_range']??'5y'];
$reuse=[];
$reuseNames=isset($o['reuse-from'])?array_values(array_filter(array_map('trim',explode(',',$o['reuse-from']))))
    :['kr-saved-scan-20261009','kr-saved-scan-20251008','kr-dated-amount-top100-202510'];
foreach($reuseNames as $name){
    if($name===PaperDatedTop100Prep::DATASET)throw new RuntimeException('Refusing to reuse the destination dataset as a source');
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
$out(['dataset'=>PaperDatedTop100Prep::DATASET,'actions'=>$count,'seconds'=>round(microtime(true)-$started,1),
    'requests'=>$requests,'http_attempts'=>count(PaperHistoryResearch::$httpAttempts),
    'http_attempts_including_retries'=>PaperHistoryResearch::$httpAttempts===[]?0:count(PaperHistoryResearch::$httpAttempts),
    'bars_bytes'=>$bytes,'aborted_after_repeated_failures'=>$consecutive>=8,
    'unmapped_provider_symbols'=>array_map(fn($u)=>$u['symbol'],$unmapped),
    'summary'=>$manifest['summary'],
    'note'=>'No account replay. Dataset-wide warnings stay distinct from as-of PaperQuality. http_attempts counts every curl in HistoryResearch::httpGet.']);
exit(($manifest['summary']['failed']??0)>0||($manifest['summary']['not_attempted']??0)>0?2:0);
