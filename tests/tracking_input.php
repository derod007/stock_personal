<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/Followup.php';
require __DIR__.'/../bin/paper/Rediagnosis.php';
function ti(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "TRACKING PASS $label\n";}
$bars=[];$start=new DateTimeImmutable('2025-01-02 15:30:00',new DateTimeZone('Asia/Seoul'));
for($i=0;$i<145;$i++){$t=$start->modify('+'.$i.' weekdays')->getTimestamp();$c=$i<140?100:101+$i-140;
 $bars[]=['time'=>$t,'available_at'=>$t,'open'=>$c,'high'=>$c+2,'low'=>$c-2,'close'=>$c,'volume'=>1000];}
$old=array_slice($bars,0,140);$session=end($old)['available_at'];$now=end($bars)['available_at'];
$r=['symbol'=>'005930.KS','name'=>'fixture','status'=>'evaluated','session'=>$session,'bars'=>$old,
 'input_hash'=>hash('sha256',PaperRrAudit::encode($old)),
 'analysis'=>['decision'=>['reason'=>'005930.KS [account1]: waiting'], 'plan'=>['ready'=>false,'status'=>'rejected_rr']],
 'patterns'=>[['confirmed_rr_rejection'=>true,'status'=>'added','exclusion_reasons'=>[],
 'candidate'=>['ready'=>true,'entry'=>101,'stop'=>95,'target'=>106,'signal_at'=>$session,'order_valid_bars'=>3]]],
 'source_file'=>'fixture.json','captured_at'=>$session+60,'observation_hash'=>'fixture'];
