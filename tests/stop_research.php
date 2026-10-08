<?php
declare(strict_types=1);
require __DIR__.'/fixtures/HistoricalResearchBaseline.php'; // Frozen historical research baseline.
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/StopResearch.php';
require __DIR__.'/../bin/paper/StopResearchPanel.php';
use ChartEntryLab\CandleClock;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$bars=[];foreach(range(1,15) as $i)$bars[]=['available_at'=>$i,'open'=>100,'close'=>100,'low'=>99,'high'=>102,'volume'=>100];
$bars[9]['high']=110;$bars[9]['close']=108;$bars[10]['low']=97;$bars[10]['close']=98;
$seg=PaperStopResearch::segment($bars,2);
check($seg['status']==='defined'&&$seg['peak_session']===10&&$seg['low']===97,'confirmed peak and post-peak low');
$copy=$bars;$copy[8]['low']=50;
check(PaperStopResearch::segment($copy,2)['low']===97,'pre-peak extreme excluded');
$copy=$bars;$copy[14]['high']=120;
check(PaperStopResearch::segment($copy,2)['peak_session']===10,'last candle high cannot become unconfirmed pivot');
$copy=$bars;$copy[10]['high']=110;
check(PaperStopResearch::segment($copy,2)['status']==='no_confirmed_peak','equal peak plateau not guessed');
$copy=$bars;$copy[9]['high']=102;
check(PaperStopResearch::segment($copy,2)['status']==='no_confirmed_peak','no pivot no alternative stop');
check(PaperStopResearch::segment($bars,100)['status']==='insufficient_pullback','minimum ATR retracement enforced');
$copy=$bars;foreach($copy as &$b)$b['close']=100;unset($b);
check(PaperStopResearch::segment($copy,2)['status']==='insufficient_pullback','declining close before decision required');
$copy=$bars;$copy[]=['available_at'=>16,'open'=>100,'close'=>90,'high'=>130,'low'=>80,'volume'=>100];
$cut=CandleClock::completed($copy,'TEST',15);
check(PaperStopResearch::segment($cut,2)===$seg,'future bar excluded before pivot selection');
$base=['entry'=>100.0,'stop'=>80.0,'target'=>110.0,'signal_at'=>15,'order_valid_bars'=>3];
$context=['context_applied'=>true,'context'=>['daily'=>'up','weekly'=>'up']];
$p=PaperStopResearch::reprice($base,95,$context);
check($p['status']==='eligible'&&$p['entry']===100.0&&$p['target']===110.0&&$p['signal_at']===15&&$p['order_valid_bars']===3,'only stop and RR change');
check(PaperStopResearch::reprice($base,100,$context)['status']==='invalid_levels','stop at entry rejected');
$context['context']['daily']='down';
check(PaperStopResearch::reprice($base,95,$context)['status']==='context_wait','trend guard unchanged');
$context['context']=['daily'=>'up','weekly'=>'down'];
check(PaperStopResearch::reprice($base,94,$context)['status']==='context_wait','weekly RR 2 still enforced');
$root=dirname(__DIR__).'/docs/paper-kr-5d-source';$before=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $f)if($f->isFile())$before[$f->getPathname()]=hash_file('sha256',$f->getPathname());
$r=PaperStopResearch::run($root,'paper-kr');
check($r['errors']===[],'exact saved evidence joins');
check($r['summary']['single_gate_hypothesis']['records']===13,'13-case hypothesis cohort kept separate');
check($r['summary']['confirmed']['records']>0,'confirmed cohort present');
foreach($r['rows'] as $row){
    if($row['status']!=='compared')continue;
    $b=$row['baseline']['plan'];$a=$row['recent_pullback']['plan'];
    check($b['entry']===$a['entry']&&$b['target']===$a['target']&&$b['signal_at']===$a['signal_at'],'fixed levels '.$row['date'].' '.$row['symbol']);
    check($a['stop']>=$b['stop'],'recent segment does not widen stop '.$row['symbol']);
    foreach(['baseline','recent_pullback'] as $arm){$t=$row[$arm]['outcome'];if(!empty($t['filled']))check($t['entry_at']>$row['session'],'entry only after decision');}
}
$temp=sys_get_temp_dir().'/stop-research-'.bin2hex(random_bytes(6));mkdir($temp);
try{
    $cmd=escapeshellarg(PHP_BINARY).' -d auto_prepend_file='.escapeshellarg(__DIR__.'/fixtures/HistoricalResearchBaseline.php').' '.escapeshellarg(dirname(__DIR__).'/bin/paper_stop_research.php').' --source-dir='.escapeshellarg($root).' --output-dir='.escapeshellarg($temp.'/stop-research/paper-kr');
    exec($cmd,$lines,$exit);check($exit===0,'CLI success');
    $saved=json_decode(file_get_contents($temp.'/stop-research/paper-kr/latest.json'),true);
    // JSON persistence normalizes integral floats (e.g. 0.0) to integers.
    $expected=json_decode(PaperRrAudit::encode($r['summary']),true,512,JSON_THROW_ON_ERROR);
    check($saved['summary']===$expected,'CLI results match after JSON round trip');
    $saved['rows'][0]['name']='<script>bad</script>';file_put_contents($temp.'/stop-research/paper-kr/latest.json',json_encode($saved));
    ob_start();paper_stop_research_panel($temp,'paper-kr');$html=ob_get_clean();
    check(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'safe UI labels');
    foreach($before as $path=>$hash)if(hash_file('sha256',$path)!==$hash)throw new RuntimeException('Source mutated');
    check(true,'all source bytes preserved');
}finally{
    foreach(glob($temp.'/stop-research/paper-kr/*')?:[] as $f)unlink($f);
    rmdir($temp.'/stop-research/paper-kr');rmdir($temp.'/stop-research');rmdir($temp);
}
echo 'STOP_REPORT='.PaperRrAudit::encode($r)."\n";
