<?php
declare(strict_types=1);
/**
 * Research only. One alternative stop (lowest low after the PR #64 recent re-decline high minus 0.2 ATR) on the existing rising
 * pullback entries. Order of work, each command a separate step:
 *   population  existing 157 selected pullbacks (ids, signal levels) and their stored baseline outcomes
 *   alt-stops   alternative stop and applicability per case from bars up to the signal day only (no outcome is read or computed)
 *   pr64-check  compare the automatic H_recent with the PR #64 marks (no outcome)
 *   seal        hash protocol, code, inputs and alt-stops into freeze.json
 *   run         verify freeze.json, reproduce the baseline on all cases (stop on any mismatch), then apply the frozen stops
 */
require __DIR__.'/bootstrap.php';require __DIR__.'/paper/RedeclineStudy.php';

$cmd=$argv[1]??'';$o=[];
foreach(array_slice($argv,2) as $a){
    if(!preg_match('/^--([a-z-]+)=(.*)$/s',$a,$m))throw new InvalidArgumentException('Unknown argument '.$a);
    $o[$m[1]]=$m[2];
}
$root=dirname(__DIR__);$dir=rtrim($o['dir']??$root.'/docs/pullback-recent-redecline-stop','/\\');
$dirs=['prior'=>rtrim($o['prior-dir']??'','/\\'),'recent'=>rtrim($o['recent-dir']??'','/\\')];
$enc=static fn(array $v):string=>PaperHistoryResearch::encode($v,true)."\n";
$write=static function(string $name,array $v) use($dir,$enc):string{PaperHistoryResearch::writeFile($dir.'/'.$name,$enc($v));return $dir.'/'.$name;};
$read=static function(string $name) use($dir):array{$v=json_decode((string)file_get_contents($dir.'/'.$name),true,512,JSON_THROW_ON_ERROR);return $v;};
$CODE=['bin/paper/RedeclineStop.php','bin/paper/RedeclineStudy.php','bin/paper_recent_redecline_stop.php','tests/redecline_stop.php',
    'bin/paper/PatternReplay.php','src/TradeSimulator.php','src/TrendPullback.php','src/PriceCandidate.php','src/PaperQuality.php','src/CandleClock.php'];
$needDirs=function() use($dirs):void{
    foreach($dirs as $p=>$d){
        if(!is_dir($d))throw new InvalidArgumentException('Required --prior-dir and --recent-dir (history datasets)');
        $problems=PaperHistoryResearch::verify($d);if($problems)throw new RuntimeException($p.' dataset: '.implode('; ',$problems));
        $ds=PaperHistoryResearch::readJson($d.'/dataset.json');
        if(($ds['dataset']??'')!==PaperRedeclineStudy::PERIODS[$p])throw new RuntimeException($p.' dataset is '.($ds['dataset']??'?'));
    }
};
$datasetInfo=function() use($dirs):array{
    $out=[];
    foreach($dirs as $p=>$d){
        $ds=PaperHistoryResearch::readJson($d.'/dataset.json');
        $out[$p]=['dataset'=>$ds['dataset'],'dataset_json_sha256'=>hash_file('sha256',$d.'/dataset.json'),'universe_sha256'=>hash_file('sha256',$d.'/universe.json'),
            'as_of_day'=>$ds['as_of']['day'],'close_ts'=>$ds['as_of']['close_ts']];
    }
    return $out;
};

