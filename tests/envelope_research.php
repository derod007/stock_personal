<?php
declare(strict_types=1);
require __DIR__.'/fixtures/SpikeDumpV1.php'; // Frozen historical research baseline.
require __DIR__.'/../bin/bootstrap.php';require __DIR__.'/../bin/paper/EnvelopeResearchPanel.php';
function er(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function record(array $bars):array{
    $r=['symbol'=>'000001.KS','name'=>'<test>','session'=>end($bars)['available_at'],'captured_at'=>end($bars)['available_at']+60,
        'bars'=>$bars,'input_hash'=>hash('sha256',PaperRrAudit::encode($bars)),
        'analysis'=>['plan'=>['ready'=>false,'status'=>'await_confirmation']], 'patterns'=>[], 'source_file'=>'test.json'];
    $r['observation_hash']=hash('sha256',PaperRrAudit::encode($r));return $r;
}
$bars=[];$start=1700000000;
for($i=0;$i<260;$i++)$bars[]=['available_at'=>$start+$i*86400,'open'=>100,'high'=>101,'low'=>99,'close'=>100,'volume'=>10000];
$bars[259]['low']=91;$r=record($bars);$v=PaperEnvelopeResearch::inspect($r);
er(abs($v['lower']-91)<1e-9&&abs($v['ma240']-100)<1e-9&&abs($v['gap60_240_pct'])<1e-9,'SMA and exact lower touch');
er($v['zone']==='lower_touch'&&$v['episode']==='new_episode'&&$v['first_in_saved_range'],'first saved range event');
er($v['close_reclaimed_lower']&&$v['higher_confirmed_low']===null,'reclaim distinct from unknown pivot');
$bars[258]['low']=90;$r2=record($bars);$v2=PaperEnvelopeResearch::inspect($r2);
er($v2['episode']==='continuing_episode'&&$v2['episodes_in_last_bars'][5]===1,'consecutive dwell not second event');
$bars[256]['low']=90;$v3=PaperEnvelopeResearch::inspect(record($bars));
er($v3['episodes_in_last_bars'][5]===2&&$v3['bars_between_episode_starts']===2&&$v3['bars_since_previous_episode_end']===1,'full bar escape then repeat episode');
$gap=$bars;$gap[259]=array_replace($gap[259],['open'=>80,'high'=>82,'low'=>79,'close'=>81]);$g=PaperEnvelopeResearch::inspect(record($gap));
er($g['zone']==='below_lower'&&!$g['lower_touch'],'whole candle below band is not touch');
$future=$r;$future['bars'][]=['available_at'=>$r['session']+86400,'open'=>200,'high'=>201,'low'=>199,'close'=>200,'volume'=>1];$future['input_hash']=hash('sha256',PaperRrAudit::encode($future['bars']));
$futureFeatures=PaperEnvelopeResearch::inspect($future);$expected=$v;
unset($futureFeatures['quality']['source'],$expected['quality']['source']);
er($futureFeatures===$expected,'future candle cannot alter frozen features');
$short=PaperEnvelopeResearch::inspect(record(array_slice($bars,-239)));er($short['status']==='insufficient_history','239 bars never fabricate MA240');
$pivot=$bars;$pivot[250]['low']=85;$pivot[254]['low']=88;$pivot[258]['low']=80;
$pv=PaperEnvelopeResearch::inspect(record($pivot));er($pv['higher_confirmed_low']===true&&end($pv['confirmed_low_pivots'])['session']===$pivot[254]['available_at'],'last two bars never confirm pivot');
$bad=$r;$bad['bars'][259]['close']=110;
try{PaperEnvelopeResearch::inspect($bad);er(false,'hash must block');}catch(RuntimeException $e){er($e->getMessage()==='input_hash_mismatch','changed original hash rejected');}
$now=$r['captured_at'];$key=$r['symbol'].'@'.$r['session'];$exec=PaperEnvelopeResearch::executionVersion();
$report=['schema'=>1,'kind'=>PaperEnvelopeResearch::KIND,'account'=>'paper-kr','rows'=>[],'errors'=>[],'added'=>0,'as_of'=>$now,'generated_at'=>$now];
$obs=['records'=>[$key=>$r],'errors'=>[],'duplicates'=>0,'unavailable'=>0];
$report=PaperEnvelopeResearch::register($report,$obs,$now,$exec);$again=PaperEnvelopeResearch::register($report,$obs,$now+100,$exec);
er($again['rows']===$report['rows']&&$again['added']===1,'duplicate no replacement');
$conflict=$obs;$conflict['records'][$key]['observation_hash']=str_repeat('a',64);
er(count(PaperEnvelopeResearch::register($report,$conflict,$now,$exec)['errors'])===1,'changed source quarantined');
$original=$report['rows'][$key];$raw=$r['bars'];
for($i=1;$i<=20;$i++)$raw[]=['available_at'=>$r['session']+$i*86400,'open'=>100+$i,'high'=>102+$i,'low'=>99+$i,'close'=>100+$i,'volume'=>10000];
$hash=hash('sha256',PaperRrAudit::encode($raw));$at=$r['session']+3*86400;
$provider=fn()=>['raw'=>$raw,'as_of'=>$at,'price_hash'=>$hash];
$tracked=PaperEnvelopeResearch::refresh($original,$provider,$exec,$at);
er($tracked['status']==='pending'&&$tracked['observed_bars']===3&&$tracked['latest_session']===$at,'three completed future bars only');
er(abs($tracked['horizons'][3]['return_pct']-3)<1e-9&&$tracked['horizons'][5]['return_pct']===null,'completed horizon vs missing');
$failed=PaperEnvelopeResearch::refresh($tracked,fn()=>throw new RuntimeException('offline'),$exec,$at+100);
er($failed['status']==='update_error'&&$failed['horizons']===$tracked['horizons']&&$failed['last_success_at']===$tracked['last_success_at'],'failure preserves last success');
er(PaperEnvelopeResearch::refresh($tracked,$provider,'different',$at)['status']==='update_error','changed research version blocked');
$at20=$r['session']+20*86400;$complete=PaperEnvelopeResearch::refresh($tracked,fn()=>['raw'=>$raw,'as_of'=>$at20,'price_hash'=>$hash],$exec,$at20);
er($complete['status']==='complete','20 bars terminal');
er(PaperEnvelopeResearch::refresh($complete,fn()=>throw new RuntimeException('no fetch'),$exec,$at20+86400)===$complete,'terminal immutable');
$summary=PaperEnvelopeResearch::summarize([$tracked]);$first=array_values(array_filter($summary['groups'],fn($g)=>$g['cohort']==='first_in_saved_range'))[0];
er($first['horizons'][3]['n']===1&&$first['horizons'][5]['n']===0,'cohort counts only complete samples');
// Frozen real sources exercise identity/quality failures, partial histories and evidence joins.
$root=dirname(__DIR__).'/docs/paper-kr-5d-source';$tmp=sys_get_temp_dir().'/envelope-'.bin2hex(random_bytes(5));mkdir($tmp);putenv('PAPER_STATE_DIR='.$tmp);
try{
    $cmd=escapeshellarg(PHP_BINARY).' -d auto_prepend_file='.escapeshellarg(__DIR__.'/fixtures/SpikeDumpV1.php').' -d memory_limit=512M '.escapeshellarg(dirname(__DIR__).'/bin/paper_envelope_research.php').' --account=paper-kr --source-dir='.escapeshellarg($root).' --saved-evidence --as-of=2026-10-01T04:02:43+09:00';
    exec($cmd,$lines,$exit);er($exit===0,'offline CLI');$saved=PaperEnvelopeStore::load($tmp,'paper-kr');
    er(count($saved['rows'])>400,'full evaluated scan cohort not selected 13');
    er(count(array_filter($saved['rows'],fn($x)=>isset($x['latest_session'])))>0,'saved prices yield actual latest bars');
    $cmd=escapeshellarg(PHP_BINARY).' -d auto_prepend_file='.escapeshellarg(__DIR__.'/fixtures/SpikeDumpV1.php').' -d memory_limit=512M '.escapeshellarg(dirname(__DIR__).'/bin/paper_envelope_research.php').' --account=paper-kr --saved-evidence --as-of=2026-10-01T04:02:43+09:00';
    exec($cmd,$more,$exit);er($exit===0,'refresh without source archive');$again=PaperEnvelopeStore::load($tmp,'paper-kr');
    er(array_column($again['rows'],'frozen_hash')===array_column($saved['rows'],'frozen_hash'),'archive-free retention and fixed evidence');
    $lock=fopen($tmp.'/envelope-research/paper-kr/update.lock','c');flock($lock,LOCK_EX);exec($cmd.' 2>/dev/null',$ignore,$exit);flock($lock,LOCK_UN);fclose($lock);er($exit!==0,'concurrent writer blocked');
    ob_start();paper_envelope_research_panel($tmp,'paper-kr');$html=ob_get_clean();er(str_contains($html,'엔벨로프 눌림 관찰 연구')&&str_contains($html,'완료봉'),'research panel');
    echo 'ENVELOPE_SUMMARY='.PaperRrAudit::encode(['retained'=>count($saved['rows']),'statuses'=>$saved['summary']['statuses'],'errors'=>count($saved['errors'])])."\n";
}finally{
    putenv('PAPER_STATE_DIR');$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($tmp);
}
