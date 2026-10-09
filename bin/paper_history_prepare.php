<?php
declare(strict_types=1);
/**
 * Prepare fixed-universe daily history for a later pattern study (data only, no strategy evaluation).
 *
 *   universe  freeze the symbol set from saved scans            --source-dir=DIR (repeatable) --dataset=ID
 *             or reuse the exact set of another dataset         --from-dataset=ID --dataset=ID
 *   collect   resumable download + quality check               --dataset=ID [--retry-failed] [--max-attempts=3] [--delay-ms=300]
 *             explicit past analysis date (first collect only)  --as-of=YYYY-MM-DD --primary-range=5y
 *   compare   shared days of two datasets (read only)           --dataset=B --against=A
 *   report    write manifest.json and print the summary         --dataset=ID [--copy-to=DIR]
 *   verify    recompute hashes and quality from stored files    --dataset=ID
 *
 * Files go to <state>/history-research/<dataset>. Optional --state-dir overrides PAPER_STATE_DIR.
 */
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/HistoryResearch.php';

$cmd=$argv[1]??'';$opt=[];
foreach(array_slice($argv,2) as $a){
    if(!preg_match('/^--([a-z-]+)(?:=(.*))?$/s',$a,$m))throw new InvalidArgumentException('Unknown argument '.$a);
    $v=$m[2]??true;
    if(isset($opt[$m[1]])){$opt[$m[1]]=array_merge((array)$opt[$m[1]],[$v]);}else $opt[$m[1]]=$v;
}
$dataset=(string)($opt['dataset']??'kr-saved-scan-v1');
$state=(string)($opt['state-dir']??(getenv('PAPER_STATE_DIR')?:dirname(__DIR__,2).'/stock-personal-paper'));
$dir=PaperHistoryResearch::datasetDir($state,$dataset);
PaperHistoryResearch::assertResearchDir($dir);
$say=static fn(string $s)=>fwrite(STDERR,$s."\n");
$out=static function(array $v):void{echo PaperHistoryResearch::encode($v,true)."\n";};

if($cmd==='universe'){
    if(isset($opt['from-dataset'])){
        // Same symbols as an existing dataset: universe.json is copied byte for byte, not extracted again.
        $srcDir=PaperHistoryResearch::datasetDir($state,(string)$opt['from-dataset']);
        $ds=PaperHistoryResearch::copyUniverse($srcDir,$dir,$dataset);
        $uni=PaperHistoryResearch::readJson($dir.'/universe.json');
        $out(['dataset'=>$dataset,'path'=>$dir,'universe_source'=>$ds['universe_source'],'counts'=>$uni['counts']]);
        exit(0);
    }
    $sources=(array)($opt['source-dir']??['docs/paper-kr-5d-source/rr-audit/paper-kr']);
    $uni=PaperHistoryResearch::universe($sources);
    PaperHistoryResearch::initDataset($dir,$uni,$dataset);
    $out(['dataset'=>$dataset,'path'=>$dir,'counts'=>$uni['counts'],'source_errors'=>$uni['source_errors']]);
    exit(0);
}

