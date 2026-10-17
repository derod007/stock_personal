<?php
declare(strict_types=1);
require __DIR__.'/paper/KrxContinuityReview.php';
use ChartEntryLab\{CandleClock,ChartPlanEngine};
try {
    $o=[];foreach(array_slice($argv,1) as $arg){
        if(!preg_match('/^--(state-dir|source|bars|report|output|symbols)=(.+)$/',$arg,$m)||isset($o[$m[1]]))throw new RuntimeException('Unknown/duplicate option '.$arg);
        $o[$m[1]]=$m[2];
    }
    $root=dirname(__DIR__);$state=$o['state-dir']??getenv('PAPER_STATE_DIR');
    if(!$state&&!isset($o['source']))throw new RuntimeException('Specify --state-dir or --source');
    $source=$o['source']??$state.'/history-research/krx_signal_compare_v1';
    $reportPath=$o['report']??$source.'/report-243.json';
    $manifest=PaperKrxSignalStudy::json($root.'/'.PaperKrxSignalStudy::DATA.'/manifest.json');
    $barsDir=$o['bars']??$manifest['bars'];
    $output=$o['output']??($state?$state.'/history-research/krx-continuity-v1/report.json':null);
    if(!$output)throw new RuntimeException('Specify --output');
    $frozen=PaperKrxSignalStudy::json($root.'/docs/krx-continuity-review/frozen-run.json');
    if(hash_file('sha256',$reportPath)!==$frozen['report_sha256'])throw new RuntimeException('Frozen source report changed (byte hash)');
    $report=PaperKrxSignalStudy::json($reportPath);$inputs=PaperKrxSignalStudy::json($source.'/inputs.json');
    $ih=PaperKrxSignalStudy::digest($inputs);
    if($ih!==$frozen['inputs_sha256']||$report['inputs_sha256']!==$ih||$report['results_sha256']!==$frozen['results_sha256'])throw new RuntimeException('Frozen source identity changed');
    foreach($inputs['code_sha256_lf'] as $path=>$hash)if(PaperStrategyVersion::fileHash($root.'/'.$path)!==$hash)throw new RuntimeException('PR94 code changed: '.$path);
    $profile=PaperKrxSignalStudy::profile($root);$subset=isset($o['symbols']);
    $symbols=$subset?explode(',',$o['symbols']):array_keys($profile['symbols']);
    if(count(array_unique($symbols))!==count($symbols))throw new RuntimeException('Duplicate requested symbol');
    foreach($symbols as $s)if(!isset($profile['symbols'][$s]))throw new RuntimeException('Unknown symbol '.$s);
    sort($symbols);$selected=array_fill_keys($symbols,true);$review=[];
    foreach($report['review_cases'] as $r){
        $key=$r['symbol'].'@'.$r['day'];if(isset($review[$key]))throw new RuntimeException('Duplicate report case');
        $review[$key]=$r;
    }
    $all=[];$cases=[];$seen=[];$verifiedPairs=0;$recomputed=0;$inputHashes=[];
    $summary=['case_pairs'=>0,'baseline'=>['original_eligible'=>0,'hold_cross_break_source'=>0,'hold_unresolved_provenance'=>0,'no_crossing_in_traced_scope'=>0],
        'variant'=>['original_eligible'=>0,'hold_cross_break_source'=>0,'hold_unresolved_provenance'=>0,'no_crossing_in_traced_scope'=>0]];
    foreach($symbols as $symbol){
        $file=$source.'/symbols/'.$symbol.'.json';$saved=PaperKrxSignalStudy::readResult($file,$ih,$symbol);
        $inputHashes['symbol_results'][$symbol]=hash_file('sha256',$file);
        $wanted=[];foreach($profile['candidates'] as $c)if(in_array($symbol,$c['symbols'],true))$wanted[]=$c['day'];
        $got=array_keys($saved);sort($got);sort($wanted);if($got!==$wanted)throw new RuntimeException('Stored dates do not match TOP100: '.$symbol);
        $rows=null;
        foreach($saved as $day=>$r){
            if($r['symbol']!==$symbol||$r['day']!==$day)throw new RuntimeException('Stored identity mismatch');
            $verifiedPairs++;if(!$subset)$all[]=$r;
            if(!$r['baseline_quality_eligible']&&!$r['variant_quality_eligible'])continue;
            $key=$symbol.'@'.$day;$rr=$review[$key]??null;
            if($rr===null)throw new RuntimeException('Eligible case missing from report');
            foreach(['baseline','variant','baseline_quality_eligible','variant_quality_eligible'] as $field)
                if(PaperRecoveryCloseAccount::differences([$field=>$r[$field]],[$field=>$rr[$field]])!==[])throw new RuntimeException('Report/result mismatch '.$key);
            if($rows===null){
                $path=$barsDir.'/'.$symbol.'.jsonl';$sha=$inputs['verified_inputs']['jsonl_sha256'][$symbol]??null;
                if(!$sha||!is_file($path)||hash_file('sha256',$path)!==$sha)throw new RuntimeException('Missing/changed JSONL '.$symbol);
                $rows=iterator_to_array(PaperKrxSignalStudy::rows($path));$inputHashes['jsonl'][$symbol]=$sha;
            }
            $again=PaperKrxSignalStudy::evaluate($rows,$symbol,$day,$profile['breaks'][$symbol]??[]);
            if(PaperRecoveryCloseAccount::differences($r,$again)!==[])throw new RuntimeException('As-of replay mismatch '.$key);
            $recomputed++;$seen[$key]=true;$bars=[];
            foreach($rows as $row)if($row['day']<=$day&&($b=PaperKrxSignalStudy::bar($row))!==null)$bars[]=$b;
            $bars=CandleClock::completed($bars,$symbol,$r['as_of']);
            $case=['symbol'=>$symbol,'day'=>$day,'original'=>$rr,'arms'=>[]];
            foreach(['baseline','variant'] as $arm){
                if(!$r[$arm.'_quality_eligible']){$case['arms'][$arm]=['status'=>'not_originally_eligible'];continue;}
                $full=$arm==='baseline'?(new ChartPlanEngine())->analyze($bars,$symbol,$r['as_of'],'account1',true)
                    :(new \RecoveryCloseStudy\ChartPlanEngine())->analyze($bars,$symbol,$r['as_of'],'account1',true,true);
                if(PaperRecoveryCloseAccount::differences($r[$arm],PaperKrxSignalStudy::compact($full['plan']))!==[])throw new RuntimeException('Trace plan mismatch');
                $trace=PaperKrxContinuityReview::trace($bars,$full,$profile['breaks'][$symbol]??[],$day);
                $case['arms'][$arm]=$trace;$summary[$arm]['original_eligible']++;$summary[$arm][$trace['status']]++;
            }
            $cases[]=$case;
        }
    }
    foreach($review as $key=>$r)if(isset($selected[$r['symbol']])&&($r['baseline_quality_eligible']||$r['variant_quality_eligible'])&&!isset($seen[$key]))throw new RuntimeException('Report case was not checked');
    if(!$subset){
        usort($all,fn($a,$b)=>[$a['day'],$a['symbol']]<=>[$b['day'],$b['symbol']]);
        if(count($all)!==24300||PaperKrxSignalStudy::digest($all)!==$frozen['results_sha256'])throw new RuntimeException('Full source results hash mismatch');
        if(PaperKrxSignalStudy::summary($all,$profile['eval_days'])!==$report['summary'])throw new RuntimeException('Full source summary mismatch');
    }
    usort($cases,fn($a,$b)=>[$a['day'],$a['symbol']]<=>[$b['day'],$b['symbol']]);$summary['case_pairs']=count($cases);
    $code=[];foreach(['bin/paper/KrxContinuityReview.php','bin/paper_krx_continuity_review.php','docs/krx-continuity-review/protocol.md','docs/krx-continuity-review/frozen-run.json'] as $f)$code[$f]=PaperStrategyVersion::fileHash($root.'/'.$f);
    $out=['kind'=>'krx_continuity_review_v1','scope'=>$subset?'explicit_symbol_subset':'full_eligible_union',
        'complete'=>!$subset,'source_report_sha256'=>$frozen['report_sha256'],'source_inputs_sha256'=>$ih,
        'source_result_hash_verified'=>!$subset,'stored_pairs_checked'=>$verifiedPairs,'asof_pairs_recomputed'=>$recomputed,
        'symbols'=>$symbols,'code_sha256_lf'=>$code,'input_sha256'=>$inputHashes,'summary'=>$summary,'cases'=>$cases,
        'raw_revalidation_ran'=>false,'raw_validation_basis'=>'PR94 frozen validation plus pinned JSONL; no fresh raw audit',
        'price_basis'=>PaperKrxSignalStudy::BASIS,'account_replay_ran'=>false,'operational_writes'=>false,
        'full_dependency_trace'=>false,'notes'=>['Selected local structure/level provenance only; global context/risk searches not fully traced.',
            'No crossing found does not certify price continuity, corporate actions or account safety.',
            'Observed break list is incomplete; causes may be unconfirmed. No prices or original recommendations changed.']];
    if(is_file($output)){
        if(PaperKrxSignalStudy::digest(PaperKrxSignalStudy::json($output))!==PaperKrxSignalStudy::digest($out))throw new RuntimeException('Existing output differs; choose a new output path');
        $writes=false;
    }else{
        if(is_link($output)||!is_dir(dirname($output))&&!mkdir(dirname($output),0777,true))throw new RuntimeException('Cannot create output directory');
        PaperKrxSignalStudy::save($output,$out);$writes=true;
    }
    echo PaperKrxSignalStudy::encode(['report'=>$output,'sha256'=>hash_file('sha256',$output),'summary'=>$summary,
        'complete'=>!$subset,'research_output_written'=>$writes,'operational_writes'=>false,'account_replay_ran'=>false])."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
