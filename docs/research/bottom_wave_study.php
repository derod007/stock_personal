<?php
declare(strict_types=1);
require_once __DIR__.'/IndependentTradeDiagnostic.php';
use ChartEntryLab\BottomWaveReview;
$a=getopt('',['input:','candidates:','output:']);foreach(['input','candidates','output'] as $k)if(!isset($a[$k]))throw new RuntimeException('Missing '.$k);
$dir=$a['input'];$m=json_decode(file_get_contents($dir.'/input.json'),true,512,JSON_THROW_ON_ERROR);
if(hash_file('sha256',$a['candidates'])!==$m['hashes']['candidates'])throw new RuntimeException('Candidate mismatch');
$c=json_decode(file_get_contents($a['candidates']),true,512,JSON_THROW_ON_ERROR);$days=$c['days'];$indices=array_flip($days);$members=[];
foreach($c['dates'] as $day=>$d)foreach($d['rows'] as $r)$members[$r['symbol']][$day]=true;
$counts=[];$records=[];$cases=[];$hashes=[];$n=0;
foreach($members as $sym=>$dates){
    $f=$dir.'/bars/'.$sym.'.json';$sha=hash_file('sha256',$f);if($sha!==$m['hashes']['bars'][$sym])throw new RuntimeException('Bar mismatch');$hashes[$f]=$sha;
    $raw=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR)['rows'];$refs=$m['reference_events'][$sym]??[];
    $review=(new BottomWaveReview())->analyze($raw,$sym,IndependentTradeDiagnostic::stamp(end($days)),$refs);$last=-1;
    foreach($review['events'] as $event){
        $d=(new DateTimeImmutable('@'.$event['confirmed_at']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
        if(!isset($indices[$d]))continue;$counts['events'][$event['status']]=($counts['events'][$event['status']]??0)+1;
        if($event['status']!=='breakout_confirmed')continue;
        $reason=null;$i=$indices[$d];
        if(!($event['signal_eligible']??false))$reason='signal_ineligible';
        elseif(!isset($dates[$d]))$reason='not_top100_on_signal';
        elseif($i+20>=count($days))$reason='period_end';
        elseif($i<=$last)$reason='overlap';
        $cases[]=['symbol'=>$sym,'day'=>$d,'event'=>$event,'exclusion'=>$reason];
        if($reason!==null){$counts['excluded'][$reason]=($counts['excluded'][$reason]??0)+1;continue;}
        $last=$i+20;$s=$event['setup'];$sig=['symbol'=>$sym,'anchor'=>$d,'entry_day'=>$days[$i+1],'end'=>$days[$i+20],
            'entry'=>$event['entry_limit'],'stop'=>$s['stop'],'target'=>$s['target'],'order_valid_bars'=>1];
        $records[]=['signal'=>$sig,'event'=>$event,'result'=>IndependentTradeDiagnostic::run($sig,$days,$raw,$refs,$sha)];
    }
    if(hash_file('sha256',$f)!==$sha)throw new RuntimeException('Input changed');
    if(++$n%300===0)fwrite(STDERR,"Reviewed $n symbols\n");
}
if(is_dir($a['output']))throw new RuntimeException('Fresh output required');mkdir($a['output'],0777,true);
file_put_contents($a['output'].'/results.json',json_encode(['account_execution'=>false,'price_basis'=>$m['price_basis'],'rules'=>BottomWaveReview::RULES,
    'counts'=>$counts,'records'=>$records,'cases'=>$cases,'hashes'=>['bars'=>$hashes,'input'=>hash_file('sha256',$dir.'/input.json'),'detector'=>hash_file('sha256',dirname(__DIR__,2).'/src/BottomWaveReview.php')]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo json_encode(['counts'=>$counts,'selected'=>count($records)])."\n";