if($cmd==='population'){
    $labels=json_decode((string)file_get_contents($o['labels']??''),true,512,JSON_THROW_ON_ERROR);
    // The labels use the review pack's case ids; the replay uses its own event ids. Match on dataset, symbol and date.
    $byKey=[];foreach($labels['rows'] as $r)$byKey[$r['dataset'].'|'.$r['symbol'].'|'.$r['date']]=$r['case_id'];
    if(count($byKey)!==157)throw new RuntimeException('Labels must list 157 selected pullbacks');
    $prior=json_decode((string)file_get_contents($o['prior-replay']??''),true,512,JSON_THROW_ON_ERROR);
    $recent=json_decode((string)file_get_contents($o['recent-replay']??''),true,512,JSON_THROW_ON_ERROR);
    $fp=PaperStrategyVersion::current();
    foreach([$prior,$recent] as $r)if($r['strategy_fingerprint']!==$fp||$r['profile']!=='account1'||($r['context_applied']??false)!==true)throw new RuntimeException('Baseline run is not the current account1 strategy');
    $events=[];$stored=[];
    foreach($prior['symbols'] as $s)foreach($s['events'] as $e){
        if($e['pattern']!=='trend_pullback'||empty($e['trades']['selected_baseline']))continue;
        $lv=array_intersect_key($e['raw'],array_flip(['entry','stop','target','reward_risk','signal_at']));
        $events[]=['id'=>$e['id'],'period'=>'prior','symbol'=>$e['symbol'],'name'=>$e['name'],'date'=>$e['date'],'session'=>$e['session'],'input_hash'=>$e['input_hash'],'levels'=>$lv];
        $stored[$e['id']]=$e['trades']['selected_baseline'];
    }
    foreach($recent['selected_baseline_events'] as $e){
        if($e['pattern']!=='trend_pullback')continue;
        $events[]=['id'=>$e['id'],'period'=>'recent','symbol'=>$e['symbol'],'name'=>$e['name'],'date'=>$e['date'],'session'=>$e['session'],'input_hash'=>$e['input_hash'],'levels'=>$e['levels']];
        $stored[$e['id']]=$e['outcome'];
    }
    $perPeriod=array_count_values(array_column($events,'period'));
    if(count($events)!==157||($perPeriod['prior']??0)!==75||($perPeriod['recent']??0)!==82)throw new RuntimeException('Expected 75 prior and 82 recent selections');
    $used=[];
    foreach($events as &$e){
        $k=PaperRedeclineStudy::PERIODS[$e['period']].'|'.$e['symbol'].'|'.$e['date'];
        if(!isset($byKey[$k]))throw new RuntimeException('Selected pullback is not among the 157 labelled cases: '.$k);
        $e['pack_case_id']=$byKey[$k];$used[$k]=true;
    }
    unset($e);
    if(count($used)!==157)throw new RuntimeException('Duplicate selected pullbacks');
    usort($events,fn($a,$b)=>[$a['period']==='recent',$a['session'],$a['symbol']]<=>[$b['period']==='recent',$b['session'],$b['symbol']]);
    $needDirs();
    $pop=['schema'=>1,'kind'=>'recent_redecline_stop_population_v1','selected'=>count($events),'by_period'=>$perPeriod,'strategy_fingerprint'=>$fp,'profile'=>'account1',
        'sources'=>['labels_file_sha256'=>PaperRedeclineStudy::lfSha($o['labels']),'prior_replay_file_sha256'=>hash_file('sha256',$o['prior-replay']),
            'recent_replay_file_sha256'=>hash_file('sha256',$o['recent-replay']),'prior_replay_dataset_hash'=>$prior['dataset_hash'],'recent_replay_dataset_hash'=>$recent['dataset_hash'],
            'prior_execution_version'=>$prior['execution_version'],'recent_execution_version'=>$recent['execution_version']],
        'datasets'=>$datasetInfo(),'events'=>$events];
    foreach($pop['datasets'] as $p=>$d)if($d['dataset_json_sha256']!==($p==='prior'?$prior['dataset_hash']:$recent['dataset_hash']))throw new RuntimeException($p.' dataset.json differs from the baseline run');
    $write('population.json',$pop);
    $write('baseline-stored.json',['schema'=>1,'note'=>'Existing baseline outcomes copied from the baseline replays. Not recomputed here.','outcomes'=>$stored]);
    fwrite(STDERR,"population ".count($events)." (prior ".$perPeriod['prior'].", recent ".$perPeriod['recent'].")\n");exit(0);
}

if($cmd==='alt-stops'){
    $needDirs();$pop=$read('population.json');$recs=[];$t0=microtime(true);
    foreach($pop['events'] as $i=>$ev){
        $r=PaperRedeclineStudy::rows($dirs[$ev['period']],$ev['symbol']);
        $fc=PaperRedeclineStudy::freezeCase($ev,$r['rows']);
        $rec=$fc['record'];$rec['bars_sha256']=$r['bars_sha256'];$recs[]=$rec;
        fwrite(STDERR,sprintf("%d/%d %s %s %s\n",$i+1,count($pop['events']),$ev['symbol'],$ev['date'],$rec['status'].($rec['reason']?' '.$rec['reason']:'')));
    }
    $sum=[];
    foreach($recs as $r){
        $s=&$sum[$r['period']];$s??=['selected'=>0,'applicable'=>0,'not_applicable'=>0,'reasons'=>[]];
        $s['selected']++;$s[$r['status']]++;if($r['reason'])$s['reasons'][$r['reason']]=($s['reasons'][$r['reason']]??0)+1;unset($s);
    }
    $write('alt-stops.json',['schema'=>1,'kind'=>'recent_redecline_stop_alt_stops_v1','version'=>PaperRedeclineStop::VERSION,
        'rule'=>'alt_stop = trunc(lowest low from the bar after H_recent through the signal day - 0.2 * ATR14). H_recent per PR #64. Fixed on the signal day.',
        'contains_outcomes'=>false,'population_sha256'=>PaperRedeclineStudy::lfSha($dir.'/population.json'),'summary'=>$sum,'cases'=>$recs]);
    fwrite(STDERR,sprintf("done in %.1fs %s\n",microtime(true)-$t0,json_encode($sum)));exit(0);
}

