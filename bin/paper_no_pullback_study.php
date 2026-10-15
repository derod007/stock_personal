<?php
declare(strict_types=1);
require __DIR__.'/paper/NoPullbackStudy.php';
use ChartEntryLab\PaperJournal;
try {
    $cmd=$argv[1]??'help';$o=[];foreach(array_slice($argv,2) as $arg){
        if(!preg_match('/^--(state-dir|bars|sessions|confirm-full-run)=(.+)$/',$arg,$m)||isset($o[$m[1]]))throw new RuntimeException('Unknown/duplicate option');$o[$m[1]]=$m[2];
    }
    if($cmd==='help'){echo "validate | baseline | baseline-check | variant | variant-check | report [--state-dir=DIR --bars=DIR --sessions=8|all]\nFull run requires --confirm-full-run=after-code-review\n";exit(0);}
    if(!in_array($cmd,['validate','baseline','baseline-check','variant','variant-check','report'],true))throw new RuntimeException('Unknown command');
    $n=$o['sessions']??'8';if(!in_array($n,['8','all'],true))throw new RuntimeException('sessions must be 8 or all');$n=$n==='all'?243:8;
    if($n===243&&($o['confirm-full-run']??'')!=='after-code-review')throw new RuntimeException('Full run confirmation missing');
    if($cmd==='report'&&$n!==243)throw new RuntimeException('Comparison requires all sessions');
    $root=dirname(__DIR__);$state=realpath($o['state-dir']??getenv('PAPER_STATE_DIR')?:'');
    if(!$state||!is_dir($state)||is_link($state))throw new RuntimeException('Existing state directory required');
    $state=str_replace('\\','/',$state);$repo=str_replace('\\','/',realpath($root));
    if(str_starts_with(strtolower($state).'/',strtolower($repo).'/'))throw new RuntimeException('State must be outside repository');
    $manifest=PaperKrxSignalStudy::json($root.'/'.PaperKrxSignalStudy::DATA.'/manifest.json');
    $loaded=PaperNoPullbackStudy::load($root,$state,$o['bars']??$manifest['bars']);
    $code=PaperDatedTop100Replay::runtimeCodeHashes(PaperNoPullbackStudy::FILES);$configs=[];
    foreach(['baseline','variant'] as $a)$configs[$a]=PaperNoPullbackStudy::config($root,$a);
    $identity=['kind'=>'krx-no-pullback-account-v1','php'=>PHP_VERSION,'report_sha256'=>PaperNoPullbackStudy::REPORT_SHA,
        'source_baseline_sha256'=>PaperNoPullbackStudy::SOURCE_BASELINE_SHA,'code_sha256_lf'=>$code,'configs'=>$configs,'sector_sha256'=>PaperNoPullbackStudy::SECTOR_SHA];
    $out=$state.'/'.PaperNoPullbackStudy::OUTPUT;
    for($p=$out;strlen($p)>strlen($state);$p=dirname($p))if(is_link($p))throw new RuntimeException('Symlink output refused');
    if(is_file($out.'/inputs.json')&&PaperRecoveryCloseAccount::differences(PaperKrxSignalStudy::json($out.'/inputs.json'),$identity)!==[])throw new RuntimeException('Research inputs changed; do not resume');
    if($cmd==='validate'){echo PaperKrxSignalStudy::encode(['pass'=>true,'sessions'=>243,'symbols'=>count($loaded['data']['symbols']),'writes'=>false])."\n";exit(0);}
    if(!is_dir($out)&&!mkdir($out,0770,true))throw new RuntimeException('Cannot create research folder');
    if(is_link($out.'/.lock'))throw new RuntimeException('Symlink lock refused');$lock=fopen($out.'/.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another run active');
    // Recheck after locking.
    if(is_file($out.'/inputs.json')){
        if(PaperRecoveryCloseAccount::differences(PaperKrxSignalStudy::json($out.'/inputs.json'),$identity)!==[])throw new RuntimeException('Research inputs changed');
    }else{
        if(glob($out.'/*/account-replay.json'))throw new RuntimeException('Orphan research account');
        PaperKrxSignalStudy::save($out.'/inputs.json',$identity);
    }
    $path=static function(string $a,bool $check=false,?int $length=null)use($out,$n):string {
        $p=$out.'/'.$a.($check?'-check-'.($length??$n):'').'/account-replay.json';
        if(is_link(dirname($p))||is_link($p)||is_link($p.'.lock'))throw new RuntimeException('Symlink journal refused');return $p;
    };
    $days=array_slice($loaded['days'],0,$n);$through=end($days);
    $read=static function(string $a,bool $c=false,?int $length=null)use($path,$configs,$loaded,$code):array{
        $j=(new PaperJournal($path($a,$c,$length)))->read()??throw new RuntimeException('Required journal missing');$s=$j['state'];
        PaperStrategyVersion::verify($s,PaperDatedTop100Replay::STRATEGY_FINGERPRINT);
        $pinned=\ChartEntryLab\PaperScanUniverse::pinned($configs[$a]);
        if(($s['config_hash']??'')!==hash('sha256',PaperJournal::encode($pinned))||$s['config']!==$pinned||$s['mode']!=='replay')throw new RuntimeException('Journal config mismatch');
        $hashes=[];foreach($loaded['data']['symbols'] as $sym=>$d)$hashes[$sym]=$d['bars_sha256'];
        $want=['dataset'=>$loaded['data']['dataset'],'dataset_files'=>$loaded['data']['files'],'bars_sha256'=>$hashes,
            'code_sha256_lf'=>$code,'candidates_sha256'=>$loaded['candidates']['file_sha256'],
            'sector_map_file_sha256'=>PaperNoPullbackStudy::SECTOR_SHA,'plan_source'=>'krx_guarded_asof_engine'];
        foreach($want as $k=>$v)if(($s['research'][$k]??null)!==$v)throw new RuntimeException('Journal identity mismatch '.$k);
        return $j;
    };
    $completed=static function(array $j)use($days):void{
        if(!empty($j['state']['halted'])||array_column(PaperAccountReplay::dailyLog($j),'date')!==$days)throw new RuntimeException('Required account incomplete or halted');
    };
    $verify=static function(array $j,bool $variant)use($loaded):array{
        $v=PaperNoPullbackStudy::verify($j,$loaded,$variant);
        if(!$variant){$v['baseline_match']=PaperNoPullbackStudy::baselineMatch($j,$loaded['source_baseline']);$v['pass']=$v['pass']&&$v['baseline_match']['pass'];}
        if(!$v['pass'])throw new RuntimeException('Books/order verification failed: '.PaperKrxSignalStudy::encode($v));return $v;
    };
    $pair=static function(string $a)use($read,$completed,$verify):array{
        $j=$read($a);$once=$read($a,true);$completed($j);$completed($once);$v=$verify($j,$a==='variant');$verify($once,$a==='variant');
        if(PaperAccountReplay::normalized($j)!==PaperAccountReplay::normalized($once))throw new RuntimeException('Split/once differ');return [$j,$v];
    };
    $save=static function(string $name,array $v)use($out):void{
        $p=$out.'/'.$name;if(is_link($p))throw new RuntimeException('Symlink output refused');
        if(is_file($p)&&PaperKrxSignalStudy::digest(PaperKrxSignalStudy::json($p))===PaperKrxSignalStudy::digest($v))return;
        PaperKrxSignalStudy::save($p,$v);
    };
    if($n===243)foreach(['baseline','variant'] as $a){
        $smoke=$read($a,true,8);
        if(!empty($smoke['state']['halted'])||array_column(PaperAccountReplay::dailyLog($smoke),'date')!==array_slice($loaded['days'],0,8))throw new RuntimeException('Both 8-session checks required before full run');
        $verify($smoke,$a==='variant');
    }
    if(str_starts_with($cmd,'variant')||$cmd==='report')[$base,$baseVerify]=$pair('baseline');
    if($cmd==='report'){
        [$variant,$varVerify]=$pair('variant');$r=PaperRecoveryCloseAccount::compare($base,$variant);
        $r['kind']='krx_no_pullback_account_v1';$r['period']=['from'=>$days[0],'through'=>$through,'sessions'=>$n];
        $r['label']='KRX 일별 TOP100 연구 · 상승 눌림 제외 계좌 비교';
        $r['notes'][]='기준은 PR96 원조건. 대안은 선택 단계에서 눌림만 제외. recovery_close 완화 아님. 새 재지지·회복 추천은 as-of 근거 재검사, PR95 합집합으로 제한하지 않음.';
        $r['notes']=array_values(array_filter($r['notes'],fn($s)=>!str_contains($s,'injected_test')));
        $r['validation']=['baseline'=>$baseVerify,'variant'=>$varVerify,'split_once_pass'=>true];
        $r['input_identity']=$identity;$r['price_basis']=PaperKrxSignalStudy::BASIS;
        $r['journal_sha256']=['baseline'=>hash_file('sha256',$path('baseline')),'variant'=>hash_file('sha256',$path('variant'))];
        $save('comparison.json',$r);echo PaperKrxSignalStudy::encode(['report'=>$out.'/comparison.json','complete'=>true,'operational_writes'=>false])."\n";exit(0);
    }
    $check=str_ends_with($cmd,'-check');$arm=str_replace('-check','',$cmd);if($check)$completed($read($arm));
    $existing=(new PaperJournal($path($arm,$check)))->read();
    if(($existing['state']['last_session']??0)>PaperDatedTop100Replay::sessionClose($through))throw new RuntimeException('Cannot shorten completed account scope');
    $run=PaperKrxAccountReplay::run($loaded['data'],$configs[$arm],$path($arm,$check),$loaded['candidates'],[
        'start'=>$days[0],'through'=>$through,'sector_map'=>$loaded['sector'],'sector_map_file_sha256'=>PaperNoPullbackStudy::SECTOR_SHA,
        'chunk'=>$check?243:3,'runtime_code_extra'=>PaperNoPullbackStudy::FILES,
        'plan_provider'=>PaperNoPullbackStudy::provider($arm==='variant',$loaded['data']['breaks'],$loaded['reference'])]);
    $j=$read($arm,$check);$v=$verify($j,$arm==='variant');
    $complete=empty($j['state']['halted'])&&array_column(PaperAccountReplay::dailyLog($j),'date')===$days;
    if($check&&$complete)$pair($arm);
    $result=['complete'=>$complete,'requested_sessions'=>$n,'verification'=>$v,'halted'=>!empty($j['state']['halted']),
        'halt_at'=>$j['state']['halt_at']??null,'halt_issues'=>$j['state']['halt_issues']??[],
        'summary'=>PaperAccountReplay::summary($j),'operational_writes'=>false];
    $save($cmd.'-'.$n.'-verification.json',$result);
    $save($cmd.'-'.$n.'-trades.json',['trades'=>PaperAccountReplay::trades($j)]);
    echo PaperKrxSignalStudy::encode(['run'=>$run,'complete'=>$complete,'verification_pass'=>true,'output'=>$out])."\n";
    if(!$complete)exit(1);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
