<?php
declare(strict_types=1);
require __DIR__.'/paper/PullbackSegmentPilot.php';
$cmd=$argv[1]??'';$o=[];
foreach(array_slice($argv,2) as $a){
    if(!preg_match('/^--([a-z-]+)=(.*)$/s',$a,$m))throw new InvalidArgumentException('Unknown argument '.$a);
    $o[$m[1]]=$m[2];
}
$need=fn(string $k)=>$o[$k]??throw new InvalidArgumentException('--'.$k.' is required');
if($cmd==='select'){
    $result=PaperPullbackSegmentPilot::select(rtrim(str_replace('\\','/',$need('pack')),'/'),$need('zip'),$need('commit'));
    PaperPullbackSegmentPilot::write($need('out').'/sample-manifest.json',$result);
    echo json_encode(['total'=>$result['total'],'actual'=>$result['actual'],'shortfalls'=>$result['shortfalls'],'notes'=>$result['selection_notes']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
    exit($result['total']===24?0:1);
}
if($cmd==='view'){
    // A-only reading aid. It prints no category, status, plan or later bar.
    $pack=rtrim(str_replace('\\','/',$need('pack')),'/');
    $manifest=PaperPullbackSegmentPilot::loadJson($need('manifest'));
    $ids=array_column($manifest['cases'],'case_id');sort($ids);
    $from=(int)($o['from']??0);$to=(int)($o['to']??count($ids));
    foreach(array_slice($ids,$from,$to-$from,true) as $k=>$id){
        $a=PaperPullbackSegmentPilot::loadJson($pack.'/json/a/'.$id.'.json');
        $bars=PaperPullbackSegmentPilot::bars($pack,$id);$h=PaperPullbackSegmentPilot::helper($bars);
        echo "#$k $id {$a['name']} {$a['symbol']} D={$a['session_date']} bars={$h['bars']} first={$h['first_date']}\n";
        echo '  pivots(k=3): ';
        foreach($h['pivots'] as $p)echo $p['i'].':'.substr($p['date'],2).':'.$p['tag'].':'.rtrim(rtrim(number_format((float)$p['price'],2,'.',''),'0'),'.').' ';
        echo "\n  tail: ";
        $n=count($bars);
        for($i=max(0,$n-10);$i<$n;$i++){$b=$bars[$i];echo $i.':'.substr($b['date'],5).' h'.$b['high'].' l'.$b['low'].' c'.$b['close'].' v'.(int)$b['volume'].' ma20='.round((float)$b['ma20'],1).' | ';}
        echo "\n";
    }
    exit(0);
}
if($cmd==='annotate'){
    // A stage: resolves the hand-written dates against the A price files. Reads no B, C, engine or outcome file.
    $pack=rtrim(str_replace('\\','/',$need('pack')),'/');
    $result=PaperPullbackSegmentPilot::annotate($pack,$need('src'),$need('manifest'),hash_file('sha256',$need('src')));
    PaperPullbackSegmentPilot::write($need('out'),$result);
    echo count($result['cases'])," cases annotated\n";
    exit(0);
}
if($cmd==='charts'){
    $pack=rtrim(str_replace('\\','/',$need('pack')),'/');
    $ann=PaperPullbackSegmentPilot::loadJson($need('annotations'));$dir=rtrim($need('out'),'/\\');
    if(!is_dir($dir))mkdir($dir,0775,true);
    foreach($ann['cases'] as $c){
        $bars=PaperPullbackSegmentPilot::bars($pack,$c['case_id']);
        foreach([120,40] as $count){
            $svg=PaperReviewCharts::svg(PaperPullbackSegmentPilot::viewOf($bars,$count),[],PaperPullbackSegmentPilot::title($c,$count),true,$c['symbol'],PaperPullbackSegmentPilot::overlay($c,$count));
            file_put_contents($dir.'/'.$c['case_id'].'-'.$count.'.svg',$svg);
        }
    }
    echo count($ann['cases'])*2," charts written\n";
    exit(0);
}
fwrite(STDERR,"Usage: php bin/paper_pullback_segment_pilot.php select|annotate|charts|view ...\n");
exit(64);
