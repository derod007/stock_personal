<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require __DIR__.'/paper/BreakevenResearch.php';
use ChartEntryLab\CandleClock;
$o=getopt('',['dataset-dir:','replay:']);
if(empty($o['dataset-dir'])||empty($o['replay']))throw new InvalidArgumentException('--dataset-dir and --replay required');
$dir=rtrim($o['dataset-dir'],'/\\');
$read=fn($p)=>json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
$source=$read($o['replay']);
$problems=PaperHistoryResearch::verify($dir);if($problems)throw new RuntimeException(implode('; ',$problems));
foreach(['dataset','universe'] as $key)if(hash_file('sha256',"$dir/$key.json")!==($source[$key.'_hash']??null))throw new RuntimeException('Dataset mismatch '.$key);
if(($source['strategy_fingerprint']??null)!==PaperStrategyVersion::current())throw new RuntimeException('Strategy changed');
if(($source['profile']??null)!=='account1'||($source['context_applied']??null)!==true)throw new RuntimeException('Unexpected profile/context');
$ds=$read("$dir/dataset.json");$cutoff=(int)$ds['as_of']['close_ts'];$events=[];$hashes=[];
if(isset($source['selected_baseline_events'])) {
    $events=$source['selected_baseline_events'];
    $hashes=array_column($source['source_symbols'],'bars_sha256','symbol');
} elseif(isset($source['symbols'])) {
    foreach($source['symbols'] as $s) {
        $hashes[$s['symbol']]=$s['bars_sha256'];
        foreach($s['events'] as $e)if(isset($e['trades']['selected_baseline'])) {
            $events[]=$e+['outcome'=>$e['trades']['selected_baseline']];
        }
    }
} else throw new RuntimeException('Full replay or selected-baseline summary required; closed-only review is insufficient');
$rows=[];$cache=[];$seen=[];
foreach($events as $e) {
    if($e['pattern']!=='trend_pullback')continue;
    if(isset($seen[$e['id']]))throw new RuntimeException('Duplicate selected event');$seen[$e['id']]=true;
    $symbol=$e['symbol'];
    if(!isset($cache[$symbol])) {
        $path="$dir/bars/$symbol.json";
        if(hash_file('sha256',$path)!==($hashes[$symbol]??null))throw new RuntimeException('Bars changed '.$symbol);
        $cache[$symbol]=$read($path)['rows'];
        foreach($cache[$symbol] as &$b)$b['available_at']=CandleClock::closeTime($b,$symbol);unset($b);
        usort($cache[$symbol],fn($a,$b)=>$a['available_at']<=>$b['available_at']);
    }
    $at=(int)$e['session'];if($at>$cutoff)throw new RuntimeException('Signal beyond cutoff');
    $past=array_values(array_filter($cache[$symbol],fn($b)=>$b['available_at']<=$at));
    $day=PaperPatternReplay::day($past,$symbol,$at,'account1');$plan=$day['analysis']['plan']??[];
    if(($day['input_hash']??null)!==$e['input_hash']||empty($plan['ready'])||($plan['pattern']??null)!=='trend_pullback_v1')throw new RuntimeException('Signal mismatch '.$e['id']);
    $future=PaperPatternReplay::future($cache[$symbol],$symbol,$at,$cutoff);
    $pair=PaperBreakevenResearch::compare($plan,$future);
    if($pair['baseline']!=$e['outcome'])throw new RuntimeException('Baseline outcome mismatch '.$e['id']);
    $rows[]=['id'=>$e['id'],'symbol'=>$symbol,'date'=>$e['date'],'stop'=>$plan['stop']]+$pair;
}
if(!$rows)throw new RuntimeException('No selected pullbacks');
$split=(new DateTimeImmutable(PaperHistoryResearch::evalStartOf($ds)))->modify('+6 months')->format('Y-m-d');
$out=['kind'=>'pullback_close_3pct_breakeven_research_v1','dataset'=>$source['dataset'],'strategy_fingerprint'=>$source['strategy_fingerprint'],
    'source_sha256'=>hash_file('sha256',$o['replay']),'cutoff'=>$ds['as_of']['day'],
    'rule'=>'close >= actual entry fill * 1.03; stop = entry fill from next session; no reentry; fixed target/TTL/holding/costs',
    'research_files'=>array_combine(['BreakevenResearch.php','paper_breakeven_research.php','TradeSimulator.php'],array_map(fn($p)=>hash_file('sha256',$p),[__DIR__.'/paper/BreakevenResearch.php',__FILE__,__DIR__.'/../src/TradeSimulator.php'])),
    'summary'=>PaperBreakevenResearch::summary($rows),'split'=>$split,
    'first_half'=>PaperBreakevenResearch::summary(array_values(array_filter($rows,fn($r)=>$r['date']<$split))),
    'second_half'=>PaperBreakevenResearch::summary(array_values(array_filter($rows,fn($r)=>$r['date']>=$split))),'rows'=>$rows];
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
