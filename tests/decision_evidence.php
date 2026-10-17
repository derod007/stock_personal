<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/RrAudit.php';
function checkEvidence(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "OK $s\n";}
$r=['symbol'=>'005930.KS','session'=>123,'status'=>'evaluated','analysis'=>['plan'=>[
    'status'=>'risk_blocked','ready'=>false,'pattern'=>'trend_pullback_v1','data_asof'=>123,
    'diagnostics'=>['patterns'=>['trend_pullback'=>['version'=>'trend_pullback_v1','status'=>'await_confirmation',
        'gates'=>['near_ma20'=>true,'volume_contracted'=>false],'evidence'=>['volume_ratio'=>0.9]]]]]]];
$before=$r;$d=PaperDecisionEvidence::fromRecord($r);$p=$d['patterns']['trend_pullback'];
checkEvidence($r===$before,'projection does not mutate engine result');
checkEvidence($p['gates']['volume_contracted']['state']==='fail','observed false preserved');
checkEvidence($p['gates']['near_ma20']['state']==='pass','observed true preserved');
checkEvidence($p['gates']['recovery_close']['state']==='unknown','missing gate is unknown');
checkEvidence($p['rr_evaluation']==='unknown','unconfirmed RR is not marked failed');
checkEvidence($p['evidence']['volume_ratio']===0.9,'engine measurements preserved');
checkEvidence($d['final']['status']==='risk_blocked'&&$p['status']==='await_confirmation','final override and raw stage both retained');
checkEvidence($d['join_key']==='005930.KS@123'&&$d['account_outcome']==='not_observed_here','no invented account outcome');
$u=PaperDecisionEvidence::fromRecord(['symbol'=>'X','status'=>'unavailable','reason'=>'chart_query_failed']);
checkEvidence($u['session']===null&&$u['join_key']===null&&$u['patterns']===[],'unavailable session and gates not invented');
$dir=sys_get_temp_dir().'/decision-evidence-'.bin2hex(random_bytes(6));mkdir($dir);
try{
 $saved=PaperRrAudit::save($dir,[],[['symbol'=>'X','status'=>'unavailable','reason'=>'chart_query_failed','patterns'=>[]]]);
 $b=json_decode(file_get_contents($saved['path']),true,512,JSON_THROW_ON_ERROR);
 checkEvidence(isset($b['records'][0]['decision_evidence'],$b['decision_code_hashes']['src/ChartPlanEngine.php']),'immutable audit sidecar includes evidence and code hashes');
 $old=$b;unset($old['decision_code_hashes']);file_put_contents($dir.'/old.json',json_encode($old));
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/paper_decision_evidence.php').' --input='.escapeshellarg($dir.'/old.json').' --output='.escapeshellarg($dir.'/export.json');
 exec($cmd,$out,$status);checkEvidence($status===0,'saved audit export succeeds');
 $e=json_decode(file_get_contents($dir.'/export.json'),true);
 checkEvidence($e['source_code_hashes']===null&&$e['source_code_status']==='unknown_not_backfilled','old source version never relabeled with current code');
 $hash=hash_file('sha256',$dir.'/export.json');exec($cmd.' 2>/dev/null',$out,$status);
 checkEvidence($status!==0&&hash_file('sha256',$dir.'/export.json')===$hash,'existing output cannot be overwritten');
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