if($cmd==='leak-check'){
    // Every row after the signal session is replaced by extreme values. The frozen record must not change.
    $needDirs();$pop=$read('population.json');$alt=$read('alt-stops.json');$saved=[];foreach($alt['cases'] as $c)$saved[$c['id']]=$c;$bad=[];$changedRows=0;
    foreach($pop['events'] as $ev){
        $rows=PaperRedeclineStudy::rows($dirs[$ev['period']],$ev['symbol'])['rows'];$mut=[];
        foreach($rows as $b){
            if($b['available_at']>$ev['session']){$b=array_replace($b,['open'=>1.0,'high'=>9.0e9,'low'=>0.5,'close'=>2.0,'volume'=>1]);$changedRows++;}
            $mut[]=$b;
        }
        $rec=PaperRedeclineStudy::freezeCase($ev,$mut)['record'];$rec['bars_sha256']=$saved[$ev['id']]['bars_sha256'];
        if($rec!=$saved[$ev['id']])$bad[]=$ev['id'];
    }
    $write('leak-check.json',['kind'=>'recent_redecline_stop_leak_check_v1','cases'=>count($pop['events']),'future_rows_replaced'=>$changedRows,'records_changed'=>$bad,'passed'=>$bad===[],'contains_outcomes'=>false]);
    fwrite(STDERR,"leak-check cases ".count($pop['events'])." future rows replaced $changedRows changed ".count($bad)."\n");exit($bad?1:0);
}

if($cmd==='pr64-check'){
    $alt=$read('alt-stops.json');$a2=json_decode((string)file_get_contents($root.'/docs/pullback-segment-pilot/annotations-a2.json'),true,512,JSON_THROW_ON_ERROR);
    $mine=[];foreach($alt['cases'] as $c)$mine[$c['pack_case_id']]=$c;$rows=[];$bad=[];$skipped=[];
    foreach($a2['cases'] as $c){
        // PR #64 also reviewed waiting and dropped pullbacks, which are not selected entries and so are not in this study.
        $m=$mine[$c['case_id']]??null;
        if($m===null){
            // Rule check only (no ATR or entry needed for the dates and statuses): same bars, same function.
            $needDirs();$p=$c['session_date']<='2025-10-02'?'prior':'recent';
            $rw=PaperRedeclineStudy::rows($dirs[$p],$c['symbol'])['rows'];$session=null;
            foreach($rw as $b)if(PaperHistoryResearch::day($b['available_at'])===$c['session_date'])$session=$b['available_at'];
            if($session===null){$bad[]=$c['name'].' session not found';continue;}
            $ev=PaperRedeclineStop::forSignal($rw,$c['symbol'],$session,1.0,PHP_FLOAT_MAX);
            $hb=$c['H_big']['status'];$hr=$c['H_recent']['status'];$expect=($hb==='marked'&&$hr==='marked'&&$c['H_recent']['source']!=='A_main')?'applicable':'not_applicable';
            $okDates=$ev['status']!=='applicable'||(($ev['H_big']['date']??null)===($c['H_big']['point']['date']??null)&&($ev['H_recent']['date']??null)===($c['H_recent']['point']['date']??null)
                &&($ev['low_after_recent']['date']??null)===($c['adjusted_lows']['from_H_recent']['date']??null));
            $skipped[]=['case_id'=>$c['case_id'],'name'=>$c['name'],'pr64'=>['H_big'=>$hb,'H_recent'=>$hr,'source'=>$c['H_recent']['source']],'auto_status'=>$ev['status'],'auto_reason'=>$ev['reason'],
                'expected_status'=>$expect,'status_matches'=>$ev['status']===$expect,'dates_match'=>$okDates];
            if($ev['status']!==$expect||!$okDates)$bad[]=$c['name'];
            continue;
        }
        $hb=$c['H_big']['status'];$hr=$c['H_recent']['status'];$src=$c['H_recent']['source'];
        $expected=($hb==='marked'&&$hr==='marked'&&$src!=='A_main')?'applicable':'not_applicable';
        $row=['case_id'=>$c['case_id'],'name'=>$c['name'],'pr64'=>['H_big'=>$hb,'H_recent'=>$hr,'source'=>$src,'H_big_date'=>$c['H_big']['point']['date']??null,
            'H_recent_date'=>$c['H_recent']['point']['date']??null,'low_after_H_recent_date'=>$c['adjusted_lows']['from_H_recent']['date']??null],
            'auto'=>['status'=>$m['status'],'reason'=>$m['reason'],'H_big_date'=>$m['H_big']['date']??null,'H_recent_date'=>$m['H_recent']['date']??null,
                'low_after_H_recent_date'=>$m['low_after_recent']['date']??null],'expected_status'=>$expected];
        $row['status_matches']=$m['status']===$expected;
        $row['dates_match']=$m['status']!=='applicable'||($row['pr64']['H_big_date']===$row['auto']['H_big_date']&&$row['pr64']['H_recent_date']===$row['auto']['H_recent_date']&&$row['pr64']['low_after_H_recent_date']===$row['auto']['low_after_H_recent_date']);
        if(!$row['status_matches']||!$row['dates_match'])$bad[]=$c['name'];
        $rows[]=$row;
    }
    $out=['kind'=>'recent_redecline_stop_pr64_consistency_v1','pr64_cases'=>count($a2['cases']),'cases'=>count($rows),'pr64_cases_not_selected_entries'=>$skipped,'mismatches'=>$bad,'contains_outcomes'=>false,
        'applicable_both'=>count(array_filter($rows,fn($r)=>$r['expected_status']==='applicable'&&$r['auto']['status']==='applicable')),'rows'=>$rows];
    $write('pr64-consistency.json',$out);
    fwrite(STDERR,"pr64 cases ".count($rows)." mismatches ".count($bad)." ".json_encode($bad,JSON_UNESCAPED_UNICODE)."\n");exit($bad?1:0);
}

