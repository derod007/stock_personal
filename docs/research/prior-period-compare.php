<?php
declare(strict_types=1);
/** Read-only. Compare a pattern-replay result with the PR #56 pullback definitions. Does not touch the PR #56 script. */
require __DIR__.'/../../bin/bootstrap.php';
require __DIR__.'/../../bin/paper/PullbackCompare.php';
$cmd=$argv[1]??'';$o=[];
foreach(array_slice($argv,2) as $a){
    if(!preg_match('/^--([a-z-]+)=(.*)$/s',$a,$m))throw new InvalidArgumentException('Unknown argument '.$a);
    $o[$m[1]]=$m[2];
}
$dir=rtrim($o['dataset-dir']??'','/\\');
$out=static function(array $v):void{echo PaperHistoryResearch::encode($v,true),"\n";};

if($cmd==='verify-baseline'){
    $review=json_decode(file_get_contents($o['review']??''),true,512,JSON_THROW_ON_ERROR);
    $base=json_decode(file_get_contents($o['replay']??''),true,512,JSON_THROW_ON_ERROR);
    $ds=PaperHistoryResearch::readJson($dir.'/dataset.json')??throw new RuntimeException('dataset.json missing');
    $events=[];
    foreach($base['selected_baseline_events'] as $e){
        if($e['pattern']!=='trend_pullback')continue;
        $events[]=$e+['cutoff'=>$ds['as_of']['close_ts']];
    }
    $got=PaperPullbackCompare::diagnose($dir,$events,'account1','2026-04-08');
    $expect=[];foreach($review['rows'] as $r)$expect[$r['id']]=$r;
    $diffs=[];
    if(count($got['rows'])!==count($expect))$diffs[]='row count '.count($got['rows']).' vs '.count($expect);
    foreach($got['rows'] as $r){
        if(!isset($expect[$r['id']])){$diffs[]=$r['id'].' missing';continue;}
        $x=$expect[$r['id']];
        foreach(['net_return_pct','signal_risk_pct','fill_risk_pct','signal_atr_pct','exit_reason','gap_stop','early_stop','rose_3pct_then_stop','entry_at','exit_at'] as $k)
            if($r[$k]!=$x[$k])$diffs[]=$r['id'].' '.$k;
        if(($r['date']<'2026-04-08'?'first_6m':'last_6m')!==$x['period'])$diffs[]=$r['id'].' period';
    }
    $report=PaperPullbackCompare::pullbackReport($got['rows']);
    $out(['verified'=>count($got['rows']),'statuses'=>$got['statuses'],'diffs'=>$diffs,
        'mean'=>$report['all']['mean_net_return_pct'],'clusters'=>$report['all']['overlap']['clusters']]);
    exit($diffs===[]?0:1);
}

if($cmd==='summarize'){
    $replay=json_decode(file_get_contents($o['replay']??''),true,512,JSON_THROW_ON_ERROR);
    $split=(string)($o['split']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$split))throw new InvalidArgumentException('--split=YYYY-MM-DD');
    if(($replay['profile']??'')!=='account1'||($replay['context_applied']??false)!==true)throw new RuntimeException('Replay profile is not the baseline account1 run');
    $ds=PaperHistoryResearch::readJson($dir.'/dataset.json')??throw new RuntimeException('dataset.json missing');
    if(PaperHistoryResearch::evalStartOf($ds)!==$replay['start']||$ds['as_of']['day']!==$replay['end'])
        throw new RuntimeException('Replay window does not match the dataset');
    $events=[];
    foreach($replay['symbols'] as $s)foreach($s['events'] as $e){
        if($e['pattern']!=='trend_pullback'||empty($e['trades']['selected_baseline']))continue;
        $events[]=['id'=>$e['id'],'symbol'=>$e['symbol'],'date'=>$e['date'],'session'=>$e['session'],'input_hash'=>$e['input_hash'],
            'outcome'=>$e['trades']['selected_baseline'],'cutoff'=>$ds['as_of']['close_ts']];
    }
    $diag=PaperPullbackCompare::diagnose($dir,$events,'account1',$split);
    $patterns=[];
    foreach($replay['symbols'] as $s)foreach($s['events'] as $e){
        $t=$e['trades']['selected_baseline']??null;if(!$t)continue;
        $patterns[$e['pattern']][]=['date'=>$e['date'],'period'=>$e['date']<$split?'first_half':'second_half','status'=>$t['status'],
            'net_return_pct'=>$t['net_return_pct'],'exit_reason'=>$t['first_exit'],'symbol'=>$e['symbol']];
    }
    $patternReport=[];
    foreach($patterns as $name=>$list){
        $closed=array_values(array_filter($list,fn($r)=>$r['status']==='closed'));
        $st=[];foreach($list as $r)$st[$r['status']]=($st[$r['status']]??0)+1;ksort($st);
        $half=fn(string $p)=>PaperPullbackCompare::performance(array_values(array_filter($closed,fn($r)=>$r['period']===$p)));
        $patternReport[$name]=['selected'=>count($list),'statuses'=>$st,'closed'=>PaperPullbackCompare::performance($closed),
            'first_half'=>$half('first_half'),'second_half'=>$half('second_half')];
    }
    ksort($patternReport);
    $summary=['schema'=>1,'kind'=>'prior_period_pattern_compare_v1','dataset'=>$replay['dataset'],'profile'=>'account1','context_applied'=>true,
        'window'=>['start'=>$replay['start'],'end'=>$replay['end'],'split'=>$split,'outcome_limited_to'=>'dataset as_of close'],
        'strategy_fingerprint'=>$replay['strategy_fingerprint'],'execution_version'=>$replay['execution_version'],
        'dataset_hash'=>$replay['dataset_hash'],'universe_hash'=>$replay['universe_hash'],
        'symbols_evaluated'=>$replay['summary']['symbols'],'symbol_days'=>$replay['summary']['days'],
        'day_statuses'=>$replay['summary']['day_statuses'],'quality_reasons'=>$replay['summary']['quality_reasons'],
        'duplicates_removed'=>$replay['summary']['duplicates'],'events'=>$replay['summary']['events'],
        'pattern_statuses'=>$replay['summary']['pattern_statuses'],'groups'=>$replay['summary']['groups'],
        'trade_summary'=>$replay['summary']['trades'],'higher_low_states'=>$replay['summary']['higher_low_states'],
        'excluded_symbols'=>count($replay['excluded']),'patterns'=>$patternReport,
        'pullback_closed'=>PaperPullbackCompare::pullbackReport($diag['rows']),
        'pullback_selected_statuses'=>$diag['statuses'],'pullback_rows'=>$diag['rows']];
    if(isset($o['out']))PaperHistoryResearch::writeFile($o['out'],PaperHistoryResearch::encode($summary,true));
    $pb=$summary['pullback_closed'];
    fwrite(STDERR,sprintf("pullback closed %d mean %.4f clusters %d\n",$pb['all']['n'],$pb['all']['mean_net_return_pct'],$pb['all']['overlap']['clusters']));
    $out(['wrote'=>$o['out']??null,'window'=>$summary['window'],'symbols'=>$summary['symbols_evaluated'],'days'=>$summary['symbol_days'],
        'pullback_statuses'=>$diag['statuses'],'pullback_closed'=>$pb['all']['n']]);
    exit(0);
}
fwrite(STDERR,"Usage: prior-period-compare.php verify-baseline|summarize\n");exit(64);
