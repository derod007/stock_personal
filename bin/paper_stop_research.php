<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/StopResearch.php';
$o=getopt('',['account:','source-dir:','output-dir:','days:']);
$id=$o['account']??'paper-kr';
$state=getenv('PAPER_STATE_DIR')?:dirname(__DIR__,2).'/stock-personal-paper';
$source=$o['source-dir']??$state;
$report=PaperStopResearch::run($source,$id,(int)($o['days']??5));
// Optional separate result directory; no source or operational files are overwritten.
{
    $output=$o['output-dir']??$state.'/stop-research/'.$id;
    if(!is_dir($output)&&!mkdir($output,0770,true)&&!is_dir($output))throw new RuntimeException('Cannot create output');
    $src=realpath($source);$dst=realpath($output);
    foreach(['rr-audit','followup'] as $protected){
        $p=$src.DIRECTORY_SEPARATOR.$protected;
        if($dst===$p||str_starts_with($dst,$p.DIRECTORY_SEPARATOR))throw new RuntimeException('Output cannot overwrite source evidence');
    }
    PaperFollowup::save($output,$report);
}
echo PaperRrAudit::encode($report)."\n";
