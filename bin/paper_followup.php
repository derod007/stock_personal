<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/Followup.php';
use ChartEntryLab\YahooChartClient;
$o=getopt('',['account:','prices:','as-of:']);$id=$o['account']??'paper-kr';
if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account');
$asOf=isset($o['as-of'])?strtotime($o['as-of']):time();
if(!$asOf || $asOf>time())throw new InvalidArgumentException('Invalid/future as-of');
$dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__,2).'/stock-personal-paper';
$folder=$dir.'/followup/'.$id;if(!is_dir($folder))mkdir($folder,0770,true);
$lock=fopen($folder.'/update.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Followup already running');
try{
    $obs=PaperFollowup::observations($dir.'/rr-audit/'.$id);$previous=PaperFollowup::load($dir,$id);
    $rows=[];$prices=[];$errors=$obs['errors'];$client=null;
    foreach($obs['records'] as $key=>$r){
        $old=$previous['rows'][$key]??null;
        if($old && !empty($old['complete']) && $old['observation_hash']===$r['observation_hash'] && $old['as_of']<=$asOf){$rows[$key]=$old;continue;}
        $symbol=$r['symbol'];
        try{
            if(!array_key_exists($symbol,$prices)){
                try{
                    if(isset($o['prices'])){
                        $file=$o['prices'].'/'.$symbol.'.json';if(!is_file($file))$file=$o['prices'].'/'.$symbol.'_2y_1d_closed_v2.json';
                        if(!is_file($file))throw new RuntimeException('Price file missing');
                        $prices[$symbol]=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
                    }else{
                        $client??=new YahooChartClient($folder.'/prices');
                        $prices[$symbol]=$client->fetch($symbol,'2y','1d',useCache:true,maxAgeSeconds:3600);
                    }
                    if(!is_array($prices[$symbol]))throw new RuntimeException('Invalid prices');
                }catch(Throwable $e){$prices[$symbol]=null;$errors[]=['symbol'=>$symbol,'error'=>$e->getMessage()];}
            }
            if($prices[$symbol]===null)throw new RuntimeException('Price unavailable');
            $priceJson=PaperRrAudit::encode($prices[$symbol]);$priceHash=hash('sha256',$priceJson);
            $evidence=$folder.'/evidence';if(!is_dir($evidence))mkdir($evidence,0770,true);
            $evidencePath=$evidence.'/'.$priceHash.'.json';
            if(!is_file($evidencePath)){
                $temp=tempnam($evidence,'write-');
                try{if(file_put_contents($temp,$priceJson)!==strlen($priceJson)||!rename($temp,$evidencePath))throw new RuntimeException('Price evidence write failed');}
                finally{if(is_file($temp))unlink($temp);}
            }
            $rows[$key]=PaperFollowup::evaluate($r,$prices[$symbol],$asOf);
            $rows[$key]['price_hash']=$priceHash;
        }catch(Throwable $e){
            $rows[$key]=['symbol'=>$symbol,'name'=>$r['name']??$symbol,'session'=>$r['session'],'captured_at'=>$r['captured_at'],
                'source_file'=>$r['source_file'],'observation_hash'=>$r['observation_hash'],'as_of'=>$asOf,'complete'=>false,
                'rejected'=>empty($r['analysis']['plan']['ready']),'reasons'=>PaperFollowup::reasons($r),
                'status'=>'price_or_evaluation_error','error'=>$e->getMessage(),'horizons'=>[],'trades'=>[]];
        }
    }
    $report=['schema'=>1,'account'=>$id,'generated_at'=>time(),'as_of'=>$asOf,'duplicates'=>$obs['duplicates'],
        'unavailable_observations'=>$obs['unavailable'],'errors'=>$errors,'rows'=>$rows];
    $report['summary']=PaperFollowup::summarize($rows);
    $bad=array_filter($rows,fn($r)=>!in_array($r['status'],['complete','pending','no_future_bars'],true));
    $report['status']=$errors||$bad?'partial':($rows?'saved':'no_observations');
    // Historical offline runs do not replace the current report.
    if($previous && $previous['as_of']>$asOf)throw new RuntimeException('Refuse to replace newer followup report');
    PaperFollowup::save($folder,$report);
    echo PaperRrAudit::encode(['status'=>$report['status'],'observations'=>count($rows),'errors'=>count($errors),'summary'=>$report['summary']])."\n";
}finally{flock($lock,LOCK_UN);fclose($lock);}