if($cmd==='seal'){
    $needDirs();$pop=$read('population.json');$alt=$read('alt-stops.json');
    if(!is_file($dir.'/protocol.md')||!is_file($dir.'/pr64-consistency.json'))throw new RuntimeException('protocol.md and pr64-consistency.json must exist before sealing');
    if($alt['contains_outcomes']!==false)throw new RuntimeException('alt-stops.json must not contain outcomes');
    if(!is_file($dir.'/leak-check.json')||!($read('leak-check.json')['passed']??false))throw new RuntimeException('leak-check.json must exist and pass');
    $files=['population.json','baseline-stored.json','alt-stops.json','pr64-consistency.json','leak-check.json','protocol.md'];$h=[];
    foreach($files as $f)$h[$f]=PaperRedeclineStudy::lfSha($dir.'/'.$f);
    $code=[];foreach($CODE as $f)$code[$f]=PaperStrategyVersion::fileHash($root.'/'.$f);
    $head=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));
    $w=json_decode((string)file_get_contents($root.'/docs/pattern-replay-20261008.json'),true,512,JSON_THROW_ON_ERROR);
    $freeze=['schema'=>1,'kind'=>'recent_redecline_stop_freeze_v1','frozen_at_kst'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s'),
        'statement'=>'Protocol, code, population and the alternative stop of every case were fixed before any alternative-stop trade result was computed. Hashes are of LF-normalised file bytes.',
        'git_head_at_freeze'=>$head,'strategy_fingerprint'=>PaperStrategyVersion::current(),'study_version'=>PaperRedeclineStudy::VERSION,'rule_version'=>PaperRedeclineStop::VERSION,
        'files_sha256_lf'=>$h,'code_sha256_lf'=>$code,'datasets'=>$datasetInfo(),'population'=>['selected'=>$pop['selected'],'by_period'=>$pop['by_period']],
        'alt_stops_summary'=>$alt['summary'],'not_blind'=>'Exploratory study on data that were already reviewed (PR #55-#64). Not a blind or independent test.'];
    $write('freeze.json',$freeze);fwrite(STDERR,"sealed at ".$freeze['frozen_at_kst']."\n");exit(0);
}

