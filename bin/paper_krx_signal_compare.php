<?php
declare(strict_types=1);
require __DIR__.'/paper/KrxSignalStudy.php';
try {
    $cmd=$argv[1]??'help';$o=[];
    foreach(array_slice($argv,2) as $arg){
        if(!preg_match('/^--(bars|prep-raw|eval-raw|state-dir|sessions)=(.+)$/',$arg,$m)||isset($o[$m[1]]))throw new RuntimeException('Unknown/duplicate option '.$arg);
        $o[$m[1]]=$m[2];
    }
    if($cmd==='help'){echo "validate | run | report [--bars=DIR --prep-raw=DIR --eval-raw=DIR --state-dir=DIR --sessions=8|all]\nSessions is cumulative first N evaluation sessions; default 8. No accounts or trades.\n";exit(0);}
    if(!in_array($cmd,['validate','run','report'],true))throw new RuntimeException('Unknown command');
    $root=dirname(__DIR__);$p=PaperKrxSignalStudy::profile($root);
    $defaults=PaperKrxSignalStudy::json($root.'/'.PaperKrxSignalStudy::DATA.'/manifest.json');
    $dirs=[];
    foreach(['bars'=>'bars','prep-raw'=>'prep_raw','eval-raw'=>'eval_raw'] as $key=>$field){
        $dir=$o[$key]??$defaults[$field];$real=realpath($dir);
        if($real===false||!is_dir($real))throw new RuntimeException('Directory missing; specify --'.$key.'=DIR: '.$dir);
        $dirs[$key]=str_replace('\\','/',$real);
    }
    $n=$o['sessions']??'8';
    if($n!=='all'&&(!ctype_digit($n)||(int)$n<1||(int)$n>243))throw new RuntimeException('sessions must be 1..243 or all');
    $days=array_slice($p['eval_days'],0,$n==='all'?243:(int)$n);
    // Every invocation checks the entire frozen input, not just the currently requested prefix.
    $verified=PaperKrxSignalStudy::validate($p,$dirs['bars'],$dirs['prep-raw'],$dirs['eval-raw']);
    $codePaths=array_merge(PaperKrxSignalStudy::json($root.'/config/paper-strategy-files.json'),[
        'bin/bootstrap.php','bin/paper/KrxSignalStudy.php','bin/paper_krx_signal_compare.php',
        'bin/paper/RecoveryCloseAccount.php','bin/paper/RecoveryClosePullback.php','bin/paper/RecoveryCloseEngine.php',
        'bin/paper/DatedTop100Replay.php','bin/paper/StrategyVersion.php',
        PaperKrxSignalStudy::DOC.'/protocol.md',PaperKrxSignalStudy::DOC.'/frozen-inputs.json']);
    $code=[];sort($codePaths);foreach(array_unique($codePaths) as $f)$code[$f]=PaperStrategyVersion::fileHash($root.'/'.$f);
    $inputs=['kind'=>PaperKrxSignalStudy::VERSION,'php_version'=>PHP_VERSION,'strategy_fingerprint'=>PaperStrategyVersion::current(),
        'price_basis'=>PaperKrxSignalStudy::BASIS,'profile'=>'account1','context'=>true,'only_intervention'=>'trend_pullback recovery_close removed',
        'code_sha256_lf'=>$code,'verified_inputs'=>$verified,'operational_writes'=>false,'account_replay_ran'=>false];
    $inputsHash=PaperKrxSignalStudy::digest($inputs);
    if($cmd==='validate'){
        echo PaperKrxSignalStudy::encode(['pass'=>true,'symbols'=>$verified['symbols'],'calendar_days'=>$verified['calendar_days'],
            'status_counts'=>$verified['status_counts'],'raw_value_comparison_pass'=>true,'inputs_sha256'=>$inputsHash,'writes'=>false])."\n";exit(0);
    }
    $state=realpath($o['state-dir']??PaperAccountReplay::stateRoot());
    if($state===false||!is_dir($state))throw new RuntimeException('Existing --state-dir required');
    $state=str_replace('\\','/',$state);$out=$state.'/history-research/'.PaperKrxSignalStudy::VERSION;
    foreach(array_merge([$root],array_values($dirs)) as $inputDir){
        $base=strtolower(str_replace('\\','/',(string)realpath($inputDir)));
        if(str_starts_with(strtolower($out).'/',rtrim($base,'/').'/'))throw new RuntimeException('Output must be outside repository and input directories');
    }
    foreach([$state.'/history-research',$out,$out.'/symbols'] as $dir){
        if(is_link($dir))throw new RuntimeException('Symlink research directory refused');
        if(!is_dir($dir)){
            if($cmd==='report')throw new RuntimeException('Run first');
            if(!mkdir($dir,0770)&&!is_dir($dir))throw new RuntimeException('Cannot create output directory');
        }
    }
    if(is_link($out.'/.lock'))throw new RuntimeException('Symlink lock refused');
    $lock=fopen($out.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another research process is running');
    $manifest=$out.'/inputs.json';
    if(is_link($manifest))throw new RuntimeException('Symlink manifest refused');
    if(is_file($manifest)){
        if(PaperKrxSignalStudy::digest(PaperKrxSignalStudy::json($manifest))!==$inputsHash)throw new RuntimeException('Research input/code/runtime changed; resume refused');
    }else{
        if($cmd==='report'||glob($out.'/symbols/*'))throw new RuntimeException('Missing manifest/orphan results');
        PaperKrxSignalStudy::save($manifest,$inputs);
    }
    $all=[];$processed=0;$visited=0;
    foreach($p['symbols'] as $symbol=>$_){
        $path=$out.'/symbols/'.$symbol.'.json';
        if(is_link($path))throw new RuntimeException('Symlink result refused');
        $results=is_file($path)?PaperKrxSignalStudy::readResult($path,$inputsHash,$symbol):[];
        $wanted=[];foreach($days as $day){$t=PaperDatedTop100Replay::sessionClose($day);if(in_array($symbol,$p['candidates'][$t]['symbols'],true))$wanted[]=$day;}
        foreach($results as $day=>$rec)if(!in_array($day,$wanted,true)||($rec['day']??null)!==$day||($rec['symbol']??null)!==$symbol)
            throw new RuntimeException('Unexpected result date/identity; do not shorten --sessions on resume');
        $todo=array_values(array_diff($wanted,array_keys($results)));
        if($todo!==[]){
            if($cmd==='report')throw new RuntimeException('Incomplete results: '.$symbol);
            $src=$dirs['bars'].'/'.$symbol.'.jsonl';
            if(hash_file('sha256',$src)!==$verified['jsonl_sha256'][$symbol])throw new RuntimeException('Dataset changed after validation');
            $rows=iterator_to_array(PaperKrxSignalStudy::rows($src));
            $extended=PaperKrxSignalStudy::extend($rows,$symbol,$wanted,$p['breaks'][$symbol]??[],$results);
            $results=$extended['results'];$processed+=$extended['processed'];
            if(hash_file('sha256',$src)!==$verified['jsonl_sha256'][$symbol])throw new RuntimeException('Dataset changed during computation');
            ksort($results);
            PaperKrxSignalStudy::save($path,['inputs_sha256'=>$inputsHash,'symbol'=>$symbol,'results_sha256'=>PaperKrxSignalStudy::digest($results),'results'=>$results]);
        }
        foreach($results as $r)$all[]=$r;
        if(++$visited%100===0)fwrite(STDERR,"checked_symbols=$visited new_pairs=$processed\n");
    }
    usort($all,fn($a,$b)=>[$a['day'],$a['symbol']]<=>[$b['day'],$b['symbol']]);
    if(count($all)!==count($days)*100)throw new RuntimeException('Expected exactly 100 candidates per session');
    $summary=PaperKrxSignalStudy::summary($all,$days);
    $review=[];
    foreach($all as $r)if(!empty($r['baseline']['ready'])||!empty($r['variant']['ready'])||$r['plans_differ']){
        $w=$r['input_warnings'];
        $review[]=['symbol'=>$r['symbol'],'day'=>$r['day'],'baseline'=>$r['baseline'],'variant'=>$r['variant'],
            'baseline_quality_eligible'=>$r['baseline_quality_eligible'],'variant_quality_eligible'=>$r['variant_quality_eligible'],
            'quality_reasons'=>$r['quality']['reasons'],'breaks_in_history'=>$w['breaks_in_history'],
            'ohl_zero_count'=>count($w['ohl_zero_days']),'ohl_zero_recent90_count'=>count($w['ohl_zero_recent90_days']),
            'leading_no_row_count'=>count($w['leading_no_row_days']),'internal_no_row_count'=>count($w['internal_no_row_days']),
            'detail_file'=>'symbols/'.$r['symbol'].'.json'];
    }
    $report=['kind'=>PaperKrxSignalStudy::VERSION,'inputs_sha256'=>$inputsHash,'from'=>$days[0],'through'=>end($days),
        'complete'=>count($days)===243,'summary'=>$summary,'review_cases'=>$review,'results_sha256'=>PaperKrxSignalStudy::digest($all),
        'price_basis'=>PaperKrxSignalStudy::BASIS,'account_replay_ran'=>false,'operational_writes'=>false,
        'independent_strategy_implementation'=>false,'notes'=>[
            '원가격 진단. 추천은 실제 매수 가능성·수익성·계좌 주문을 뜻하지 않음',
            'quality_eligible은 기존 PaperQuality만 통과. 기업행사·가격 기준 안전성을 보증하지 않음',
            '단절 목록은 부분 탐지이며 추천 결과의 원인으로 확정하지 않음',
            '기준/대안은 같은 KRX 자료 비교. 기존 Yahoo 계좌와 수익률 대조하지 않음',
            '행 없음은 봉으로 만들지 않음. 중간 공백과 초기 공백은 관찰 구분이며 상장·정지 원인 확정 아님',
            '과거 전체를 쓰는 엔진이므로 시작 전 240일 준비 길이도 연구 입력 가정',
            '보유·대기·기업행사 수량·체결·수익률 검증 없음']];
    $reportPath=$out.'/report-'.count($days).'.json';
    if(!is_file($reportPath)||PaperKrxSignalStudy::digest(PaperKrxSignalStudy::json($reportPath))!==PaperKrxSignalStudy::digest($report))PaperKrxSignalStudy::save($reportPath,$report);
    echo PaperKrxSignalStudy::encode(['report'=>$reportPath,'processed_pairs'=>$processed,'summary'=>$summary,'complete'=>$report['complete'],
        'account_replay_ran'=>false,'operational_writes'=>false])."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
