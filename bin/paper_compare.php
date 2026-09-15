<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/Experiment.php';
use ChartEntryLab\PaperJournal;
$o=getopt('',['source:','experiment:','mode:','candidate-ttl:']);
$id=$o['experiment']??'';$source=$o['source']??'';$mode=$o['mode']??'forward';
foreach([$id,$source] as $value)if(!preg_match('/^[a-z0-9_-]{1,64}$/',$value))throw new InvalidArgumentException('Valid --source and --experiment required');
if(!in_array($mode,['forward','replay'],true))throw new InvalidArgumentException('Invalid mode');
$candidate=[];
if(isset($o['candidate-ttl'])){if(!preg_match('/^[1-5]$/',$o['candidate-ttl']))throw new InvalidArgumentException('TTL must be 1..5');$candidate=['order_valid_bars'=>(int)$o['candidate-ttl']];}
$dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__,2).'/stock-personal-paper';
$definition=['id'=>$id,'source'=>$source,'mode'=>$mode,'candidate'=>$candidate];
$j=new PaperJournal($dir.'/experiments/'.$id.'-'.$mode.'.json');
try {
    $result=$j->transact(function(&$p,$emit)use($dir,$source,$mode,$definition){
        $d=(new PaperJournal($dir.'/'.$source.'-'.$mode.'.json'))->read();
        if(!$d || !$d['state'])throw new RuntimeException('Source account not found');
        $versions=[];foreach(glob(dirname(__DIR__).'/src/*.php') as $file)$versions[basename($file)]=hash_file('sha256',$file);
        if(hash('sha256',PaperJournal::encode($versions))!==$d['state']['version'])throw new RuntimeException('Source strategy differs from installed code');
        $p=PaperExperiment::update($p,$d,$definition,time(),$emit);
    });
    echo PaperJournal::encode(PaperExperiment::report($result['state'])).PHP_EOL;
} catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