if($cmd==='run'){
    $needDirs();$freeze=$read('freeze.json');
    // 1. nothing frozen may differ in content
    $changed=[];
    foreach($freeze['files_sha256_lf'] as $f=>$sha)if(PaperRedeclineStudy::lfSha($dir.'/'.$f)!==$sha)$changed[]=$f;
    foreach($freeze['code_sha256_lf'] as $f=>$sha)if(PaperStrategyVersion::fileHash($root.'/'.$f)!==$sha)$changed[]=$f;
    if(PaperStrategyVersion::current()!==$freeze['strategy_fingerprint'])$changed[]='strategy_fingerprint';
    if($datasetInfo()!=$freeze['datasets'])$changed[]='datasets';
    if($changed){fwrite(STDERR,"FROZEN INPUT CHANGED: ".implode(', ',$changed)."\n");exit(3);}
    $pop=$read('population.json');$alt=$read('alt-stops.json');$stored=$read('baseline-stored.json')['outcomes'];
    $frozen=[];foreach($alt['cases'] as $c)$frozen[$c['id']]=$c;
    $info=$datasetInfo();$ctxs=[];$fail=[];$t0=microtime(true);
    // 2. baseline reproduction on every selected case. Any mismatch stops the study before an alternative result exists.
    foreach($pop['events'] as $i=>$ev){
        $r=PaperRedeclineStudy::rows($dirs[$ev['period']],$ev['symbol']);
        $ctx=PaperRedeclineStudy::baselineCase($frozen[$ev['id']],$ev,$r['rows'],$info[$ev['period']]['close_ts'],$stored[$ev['id']]);
        if(!$ctx['row']['repro']['engine_equals_stored'])$fail[]=$ev['id'].' '.$ev['symbol'].' '.$ev['date'].' engine != stored baseline';
        if(!$ctx['row']['repro']['study_simulator_equals_engine'])$fail[]=$ev['id'].' '.$ev['symbol'].' '.$ev['date'].' study simulator != engine simulator';
        $ctxs[]=$ctx;
        fwrite(STDERR,sprintf("baseline %d/%d %s %s\n",$i+1,count($pop['events']),$ev['symbol'],$ev['date']));
    }
    if($fail){
        $write('repro-failure.json',['schema'=>1,'kind'=>'recent_redecline_stop_repro_failure_v1','mismatches'=>$fail,'note'=>'Alternative comparison not performed.']);
        fwrite(STDERR,"BASELINE NOT REPRODUCED: ".count($fail)." mismatches; comparison stopped\n");exit(2);
    }
    $statuses=[];foreach($ctxs as $c){$p=$c['row']['period'];$s=$c['row']['baseline']['status'];$statuses[$p][$s]=($statuses[$p][$s]??0)+1;}
    foreach($statuses as &$s)ksort($s);unset($s);
    $repro=['cases'=>count($ctxs),'all_equal_to_stored'=>true,'all_study_simulator_equal_engine'=>true,'baseline_statuses'=>$statuses];
    // 3. frozen alternative stops
    $rows=[];foreach($ctxs as $c)$rows[]=PaperRedeclineStudy::altCase($c);
    $by=['prior'=>[],'recent'=>[]];foreach($rows as $r)$by[$r['period']][]=$r;
    $sum=[];foreach($by as $p=>$list)$sum[$p]=PaperRedeclineStudy::summarize($list);
    $preFill=count(array_filter($rows,fn($r)=>isset($r['pre_fill_identical'])&&!$r['pre_fill_identical']));
    $write('comparison.json',['schema'=>1,'kind'=>'recent_redecline_stop_comparison_v1','freeze_sha256_lf'=>PaperRedeclineStudy::lfSha($dir.'/freeze.json'),
        'strategy_fingerprint'=>$freeze['strategy_fingerprint'],'not_blind'=>$freeze['not_blind'],
        'definitions'=>['common_closed'=>'both the baseline and the alternative trade are closed','net_return_pct'=>'per-trade net return after the engine costs; a trade average, not an account return',
            'R_common_denominator'=>'net return % / baseline initial risk % at the real fill price (both methods)','R_own'=>'net return % / own initial risk % at the real fill price',
            'big_loss_pct'=>PaperRedeclineStudy::BIG_LOSS_PCT,'not_applicable'=>'no alternative stop was applied; the baseline stop is not substituted'],
        'reproduction'=>$repro,'pre_fill_mismatches'=>$preFill,'summary'=>$sum,'cases'=>$rows]);
    fwrite(STDERR,sprintf("comparison written in %.1fs\n",microtime(true)-$t0));exit(0);
}
fwrite(STDERR,"Usage: paper_recent_redecline_stop.php population|alt-stops|pr64-check|seal|run --dir=... --prior-dir=... --recent-dir=...\n");exit(64);
