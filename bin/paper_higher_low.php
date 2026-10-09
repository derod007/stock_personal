<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require __DIR__.'/paper/HigherLowResearch.php';
$o=getopt('',['account:','source-dir:','prices:','as-of:','saved-evidence']);$id=$o['account']??'paper-kr';
if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account');
if(isset($o['prices'],$o['saved-evidence']))throw new InvalidArgumentException('Choose prices or saved evidence');
$now=isset($o['as-of'])?strtotime($o['as-of']):time();if(!$now||$now>time())throw new InvalidArgumentException('Invalid cutoff');
$state=getenv('PAPER_STATE_DIR')?:dirname(__DIR__,2).'/stock-personal-paper';$source=$o['source-dir']??$state;
$folder=$state.'/higher-low-research/'.$id;if(!is_dir($folder)&&!mkdir($folder,0770,true)&&!is_dir($folder))throw new RuntimeException('Cannot create ledger');
$lock=fopen($folder.'/update.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Higher-low research already running');
try{
    $report=PaperHigherLowResearch::load($state,$id)??['schema'=>1,'kind'=>PaperHigherLowResearch::KIND,'account'=>$id,'rows'=>[]];
    if(($report['as_of']??0)>$now)throw new RuntimeException('Refuse older ledger update');
    $report['generated_at']=time();$report['as_of']=$now;$report['errors']=[];$report['added']=0;
    $execution=PaperHigherLowResearch::executionVersion();
    $report=PaperHigherLowResearch::register($report,$folder,$source.'/rr-audit/'.$id,$now,$execution);
    $report['storage_format']='frozen_files_v1';
    $saved=[];
    if(isset($o['saved-evidence'])){
        $saved=PaperEnvelopeStore::savedIndex($source.'/followup/'.$id.'/latest.json');
    }
    $cache=[];$client=null;
    $provider=function(array $r,array $row)use(&$cache,&$client,$o,$saved,$source,$folder,$id,$now,$state):array{
        $symbol=$r['symbol'];$cutoff=$now;
        if(isset($o['saved-evidence'])){
            $f=$saved[$r['observation_hash']]??null;
            if($f&&$f['source_file']===$r['source_file']&&$f['symbol']===$symbol&&$f['session']===$r['session']&&isset($f['price_hash'])&&$f['as_of']>=($row['last_success_at']??0)){
                $hash=$f['price_hash'];$path=$source.'/followup/'.$id.'/evidence/'.$hash.'.json';$cutoff=min($now,(int)$f['as_of']);
            }elseif(isset($row['price_hash'])){
                $hash=$row['price_hash'];$path=$folder.'/evidence/'.$hash.'.json';$cutoff=min($now,(int)$row['last_success_at']);
            }else throw new RuntimeException('Matching saved evidence unavailable');
            if(!preg_match('/^[a-f0-9]{64}$/',$hash))throw new RuntimeException('Invalid evidence hash');
            $raw=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
            if(!hash_equals($hash,hash('sha256',PaperRrAudit::encode($raw))))throw new RuntimeException('Saved evidence checksum mismatch');
        }else{
            if(!isset($cache[$symbol])){
                if(isset($o['prices'])){
                    $path=$o['prices'].'/'.$symbol.'.json';if(!is_file($path))$path=$o['prices'].'/'.$symbol.'_2y_1d_closed_v2.json';
                    $raw=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
                }else{
                    $client??=new ChartEntryLab\YahooChartClient($state.'/followup/'.$id.'/prices');
                    $raw=$client->fetch($symbol,'2y','1d',useCache:true,maxAgeSeconds:3600);
                }
            }else{
                $raw=json_decode(file_get_contents($cache[$symbol]),true,512,JSON_THROW_ON_ERROR);
                if(!hash_equals(basename($cache[$symbol],'.json'),hash('sha256',PaperRrAudit::encode($raw))))throw new RuntimeException('Pinned run evidence changed');
            }
        }
        if(!is_array($raw)||!array_is_list($raw))throw new RuntimeException('Invalid price list');
        $bytes=PaperRrAudit::encode($raw);$hash=hash('sha256',$bytes);$dir=$folder.'/evidence';
        if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Cannot create evidence directory');
        $path=$dir.'/'.$hash.'.json';
        if(!is_file($path)){$temp=tempnam($dir,'write-');try{if(file_put_contents($temp,$bytes)!==strlen($bytes)||!rename($temp,$path))throw new RuntimeException('Evidence write failed');}finally{if(is_file($temp))unlink($temp);}}
        if(!hash_equals($hash,hash_file('sha256',$path)))throw new RuntimeException('Evidence file hash mismatch');
        if(!isset($o['saved-evidence']))$cache[$symbol]=$path;
        return ['raw'=>$raw,'as_of'=>$cutoff,'price_hash'=>$hash];
    };
    foreach($report['rows'] as $key=>$row)$report['rows'][$key]=PaperHigherLowResearch::refresh($folder,$row,$provider,$execution,$now);
    $report['summary']=PaperHigherLowResearch::summarize($report['rows']);
    $report['status']=$report['errors']||($report['summary']['statuses']['update_error']??0)?'partial':($report['rows']?'saved':'no_observations');
    PaperEnvelopeStore::save($folder,$report);
    echo PaperRrAudit::encode(['status'=>$report['status'],'added'=>$report['added'],'summary'=>$report['summary'],'errors'=>count($report['errors']),'peak_memory_bytes'=>memory_get_peak_usage(true)])."\n";
}finally{flock($lock,LOCK_UN);fclose($lock);}
