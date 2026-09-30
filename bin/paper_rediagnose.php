<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/Rediagnosis.php';
require __DIR__.'/paper/StrategyVersion.php';

$o=getopt('',['account:','prices:','as-of:','profile:']);$id=$o['account']??'paper-kr';
if(!is_string($id)||!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account');
$profile=$o['profile']??null;
if($profile!==null&&!in_array($profile,['account1','custom','isa'],true))throw new InvalidArgumentException('Invalid profile');
$asOf=isset($o['as-of'])?strtotime($o['as-of']):time();
if(!$asOf||$asOf>time())throw new InvalidArgumentException('Invalid/future as-of');
$state=getenv('PAPER_STATE_DIR')?:dirname(__DIR__,2).'/stock-personal-paper';
$folder=$state.'/rediagnosis/'.$id;
if(!is_dir($folder)&&!mkdir($folder,0770,true)&&!is_dir($folder))throw new RuntimeException('Cannot create report directory');
$lock=fopen($folder.'/update.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Rediagnosis already running');
try{
    $fingerprint=PaperStrategyVersion::current();$rows=[];$errors=[];$statusCounts=[];$tracked=[];
    try{$old=PaperFollowup::load($state,$id);foreach($old['rows']??[] as $r)$tracked[$r['observation_hash']]=$r;}
    catch(Throwable $e){$errors[]=['stage'=>'followup_report','error'=>$e->getMessage()];}
    $files=PaperRrView::files($state.'/rr-audit/'.$id);sort($files,SORT_STRING);
    foreach($files as $file){
        try{
            $bundle=PaperRrView::load($state.'/rr-audit/'.$id.'/'.$file);
            if(($bundle['membership']??'')!=='observed_scan_only')continue;
            foreach($bundle['records'] as $index=>$raw){
                try{
                    $r=PaperRediagnosis::identity($raw,$bundle,$file);$symbol=$r['symbol']??'';
                    if(!preg_match('/^[A-Za-z0-9.^=_-]{1,40}$/',$symbol))throw new RuntimeException('Invalid symbol');
                    $prices=null;$source=['kind'=>'unavailable'];$evaluationAsOf=$asOf;
                    if(($r['status']??'')==='evaluated'){
                        $f=$tracked[$r['observation_hash']]??null;
                        if(isset($o['prices'])){
                            $path=$o['prices'].'/'.$symbol.'.json';
                            if(!is_file($path))$path=$o['prices'].'/'.$symbol.'_2y_1d_closed_v2.json';
                            $source=['kind'=>'operator_price_directory','path'=>$path];
                        }elseif($f&&($f['source_file']??null)===$file&&($f['symbol']??null)===$symbol
                            &&($f['session']??null)===($r['session']??null)&&preg_match('/^[a-f0-9]{64}$/',$f['price_hash']??'')){
                            $path=$state.'/followup/'.$id.'/evidence/'.$f['price_hash'].'.json';
                            $source=['kind'=>'original_followup_evidence','price_hash'=>$f['price_hash'],'path'=>$path];
                            $evaluationAsOf=min($asOf,(int)$f['as_of']);
                        }else{$path=dirname(__DIR__).'/data/ohlcv/'.$symbol.'_2y_1d_closed_v2.json';$source=['kind'=>'local_cache','path'=>$path];}
                        try{
                            if(!is_file($path))throw new RuntimeException('Price evidence missing');
                            $bytes=file_get_contents($path);$prices=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
                            if(!is_array($prices)||!array_is_list($prices))throw new RuntimeException('Invalid price array');
                            $hash=hash('sha256',PaperRrAudit::encode($prices));
                            if(isset($source['price_hash'])&&!hash_equals($source['price_hash'],$hash))throw new RuntimeException('Price evidence hash mismatch');
                            $source['price_hash']=$hash;
                        }catch(Throwable $e){$prices=null;$source['error']=$e->getMessage();}
                    }
                    // Replay always uses the original decision time. Outcome uses only the selected price snapshot's cutoff.
                    $row=PaperRediagnosis::evaluate($r,$prices,$asOf,$fingerprint,$profile);
                    if($prices!==null&&$evaluationAsOf!==$asOf&&$row['followup']!==null){
                        $row['followup']=PaperFollowup::evaluate($r,$prices,$evaluationAsOf);
                    }
                    $row['price_source']=$source;$row['followup_as_of']=$evaluationAsOf;
                    $rows[]=$row;$s=$row['status'];$statusCounts[$s]=($statusCounts[$s]??0)+1;
                }catch(Throwable $e){$errors[]=['file'=>$file,'record'=>$index,'error'=>$e->getMessage()];}
            }
        }catch(Throwable $e){$errors[]=['file'=>$file,'error'=>$e->getMessage()];}
    }
    $report=['schema'=>1,'kind'=>'saved_audit_rediagnosis','account'=>$id,'generated_at'=>time(),'as_of'=>$asOf,
        'strategy_fingerprint'=>$fingerprint,'profile'=>$profile,'status_counts'=>$statusCounts,
        'count'=>count($rows),'errors'=>$errors,'rows'=>$rows];
    // A separate history and latest file; never writes rr-audit, followup, orders, or account state.
    PaperFollowup::save($folder,$report);
    echo PaperRrAudit::encode(['report'=>$folder.'/latest.json','rows'=>count($rows),'statuses'=>$statusCounts,'errors'=>count($errors)])."\n";
}finally{flock($lock,LOCK_UN);fclose($lock);}
