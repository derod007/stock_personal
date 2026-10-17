<?php
declare(strict_types=1);
require __DIR__.'/paper/RecentNoPullbackStudy.php';
use ChartEntryLab\PaperJournal;
try {
    $cmd=$argv[1]??'help';$o=[];foreach(array_slice($argv,2) as $arg){
        if(!preg_match('/^--(state-dir|sessions|confirm-full-run)=(.+)$/',$arg,$m)||isset($o[$m[1]]))throw new RuntimeException('Unknown/duplicate option');$o[$m[1]]=$m[2];
    }
    if($cmd==='help'){echo "validate | baseline | baseline-check | variant | variant-check | report [--state-dir=DIR --sessions=8|all]\nFull run requires --confirm-full-run=after-code-review\n";exit(0);}
    if(!in_array($cmd,['validate','baseline','baseline-check','variant','variant-check','report'],true))throw new RuntimeException('Unknown command');
    $n=$o['sessions']??'8';if(!in_array($n,['8','all'],true))throw new RuntimeException('sessions must be 8 or all');$n=$n==='all'?244:8;
    if($n===244&&($o['confirm-full-run']??'')!=='after-code-review')throw new RuntimeException('Full run confirmation missing');
    if($cmd==='report'&&$n!==244)throw new RuntimeException('Comparison requires all sessions');
    $root=dirname(__DIR__);$state=realpath($o['state-dir']??getenv('PAPER_STATE_DIR')?:'');
    if(!$state||!is_dir($state)||is_link($state))throw new RuntimeException('Existing state directory required');
    $state=str_replace('\\','/',$state);$repo=str_replace('\\','/',realpath($root));
    if(str_starts_with(strtolower($state).'/',strtolower($repo).'/'))throw new RuntimeException('State must be outside repository');
    $loaded=PaperRecentNoPullbackStudy::load($root,$state);
    $code=PaperDatedTop100Replay::runtimeCodeHashes(PaperRecentNoPullbackStudy::FILES);$configs=[];
    foreach(['baseline','variant'] as $a)$configs[$a]=PaperRecentNoPullbackStudy::config($root,$a);
    $identity=['kind'=>'recent-no-pullback-account-v1','php'=>PHP_VERSION,'fixed_inputs_sha256'=>PaperRecentNoPullbackStudy::FIXED_SHA,
        'code_sha256_lf'=>$code,'configs'=>$configs,'sector_sha256'=>PaperRecentNoPullbackStudy::SECTOR_SHA];
    $out=$state.'/'.PaperRecentNoPullbackStudy::OUTPUT;
    for($p=$out;strlen($p)>strlen($state);$p=dirname($p))if(is_link($p))throw new RuntimeException('Symlink output refused');
    if(is_file($out.'/inputs.json')&&PaperRecoveryCloseAccount::differences(PaperKrxSignalStudy::json($out.'/inputs.json'),$identity)!==[])throw new RuntimeException('Research inputs changed; do not resume');
    if($cmd==='validate'){echo PaperKrxSignalStudy::encode(['pass'=>true,'sessions'=>244,'symbols'=>count($loaded['data']['symbols']),'writes'=>false])."\n";exit(0);}
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
            'sector_map_file_sha256'=>PaperRecentNoPullbackStudy::SECTOR_SHA,'plan_source'=>$a==='baseline'?'engine':'injected_test'];
        foreach($want as $k=>$v)if(($s['research'][$k]??null)!==$v)throw new RuntimeException('Journal identity mismatch '.$k);
        return $j;
    };
    $completed=static function(array $j)use($days):void{
        if(!empty($j['state']['halted'])||array_column(PaperAccountReplay::dailyLog($j),'date')!==$days)throw new RuntimeException('Required account incomplete or halted');
    };
    $verify=static function(array $j,bool $variant)use($loaded):array{
        $v=PaperRecentNoPullbackStudy::verify($j,$loaded,$variant);
        if(!$variant){$v['baseline_match']=PaperRecentNoPullbackStudy::baselineMatch($j,$loaded['reference']);$v['pass']=$v['pass']&&$v['baseline_match']['pass'];}
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
    if($n===244)foreach(['baseline','variant'] as $a){
        $smoke=$read($a,true,8);
        if(!empty($smoke['state']['halted'])||array_column(PaperAccountReplay::dailyLog($smoke),'date')!==array_slice($loaded['days'],0,8))throw new RuntimeException('Both 8-session checks required before full run');
        $verify($smoke,$a==='variant');
    }
    if(str_starts_with($cmd,'variant')||$cmd==='report')[$base,$baseVerify]=$pair('baseline');
    if($cmd==='report'){
        [$variant,$varVerify]=$pair('variant');$r=PaperRecoveryCloseAccount::compare($base,$variant);
        $r['kind']='recent_no_pullback_account_v1';$r['period']=['from'=>$days[0],'through'=>$through,'sessions'=>$n];
        $r['label']='최근 Yahoo 일봉 · KRX 일별 TOP100 · 상승 눌림 제외 계좌 비교';
        $r['notes'][]='기준은 PR76 최근 원조건. PR97과 같은 선택 단계 눌림 제외. recovery_close 완화 아님. 최근 Yahoo 데이터와 기존 품질·정지 규칙 사용. 이전 KRX 연구와 직접 합산 금지.';
        $r['notes']=array_values(array_filter($r['notes'],fn($s)=>!str_contains($s,'injected_test')));
        $r['validation']=['baseline'=>$baseVerify,'variant'=>$varVerify,'split_once_pass'=>true];
        $r['input_identity']=$identity;$r['price_basis']=PaperHistoryResearch::PRICE_BASIS;
        $r['notes'][]='injected_test는 기존 콜백 경로 명칭이다. 합성 추천이 아니라 고정 NoPullbackStudy 엔진의 실제 as-of 판정이다. PR95/96 KRX 단절 보류·원자적 정지 가정을 추가하지 않았다.';
        $r['journal_sha256']=['baseline'=>hash_file('sha256',$path('baseline')),'variant'=>hash_file('sha256',$path('variant'))];
        $save('comparison.json',$r);echo PaperKrxSignalStudy::encode(['report'=>$out.'/comparison.json','complete'=>true,'operational_writes'=>false])."\n";exit(0);
    }
    $check=str_ends_with($cmd,'-check');$arm=str_replace('-check','',$cmd);if($check)$completed($read($arm));
    $existing=(new PaperJournal($path($arm,$check)))->read();
    if(!empty($existing['state']['halted']))throw new RuntimeException('Existing account halted; preserve journal and stop');
    if(($existing['state']['last_session']??0)>PaperDatedTop100Replay::sessionClose($through))throw new RuntimeException('Cannot shorten completed account scope');
    $run=PaperDatedTop100Replay::run($loaded['data'],$configs[$arm],$path($arm,$check),$loaded['candidates'],[
        'start'=>$days[0],'through'=>$through,'sector_map'=>$loaded['sector'],'sector_map_file_sha256'=>PaperRecentNoPullbackStudy::SECTOR_SHA,
        'chunk'=>$check?244:3,'runtime_code_extra'=>PaperRecentNoPullbackStudy::FILES,
        'plan_provider'=>PaperRecentNoPullbackStudy::provider($arm==='variant')]);
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
