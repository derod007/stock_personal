<?php
declare(strict_types=1);
require __DIR__.'/paper/RecoveryCloseAccount.php';
use ChartEntryLab\PaperJournal;
try {
    $cmd=$argv[1]??'help';$o=[];
    foreach(array_slice($argv,2) as $a){
        if(!preg_match('/^--(state-dir|sessions|confirm-full-run)=(.+)$/',$a,$m))throw new InvalidArgumentException('Unknown argument '.$a);
        if(isset($o[$m[1]]))throw new InvalidArgumentException('Duplicate option');$o[$m[1]]=$m[2];
    }
    if($cmd==='help'){
        echo "Commands: validate, baseline, variant, report\nDefault: at most 8 new sessions. --sessions=all --confirm-full-run=after-code-review permits remaining sessions.\n";exit(0);
    }
    if(!in_array($cmd,['validate','baseline','variant','report'],true))throw new InvalidArgumentException('Unknown command');
    if(isset($o['state-dir']))putenv('PAPER_STATE_DIR='.$o['state-dir']);
    $limit=$o['sessions']??'8';
    if($limit==='all'){
        if(($o['confirm-full-run']??'')!=='after-code-review')throw new RuntimeException('Full run confirmation is missing');
    }elseif(!ctype_digit($limit)||(int)$limit<1||(int)$limit>244)throw new InvalidArgumentException('sessions must be 1..244 or all');
    if($limit!=='all'&&(int)$limit>8&&($o['confirm-full-run']??'')!=='after-code-review')throw new RuntimeException('More than 8 sessions requires full run confirmation');
    $root=dirname(__DIR__);$state=PaperAccountReplay::stateRoot();
    if(!is_dir($state)||is_link($state))throw new RuntimeException('Existing nonsymlink PAPER_STATE_DIR is required');
    $state=str_replace('\\','/',(string)realpath($state));
    if(str_starts_with(strtolower($state).'/',strtolower(str_replace('\\','/',(string)realpath($root))).'/'))throw new RuntimeException('Research state must be outside repository');
    PaperRecoveryCloseAccount::assertMirror($root);
    if(PaperStrategyVersion::current()!==PaperDatedTop100Replay::STRATEGY_FINGERPRINT)throw new RuntimeException('Strategy fingerprint changed');
    $fixed=PaperRecoveryCloseAccount::read($root.'/docs/recovery-close-account/frozen-inputs.json');
    foreach($fixed['repository_lf_sha256'] as $file=>$sha){
        if(PaperStrategyVersion::fileHash($root.'/'.$file)!==$sha)throw new RuntimeException('Frozen repository input changed: '.$file);
    }
    $profile=PaperDatedTop100Replay::profile(PaperDatedTop100Replay::PROFILE_YEAR);
    $datasetDir=PaperHistoryResearch::datasetDir($state,PaperDatedTop100Replay::DATASET_YEAR);
    foreach($fixed['dataset_sha256'] as $file=>$sha)if(hash_file('sha256',$datasetDir.'/'.$file)!==$sha)throw new RuntimeException('Frozen dataset changed: '.$file);
    $cand=PaperDatedTop100Replay::loadCandidates($root.'/'.$profile['candidates'],244,933);
    $data=PaperDatedTop100Replay::loadDataset($datasetDir,$cand,$profile['dataset'],$profile['eval_start'],$profile['as_of']);
    $sector=PaperDatedTop100Replay::pinSectorMap($root.'/'.PaperDatedTop100Replay::YEAR_SECTOR_MAP,PaperDatedTop100Replay::YEAR_SECTOR_MAP_SHA256);
    $cfgs=PaperDatedTop100Replay::configs($root,PaperDatedTop100Replay::RESEARCH_CONFIG_YEAR);
    $research=$cfgs['research'];$research['id_prefix']='hist-rc-account-v1-';$research['smoke_id_prefix']='hist-rc-smoke-v1-';
    $paths=[];$configs=[];
    foreach(['baseline','variant'] as $arm){
        $id=$research['id_prefix'].$arm;
        $configs[$arm]=PaperAccountReplay::accountConfig($cfgs['base'],$id,$research);
        $paths[$arm]=PaperAccountReplay::journalPath(PaperAccountReplay::accountDir($state,$research,$id));
        // No aliases to old accounts/datasets via symlinks, even if the textual path looks safe.
        for($p=dirname($paths[$arm]);strlen($p)>strlen($state);$p=dirname($p))if(is_link($p))throw new RuntimeException('Symlink research path refused');
        if(is_link($paths[$arm]))throw new RuntimeException('Symlink journal refused');
    }
    $outdir=$state.'/history-research/recovery-close-account-v1';
    foreach([$state.'/history-research',$outdir] as $p)if(is_link($p))throw new RuntimeException('Symlink output refused');
    $bars=[];foreach($data['symbols'] as $sym=>$info)$bars[$sym]=$info['bars_sha256'];ksort($bars);
    if(hash('sha256',PaperJournal::encode($bars))!==$fixed['bars_hash'])throw new RuntimeException('Frozen bar hashes changed');
    $manifest=['php_version'=>PHP_VERSION,'kind'=>PaperRecoveryCloseAccount::VERSION,'strategy_fingerprint'=>PaperStrategyVersion::current(),
        'dataset_files'=>$data['files'],'bars_sha256'=>$bars,'candidates_sha256'=>$cand['file_sha256'],
        'sector_sha256'=>$sector['_file_sha256_lf'],'configs'=>$configs,
        'code_sha256_lf'=>PaperDatedTop100Replay::runtimeCodeHashes(PaperRecoveryCloseAccount::FILES),
        'variant_source'=>'RecoveryCloseStudy\\ChartPlanEngine: only recovery_close disabled; account1, context on',
        'low_level_variant_plan_source'=>'injected_test (legacy callback label, not synthetic recommendations)'];
    $manifestPath=$outdir.'/inputs.json';
    if(is_link($manifestPath))throw new RuntimeException('Symlink manifest refused');
    if(is_file($manifestPath)&&PaperRecoveryCloseAccount::differences(PaperRecoveryCloseAccount::read($manifestPath),$manifest)!==[])
        throw new RuntimeException('Research input changed; resume refused');
    $ref=PaperRecoveryCloseAccount::reference($root);
    $out=static fn($v)=>print(PaperHistoryResearch::encode($v,true)."\n");
    if($cmd==='validate'){$out(['pass'=>true,'sessions'=>count($cand['days']),'symbols'=>count($cand['union']),'writes'=>false,'variant_requires_full_baseline'=>true]);exit(0);}
    if(!is_dir($outdir)&&!mkdir($outdir,0770,true)&&!is_dir($outdir))throw new RuntimeException('Cannot create research output');
    if(is_link($outdir.'/.lock'))throw new RuntimeException('Symlink lock refused');
    $lock=fopen($outdir.'/.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another comparison process is running');
    if(is_file($manifestPath)&&PaperRecoveryCloseAccount::differences(PaperRecoveryCloseAccount::read($manifestPath),$manifest)!==[])throw new RuntimeException('Research input changed; resume refused');
    $baselineJournal=(new PaperJournal($paths['baseline']))->read();
    $gate=null;$baselineBooks=null;
    if(in_array($cmd,['variant','report'],true)){
        if($baselineJournal===null||!is_file($manifestPath))throw new RuntimeException('Complete baseline first');
        $gate=PaperRecoveryCloseAccount::baselineCheck($baselineJournal,$ref);
        $baselineBooks=PaperAccountReplay::reconcile($baselineJournal,$data);
        PaperRecoveryCloseAccount::requireBaseline($gate,$baselineBooks);
    }
    $save=static function(string $name,array $v)use($outdir):void{
        $path=$outdir.'/'.$name;if(is_link($path))throw new RuntimeException('Symlink output refused');
        PaperHistoryResearch::writeFile($path,PaperHistoryResearch::encode($v,true));
    };
    if($cmd==='report'){
        $variantJournal=(new PaperJournal($paths['variant']))->read()??throw new RuntimeException('Run variant first');
        if(count(PaperAccountReplay::dailyLog($variantJournal))!==244||!empty($variantJournal['state']['halted']))throw new RuntimeException('Variant is incomplete or halted');
        $books=PaperAccountReplay::reconcile($variantJournal,$data);
        $audits=['baseline'=>PaperRecoveryCloseAccount::orderAudit($baselineJournal,$data,$cand,false),
            'variant'=>PaperRecoveryCloseAccount::orderAudit($variantJournal,$data,$cand,true)];
        if(!$books['pass']||!$audits['baseline']['pass']||!$audits['variant']['pass'])throw new RuntimeException('Reconciliation or order audit failed');
        $report=PaperRecoveryCloseAccount::compare($baselineJournal,$variantJournal);
        $report['validation']=['baseline'=>$gate,'baseline_books'=>$baselineBooks,'variant_books'=>$books,'orders'=>$audits];
        $report['inputs_sha256']=hash_file('sha256',$manifestPath);
        $report['journal_sha256']=array_map(fn($p)=>hash_file('sha256',$p),$paths);
        $save('comparison.json',$report);
        $out(['report'=>$outdir.'/comparison.json','equity_delta'=>$report['equity_delta'],'changes'=>$report['change_counts'],'operational_writes'=>false]);exit(0);
    }
    if(!is_file($manifestPath)){
        if(file_exists($paths['baseline'])||file_exists($paths['variant']))throw new RuntimeException('Orphan research account; do not overwrite');
        $save('inputs.json',$manifest);
    }
    $before=PaperAccountReplay::treeHash($datasetDir);
    $opts=['sector_map'=>$sector,'sector_map_file_sha256'=>$sector['_file_sha256_lf'],
        'start'=>$profile['run_start'],'through'=>$profile['run_through'],'chunk'=>3,
        'runtime_code_extra'=>PaperRecoveryCloseAccount::FILES];
    if($limit!=='all')$opts['stop_after_sessions']=(int)$limit;
    if($cmd==='variant')$opts['plan_provider']=PaperRecoveryCloseAccount::provider();
    $res=PaperDatedTop100Replay::run($data,$configs[$cmd],$paths[$cmd],$cand,$opts);
    if($before!==PaperAccountReplay::treeHash($datasetDir))throw new RuntimeException('Dataset changed during replay');
    $j=(new PaperJournal($paths[$cmd]))->read()??throw new RuntimeException('No journal');
    $books=PaperAccountReplay::reconcile($j,$data);$audit=PaperRecoveryCloseAccount::orderAudit($j,$data,$cand,$cmd==='variant');
    $summary=PaperAccountReplay::summary($j);$complete=($summary['event_counts']['day_log']??0)===244&&!$summary['halted'];
    $check=$cmd==='baseline'&&$complete?PaperRecoveryCloseAccount::baselineCheck($j,$ref):null;
    $save($cmd.'-verification.json',['run'=>$res,'complete'=>$complete,'books'=>$books,'order_audit'=>$audit,'baseline_match'=>$check]);
    $save($cmd.'-summary.json',$summary);$save($cmd.'-trades.json',['trades'=>PaperAccountReplay::trades($j)]);
    $out(['run'=>$res,'complete'=>$complete,'books_pass'=>$books['pass'],'order_audit_pass'=>$audit['pass'],'baseline_match'=>$check,'output_dir'=>$outdir]);
    if(!$books['pass']||!$audit['pass']||($check!==null&&!$check['pass'])||$res['halted'])exit(1);
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
