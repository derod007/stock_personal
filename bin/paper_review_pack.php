<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/ReviewPack.php';
$cmd=$argv[1]??'';$o=[];
foreach(array_slice($argv,2) as $a){
    if(!preg_match('/^--([a-z-]+)=(.*)$/s',$a,$m))throw new InvalidArgumentException('Unknown argument '.$a);
    $o[$m[1]]=$m[2];
}
if($cmd==='build'){
    $commit=trim((string)shell_exec('git rev-parse HEAD'));
    $base=dirname(__DIR__);
    $root=$o['state']??(getenv('PAPER_STATE_DIR')?:dirname($base).'/stock-personal-paper');
    $root=rtrim(str_replace('\\','/',$root),'/');
    $datasets=[
        ['id'=>'kr-saved-scan-20261009','dir'=>$root.'/history-research/kr-saved-scan-20261009','replay'=>$root.'/history-research/replay-kr-saved-scan-20261009/pattern-replay-account1.json'],
        ['id'=>'kr-saved-scan-20251008','dir'=>$root.'/history-research/kr-saved-scan-20251008','replay'=>$root.'/history-research/replay-kr-saved-scan-20251008/pattern-replay-account1.json'],
    ];
    $manifest=PaperReviewPack::build($o['out']??throw new InvalidArgumentException('--out is required'),$datasets,$commit);
    echo json_encode(['cases'=>$manifest['cases'],'problems'=>$manifest['problems'],'counts'=>$manifest['counts']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
    exit($manifest['problems']===[]?0:1);
}
if($cmd==='verify'){
    $base=dirname(__DIR__);
    $root=$o['state']??(getenv('PAPER_STATE_DIR')?:dirname($base).'/stock-personal-paper');
    $root=rtrim(str_replace('\\','/',$root),'/');
    $datasets=[
        ['id'=>'kr-saved-scan-20261009','dir'=>$root.'/history-research/kr-saved-scan-20261009','replay'=>$root.'/history-research/replay-kr-saved-scan-20261009/pattern-replay-account1.json'],
        ['id'=>'kr-saved-scan-20251008','dir'=>$root.'/history-research/kr-saved-scan-20251008','replay'=>$root.'/history-research/replay-kr-saved-scan-20251008/pattern-replay-account1.json'],
    ];
    $report=PaperReviewPack::verifyWritten($o['out']??throw new InvalidArgumentException('--out is required'),$datasets);
    echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
    exit($report['problems']===[]&&$report['missing']===[]?0:1);
}
fwrite(STDERR,"Usage: php bin/paper_review_pack.php build --out=DIR\n");
exit(64);