$before=PaperRrAudit::encode($r);$baseline=PaperFollowup::evaluate($r,$bars,$now);
$tracking=array_slice($bars,5);$tracking[134]['volume']=999;$sourceBefore=PaperRrAudit::encode($tracking);
$x=PaperFollowup::evaluate($r,$tracking,$now);$a=$x['reconciliation'];
ti($x['status']==='pending'&&$x['horizons'][5]['status']==='complete','prefix and volume recovery produces mature results');
ti(count($a['prefix_preserved'])===5&&count($a['volume_differences'])===1,'both recoverable differences logged');
ti($a['volume_differences'][0]===['session'=>$session,'saved'=>1000,'tracking'=>999],'exact volume evidence retained');
ti($baseline['horizons']===$x['horizons'],'recovery returns equal full-history reference');
ti($baseline['trades'][0]['outcome']['net_return_pct']===$x['trades'][0]['outcome']['net_return_pct']&&$x['trades'][0]['outcome']['fee_bps_per_side']===10.0,'frozen limit and costed result preserved');
ti(PaperRrAudit::encode($r)===$before&&PaperRrAudit::encode($tracking)===$sourceBefore,'saved signal and source prices not mutated');
$bad=$tracking;$bad[134]['close']=99;
ti(PaperFollowup::evaluate($r,$bad,$now)['status']==='historical_revision_or_missing','overlapping OHLC change still blocked');
$bad=$bars;array_splice($bad,75,1);
ti(PaperFollowup::evaluate($r,$bad,$now)['status']==='historical_revision_or_missing','interior old gap not repaired');
ti(PaperFollowup::evaluate($r,array_slice($bars,100),$now)['status']==='historical_revision_or_missing','recent leading gap not repaired');
$bad=$bars;array_splice($bad,139,1);
ti(PaperFollowup::evaluate($r,$bad,$now)['status']==='historical_revision_or_missing','signal anchor required');
ti(PaperFollowup::evaluate($r,array_slice($bars,140),$now)['status']==='historical_revision_or_missing','future-only input cannot establish continuity');
$bad=$tracking;$bad[]=$tracking[0];
ti(PaperFollowup::evaluate($r,$bad,$now)['status']==='future_quality_blocked','duplicate historical raw bar not hidden by merge');
$bad=$tracking;$bad[count($bad)-1]['high']=0;
ti(PaperFollowup::evaluate($r,$bad,$now)['status']==='future_quality_blocked','invalid future OHLC blocked');
$bad=$tracking;$bad[134]['volume']=-1;
ti(PaperFollowup::evaluate($r,$bad,$now)['status']==='future_quality_blocked','invalid volume cannot be treated as permissible difference');
$bad=$tracking;$b=end($bad);$b['available_at']=$now+86400;$b['high']=0;$bad[]=$b;
ti(PaperFollowup::evaluate($r,$bad,$now)['horizons']===$x['horizons'],'future invalid bar past as-of is not observed');
$wrong=$r;$wrong['analysis_symbol']='000660.KS';
ti(PaperFollowup::evaluate($wrong,$bars,$now)['status']==='analysis_symbol_mismatch','explicit analysis ticker mismatch quarantined');
$wrong=$r;$wrong['symbol']='122630.KS';
$quarantine=PaperFollowup::evaluate($wrong,$bars,$now);
ti($quarantine['status']==='analysis_symbol_mismatch'&&$quarantine['horizons']===[]&&$quarantine['trades']===[],'legacy engine reason detects proxy ticker mismatch');
$unknown=$r;unset($unknown['analysis']['decision']);
ti(PaperFollowup::evaluate($unknown,$bars,$now)['reconciliation']['identity']['status']==='not_recorded','missing symbol provenance is not labelled verified');
$tampered=$r;$tampered['bars'][0]['volume']++;
ti(PaperFollowup::evaluate($tampered,$tracking,$now)['status']==='input_hash_mismatch','original hash guard retained');
ti(PaperFollowup::evaluate($r,$tracking,$session)['status']==='not_yet_observed','captured timestamp still enforced');
// New capture records the resolved symbol independently of the leader code.
$captureAnalysis=['plan'=>['data_asof'=>$session,'asof'=>$session,'status'=>'no_setup','diagnostics'=>['patterns'=>[]]],'features'=>[]];
$captured=PaperRrAudit::capture(['yahoo'=>'122630.KS'],['ok'=>true,'symbol'=>'005930.KS','research_input'=>['bars'=>$old,'analysis'=>$captureAnalysis]]);
ti($captured['analysis_symbol']==='005930.KS'&&PaperTrackingInput::identity($captured)['status']==='analysis_symbol_mismatch','capture records resolved ticker');
// Old invalid OHLC already excluded from the frozen input must not veto price-only observation.
$withOldError=$r;$withOldError['bars'][10]['high']=0;
$withOldError['input_hash']=hash('sha256',PaperRrAudit::encode($withOldError['bars']));
$withOldErrorPrices=$bars;$withOldErrorPrices[10]['high']=0;
$originalBytes=PaperRrAudit::encode($withOldError);$trackingBytes=PaperRrAudit::encode($withOldErrorPrices);
$restored=PaperFollowup::evaluate($withOldError,$withOldErrorPrices,$now);
ti($restored['horizons']===$baseline['horizons'],'previously excluded old OHLC does not suppress future returns');
ti($restored['trades'][0]['outcome']['net_return_pct']===$baseline['trades'][0]['outcome']['net_return_pct'],'old error exclusion preserves frozen costed trade');
$excluded=$restored['reconciliation']['excluded_old_invalid_bars'];
ti(count($excluded)===1&&$excluded[0]['session']===$old[10]['available_at']&&$excluded[0]['saved']['high']===0&&$excluded[0]['tracking']['high']===0,'excluded old error has both original values and timestamp');
ti(PaperRrAudit::encode($withOldError)===$originalBytes&&PaperRrAudit::encode($withOldErrorPrices)===$trackingBytes,'old invalid inputs remain immutable');
$bad=$bars;$bad[10]['high']=0;
$forged=$r;$forged['quality']=['can_simulate'=>true,'invalid_bars'=>[$old[10]['available_at']]];
ti(PaperFollowup::evaluate($forged,$bad,$now)['status']==='future_quality_blocked','newly invalid old bar cannot use forged quality metadata');
$bad=$withOldErrorPrices;$bad[]=$bad[10];
ti(PaperFollowup::evaluate($withOldError,$bad,$now)['status']==='future_quality_blocked','duplicate on an excluded date still blocked');
$recent=$r;$recent['bars'][130]['high']=0;$recent['input_hash']=hash('sha256',PaperRrAudit::encode($recent['bars']));
$bad=$bars;$bad[130]['high']=0;
ti(PaperFollowup::evaluate($recent,$bad,$now)['status']==='future_quality_blocked','recent original and tracking errors remain blocked');
$bad=$withOldErrorPrices;$bad[142]['high']=0;
ti(PaperFollowup::evaluate($withOldError,$bad,$now)['status']==='future_quality_blocked','future error never inherits old exclusion');
$volumeOnly=$r;$volumeOnly['bars'][10]['volume']=-1;$volumeOnly['input_hash']=hash('sha256',PaperRrAudit::encode($volumeOnly['bars']));
$bad=$bars;$bad[10]['volume']=-1;
ti(PaperFollowup::evaluate($volumeOnly,$bad,$now)['status']==='future_quality_blocked','valid OHLC with bad volume was not an excluded candle');
$bad=$withOldErrorPrices;$bad[100]['close']=99;
ti(PaperFollowup::evaluate($withOldError,$bad,$now)['status']==='historical_revision_or_missing','old exclusion does not hide a price revision elsewhere');
// Use the actual regression shape for the offline CLI fixture below.
$r=$withOldError;$tracking=$withOldErrorPrices;
// Offline recovery uses immutable provider evidence and upgrades old completed caches exactly once.
$root=sys_get_temp_dir().'/tracking-recovery-'.bin2hex(random_bytes(5));mkdir($root);
$prior=getenv('PAPER_STATE_DIR');putenv('PAPER_STATE_DIR='.$root);
$remove=function($dir)use(&$remove){foreach(scandir($dir) as $f){if($f==='.'||$f==='..')continue;$p=$dir.'/'.$f;is_dir($p)?$remove($p):unlink($p);}rmdir($dir);};
try{
 mkdir($root.'/rr-audit/test',0770,true);mkdir($root.'/followup/test/evidence',0770,true);
 $file='20250801-000000-aaaaaaaaaaaa.json';$bundle=['version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only','recorded_at'=>$session+60,'records'=>[$r]];
 $path=$root.'/rr-audit/test/'.$file;file_put_contents($path,PaperRrAudit::encode($bundle));$savedHash=hash_file('sha256',$path);
 $obs=PaperFollowup::observations($root.'/rr-audit/test')['records'];$key=array_key_first($obs);$record=$obs[$key];
 $priceHash=hash('sha256',PaperRrAudit::encode($tracking));$evidence=$root.'/followup/test/evidence/'.$priceHash.'.json';file_put_contents($evidence,PaperRrAudit::encode($tracking));
 $legacy=PaperFollowup::evaluate($record,$tracking,$now);$legacy['tracking_policy']='frozen_signal_price_tracking_v2';
 $legacy['complete']=true;$legacy['status']='historical_revision_or_missing';$legacy['horizons']=[];$legacy['trades']=[];$legacy['price_hash']=$priceHash;
 $previous=['schema'=>1,'account'=>'test','as_of'=>$now,'rows'=>[$key=>$legacy]];
 file_put_contents($root.'/followup/test/latest.json',PaperRrAudit::encode($previous));
 $cmd=escapeshellarg(PHP_BINARY).' -n '.escapeshellarg(__DIR__.'/../bin/paper_followup.php').' --account=test --saved-evidence';
 exec($cmd.' 2>&1',$lines,$code);ti($code===0,'offline evidence CLI: '.implode("\n",$lines));
 $report=PaperFollowup::load($root,'test');$row=$report['rows'][$key];
 ti($row['tracking_policy']===PaperTrackingInput::POLICY&&$row['horizons'][5]['status']==='complete','old completed cache recalculated under new policy');
 ti($row['as_of']===$now&&$report['as_of']===$now,'saved evidence cutoff preserved');
 ti(hash_file('sha256',$path)===$savedHash&&hash_file('sha256',$evidence)===$priceHash,'audit and price archive immutable');
 ti(count(glob($root.'/followup/test/*-*.json'))===1,'followup publishes new history');
 file_put_contents($evidence,'[]');$lines=[];exec($cmd.' 2>&1',$lines,$code);
 ti(PaperFollowup::load($root,'test')['rows'][$key]['status']==='price_or_evaluation_error','tampered offline evidence rejected');
 require __DIR__.'/../bin/paper/FollowupPanel.php';ob_start();paper_followup_panel($report);$html=ob_get_clean();
 ti(str_contains($html,'입력 정합 처리')&&str_contains($html,'--saved-evidence'),'recovery visible in dashboard');
}finally{putenv($prior===false?'PAPER_STATE_DIR':'PAPER_STATE_DIR='.$prior);$remove($root);}
