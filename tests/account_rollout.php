<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/StrategyVersion.php';
use ChartEntryLab\{PaperJournal,PaperScanUniverse};
function ar(bool $ok,string $s):void{if(!$ok)throw new RuntimeException($s);echo "PASS $s\n";}
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/rollout-'.bin2hex(random_bytes(5));mkdir($tmp);putenv('PAPER_STATE_DIR='.$tmp);
$c=json_decode(file_get_contents($root.'/config/paper-kr-recovery-v1.json'),true);$old=file_get_contents($root.'/config/paper-kr.json');
$legacy=$tmp.'/paper-kr-forward.json';file_put_contents($legacy,'old record preserved');$before=hash_file('sha256',$legacy);
$path=$tmp.'/'.$c['id'].'-forward.json';
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/paper_account_preflight.php').' --config='.escapeshellarg($root.'/config/paper-kr-recovery-v1.json');
try{
 exec($cmd,$lines,$code);$r=json_decode(implode("\n",$lines),true);
 ar($code===0&&$r['status']==='new_account'&&!file_exists($path),'new account checked without creating a ledger');
 $j=new PaperJournal($path);$j->transact(function(&$s,$emit)use($c){$s=['mode'=>'forward','version'=>PaperStrategyVersion::current(),'config_hash'=>hash('sha256',PaperJournal::encode(PaperScanUniverse::pinned($c)))];$emit('fixture',[]);});
 $h=hash_file('sha256',$path);$lines=[];exec($cmd,$lines,$code);$r=json_decode(implode("\n",$lines),true);
 ar($code===0&&$r['status']==='compatible'&&hash_file('sha256',$path)===$h,'compatible account verified read-only');
 $j->transact(function(&$s,$emit){$s['version']=str_repeat('0',64);$emit('fixture',[]);});$h=hash_file('sha256',$path);
 exec($cmd.' 2>/dev/null',$ignored,$code);ar($code!==0&&hash_file('sha256',$path)===$h,'strategy mismatch refused without migration');
 ar(hash_file('sha256',$legacy)===$before&&file_get_contents($root.'/config/paper-kr.json')===$old,'old account and config preserved');
 ar(str_contains(file_get_contents($root.'/bin/run_paper_daily.cmd'),'--config=config\\paper-kr-recovery-v1.json'),'existing scheduler runner selects new config');
}finally{putenv('PAPER_STATE_DIR');foreach(glob($tmp.'/*') as $f)if(is_file($f))unlink($f);rmdir($tmp);}
