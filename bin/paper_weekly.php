<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require __DIR__.'/paper/Experiment.php';require __DIR__.'/paper/Weekly.php';
use ChartEntryLab\PaperJournal;
$o=getopt('',['account:','mode:','week:']);$dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__,2).'/stock-personal-paper';
try{echo PaperJournal::encode(PaperWeekly::load($dir,$o['account']??'paper-us',$o['mode']??'forward',$o['week']??null)).PHP_EOL;}
catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
