<?php
declare(strict_types=1);
require __DIR__.'/paper/RecoveryClosePriorAccount.php';
use ChartEntryLab\PaperJournal;
try {
    $cmd=$argv[1]??'help';$o=[];
    foreach(array_slice($argv,2) as $a){
        if(!preg_match('/^--(state-dir|sessions|confirm-full-run)=(.+)$/',$a,$m)||isset($o[$m[1]]))throw new InvalidArgumentException('Invalid/duplicate argument '.$a);
        $o[$m[1]]=$m[2];
    }
    if($cmd==='help'){echo "validate | baseline | baseline-check | variant | variant-check | report\nDefault 8 sessions; --sessions=all --confirm-full-run=after-code-review for remainder/check.\n";exit(0);}
    PaperRecoveryClosePriorAccount::demand(in_array($cmd,['validate','baseline','baseline-check','variant','variant-check','report'],true),'Unknown command');
    $check=str_ends_with($cmd,'-check');$arm=str_replace('-check','',$cmd);$n=$o['sessions']??'8';
    PaperRecoveryClosePriorAccount::demand($n==='all'||(ctype_digit($n)&&(int)$n>=1&&(int)$n<=243),'Invalid session count');
    if($n==='all'||(int)$n>8)PaperRecoveryClosePriorAccount::demand(($o['confirm-full-run']??'')==='after-code-review','Full run confirmation missing');
    if($check)PaperRecoveryClosePriorAccount::demand($n==='all','A check must be one complete transaction; use --sessions=all');
    if(isset($o['state-dir']))putenv('PAPER_STATE_DIR='.$o['state-dir']);
    $root=dirname(__DIR__);$state=PaperAccountReplay::stateRoot();
    PaperRecoveryClosePriorAccount::demand(is_dir($state)&&!is_link($state),'Existing nonsymlink state folder required');
    $state=str_replace('\\','/',(string)realpath($state));
    PaperRecoveryClosePriorAccount::demand(!str_starts_with(strtolower($state).'/',strtolower(str_replace('\\','/',(string)realpath($root))).'/'),'State must be outside repository');
    PaperRecoveryCloseAccount::assertMirror($root);
    PaperRecoveryClosePriorAccount::demand(PaperStrategyVersion::current()===PaperDatedTop100Replay::STRATEGY_FINGERPRINT,'Operational strategy changed');
    $fixed=PaperRecoveryCloseAccount::read($root.'/docs/recovery-close-prior-account/frozen-inputs.json');
    foreach($fixed['repository_lf_sha256'] as $p=>$sha)PaperRecoveryClosePriorAccount::demand(PaperStrategyVersion::fileHash($root.'/'.$p)===$sha,'Frozen input changed '.$p);
    $recent=PaperRecoveryClosePriorAccount::recentGuard($root,$state);
    $docs=$root.'/'.PaperRecoveryClosePriorAccount::DOCS;
    $cand=PaperDatedTop100Replay::loadCandidates($docs.'/candidates.json',243,1248);
    PaperRecoveryClosePriorAccount::demand($cand['days'][0]===PaperRecoveryClosePriorAccount::START&&end($cand['days'])===PaperRecoveryClosePriorAccount::END,'Candidate period changed');
    $ds=PaperHistoryResearch::datasetDir($state,PaperRecoveryClosePriorAccount::DATASET);
    $meta=PaperRecoveryCloseAccount::read($ds.'/dataset.json');
    PaperRecoveryClosePriorAccount::demand(($meta['evaluation_start_day']??null)===PaperRecoveryClosePriorAccount::START&&($meta['as_of']['day']??null)===PaperRecoveryClosePriorAccount::END,'Dataset window differs');
    PaperRecoveryClosePriorAccount::demand(PaperRecoveryCloseAccount::differences(PaperRecoveryCloseAccount::read($ds.'/universe.json'),PaperRecoveryCloseAccount::read($docs.'/universe-v2.json'))===[],'Dataset universe differs from fixed v2');
    $data=PaperDatedTop100Replay::loadDataset($ds,$cand,PaperRecoveryClosePriorAccount::DATASET,PaperRecoveryClosePriorAccount::START,PaperRecoveryClosePriorAccount::END);
    $sector=PaperDatedTop100Replay::pinSectorMap($docs.'/sector-map-full-v2.json',$fixed['repository_lf_sha256'][PaperRecoveryClosePriorAccount::DOCS.'/sector-map-full-v2.json']);
    PaperSectorFreeze::assertCoversUnion($cand['union'],$sector);
    $outdir=$state.'/'.PaperRecoveryClosePriorAccount::OUTPUT;$paths=[];
    foreach(['baseline','variant'] as $a)foreach([false,true] as $c)$paths[$a.($c?'-check':'')]=PaperRecoveryClosePriorAccount::journalPath($state,$a,$c);
    foreach(array_merge(array_values($paths),[$outdir.'/inputs.json',$outdir.'/.lock']) as $path)
        for($p=$path;strlen($p)>strlen($state);$p=dirname($p))PaperRecoveryClosePriorAccount::demand(!is_link($p),'Symlink output refused');
    $bars=[];foreach($data['symbols'] as $s=>$d)$bars[$s]=$d['bars_sha256'];ksort($bars);
    $identity=['kind'=>PaperRecoveryClosePriorAccount::VERSION,'php_version'=>PHP_VERSION,'strategy'=>PaperStrategyVersion::current(),
        'dataset_files'=>$data['files'],'bars_sha256'=>$bars,'candidates_sha256'=>$cand['file_sha256'],
        'sector_sha256'=>$sector['_file_sha256_lf'],'recent_baseline_sha256'=>$recent['journal_sha256'],
        'configs'=>['baseline'=>PaperRecoveryClosePriorAccount::config($root,'baseline'),'variant'=>PaperRecoveryClosePriorAccount::config($root,'variant')],
        'code_sha256_lf'=>PaperDatedTop100Replay::runtimeCodeHashes(PaperRecoveryClosePriorAccount::codeFiles()),
        'variant_source'=>'PR84 RecoveryCloseStudy engine, only recovery_close removed; account1/context on; legacy callback tag injected_test'];
    $manifest=$outdir.'/inputs.json';
    $guard=static function()use($manifest,$identity):void{if(is_file($manifest))PaperRecoveryClosePriorAccount::demand(PaperRecoveryCloseAccount::differences(PaperRecoveryCloseAccount::read($manifest),$identity)===[],'Research input changed; resume refused');};$guard();
    $print=static fn(array $v)=>print(PaperHistoryResearch::encode($v,true)."\n");
    if($cmd==='validate'){$print(['pass'=>true,'sessions'=>243,'symbols'=>1248,'recent_baseline_pass'=>true,'writes'=>false]);exit(0);}
    if(!is_dir($outdir)&&!mkdir($outdir,0770,true)&&!is_dir($outdir))throw new RuntimeException('Cannot create output');
    $lock=fopen($outdir.'/.lock','c');PaperRecoveryClosePriorAccount::demand($lock!==false&&flock($lock,LOCK_EX|LOCK_NB),'Another process is running');$guard();
    $read=static function(string $a)use($paths,$identity):array{
        $j=(new PaperJournal($paths[$a]))->read()??throw new RuntimeException('Missing research account '.$a);
        PaperRecoveryClosePriorAccount::assertIdentity($j,$identity,str_replace('-check','',$a));return $j;
    };
    $save=static function(string $name,array $v)use($outdir):void{PaperRecoveryClosePriorAccount::demand(!is_link($outdir.'/'.$name),'Symlink output refused');PaperHistoryResearch::writeFile($outdir.'/'.$name,PaperHistoryResearch::encode($v,true));};
    $baselineVerification=null;$baselineSplit=null;
    if(in_array($cmd,['variant','variant-check','report'],true)){
        PaperRecoveryClosePriorAccount::demand(is_file($manifest),'Inputs manifest missing');
        $b=$read('baseline');$bc=$read('baseline-check');
        $baselineSplit=PaperRecoveryClosePriorAccount::splitCheck($b,$bc,$cand['days']);
        $baselineVerification=PaperRecoveryClosePriorAccount::verify($b,$data,$cand,false);
    }
    if($check)PaperRecoveryClosePriorAccount::completed($read($arm),$cand['days']);
    if(!is_file($manifest)){
        foreach($paths as $p)PaperRecoveryClosePriorAccount::demand(!file_exists($p),'Orphan account; do not overwrite');
        $save('inputs.json',$identity);
    }
    if($cmd==='report'){
        $v=$read('variant');$vc=$read('variant-check');$variantSplit=PaperRecoveryClosePriorAccount::splitCheck($v,$vc,$cand['days']);
        $vv=PaperRecoveryClosePriorAccount::verify($v,$data,$cand,true);
        $r=PaperRecoveryClosePriorAccount::compare($b,$v);
        $r['validation']=['recent_baseline'=>$recent,'baseline'=>$baselineVerification,'baseline_split'=>$baselineSplit,'variant'=>$vv,'variant_split'=>$variantSplit];
        $r['inputs_sha256']=hash_file('sha256',$manifest);$r['journal_sha256']=array_map(fn($p)=>hash_file('sha256',$p),$paths);
        $save('comparison.json',$r);$print(['report'=>$outdir.'/comparison.json','equity_delta'=>$r['equity_delta'],'changes'=>$r['change_counts'],'operational_writes'=>false]);exit(0);
    }
    if($check&&file_exists($paths[$cmd]))PaperRecoveryClosePriorAccount::completed($read($cmd),$cand['days']);
    $opts=['start'=>PaperRecoveryClosePriorAccount::START,'through'=>PaperRecoveryClosePriorAccount::END,
        'sector_map'=>$sector,'sector_map_file_sha256'=>$sector['_file_sha256_lf'],'chunk'=>$check?243:3,
        'runtime_code_extra'=>PaperRecoveryClosePriorAccount::codeFiles()];
    if($n!=='all')$opts['stop_after_sessions']=(int)$n;
    if($arm==='variant')$opts['plan_provider']=PaperRecoveryCloseAccount::provider();
    $before=PaperAccountReplay::treeHash($ds);
    $run=PaperDatedTop100Replay::run($data,$identity['configs'][$arm],$paths[$cmd],$cand,$opts);
    PaperRecoveryClosePriorAccount::demand(PaperAccountReplay::treeHash($ds)===$before,'Dataset changed during replay');
    $j=$read($cmd);$verification=PaperRecoveryClosePriorAccount::verify($j,$data,$cand,$arm==='variant');
    $complete=array_column(PaperAccountReplay::dailyLog($j),'date')===$cand['days']&&empty($j['state']['halted']);
    $split=$check?PaperRecoveryClosePriorAccount::splitCheck($read($arm),$j,$cand['days']):null;
    $save($cmd.'-verification.json',['run'=>$run,'complete'=>$complete,'verification'=>$verification,'split_check'=>$split]);
    $save($cmd.'-summary.json',PaperAccountReplay::summary($j));$save($cmd.'-trades.json',['trades'=>PaperAccountReplay::trades($j)]);
    $print(['run'=>$run,'complete'=>$complete,'verification_pass'=>true,'split_check'=>$split,'output_dir'=>$outdir]);
    if($run['halted'])exit(1);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
