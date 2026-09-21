<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/StrategyVersion.php';
require __DIR__.'/paper/Experiment.php';
require __DIR__.'/paper/EntryRelaxation.php';
require __DIR__.'/paper/EntryExperiment.php';
use ChartEntryLab\PaperJournal;
$o=getopt('',['source:','experiment:','mode:','identity']);
$id=$o['experiment']??'';$source=$o['source']??'';$mode=$o['mode']??'forward';
try {
    foreach([$id,$source] as $v)if(!is_string($v)||!preg_match('/^[a-z0-9_-]{1,64}$/',$v))throw new InvalidArgumentException('Valid source/experiment required');
    if(!in_array($mode,['forward','replay'],true))throw new InvalidArgumentException('Invalid mode');
    $dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__,2).'/stock-personal-paper';
    $definition=['id'=>$id,'source'=>$source,'mode'=>$mode,'candidate'=>isset($o['identity'])?[]:['pullback_volume_ratio'=>0.95]];
    $j=new PaperJournal($dir.'/entry-experiments/'.$id.'-'.$mode.'.json');
    $result=$j->transact(function(&$p,$emit)use($dir,$source,$mode,$definition){
        $d=(new PaperJournal($dir.'/'.$source.'-'.$mode.'.json'))->read();
        if(!$d || !$d['state'])throw new RuntimeException('Source account not found');
        $cfg=$d['state']['config'];
        if(!str_starts_with($source,'research-') || empty($cfg['symbols']) || !is_array($cfg['universe']??null))throw new RuntimeException('Use a fixed research universe source');
        PaperStrategyVersion::verify($d['state'],PaperStrategyVersion::current());
        $p=PaperEntryExperiment::update($p,$d,$definition,time(),$emit);
    });
    echo PaperJournal::encode(PaperEntryExperiment::report($result['state'])).PHP_EOL;
} catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
