<?php
declare(strict_types=1);
use ChartEntryLab\PaperJournal;
require __DIR__.'/recovery_close_account.php'; // PR84 regressions + real engine fixture, cleanup registered.
require __DIR__.'/../bin/paper/RecoveryClosePriorAccount.php';
$candidates=PaperDatedTop100Replay::loadCandidates($root.'/'.PaperRecoveryClosePriorAccount::DOCS.'/candidates.json',243,1248);
$days=$candidates['days'];
rcheck(count($days)===243&&$days[0]==='2024-10-08'&&end($days)==='2025-10-02','prior exact 243-day calendar');
$fixed=PaperRecoveryCloseAccount::read($root.'/docs/recovery-close-prior-account/frozen-inputs.json');
foreach($fixed['repository_lf_sha256'] as $f=>$h)rcheck(PaperStrategyVersion::fileHash($root.'/'.$f)===$h,'prior pinned input '.$f);
$map=PaperDatedTop100Replay::pinSectorMap($root.'/'.PaperRecoveryClosePriorAccount::DOCS.'/sector-map-full-v2.json',$fixed['repository_lf_sha256'][PaperRecoveryClosePriorAccount::DOCS.'/sector-map-full-v2.json']);
PaperSectorFreeze::assertCoversUnion($candidates['union'],$map);rcheck(true,'prior full map covers all 1248 symbols');
$cfg=PaperRecoveryClosePriorAccount::config($root,'baseline');$oldCfg=PaperDatedTop100Replay::configs($root)['base'];
rcheck($cfg===array_replace($oldCfg,['id'=>$cfg['id']]),'prior config only changes ID');
rcheck(PaperRecoveryClosePriorAccount::journalPath('/state','baseline')!==PaperRecoveryClosePriorAccount::journalPath('/state','baseline',true),'check path is separate');
rthrows(fn()=>PaperRecoveryClosePriorAccount::journalPath('/state','../paper-kr'),'invalid account arm rejected');

// 243 real calendar dates, synthetic price history, real engines/portfolio. Not a profitability experiment.
$first=PaperDatedTop100Replay::sessionClose($days[0]);$shift=$first-$at;$history=$miss;
foreach($history as &$bar){$bar['available_at']+=$shift;$bar['time_kst']=PaperHistoryResearch::day($bar['available_at']).' 15:30:00';}unset($bar);
foreach(array_slice($days,1) as $day)$history[]=['time_kst'=>$day.' 15:30:00','available_at'=>PaperDatedTop100Replay::sessionClose($day),'open'=>1085,'high'=>1094,'low'=>1084,'close'=>1090,'volume'=>1000];
$data=['dataset'=>PaperRecoveryClosePriorAccount::DATASET,'dir'=>'','start_day'=>$days[0],'as_of_day'=>end($days),'missing'=>[],'hold'=>[],
    'files'=>['dataset.json'=>'synthetic','universe.json'=>'synthetic'],'symbols'=>['005930.KS'=>['name'=>'fixture','rows'=>$history,'bars_sha256'=>hash('sha256',PaperJournal::encode($history)),'quality'=>['status'=>'ok'],'failed'=>false]]];
$by=[];foreach($days as $day)$by[PaperDatedTop100Replay::sessionClose($day)]=['day'=>$day,'symbols'=>['005930.KS'],'rows'=>[]];
$cand=['days'=>$days,'by_session'=>$by,'union'=>['005930.KS'],'file_sha256'=>'synthetic'];
$journals=[];
foreach(['baseline','variant'] as $arm){
    $cfg=PaperRecoveryClosePriorAccount::config($root,$arm);
    $opts=['start'=>$days[0],'through'=>end($days),'runtime_code_extra'=>PaperRecoveryClosePriorAccount::codeFiles()];
    if($arm==='variant')$opts['plan_provider']=PaperRecoveryCloseAccount::provider();
    $split=$tmp.'/prior-'.$arm.'.json';$once=$tmp.'/prior-'.$arm.'-once.json';
    PaperDatedTop100Replay::run($data,$cfg,$split,$cand,$opts+['chunk'=>3,'stop_after_sessions'=>8]);
    $partial=(new PaperJournal($split))->read();
    rthrows(fn()=>PaperRecoveryClosePriorAccount::completed($partial,$days),'incomplete '.$arm.' cannot unlock next stage');
    PaperDatedTop100Replay::run($data,$cfg,$split,$cand,$opts+['chunk'=>3]);
    PaperDatedTop100Replay::run($data,$cfg,$once,$cand,$opts+['chunk'=>243]);
    $a=(new PaperJournal($split))->read();$b=(new PaperJournal($once))->read();
    rcheck(PaperRecoveryClosePriorAccount::splitCheck($a,$b,$days)['pass'],'243-session split/once matches '.$arm);
    rcheck(PaperRecoveryClosePriorAccount::verify($a,$data,$cand,$arm==='variant')['pass'],'243-session books and orders '.$arm);
    $hash=hash_file('sha256',$split);$again=PaperDatedTop100Replay::run($data,$cfg,$split,$cand,$opts);
    rcheck($again['processed_sessions']===0&&hash_file('sha256',$split)===$hash,'prior completed rerun unchanged '.$arm);
    $identity=['configs'=>[$arm=>$cfg],'dataset_files'=>$data['files'],'bars_sha256'=>['005930.KS'=>$data['symbols']['005930.KS']['bars_sha256']],
        'candidates_sha256'=>'synthetic','sector_sha256'=>null,'code_sha256_lf'=>PaperDatedTop100Replay::runtimeCodeHashes(PaperRecoveryClosePriorAccount::codeFiles())];
    PaperRecoveryClosePriorAccount::assertIdentity($a,$identity,$arm);rcheck(true,'journal source identity matches '.$arm);
    $bad=$identity;$bad['candidates_sha256']='changed';rthrows(fn()=>PaperRecoveryClosePriorAccount::assertIdentity($a,$bad,$arm),'different input journal refused '.$arm);
    $altered=$b;$altered['state_hash']='changed';rthrows(fn()=>PaperRecoveryClosePriorAccount::splitCheck($a,$altered,$days),'different final state refused '.$arm);
    $altered=$b;$altered['state']['halted']=true;rthrows(fn()=>PaperRecoveryClosePriorAccount::splitCheck($a,$altered,$days),'halted full-count run refused '.$arm);
    $journals[$arm]=$a;
}
$r=PaperRecoveryClosePriorAccount::compare($journals['baseline'],$journals['variant']);
rcheck($r['period']['sessions']===243&&$r['halves']['baseline'][0]['through']===$days[121]&&$r['halves']['baseline'][1]['from']===$days[122]&&$r['halves']['baseline'][1]['through']===$days[242],'prior 122/121 split covers all dates');
rcheck($r['variant']['event_counts']['order']>0,'prior variant fixture actually places orders');
rcheck(PaperStrategyVersion::current()===PaperDatedTop100Replay::STRATEGY_FINGERPRINT,'operational fingerprint preserved');
