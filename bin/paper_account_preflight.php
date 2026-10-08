<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/StrategyVersion.php';
use ChartEntryLab\{PaperJournal, PaperScanUniverse};
$o=getopt('',['config:']);
if(empty($o['config']))throw new InvalidArgumentException('--config required');
$config=json_decode(file_get_contents($o['config']),true,512,JSON_THROW_ON_ERROR);
$id=$config['id']??'';
if(!is_string($id)||!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account ID');
$dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__,2).'/stock-personal-paper';
$path=$dir.'/'.$id.'-forward.json';$version=PaperStrategyVersion::current();
$status='new_account';
if(is_file($path)){
    $journal=(new PaperJournal($path))->read();$s=$journal['state']??null;
    if(!is_array($s))throw new RuntimeException('Existing account state missing');
    if(!empty($s['halted']))throw new RuntimeException('Account halted; review existing journal');
    if(($s['mode']??null)!=='forward'||($s['config_hash']??null)!==hash('sha256',PaperJournal::encode(PaperScanUniverse::pinned($config))))throw new RuntimeException('Pinned config changed; use a new account ID');
    PaperStrategyVersion::verify($s,$version);$status='compatible';
}
// Read-only: never initialize, migrate, or emit events. Account CLI rechecks under its lock.
echo json_encode(['account'=>$id,'status'=>$status,'strategy_fingerprint'=>$version,'writes'=>false],JSON_THROW_ON_ERROR)."\n";
