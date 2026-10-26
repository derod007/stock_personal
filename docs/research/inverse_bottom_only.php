<?php
declare(strict_types=1);
require_once __DIR__.'/InverseBottomOnly.php';
$a=getopt('',['input:','candidates:','output:']);
foreach(['input','candidates','output'] as $k)if(!isset($a[$k]))throw new RuntimeException('Missing '.$k);
$dir=$a['input'];$m=json_decode(file_get_contents($dir.'/input.json'),true,512,JSON_THROW_ON_ERROR);
if(hash_file('sha256',$a['candidates'])!==$m['hashes']['candidates'])throw new RuntimeException('Candidate hash mismatch');
$c=json_decode(file_get_contents($a['candidates']),true,512,JSON_THROW_ON_ERROR);$days=$c['days'];
if($days!==$m['days'])throw new RuntimeException('Calendar mismatch');
$raw=[];$hashes=[];$scores=[];$records=[];$last=[];$counts=[];$cache=[];
foreach($days as $i=>$day){
    foreach($c['dates'][$day]['rows'] as $row){
        $sym=$row['symbol'];
        if(!isset($raw[$sym])){
            $f=$dir.'/bars/'.$sym.'.json';$h=hash_file('sha256',$f);
            if($h!==$m['hashes']['bars'][$sym])throw new RuntimeException('Bar hash mismatch '.$sym);
            $raw[$sym]=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR)['rows'];$hashes[$f]=$h;
        }
        $events=$m['reference_events'][$sym]??[];$ev=InverseBottomOnly::evaluate($raw[$sym],$day,$events);
        $scores[]=['symbol'=>$sym,'day'=>$day,'evaluation'=>$ev];
        if(!$ev['eligible'])continue;
        if($i+20>=count($days)){ $counts['period_end']=($counts['period_end']??0)+1;continue; }
        foreach(['all'=>0,'score5plus'=>5,'score12'=>12] as $group=>$cut){
            if($ev['score']<$cut||$i<=($last[$group][$sym]??-1))continue;
            $last[$group][$sym]=$i+20;$key=$sym.'|'.$day;
            if(!isset($cache[$key])){
                $s=['symbol'=>$sym,'anchor'=>$day,'entry_day'=>$days[$i+1],'end'=>$days[$i+20],'entry'=>$ev['close'],'stop'=>$ev['close']*.95,'target'=>$ev['close']*1.15,'order_valid_bars'=>1];
                $cache[$key]=['signal'=>$s,'evaluation'=>$ev,'result'=>IndependentTradeDiagnostic::run($s,$days,$raw[$sym],$events,$hashes[$dir.'/bars/'.$sym.'.json']), 'path'=>InverseBottomOnly::path($raw[$sym],$days,$i,$events)];
            }
            $records[]=['group'=>$group]+$cache[$key];
        }
    }
    if($i%40===0)fwrite(STDERR,"Processed ".($i+1)."/".count($days)." sessions\n");
}
foreach($hashes as $f=>$h)if(hash_file('sha256',$f)!==$h)throw new RuntimeException('Input mutated');
if(is_dir($a['output']))throw new RuntimeException('Fresh output required');mkdir($a['output'],0777,true);
$out=['price_basis'=>$m['price_basis'],'account_execution'=>false,'counts'=>$counts,'scores'=>$scores,'records'=>$records,
    'hashes'=>['candidates'=>hash_file('sha256',$a['candidates']),'input'=>hash_file('sha256',$dir.'/input.json'),'detector'=>hash_file('sha256',dirname(__DIR__,2).'/src/NoramuTopPattern.php'),'bars'=>$hashes]];
file_put_contents($a['output'].'/results.json',json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo json_encode(['observations'=>count($scores),'trades'=>count($records),'distinct_simulations'=>count($cache)])."\n";
