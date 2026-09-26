<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/TopWavePanel.php';
use ChartEntryLab\PaperJournal;
$o=getopt('',['account:','mode:']);$id=$o['account']??'paper-kr';$mode=$o['mode']??'forward';
if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id)||!in_array($mode,['forward','replay'],true))throw new InvalidArgumentException('Invalid account/mode');
$dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__,2).'/stock-personal-paper';
$d=(new PaperJournal($dir.'/'.$id.'-'.$mode.'.json'))->read();$s=$d['state']??[];
echo PaperJournal::encode(['account'=>$id,'mode'=>$mode,'basis'=>'read_only_reconstruction',
    'rows'=>paper_top_wave_rows($s,$mode==='replay'?(int)($s['last_session']??0):time())]).PHP_EOL;
