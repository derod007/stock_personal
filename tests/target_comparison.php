<?php
declare(strict_types=1);
require __DIR__.'/fixtures/HistoricalResearchBaseline.php'; // Frozen historical research baseline.
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/TargetComparison.php';
require __DIR__.'/../bin/paper/TargetComparisonPanel.php';
function tc(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
$b=['entry'=>100.0,'stop'=>90.0,'target'=>95.0,'signal_at'=>10,'order_valid_bars'=>3];
$c=['context_applied'=>true,'context'=>['daily'=>'up','weekly'=>'up']];$a=['failed'=>['rr'],'unknown'=>[]];
$p=PaperTargetComparison::plans($b,$c,$a,['price'=>120]);
tc($p['alternative']['ready']&&!$p['baseline']['ready'],'only alternative eligible');
foreach(['entry','stop','signal_at','order_valid_bars'] as $k)tc($p['alternative'][$k]===$b[$k],'fixed '.$k);
tc(PaperTargetComparison::plans($b,$c,['failed'=>['rr','spike_dump'],'unknown'=>[]],['price'=>120])['status']==='independent_blockers','risk retained despite high RR');
tc(PaperTargetComparison::plans($b,$c,['failed'=>['rr'],'unknown'=>['fresh_confirmation']],['price'=>120])['status']==='independent_blockers','missing gates not passed');
tc(PaperTargetComparison::plans($b,$c,$a,null)['status']==='no_upper_candidate','no target invented');
tc(!PaperTargetComparison::plans($b,$c,$a,['price'=>110])['alternative']['ready'],'nearest low RR remains rejected');
$c['context']['daily']='down';tc(PaperTargetComparison::plans($b,$c,$a,['price'=>120])['alternative']['status']==='context_wait','daily guard retained');
$c['context']=['daily'=>'up','weekly'=>'down'];tc(!PaperTargetComparison::plans($b,$c,$a,['price'=>117])['alternative']['ready'],'weekly requires RR 2');
tc(PaperTargetComparison::plans($b,$c,['failed'=>['rr','context_wait'],'unknown'=>[]],['price'=>120])['alternative']['ready'],'same weekly rule reevaluated with new RR');
tc(!PaperTargetComparison::plans($b,[],$a,['price'=>120])['alternative']['ready'],'missing context fails closed');
$root=dirname(__DIR__).'/docs/paper-kr-5d-source';$before=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $f)if($f->isFile())$before[$f->getPathname()]=hash_file('sha256',$f->getPathname());
$r=PaperTargetComparison::run($root,'paper-kr');tc($r['errors']===[]&&$r['summary']['records']===13,'all 13 selected regardless of outcome');
foreach($r['rows'] as $row){if($row['status']!=='compared')continue;
    $b=$row['baseline']['plan'];$a=$row['alternative']['plan'];
    foreach(['entry','stop','signal_at','order_valid_bars'] as $k)tc($a[$k]===$b[$k],'frozen row '.$k);
    tc($a['target']==ChartEntryLab\PriceCandidate::trunc($row['nearest_upper_resistance']['price']),'nearest target used');
    foreach(['baseline','alternative'] as $arm){$t=$row[$arm]['outcome'];if(!empty($t['filled']))tc($t['entry_at']>$row['session'],'no same-day fill');}
}
$temp=sys_get_temp_dir().'/target-comparison-'.bin2hex(random_bytes(5));mkdir($temp);
try{
    exec(escapeshellarg(PHP_BINARY).' -d auto_prepend_file='.escapeshellarg(__DIR__.'/fixtures/HistoricalResearchBaseline.php').' '.escapeshellarg(dirname(__DIR__).'/bin/paper_target_comparison.php').' --source-dir='.escapeshellarg($root).' --output-dir='.escapeshellarg($temp.'/target-comparison/paper-kr'),$lines,$exit);
    tc($exit===0,'CLI success');$saved=json_decode(file_get_contents($temp.'/target-comparison/paper-kr/latest.json'),true);
    tc($saved['summary']===json_decode(PaperRrAudit::encode($r['summary']),true),'CLI matches');
    $saved['rows'][0]['name']='<script>bad</script>';file_put_contents($temp.'/target-comparison/paper-kr/latest.json',PaperRrAudit::encode($saved));
    ob_start();paper_target_comparison_panel($temp,'paper-kr');$html=ob_get_clean();tc(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'safe panel');
    foreach($before as $path=>$hash)if(hash_file('sha256',$path)!==$hash)throw new RuntimeException('source mutated');tc(true,'source preserved');
}finally{foreach(glob($temp.'/target-comparison/paper-kr/*')?:[] as $f)unlink($f);rmdir($temp.'/target-comparison/paper-kr');rmdir($temp.'/target-comparison');rmdir($temp);}
echo 'TARGET_COMPARISON='.PaperRrAudit::encode($r)."\n";
