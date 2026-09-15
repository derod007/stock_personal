<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/TradeReview.php';
use ChartEntryLab\PaperJournal;
$o=getopt('',['account:','mode:']);$id=$o['account']??'paper-us';$mode=$o['mode']??'forward';
if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id)||!in_array($mode,['forward','replay'],true))throw new InvalidArgumentException('Invalid account/mode');
$dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__,2).'/stock-personal-paper';
$d=(new PaperJournal($dir.'/'.$id.'-'.$mode.'.json'))->read();
if(!$d || !$d['state'])throw new RuntimeException('No account journal');
echo PaperJournal::encode(PaperTradeReview::build($d)).PHP_EOL;
