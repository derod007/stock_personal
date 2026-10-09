<?php
declare(strict_types=1);
// Read-only reproduction of the PR #55 selected pullback trade review. No strategy changes.
require __DIR__.'/../../bin/bootstrap.php';require __DIR__.'/../../bin/paper/PatternReplay.php';
use ChartEntryLab\CandleClock;
$o=getopt('',['dataset-dir:']);$dir=rtrim($o['dataset-dir']??'','/\\');
$baseline=json_decode(file_get_contents(__DIR__.'/../pattern-replay-20261008.json'),true,512,JSON_THROW_ON_ERROR);
if(PaperStrategyVersion::current()!==$baseline['strategy_fingerprint'])throw new RuntimeException('Strategy version changed');
foreach($baseline['research_files'] as $file=>$hash)if(PaperStrategyVersion::fileHash(dirname(__DIR__,2).'/'.$file)!==$hash)throw new RuntimeException('Research version changed: '.$file);
$problems=PaperHistoryResearch::verify($dir);if($problems)throw new RuntimeException(implode('; ',$problems));
if(hash_file('sha256',$dir.'/dataset.json')!==$baseline['dataset_hash']||hash_file('sha256',$dir.'/universe.json')!==$baseline['universe_hash'])throw new RuntimeException('Wrong dataset');
$ds=PaperHistoryResearch::readJson($dir.'/dataset.json');$cutoff=$ds['as_of']['close_ts'];
$out=['kind'=>'pr55_closed_pullback_review','source_report_sha256'=>hash_file('sha256',__DIR__.'/../pattern-replay-20261008.json'),
    'strategy_fingerprint'=>$baseline['strategy_fingerprint'],'rows'=>[],'selected_statuses'=>[]];
