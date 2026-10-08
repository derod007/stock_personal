<?php
declare(strict_types=1);
$tmp=sys_get_temp_dir().'/rr-labels-'.bin2hex(random_bytes(5));mkdir($tmp.'/rr-audit/paper-kr',0700,true);
$name='20261008-112318-123456abcdef.json';$patterns=[];
foreach(['trend_pullback'=>'rejected_rr','trend_recovery'=>'await_higher_low','breakout_retest'=>'await_retest'] as $pattern=>$status)$patterns[]=[
 'pattern'=>$pattern,'raw_status'=>$status,'raw_reason'=>'RAW_'.$pattern,'final_status'=>'rejected_rr','final_reason'=>'FINAL_ONLY_ONCE',
 'original'=>[],'status'=>$pattern==='trend_pullback'?'added':'excluded','missing_conditions'=>[],'exclusion_reason_labels'=>[],
 'confirmed_rr_rejection'=>$pattern==='trend_pullback'];
$bundle=['version'=>'confirmed_rr_limit_v1','recorded_at'=>1791440000,'records'=>[['symbol'=>'TEST','name'=>'TEST','status'=>'evaluated','session'=>1791426600,'patterns'=>$patterns]],'summary'=>['symbols'=>1]];
file_put_contents($tmp.'/rr-audit/paper-kr/'.$name,json_encode($bundle));putenv('PAPER_STATE_DIR='.$tmp);
$_GET=['account'=>'paper-kr','file'=>$name];
try{
 ob_start();require __DIR__.'/../paper_rr.php';$html=ob_get_clean();
 $table=explode('종목별 탈락·지정가 연구</h2>',$html,2)[1]??'';$table=explode('</table>',$table,2)[0];
 if(substr_count($table,'FINAL_ONLY_ONCE')!==1||!str_contains($table,'<td>돌파 후 높은 저점 대기</td>')||!str_contains($table,'<td>재지지 대기</td>')||!str_contains($table,'연구 포함 · 운영 추천 아님'))throw new RuntimeException('Per-pattern status and single final summary must remain distinct');
 echo "PASS actual RR page separates final summary, pattern status, and research inclusion\n";
}finally{putenv('PAPER_STATE_DIR');unlink($tmp.'/rr-audit/paper-kr/'.$name);rmdir($tmp.'/rr-audit/paper-kr');rmdir($tmp.'/rr-audit');rmdir($tmp);}
