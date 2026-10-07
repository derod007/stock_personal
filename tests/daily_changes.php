<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/DailyChanges.php';
function ck(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);echo "PASS $m\n";}
$t=strtotime('2026-10-07 20:23:14 +0900');$day='2026-10-07';
function stateRow(?bool $ready,int $session=1):array{return ['name'=>'test','ready'=>$ready,'status'=>$ready?'ready':'await_confirmation','session'=>$session];}
function runRow(string $id,int $at,array $rows,string $scope='audit'):array{return ['id'=>$id,'at'=>$at,'rows'=>$rows,'scope'=>$scope,'source'=>$scope,'note'=>'test'];}
$before=runRow('1',$t-86400,['A'=>stateRow(false),'B'=>stateRow(true),'C'=>stateRow(true),'D'=>stateRow(true)]);
$now=runRow('2',$t,['A'=>stateRow(true),'B'=>stateRow(false),'D'=>stateRow(null),'E'=>stateRow(true)]);
$x=PaperDailyChanges::transitions([$now,$before],$day);
ck(array_column($x,'kind')===['new_ready','lost_ready','first_seen_ready'],'new/lost/first observation separated');
ck(!in_array('C',array_column($x,'symbol'))&&!in_array('D',array_column($x,'symbol')),'missing membership and failed query are not revoked');
ck($x[0]['same_bar'],'same-bar rerun is labelled');
$again=runRow('3',$t+1,$now['rows']);ck(count(PaperDailyChanges::transitions([$before,$now,$again],$day))===3,'same ready state does not repeat event');
$gap=runRow('gap',$t-1,[]);$g=PaperDailyChanges::transitions([$before,$gap,$now],$day);
ck(count(array_filter($g,fn($e)=>$e['kind']==='new_ready'||$e['kind']==='lost_ready'))===0,'no transition across membership gap');
$other=runRow('other',$t,$now['rows'],'manual:isa');ck(PaperDailyChanges::transitions([$before,$other],$day)[0]['kind']==='first_seen_ready','different scopes never cross-link');
ck(PaperDailyChanges::day(strtotime('2026-10-06 16:00:00 UTC'))===$day,'KST day boundary');
$dir=sys_get_temp_dir().'/changes-'.bin2hex(random_bytes(4));mkdir($dir,0700,true);
function removeChanges(string $path):void{foreach(scandir($path) as $f){if($f==='.'||$f==='..')continue;$p=$path.'/'.$f;is_dir($p)?removeChanges($p):unlink($p);}rmdir($path);}
try{
    mkdir($dir.'/rr-audit/paper-kr',0770,true);mkdir($dir.'/followup/paper-kr',0770,true);
    $session=strtotime('2026-10-06 15:30:00 +0900');$file='20261006-112300-abcdef012345.json';
    $records=[];
    foreach(['A','B','C'] as $symbol)$records[]=['symbol'=>$symbol,'name'=>$symbol,'session'=>$session,'status'=>'evaluated','analysis'=>['plan'=>['ready'=>true,'status'=>'ready']]];
    file_put_contents($dir.'/rr-audit/paper-kr/'.$file,json_encode(['version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only','recorded_at'=>$t-86400,'records'=>$records]));
    $rows=[];
    foreach($records as $r){$r['source_file']=$file;$r['captured_at']=$t-86400;$hash=hash('sha256',PaperRrAudit::encode($r));
        $rows[$r['symbol']]=['symbol'=>$r['symbol'],'session'=>$session,'source_file'=>$file,'observation_hash'=>$hash,'tracking_policy'=>PaperTrackingInput::POLICY,'as_of'=>$t,
            'trades'=>[['kind'=>'baseline','outcome'=>['status'=>$r['symbol']==='A'?'closed':'unfilled','entry_at'=>$r['symbol']==='A'?$t-3600:null,'exit_at'=>$r['symbol']==='A'?$t-1800:null,'entry_fill'=>100,'exit_fill'=>105,'net_return_pct'=>4.7,'first_exit'=>'target']]]];}
    // B was already expired yesterday; C is observed expired only today.
    $old=['schema'=>1,'generated_at'=>$t-86400,'as_of'=>$t-86400,'rows'=>['B'=>$rows['B']]];$old['rows']['B']['as_of']=$t-86400;
    file_put_contents($dir.'/followup/paper-kr/20261006-112300-abcdef012345.json',json_encode($old));
    $report=['schema'=>1,'generated_at'=>$t,'as_of'=>$t,'rows'=>$rows];
    file_put_contents($dir.'/followup/paper-kr/20261007-112300-abcdef012345.json',json_encode($report));
    file_put_contents($dir.'/followup/paper-kr/latest.json',json_encode($report));
    (new ChartEntryLab\PaperJournal($dir.'/paper-kr-forward.json'))->transact(function(&$state,$emit)use($t){$state=['test'=>true];$emit('fill',['symbol'=>'A','session'=>$t,'price'=>100]);});
    $r=PaperDailyChanges::read($dir,'paper-kr','account1',$day);
    ck(!$r['errors']&&count($r['research'])===3,'fill/exit plus first terminal observation only, latest duplicate ignored');
    ck(count(array_filter($r['research'],fn($e)=>$e['kind']==='unfilled'&&$e['symbol']==='C'))===1,'old expiry not reannounced today');
    ck(count($r['account_events'])===1,'account ledger separate from independent outcome');
    $fill=array_values(array_filter($r['research'],fn($e)=>$e['kind']==='fill'))[0];
    ck($fill['exit']===null&&$fill['reason']===null&&$fill['net_return_pct']===null,'later exit cannot appear as fill-day outcome');
    // Today audit summary is read, not supplied by UI or inferred from fills.
    file_put_contents($dir.'/rr-audit/paper-kr/20261007-112314-abcdef012345.json',json_encode(['version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only','recorded_at'=>$t,'records'=>$records,'summary'=>['confirmed_rr_symbol_days'=>2,'added'=>2]]));
    putenv('PAPER_STATE_DIR='.$dir);$_GET=['account'=>'paper-kr','day'=>$day];ob_start();require __DIR__.'/../paper_changes.php';$html=ob_get_clean();
    ck(str_contains($html,'확인 후 손익비 탈락 2')&&str_contains($html,'연구 포함 2')&&str_contains($html,'미체결 만료'),'real page shows audit summary and event');
    file_put_contents($dir.'/followup/paper-kr/20261005-112300-abcdef012345.json','{broken');
    $r=PaperDailyChanges::read($dir,'paper-kr','account1',$day);
    ck(count($r['errors'])===1&&count($r['research'])===2,'corrupt history suppresses uncertain first-seen terminal dates but preserves exact fill dates');
}finally{removeChanges($dir);}
