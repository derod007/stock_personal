<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/PullbackYearReview.php';
$cmd=$argv[1]??'';$o=[];
foreach(array_slice($argv,2) as $a){
    if(!preg_match('/^--([a-z-]+)=(.*)$/s',$a,$m))throw new InvalidArgumentException('Unknown argument '.$a);
    $o[$m[1]]=$m[2];
}
$root=dirname(__DIR__);
$state=rtrim(str_replace('\\','/',$o['state']??(getenv('PAPER_STATE_DIR')?:dirname($root).'/stock-personal-paper')),'/');
$datasetDir=$o['dataset-dir']??PaperHistoryResearch::datasetDir($state,PaperPullbackYearReview::DATASET);
$out=rtrim(str_replace('\\','/',$o['out']??$root.'/docs/pullback-year-review'),'/');
$trades=$o['trades']??$root.'/docs/historical-top100-replay-20251008-20261008/account/trades.json';
if($cmd==='extract'){
    $rows=PaperPullbackYearReview::extract(PaperPullbackYearReview::loadJson($trades));
    echo json_encode(['count'=>count($rows),'ids'=>array_column($rows,'case_id')],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
    exit(count($rows)===PaperPullbackYearReview::EXPECTED?0:1);
}
if($cmd==='build'){
    if(!is_dir($datasetDir)){
        fwrite(STDERR,"로컬 데이터셋이 없습니다. 필요한 경로: ".$datasetDir."\n");
        exit(2);
    }
    $commit=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));
    $list=PaperPullbackYearReview::build($out,$datasetDir,$trades,$commit);
    echo json_encode(['count'=>$list['count'],'mismatch_cases'=>$list['mismatch_cases'],'trades_sha256'=>$list['trades_sha256']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
    exit(0);
}
if($cmd==='verify'){
    if(!is_dir($datasetDir)){
        fwrite(STDERR,"로컬 데이터셋이 없습니다. 필요한 경로: ".$datasetDir."\n");
        exit(2);
    }
    $res=PaperPullbackYearReview::verify($out,$datasetDir,$trades);
    foreach($res['problems'] as $p)echo "FAIL $p\n";
    foreach($res['missing'] as $p)echo "MISS $p\n";
    echo json_encode(['pass'=>$res['pass'],'cases'=>$res['cases']],JSON_UNESCAPED_UNICODE),"\n";
    exit($res['pass']?0:1);
}
if($cmd==='zip'){
    $zip=$o['zip']??$state.'/pullback-year-review-prices.zip';
    $info=PaperPullbackYearReview::zipPrices($out,$zip);
    echo json_encode($info,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
    exit(0);
}
fwrite(STDERR,"Usage: php bin/paper_pullback_year_review.php extract|build|verify|zip\n");
exit(64);
