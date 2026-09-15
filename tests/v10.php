<?php
declare(strict_types=1);
require __DIR__.'/v8.php';require __DIR__.'/../bin/paper/Experiment.php';
use ChartEntryLab\PaperJournal;
function echeck(bool $x,string $s):void{if(!$x)throw new RuntimeException($s);echo "experiment OK: $s\n";}
$cfg=['id'=>'source','currency'=>'USD','initial_cash'=>10000,'max_positions'=>4,'risk_pct'=>0.01,'position_pct'=>0.2,'sector_pct'=>0.4,'total_risk_pct'=>0.03,'symbols'=>['MU'=>'semi']];
$t=strtotime('2026-01-05 21:00:00 UTC');$hist=[];$events=[];
for($i=0;$i<4;$i++){
 $at=$t+$i*86400;$hist[]=['available_at'=>$at,'timestamp'=>$at,'open'=>101,'high'=>105,'low'=>99,'close'=>102,'volume'=>1000];
 $p=['symbol'=>'MU','session'=>$at,'recorded_at'=>$at+1,'origin'=>'replay','input_hash'=>'fixture-'.$i,'plan'=>['ready'=>true,'entry'=>100,'stop'=>95,'target'=>110,'signal_at'=>$at,'order_valid_bars'=>3],'quality'=>['can_simulate'=>true,'reasons'=>[]]];
 $events[]=['type'=>'snapshot','payload'=>$p,'hash'=>'hash-'.$i];
}
$source=['state'=>['mode'=>'replay','config'=>$cfg,'version'=>'v','history'=>['MU'=>$hist],'last_session'=>$t+3*86400],'events'=>$events];
$def=['id'=>'test','source'=>'source','mode'=>'replay','candidate'=>[]];$out=[];$emit=function($k,$p)use(&$out){$out[]=[$k,$p];};
$original=serialize($source);$pair=PaperExperiment::update(null,$source,$def,$t+4*86400,$emit);$r=PaperExperiment::report($pair);
echeck($pair['baseline']===$pair['candidate'],'independent identical arms match full state');
echeck($pair['sessions']===4,'replay includes all sessions');
echeck(count($pair['baseline']['active'])===1 && $pair['baseline']['active']['MU']['filled'],'fixture actually fills');
echeck(serialize($source)===$original,'source unchanged');
$again=PaperExperiment::update($pair,$source,$def,$t+5*86400,$emit);echeck($again===$pair,'repeat unchanged source idempotent');
echeck($r['identity_check']==='matched' && in_array('fewer_than_30_closed_trades',$r['reasons']),'identity not profitability proof');
$forward=$source;$forward['state']['mode']='forward';foreach($forward['events'] as &$e)$e['payload']['origin']='forward';unset($e);
$fd=$def;$fd['mode']='forward';$fp=PaperExperiment::update(null,$forward,$fd,$t+3*86400+10,$emit);
echeck($fp['sessions']===1,'forward starts latest only');
echeck($fp['baseline']['last_snapshots']['MU']['recorded_at']===$t+3*86400+10,'comparison actual record time used');
$changed=$def;$changed['candidate']=['order_valid_bars'=>1];
try{PaperExperiment::update($pair,$source,$changed,$t,$emit);echeck(false,'pinned change');}catch(RuntimeException $e){echeck(true,'changed definition rejected');}
$bad=$source;$bad['events'][3]['hash']='revised';try{PaperExperiment::update($pair,$bad,$def,$t,$emit);echeck(false,'rollback');}catch(RuntimeException $e){echeck(true,'source changed rejected');}
$bad=$source;$bad['state']['halted']=true;try{PaperExperiment::update($pair,$bad,$def,$t,$emit);echeck(false,'halt');}catch(RuntimeException $e){echeck(true,'halted source rejected');}
$variant=PaperExperiment::update(null,$source,$changed,$t+4*86400,$emit);
echeck($variant['baseline']['last_snapshots']['MU']['plan']['order_valid_bars']===3 && $variant['candidate']['last_snapshots']['MU']['plan']['order_valid_bars']===1,'only candidate TTL changed');
$dir=sys_get_temp_dir().'/experiment-'.bin2hex(random_bytes(5));mkdir($dir);$j=new PaperJournal($dir.'/pair.json');
try{
$j->transact(function(&$s,$emit)use($source,$def,$t){$s=PaperExperiment::update($s,$source,$def,$t+4*86400,$emit);});$hash=hash_file('sha256',$dir.'/pair.json');
try{$j->transact(function(&$s,$emit)use($source,$changed,$t){$s=PaperExperiment::update($s,$source,$changed,$t,$emit);});}catch(RuntimeException $e){}
echeck(hash_file('sha256',$dir.'/pair.json')===$hash,'failed update rolls back both arms');
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
echo "v10: 13 experiment checks passed\n";

$dir=sys_get_temp_dir().'/experiment-cli-'.bin2hex(random_bytes(5));mkdir($dir);
$old=getenv('PAPER_STATE_DIR');putenv('PAPER_STATE_DIR='.$dir);
try {
 $versions=[];foreach(glob(__DIR__.'/../src/*.php') as $file)$versions[basename($file)]=hash_file('sha256',$file);
 $fixture=$source;$fixture['state']['version']=hash('sha256',PaperJournal::encode($versions));
 $j=new PaperJournal($dir.'/source-replay.json');
 $j->transact(function(&$s,$emit)use($fixture){$s=$fixture['state'];foreach($fixture['events'] as $e)$emit($e['type'],$e['payload']);});
 $sourceHash=hash_file('sha256',$dir.'/source-replay.json');
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/paper_compare.php').' --source=source --experiment=cli --mode=replay';
 exec($cmd,$output,$code);$report=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
 echeck($code===0 && $report['identity_check']==='matched','real CLI independent identical states');
 $pairHash=hash_file('sha256',$dir.'/experiments/cli-replay.json');$output=[];
 exec($cmd,$output,$code);
 echeck($code===0 && $pairHash===hash_file('sha256',$dir.'/experiments/cli-replay.json'),'real CLI repeat byte-identical');
 echeck($sourceHash===hash_file('sha256',$dir.'/source-replay.json'),'real CLI preserves source journal');
}finally{
 putenv($old===false?'PAPER_STATE_DIR':'PAPER_STATE_DIR='.$old);
 foreach(glob($dir.'/experiments/*')?:[] as $file)unlink($file);
 if(is_dir($dir.'/experiments'))rmdir($dir.'/experiments');
 foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);
}
echo "v10: 16 total experiment checks passed\n";
