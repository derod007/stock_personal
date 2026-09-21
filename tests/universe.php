<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';require __DIR__.'/../bin/paper/Universe.php';
use ChartEntryLab\PaperJournal;
function uc(bool $v,string $name):void{if(!$v)throw new RuntimeException($name);echo "OK $name\n";}
$cfg=json_decode(file_get_contents(__DIR__.'/../config/paper-research-us-v1.json'),true);
$s=['config'=>$cfg,'mode'=>'forward','last_session'=>300,'closed_trades'=>2,'realized'=>5];
$events=[['type'=>'account_started','payload'=>[],'recorded_at'=>123],['type'=>'exit','payload'=>['symbol'=>'MU','net_pnl'=>10]],['type'=>'exit','payload'=>['symbol'=>'NVDA','net_pnl'=>-5]],['type'=>'snapshot','payload'=>['symbol'=>'MU','session'=>300,'origin'=>'catchup','plan'=>['ready'=>true],'quality'=>['status'=>'warning','reasons'=>[]]]],['type'=>'decision','payload'=>['symbol'=>'MU','reason'=>'retrospective_signal']]];
$d=['state'=>$s,'events'=>$events];$before=serialize($d);$r=PaperUniverse::summarize($d);
uc(count($r['symbols'])===10 && count($r['sectors'])===5,'all configured symbols represented including zero-trade');
uc($r['closed']===2 && $r['realized']===5.0 && $r['issues']===[],'reconcile realized');
uc($r['sectors'][0]['net_pnl']===5.0 && $r['sectors'][0]['closed']===2,'same sector aggregated');
uc($r['symbols'][0]['origins']['catchup']===1,'catchup labeled');
uc($r['started_at']===123 && serialize($d)===$before,'first run and immutable input');
$bad=$d;$bad['state']['realized']=99;uc(PaperUniverse::summarize($bad)['issues']!==[],'reconciliation mismatch visible');
$dir=sys_get_temp_dir().'/universe-'.bin2hex(random_bytes(5));mkdir($dir);$old=getenv('PAPER_STATE_DIR');putenv('PAPER_STATE_DIR='.$dir);
try{
 $path=$dir.'/research-us-v1-forward.json';(new PaperJournal($path))->transact(function(&$state,$emit)use($d){$state=$d['state'];foreach($d['events'] as $e)$emit($e['type'],$e['payload']);});
 $hash=hash_file('sha256',$path);$_GET=['account'=>'research-us-v1'];ob_start();require __DIR__.'/../paper_universe.php';$html=ob_get_clean();
 uc(str_contains($html,'업종별')&&str_contains($html,'MU'),'render report');uc(hash_file('sha256',$path)===$hash,'read only page');
}finally{putenv($old===false?'PAPER_STATE_DIR':'PAPER_STATE_DIR='.$old);foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
echo "UNIVERSE_PASS (8 checks)\n";
