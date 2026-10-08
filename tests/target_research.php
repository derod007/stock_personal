<?php
declare(strict_types=1);
require __DIR__.'/fixtures/HistoricalResearchBaseline.php'; // Frozen historical research baseline.
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/TargetResearch.php';
require __DIR__.'/../bin/paper/TargetResearchPanel.php';
function target_check(bool $v,string $name):void{if(!$v)throw new RuntimeException($name);echo "PASS $name\n";}
$bars=[];foreach(range(1,30) as $i)$bars[]=['available_at'=>$i,'open'=>100,'high'=>102,'low'=>98,'close'=>100];
$bars[4]['high']=130;$bars[14]['high']=110;
$r=PaperTargetResearch::inspect($bars,'TEST',30,100,95,110);
target_check($r['target_matches_formula']&&$r['target_sessions']===[15],'20 prior bars exclude older peak');
target_check(array_column($r['upper_resistance_candidates'],'price')===[110.0,130.0],'nearest overhead first including older saved pivot');
$copy=$bars;$copy[29]['high']=115;$copy[29]['close']=112;
$r=PaperTargetResearch::inspect($copy,'TEST',30,112,95,110);
target_check($r['decision_relation']==='closed_above'&&$r['target_vs_entry']==='below_entry','breakout explains target below entry');
target_check($r['nearest_upper_resistance']['price']===130.0,'broken lower peak excluded');
$copy[29]['close']=110;
target_check(PaperTargetResearch::inspect($copy,'TEST',30,110,95,110)['decision_relation']==='closed_at','equality not strict breakout');
$copy[29]['close']=100;
$r=PaperTargetResearch::inspect($copy,'TEST',30,100,95,110);
target_check($r['decision_relation']==='wick_above_close_below'&&$r['nearest_upper_resistance']['test_sessions']===[30],'intraday breach kept distinct from close breakout');
$copy=$bars;$copy[]=['available_at'=>31,'open'=>100,'high'=>200,'low'=>90,'close'=>180];
target_check(PaperTargetResearch::inspect($copy,'TEST',30,100,95,110)===PaperTargetResearch::inspect($bars,'TEST',30,100,95,110),'future prices cannot alter diagnosis');
$copy=$bars;$copy[28]['high']=150;
target_check(!in_array(150.0,array_column(PaperTargetResearch::inspect($copy,'TEST',30,100,95,150)['upper_resistance_candidates'],'price'),true),'last two bars cannot confirm pivot');
$copy=$bars;$copy[15]['high']=110;
target_check(PaperTargetResearch::inspect($copy,'TEST',30,100,95,110)['nearest_upper_resistance']['price']===130.0,'plateau not invented as strict pivot');
$copy=$bars;$copy[8]['high']=140;$copy[8]['close']=135;
target_check(!in_array(130.0,array_column(PaperTargetResearch::inspect($copy,'TEST',30,100,95,110)['upper_resistance_candidates'],'price'),true),'previously close-broken peak excluded after price falls back');
target_check(!PaperTargetResearch::inspect($bars,'TEST',30,100,95,111)['target_matches_formula'],'wrong target detected');
target_check(PaperTargetResearch::inspect(array_slice($bars,0,20),'TEST',20,100,95,110)['status']==='insufficient_original_bars','short input not guessed');
$root=dirname(__DIR__).'/docs/paper-kr-5d-source';$before=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $f)if($f->isFile())$before[$f->getPathname()]=hash_file('sha256',$f->getPathname());
$report=PaperTargetResearch::run($root,'paper-kr');
target_check(!$report['errors'],'saved evidence joins');
target_check($report['summary']['confirmed']['records']===29&&$report['summary']['single_gate_hypothesis']['records']===13,'both frozen cohorts including exclusions retained');
foreach($report['rows'] as $row){
    target_check($row['status']==='diagnosed','replay and diagnosis '.$row['symbol']);
    target_check(max(array_column($row['bars'],'available_at'))===$row['session'],'original chart cutoff '.$row['symbol']);
    target_check($row['target_matches_formula']&&$row['original_levels_match']!==false,'original price formula '.$row['symbol']);
}
$temp=sys_get_temp_dir().'/target-study-'.bin2hex(random_bytes(5));mkdir($temp);
try{
    $cmd=escapeshellarg(PHP_BINARY).' -d auto_prepend_file='.escapeshellarg(__DIR__.'/fixtures/HistoricalResearchBaseline.php').' '.escapeshellarg(dirname(__DIR__).'/bin/paper_target_research.php').' --source-dir='.escapeshellarg($root).' --output-dir='.escapeshellarg($temp.'/target-research/paper-kr');
    exec($cmd,$lines,$exit);target_check($exit===0,'CLI success');
    $saved=json_decode(file_get_contents($temp.'/target-research/paper-kr/latest.json'),true);
    target_check($saved['summary']===json_decode(PaperRrAudit::encode($report['summary']),true),'saved CLI summary matches');
    $saved['rows'][0]['name']='<script>bad</script>';file_put_contents($temp.'/target-research/paper-kr/latest.json',PaperRrAudit::encode($saved));
    ob_start();paper_target_research_panel($temp,'paper-kr');$html=ob_get_clean();
    target_check(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;')&&str_contains($html,'<svg'),'escaped names and original candlestick charts');
    foreach($before as $path=>$hash)target_check(hash_file('sha256',$path)===$hash,'source preserved '.basename($path));
}finally{
    foreach(glob($temp.'/target-research/paper-kr/*')?:[] as $f)unlink($f);
    rmdir($temp.'/target-research/paper-kr');rmdir($temp.'/target-research');rmdir($temp);
}
echo 'TARGET_REPORT='.PaperRrAudit::encode($report)."\n";
