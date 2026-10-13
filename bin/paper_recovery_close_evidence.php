<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/RecoveryCloseEvidence.php';
try {
    $args=[];foreach(array_slice($argv,1) as $arg){
        if(!preg_match('/^--(report|dataset-dir|output)=(.+)$/s',$arg,$m)||isset($args[$m[1]]))throw new InvalidArgumentException('Use --report=PATH --dataset-dir=PATH [--output=NEW_FILE]');
        $args[$m[1]]=$m[2];
    }
    $root=dirname(__DIR__);$reportPath=$args['report']??'';$datasetDir=$args['dataset-dir']??'';
    PaperOpportunityResearch::demand(is_file($reportPath)&&is_dir($datasetDir),'Report and dataset-dir are required');
    PaperOpportunityResearch::demand(hash_file('sha256',$reportPath)===PaperRecoveryCloseEvidence::REPORT_SHA256,'Frozen report byte hash mismatch; do not re-save the report');
    $report=PaperOpportunityResearch::read($reportPath);$p=$report['provenance'];
    PaperOpportunityResearch::demand(PaperStrategyVersion::current()===$p['strategy']&&$p['strategy']===PaperDatedTop100Replay::STRATEGY_FINGERPRINT,'Operating fingerprint changed');
    $required=['bin/paper/OpportunityResearch.php','bin/paper_opportunity_research.php','bin/paper/PatternReplay.php','bin/paper/DatedTop100Replay.php',
        'bin/paper/HistoryResearch.php','bin/paper/StrategyVersion.php','bin/paper/AccountReplay.php','docs/opportunity-research/protocol.md'];
    foreach($required as $f)PaperOpportunityResearch::demand(isset($p['code_sha256_lf'][$f])&&PaperStrategyVersion::fileHash($root.'/'.$f)===$p['code_sha256_lf'][$f],'Research code hash mismatch: '.$f);
    $profile=PaperDatedTop100Replay::profile(PaperDatedTop100Replay::PROFILE_YEAR);
    $cp=$root.'/'.$profile['candidates'];$lp=$root.'/'.$profile['docs'].'/account/daily-log.jsonl';
    PaperOpportunityResearch::demand(PaperStrategyVersion::fileHash($cp)===$p['candidates_sha256_lf']&&PaperStrategyVersion::fileHash($lp)===$p['daily_log_sha256_lf'],'Candidates or baseline log changed');
    $c=PaperDatedTop100Replay::loadCandidates($cp,244,933);
    $data=PaperDatedTop100Replay::loadDataset($datasetDir,$c,$profile['dataset'],$profile['eval_start'],$profile['as_of']);
    PaperOpportunityResearch::demand($data['files']===$p['dataset_files'],'Dataset metadata differs from report');
    $hashes=[];foreach($data['symbols'] as $symbol=>$info)$hashes[$symbol]=$info['bars_sha256'];ksort($hashes);
    PaperOpportunityResearch::demand(PaperOpportunityResearch::hash($hashes)===$p['bars_hash'],'Dataset bars differ from report');
    $selected=PaperRecoveryCloseEvidence::select($report);$fixed=PaperOpportunityResearch::read($root.'/docs/recovery-close-evidence/selection.json');
    $list=[];foreach($selected as $r)$list[]=['date'=>$r['date'],'symbol'=>$r['symbol'],'name'=>$r['name'],'net_return_pct'=>$r['shadow']['net_return_pct']];
    PaperOpportunityResearch::demand($list===$fixed['cases']&&$fixed['report_sha256']===PaperRecoveryCloseEvidence::REPORT_SHA256,'Frozen five-case selection differs');
    $sessions=PaperDatedTop100Replay::sessions($c);$cases=[];$modelErrors=0;$rangeWarnings=0;$providerDiffs=0;$providerMissing=0;$providerDuplicates=0;$jumpCases=0;$repairCases=0;
    foreach($selected as $r){
        PaperOpportunityResearch::demand(in_array($r['symbol'],$c['by_session'][$r['session']]['symbols']??[],true),'Signal not in same-day TOP100');
        $info=$data['symbols'][$r['symbol']];$audit=PaperRecoveryCloseEvidence::auditCase($r,$info,$sessions,$data['dataset']);
        $st=PaperHistoryResearch::readStatus($datasetDir,$r['symbol']);
        $source=PaperRecoveryCloseEvidence::inputPath($datasetDir,$st['bars_path']);$bars=PaperOpportunityResearch::read($source);
        PaperOpportunityResearch::demand(hash_file('sha256',$source)===$info['bars_sha256'],'Selected bars changed');
        $visible=array_merge($audit['as_of_rows'],array_values(array_filter($audit['future_rows'],static fn($b)=>$b!==null)));
        $audit['provider']=PaperRecoveryCloseEvidence::providerExcerpt($datasetDir,$bars['request'],$visible);
        PaperOpportunityResearch::demand($audit['provider']['provider_symbol']===$r['symbol']&&$audit['provider']['currency']==='KRW','Provider symbol/currency mismatch');
        $audit['source']=['bars_path'=>$st['bars_path'],'bars_sha256'=>$info['bars_sha256'],'status_sha256'=>hash_file('sha256',$datasetDir.'/status/'.$r['symbol'].'.json'),
            'quality'=>$st['quality']??null,'raw_checks'=>$st['raw_checks']??null,'pipeline'=>$bars['pipeline']??null,'price_basis'=>$bars['price_basis']??null];
        $modelErrors+=(int)!$audit['model_match'];$rangeWarnings+=(int)($audit['market_checks']['range_result']!=='within_range');
        $providerDiffs+=count($audit['provider']['stored_provider_differences']);
        $providerMissing+=count($audit['provider']['missing_dates']);$providerDuplicates+=count($audit['provider']['duplicate_dates']);
        $jumpCases+=(int)($audit['market_checks']['close_jumps_over_30pct']!==[]);$repairCases+=(int)($audit['market_checks']['repaired_rows']!==[]);$cases[]=$audit;
    }
    $toolHashes=[];foreach(['bin/paper/RecoveryCloseEvidence.php','bin/paper_recovery_close_evidence.php','docs/recovery-close-evidence/selection.json'] as $f)$toolHashes[$f]=PaperStrategyVersion::fileHash($root.'/'.$f);
    $out=['kind'=>'recovery_close_top5_evidence_v1','source_report_sha256'=>PaperRecoveryCloseEvidence::REPORT_SHA256,'source_provenance'=>$p,
        'tool_sha256_lf'=>$toolHashes,'runtime_php'=>PHP_VERSION,'selection'=>$fixed,'cases'=>$cases,
        'summary'=>['cases'=>count($cases),'unique_symbols'=>count(array_unique(array_column($selected,'symbol'))),'model_error_cases'=>$modelErrors,
            'range_assumption_cases'=>$rangeWarnings,'provider_field_differences'=>$providerDiffs,'provider_missing_case_dates'=>$providerMissing,
            'provider_duplicate_case_dates'=>$providerDuplicates,'jump_warning_cases'=>$jumpCases,'repair_evidence_cases'=>$repairCases,
            'human_review_required'=>true,'operational_writes'=>false,
            'account_replay_ran'=>false,'adjustment_basis_confirmed'=>false],
        'warning'=>'Outcome-selected source audit, not blind or representative. Model agreement is not proof of actual fills or consistent adjusted prices. Review raw differences, events and repair evidence before account experiment.'];
    $outPath=$args['output']??dirname(realpath($datasetDir)).'/recovery-close-evidence-v1.json';
    $parent=realpath(dirname($outPath));PaperOpportunityResearch::demand($parent!==false&&is_dir($parent),'Output parent must already exist');
    $normalize=static fn($x)=>strtolower(str_replace('\\','/',$x));
    foreach([$root,realpath($datasetDir)] as $protected)PaperOpportunityResearch::demand(!str_starts_with($normalize($parent).'/',rtrim($normalize($protected),'/').'/'),'Output must be outside repository and dataset');
    PaperOpportunityResearch::demand(!file_exists($outPath)&&!is_link($outPath),'Refusing existing output');
    $bytes=PaperOpportunityResearch::encode($out)."\n";$handle=fopen($outPath,'x');PaperOpportunityResearch::demand($handle!==false,'Cannot create output');
    try{PaperOpportunityResearch::demand(fwrite($handle,$bytes)===strlen($bytes),'Incomplete evidence write');}finally{fclose($handle);}
    echo PaperOpportunityResearch::encode(['output'=>$outPath,'sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes),'summary'=>$out['summary']])."\n";
    exit($modelErrors===0?0:2);
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