foreach($baseline['source_symbols'] as $s){
    $wanted=array_values(array_filter($baseline['selected_baseline_events'],fn($e)=>$e['symbol']===$s['symbol']&&$e['pattern']==='trend_pullback'));if(!$wanted)continue;
    $bp=$dir.'/bars/'.$s['symbol'].'.json';if(hash_file('sha256',$bp)!==$s['bars_sha256'])throw new RuntimeException('Bars changed');
    $rows=PaperHistoryResearch::readJson($bp)['rows'];foreach($rows as &$b)$b['available_at']=CandleClock::closeTime($b,$s['symbol']);unset($b);
    usort($rows,fn($a,$b)=>$a['available_at']<=>$b['available_at']);
    foreach($wanted as $e){
        $t=$e['outcome'];$status=$t['status'];$out['selected_statuses'][$status]=($out['selected_statuses'][$status]??0)+1;if($status!=='closed')continue;
        $at=$e['session'];$past=array_values(array_filter($rows,fn($b)=>$b['available_at']<=$at));
        $d=PaperPatternReplay::day($past,$s['symbol'],$at,$baseline['profile']);
        if($d['input_hash']!==$e['input_hash']||array_intersect_key($d['analysis']['plan']['diagnostics']['patterns']['trend_pullback'],$e['levels'])!=$e['levels']||!$d['analysis']['plan']['ready'])throw new RuntimeException('Signal mismatch');
        $future=PaperPatternReplay::future($rows,$s['symbol'],$at,$cutoff);$again=PaperPatternReplay::trade($d['analysis']['plan'],$future);
        if($again!=$t)throw new RuntimeException('Outcome mismatch');
        $bars=CandleClock::completed($past,$s['symbol'],$at);$n=count($bars);$last=end($bars);$prev=$bars[$n-2];
        $a=$d['analysis'];$p=$a['plan'];$atr=$a['features']['atr14'];$ma=array_sum(array_column(array_slice($bars,-20),'close'))/20;
        $oldma=array_sum(array_column(array_slice($bars,-25,20),'close'))/20;
        $baseVol=array_sum(array_column(array_slice($bars,-24,20),'volume'))/20;
        $pullVol=array_sum(array_column(array_slice($bars,-4,3),'volume'))/3;
        $entry=null;$exit=null;$ei=null;$xi=null;foreach($future as $i=>$b){if(!$b)continue;if($b['available_at']===$t['entry_at']){$entry=$b;$ei=$i;}if($b['available_at']===$t['exit_at']){$exit=$b;$xi=$i;}}
        if(!$entry||!$exit)throw new RuntimeException('Fill/exit candle unavailable');
        // Avoid claiming that entry/exit-day highs happened after/before the actual intrabar fill/exit.
        $between=array_values(array_filter($future,fn($b)=>$b&&$b['available_at']>$t['entry_at']&&$b['available_at']<$t['exit_at']));
        $priorGain=$between?max(0,(max(array_column($between,'high'))/$t['entry_fill']-1)*100):null;
        $gap=$t['first_exit']==='stop'&&$exit['available_at']>$entry['available_at']&&$exit['open']<$p['stop'];
        $out['rows'][]=['id'=>$e['id'],'symbol'=>$s['symbol'],'name'=>$s['name'],'date'=>$e['date'],'session'=>$at,
            'period'=>$e['date']<'2026-04-08'?'first_6m':'last_6m','net_return_pct'=>$t['net_return_pct'],'win'=>$t['net_return_pct']>0,
            'entry_date'=>PaperHistoryResearch::day($t['entry_at']),'exit_date'=>PaperHistoryResearch::day($t['exit_at']),
            'entry_at'=>$t['entry_at'],'exit_at'=>$t['exit_at'],'bars_held'=>$t['bars'],'entry_delay_bars'=>$ei+1,'exit_reason'=>$t['first_exit'],
            'entry'=>$p['entry'],'stop'=>$p['stop'],'target'=>$p['target'],'entry_fill'=>$t['entry_fill'],'exit_fill'=>$t['exit_fill'],
            'entry_open'=>$entry['open'],'exit_open'=>$exit['open'],'signal_risk_pct'=>($p['entry']-$p['stop'])/$p['entry']*100,
            'fill_risk_pct'=>($t['entry_fill']-$p['stop'])/$t['entry_fill']*100,'reward_risk'=>$p['reward_risk'],
            'gap_stop'=>$gap,'gap_extra_pct'=>$gap?($p['stop']-$exit['open'])/$t['entry_fill']*100:0,
            'early_stop'=>$t['first_exit']==='stop'&&$t['bars']<=3,'entry_bar_stop'=>$t['first_exit']==='stop'&&$t['bars']===1,
            'prior_full_session_max_up_pct'=>$priorGain,'rose_3pct_then_stop'=>$t['first_exit']==='stop'&&$priorGain!==null&&$priorGain>=3,
            'entry_close_return_pct'=>$t['bars']>1?($entry['close']/$t['entry_fill']-1)*100:null,
            'daily_context'=>$p['context']['daily'],'weekly_context'=>$p['context']['weekly'],
            'ma20_slope_5bar_pct'=>($ma/$oldma-1)*100,'signal_distance_ma20_atr'=>($last['close']-$ma)/$atr,
            'signal_atr_pct'=>$atr/$last['close']*100,'pullback_volume_ratio'=>$pullVol/$baseVol,
            'signal_volume_to_baseline'=>$last['volume']/$baseVol,'signal_volume_to_previous'=>$prev['volume']>0?$last['volume']/$prev['volume']:null,
            'score'=>$a['decision']['score']??null,'input_hash'=>$e['input_hash'],'bars_sha256'=>$s['bars_sha256']];
    }
}
usort($out['rows'],fn($a,$b)=>[$a['session'],$a['symbol']]<=>[$b['session'],$b['symbol']]);
if(count($out['rows'])!==70||array_sum($out['selected_statuses'])!==82)throw new RuntimeException('Unexpected PR55 pullback cohort');
$out['verified_signals_and_outcomes']=count($out['rows']);echo PaperHistoryResearch::encode($out,true)."\n";