if($cmd==='collect'){
    $ds=PaperHistoryResearch::readJson($dir.'/dataset.json')??throw new RuntimeException('Run universe first');
    $uni=PaperHistoryResearch::readJson($dir.'/universe.json')??throw new RuntimeException('universe.json missing');
    if(!hash_equals($ds['universe_sha256'],hash_file('sha256',$dir.'/universe.json')))throw new RuntimeException('universe.json changed after it was fixed');
    $maxAttempts=max(1,min(5,(int)($opt['max-attempts']??PaperHistoryResearch::MAX_ATTEMPTS)));
    $delay=max(0,(int)($opt['delay-ms']??300))*1000;$retryFailed=array_key_exists('retry-failed',$opt);
    $http=static fn(string $url):array=>PaperHistoryResearch::httpGet($url);
    if(!is_array($ds['as_of'])){
        // The calendar reference only decides the last completed regular session. It is not added to the dataset.
        $probe=PaperHistoryResearch::makeClient($dir.'/probe',$http);
        $probe['context']['symbol']='005930.KS';
        if(isset($opt['as-of'])){
            // A requested calendar day. It resolves to the last completed session on or before it; the requested day
            // keeps defining the 12 month evaluation window.
            $want=(string)$opt['as-of'];
            $rows=$probe['client']->fetch('005930.KS','5y','1d',false,0);
            $as=PaperHistoryResearch::asOfOnOrBefore($rows,'005930.KS',$want,time())??throw new RuntimeException('No completed regular session on or before '.$want.' in the reference symbol');
            $basis='analysis date requested as '.$want.'; last completed regular session on or before it in reference symbol 005930.KS is '.$as['day'];
            $requested=$want;
        }else{
            $rows=$probe['client']->fetch('005930.KS','5d','1d',false,0);
            $as=PaperHistoryResearch::asOfFromRows($rows,'005930.KS',time())??throw new RuntimeException('No completed session found');
            $basis='last completed regular session of reference symbol 005930.KS at first collect';
            $requested=null;
        }
        $ds=PaperHistoryResearch::fixAsOf($dir,$as,$basis,isset($opt['primary-range'])?(string)$opt['primary-range']:null,$requested);
    }elseif(isset($opt['as-of'])&&$opt['as-of']!==($ds['requested_as_of']??$ds['as_of']['day'])){
        throw new RuntimeException('Analysis date is already fixed to '.($ds['requested_as_of']??$ds['as_of']['day']).'; use a new --dataset id for another date');
    }
    $as=$ds['as_of']+['primary_range'=>$ds['primary_range']??PaperHistoryResearch::PRIMARY_RANGE,'evaluation_start_day'=>PaperHistoryResearch::evalStartOf($ds)];
    $deps=PaperHistoryResearch::makeClient($dir,$http);
    $included=array_values(array_filter($uni['rows'],fn($u)=>$u['status']==='included'));
    $count=['reused'=>0,'collected'=>0,'failed'=>0,'skipped_attempt_limit'=>0];$consecutive=0;$aborted=false;
    $pass=static function(array $list) use ($dir,$as,$deps,$maxAttempts,$retryFailed,$delay,$say,&$count,&$consecutive,&$aborted):array{
        $failed=[];
        foreach($list as $i=>$u){
            $r=PaperHistoryResearch::collectSymbol($dir,$u,$as,$deps,$maxAttempts,$retryFailed);
            $count[$r['action']]++;
            if($r['action']==='failed'){$failed[]=$u;$consecutive++;$say(sprintf('[%d/%d] FAIL %s %s',$i+1,count($list),$u['symbol'],$r['status']['error']));}
            else{$consecutive=0;if($r['action']==='collected')$say(sprintf('[%d/%d] ok   %s %s (%s)',$i+1,count($list),$u['symbol'],$u['name'],$r['status']['quality']['status']));}
            if($consecutive>=8){$aborted=true;break;}
            if($r['action']==='collected'&&$delay>0)usleep($delay);
        }
        return $failed;
    };
    $failed=$pass($included);
    if($failed&&!$aborted){
        // One bounded second pass for transient failures; the per symbol attempt counter still applies.
        $say('Second pass for '.count($failed).' failed symbol(s)');
        sleep(3);$count['failed']-=count($failed);$pass($failed);
    }
    $manifest=PaperHistoryResearch::manifest($dir);
    PaperHistoryResearch::writeFile($dir.'/manifest.json',PaperHistoryResearch::encode($manifest,true));
    $out(['dataset'=>$dataset,'as_of'=>$as['day'],'actions'=>$count,'aborted_after_repeated_failures'=>$aborted,'summary'=>$manifest['summary']]);
    exit($manifest['summary']['failed']>0||$manifest['summary']['not_attempted']>0?2:0);
}

if($cmd==='report'){
    $manifest=PaperHistoryResearch::manifest($dir);
    PaperHistoryResearch::writeFile($dir.'/manifest.json',PaperHistoryResearch::encode($manifest,true));
    if(isset($opt['copy-to'])){
        // Summary only: no bars and no raw responses.
        $to=rtrim((string)$opt['copy-to'],'/\\');
        PaperHistoryResearch::writeFile($to.'/manifest.json',PaperHistoryResearch::encode($manifest,true));
        PaperHistoryResearch::writeFile($to.'/universe.json',(string)file_get_contents($dir.'/universe.json'));
    }
    $out(['dataset'=>$dataset,'manifest'=>$dir.'/manifest.json','summary'=>$manifest['summary'],'hold_reason_counts'=>$manifest['hold_reason_counts'],
        'warning_counts'=>$manifest['warning_counts']]);
    exit(0);
}

if($cmd==='compare'){
    $against=PaperHistoryResearch::datasetDir($state,(string)($opt['against']??throw new InvalidArgumentException('--against=DATASET is required')));
    $res=PaperHistoryResearch::compare($against,$dir);
    $out($res);
    exit($res['summary']['mismatch_days']>0?1:0);
}

if($cmd==='verify'){
    $problems=PaperHistoryResearch::verify($dir);
    $out(['dataset'=>$dataset,'intact'=>$problems===[],'problems'=>$problems]);
    exit($problems===[]?0:1);
}

fwrite(STDERR,"Usage: php bin/paper_history_prepare.php universe|collect|report|verify --dataset=ID\n");
exit(64);
