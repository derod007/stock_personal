<?php
declare(strict_types=1);
require __DIR__.'/entry.php';
require __DIR__.'/../bin/paper/StrategyVersion.php';
$dir=sys_get_temp_dir().'/entry-cli-'.bin2hex(random_bytes(5));mkdir($dir);$old=getenv('PAPER_STATE_DIR');putenv('PAPER_STATE_DIR='.$dir);
try {
    $fixture=$source;$fixture['state']['version']=PaperStrategyVersion::current();
    $path=$dir.'/research-fixture-replay.json';
    (new ChartEntryLab\PaperJournal($path))->transact(function(&$s,$emit)use($fixture){$s=$fixture['state'];foreach($fixture['events'] as $e)$emit($e['type'],$e['payload']);});
    $hash=hash_file('sha256',$path);
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/paper_entry_compare.php').' --source=research-fixture --experiment=cli --mode=replay';
    exec($cmd,$out,$code);$report=json_decode(implode("\n",$out),true,512,JSON_THROW_ON_ERROR);
    ck($code===0 && $report['sessions']===2,'real CLI processes common sessions');
    $pairPath=$dir.'/entry-experiments/cli-replay.json';$pairHash=hash_file('sha256',$pairPath);$out=[];
    exec($cmd,$out,$code);ck($code===0 && hash_file('sha256',$pairPath)===$pairHash,'CLI repeat byte-identical');
    ck(hash_file('sha256',$path)===$hash,'CLI leaves source byte-identical');
    $out=[];exec($cmd.' --identity 2>/dev/null',$out,$code);
    ck($code!==0 && hash_file('sha256',$pairPath)===$pairHash,'pinned-definition failure atomic');
    $_GET=['account'=>'research-fixture','experiment'=>'cli','mode'=>'replay'];
    ob_start();require __DIR__.'/../paper_entry.php';$html=ob_get_clean();
    ck(str_contains($html,'눌림 거래량 감소') && str_contains($html,'완화 후보'),'real report renders journal');
    ck(hash_file('sha256',$path)===$hash && hash_file('sha256',$pairPath)===$pairHash,'report read only');
} finally {
    putenv($old===false?'PAPER_STATE_DIR':'PAPER_STATE_DIR='.$old);
    foreach(glob($dir.'/entry-experiments/*')?:[] as $f)unlink($f);
    if(is_dir($dir.'/entry-experiments'))rmdir($dir.'/entry-experiments');
    foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);
}
echo "ENTRY_CLI_PASS\n";
