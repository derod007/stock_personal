<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require __DIR__.'/paper/TargetTracking.php';
$o=getopt('',['account:','source-dir:','prices:','as-of:','saved-evidence']);$id=$o['account']??'paper-kr';
if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account');
if(isset($o['prices'],$o['saved-evidence']))throw new InvalidArgumentException('Choose prices or saved evidence');
$now=isset($o['as-of'])?strtotime($o['as-of']):time();if(!$now||$now>time())throw new InvalidArgumentException('Invalid cutoff');
$state=getenv('PAPER_STATE_DIR')?:dirname(__DIR__,2).'/stock-personal-paper';$source=$o['source-dir']??$state;
$folder=$state.'/target-tracking/'.$id;if(!is_dir($folder)&&!mkdir($folder,0770,true)&&!is_dir($folder))throw new RuntimeException('Cannot create ledger');
$lock=fopen($folder.'/update.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Target tracking already running');
try{
    $report=PaperTargetTracking::load($state,$id)??['schema'=>1,'kind'=>PaperTargetTracking::KIND,'account'=>$id,'rows'=>[]];
    if(($report['as_of']??0)>$now)throw new RuntimeException('Refuse older ledger update');
    $report['generated_at']=time();$report['as_of']=$now;$report['errors']=[];$report['added']=0;
    $execution=PaperTargetTracking::executionVersion();$fingerprint=PaperStrategyVersion::current();
    $obs=PaperFollowup::observations($source.'/rr-audit/'.$id);
    $report=PaperTargetTracking::register($report,$obs,$now,$fingerprint,$execution);
    $saved=[];
    if(isset($o['saved-evidence'])){
        $followup=PaperFollowup::load($source,$id);
        foreach($followup['rows']??[] as $r)$saved[$r['observation_hash']]=$r;
    }
    $cache=[];$client=null;
    $provider=function(array $r,array $row)use(&$cache,&$client,$o,$saved,$source,$folder,$id,$now):array{
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
                    $cache[$symbol]=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
                }else{
                    $client??=new ChartEntryLab\YahooChartClient($folder.'/prices');
                    $cache[$symbol]=$client->fetch($symbol,'2y','1d',useCache:true,maxAgeSeconds:3600);
                }
            }$raw=$cache[$symbol];
        }
        if(!is_array($raw)||!array_is_list($raw))throw new RuntimeException('Invalid price list');
        $bytes=PaperRrAudit::encode($raw);$hash=hash('sha256',$bytes);$dir=$folder.'/evidence';
        if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Cannot create evidence directory');
        $path=$dir.'/'.$hash.'.json';
        if(!is_file($path)){$temp=tempnam($dir,'write-');try{if(file_put_contents($temp,$bytes)!==strlen($bytes)||!rename($temp,$path))throw new RuntimeException('Evidence write failed');}finally{if(is_file($temp))unlink($temp);}}
        return ['raw'=>$raw,'as_of'=>$cutoff,'price_hash'=>$hash];
    };
    foreach($report['rows'] as $key=>$row)$report['rows'][$key]=PaperTargetTracking::refresh($row,$provider,$execution,$now);
    $report['summary']=PaperTargetTracking::summarize($report['rows']);
    $report['status']=$report['errors']||$report['summary']['update_errors']?'partial':($report['rows']?'saved':'no_observations');
    PaperFollowup::save($folder,$report);
    echo PaperRrAudit::encode(['status'=>$report['status'],'added'=>$report['added'],'summary'=>$report['summary'],'errors'=>count($report['errors'])])."\n";
}finally{flock($lock,LOCK_UN);fclose($lock);}
