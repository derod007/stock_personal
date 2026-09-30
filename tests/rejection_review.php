<?php
declare(strict_types=1);
require_once __DIR__.'/../bin/paper/RejectionReviewPanel.php';
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function record(string $symbol,string $raw,string $final,array $missing=[]):array {
 return ['symbol'=>$symbol,'name'=>$symbol,'status'=>'evaluated','session'=>100,
 'observation_hash'=>$symbol,'analysis'=>['plan'=>['status'=>$final]],
 'measurements'=>['pullback_volume_ratio'=>0.89,'required_volume_ratio_lt'=>0.85],
 'patterns'=>[['pattern'=>'trend_pullback','raw_status'=>$raw,'status'=>'added',
 'missing_conditions'=>$missing,'not_evaluated'=>[],
 'exclusion_reasons'=>$final==='risk_blocked'?['risk_blocked','spike_dump']:[]],
 ['pattern'=>'breakout_retest','raw_status'=>'ready','exclusion_reasons'=>['not_selected_pattern']]]];
}
function bundle(string $file,string $date,array $records):array {
 return ['source_file'=>$file,'membership'=>'observed_scan_only','recorded_at'=>strtotime($date.' 12:00 UTC'),'records'=>$records];
}
$a=record('A','ready','risk_blocked');$b=record('B','rejected_rr','rejected_rr');
$c=record('C','await_confirmation','risk_blocked',['volume_contracted'=>'눌림 거래량']);
$d=record('D','await_confirmation','await_confirmation',['volume_contracted'=>'눌림 거래량','bullish_candle'=>'양봉']);
$f=['symbol'=>'B','source_file'=>'a.json','session'=>100,'observation_hash'=>'B','rejected'=>true,
 'reasons'=>['rr'=>'손익비'],'status'=>'pending','horizons'=>[1=>['status'=>'complete','return_pct'=>2,'max_up_pct'=>3,'max_down_pct'=>-1],5=>['status'=>'pending','return_pct'=>null]],
 'trades'=>[['kind'=>'limit','outcome'=>['status'=>'closed','net_return_pct'=>1.5,'filled'=>true]]]];
$bundles=[bundle('b.json','2026-09-30',[$b]),bundle('a.json','2026-09-30',[$a,$b,$c,$d,
 ['symbol'=>'E','status'=>'unavailable','reason'=>'chart_query_failed','detail'=>'HTTP 404']])];
$r=PaperRejectionReview::build($bundles,['rows'=>[$f],'as_of'=>200]);
check(count($r['rows'])===5&&$r['duplicates']===1,'earliest daily capture, rerun dedup');
check(($r['cross']['ready → risk_blocked']??0)===1,'raw ready distinct from final risk');
check(($r['blockers']['spike_dump']??0)===2,'risk subtype retained');
check(count($r['volume_only'])===1&&$r['volume_only'][0]['final']==='risk_blocked','volume-only gate does not imply eligibility');
check($r['volume_only'][0]['measurements']['pullback_volume_ratio']===0.89,'saved numeric ratio retained');
check(($r['quality']['HTTP 404']??0)===1,'fetch failures separate');
check($r['rr'][0]['followup_status']==='pending','exact observation joined');
check($r['rr_summary']['groups']['rr']['horizons'][1]['n']===1&&$r['rr_summary']['groups']['rr']['horizons'][5]['n']===0,'only mature horizons enter sample');
check($r['rr_summary']['trades']['limit']['mean_net_pct']===1.5,'existing cost-adjusted result reused');
$f['source_file']='other.json';$m=PaperRejectionReview::build($bundles,['rows'=>[$f]]);
check($m['rr'][0]['followup']===null,'same symbol with different source never joins');
$f['source_file']='a.json';$f['observation_hash']='changed';
check(PaperRejectionReview::build($bundles,['rows'=>[$f]])['rr'][0]['followup']===null,'different input hash never joins');
$many=[];foreach(range(20,26) as $day)$many[]=bundle('d'.$day,'2026-09-'.$day,[record('X','no_setup','no_setup')]);
$many[]=bundle('preview','2026-09-30',[]);$many[count($many)-1]['membership']='preview_local_cache';
$r5=PaperRejectionReview::build($many,null);
check($r5['dates']===['2026-09-22','2026-09-23','2026-09-24','2026-09-25','2026-09-26'],'latest five observed dates only');
$r['volume_only'][0]['name']='<script>alert(1)</script>';ob_start();paper_rejection_review_panel($r);$html=ob_get_clean();
check(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'escaped diagnostic labels');
// Exercise disk loader and the same hash recipe used by PaperFollowup::observations.
$dir=sys_get_temp_dir().'/rejection-review-'.bin2hex(random_bytes(6));mkdir($dir);
try {
 $file='20260930-120000-aaaaaaaaaaaa.json';$original=$b;unset($original['observation_hash']);$original['bars']=[];$original['input_hash']='test';
 $disk=bundle($file,'2026-09-30',[$original]);$disk['version']=PaperRrAudit::VERSION;
 file_put_contents($dir.'/'.$file,PaperRrAudit::encode($disk));
 $observed=PaperFollowup::observations($dir)['records']['B@100'];
 $f['observation_hash']=$observed['observation_hash'];$f['source_file']=$file;
 check(PaperRejectionReview::load($dir,['rows'=>[$f]])['rr'][0]['followup_status']==='pending','disk observation hash matches existing tracker');
 file_put_contents($dir.'/20260930-130000-bbbbbbbbbbbb.json','broken');
 check(count(PaperRejectionReview::load($dir,null)['errors'])===1,'corrupt bundle visibly reported');
} finally {foreach(glob($dir.'/*') as $path)unlink($path);rmdir($dir);}
