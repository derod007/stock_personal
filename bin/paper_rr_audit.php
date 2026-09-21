<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/paper/RrAudit.php';

use ChartEntryLab\CandleClock;
use ChartEntryLab\ChartPlanEngine;

// Both modes are offline. No portfolio journal or operational recommendation is written.
$o=getopt('', ['input:', 'prices:', 'history:', 'from:', 'to:', 'as-of:', 'out:']);
try {
    if (empty($o['out']) || (isset($o['input']) === isset($o['history']))) {
        throw new InvalidArgumentException('--out=<new.json> with either --input=<audit.json> [--prices=<ohlcv dir>] or --history=<ohlcv dir> --from=YYYY-MM-DD --to=YYYY-MM-DD');
    }
    $asOf=isset($o['as-of'])?strtotime($o['as-of']):time();
    if($asOf===false || $asOf>time()) throw new InvalidArgumentException('--as-of must be a valid past timestamp (include timezone)');
    $read=static function(string $path):array {
        $value=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)) throw new RuntimeException('Expected JSON array/object: '.$path);
        return $value;
    };
    $prices=[];
    $loadBars=static function(string $file)use($read):array {
        $bars=$read($file);
        if(!array_is_list($bars)) throw new RuntimeException('OHLCV must be a JSON list: '.$file);
        return $bars;
    };
    $loadPrices=static function(string $dir):array {
        if(!is_dir($dir)) throw new RuntimeException('Missing price directory: '.$dir);
        $out=[];
        foreach(glob(rtrim($dir,'/').'/*.json')?:[] as $file) {
            if(!preg_match('/^(\d{6}\.(?:KS|KQ))(?:_2y_1d_closed_v2)?\.json$/',basename($file),$m)) continue;
            if(isset($out[$m[1]])) throw new RuntimeException('Duplicate symbol files: '.$m[1]);
            $out[$m[1]]=$file;
        }
        if($out===[]) throw new RuntimeException('No KR candle files: expected 005930.KS.json or Yahoo *_2y_1d_closed_v2.json');
        ksort($out); return $out;
    };
    if(isset($o['input'])) {
        $bundle=$read($o['input']);
        if(($bundle['version']??null)!==PaperRrAudit::VERSION || !isset($bundle['records'])) throw new RuntimeException('Unsupported audit bundle');
        $records=$bundle['records'];
        foreach ($records as $r) {
            if (($r['status']??'')==='evaluated' && !isset($r['bars'])) {
                throw new RuntimeException('Historical report: rerun --history with the original candle files');
            }
        }
        $membership=$bundle['membership']??'unknown';
        if(isset($o['prices'])) $prices=$loadPrices($o['prices']);
    } else {
        foreach(['from','to'] as $k) {
            if(!isset($o[$k]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$o[$k])) throw new InvalidArgumentException('--from/--to required');
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$o[$k],new DateTimeZone('Asia/Seoul'));
            if(!$d || $d->format('Y-m-d')!==$o[$k]) throw new InvalidArgumentException('Invalid date');
        }
        if($o['from']>$o['to']) throw new InvalidArgumentException('Reversed date range');
        $prices=$loadPrices($o['history']); $records=[];
        $membership='exploratory_union_NOT_historical_daily_TOP100';
        $engine=new ChartPlanEngine();
        foreach($prices as $symbol=>$file) {
            $raw=$loadBars($file);
            foreach(CandleClock::completed($raw,$symbol,$asOf) as $bar) {
                $at=(int)$bar['available_at'];
                $day=(new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
                if($day<$o['from'] || $day>$o['to']) continue;
                $past=array_values(array_filter($raw,static fn($b)=>CandleClock::closeTime($b,$symbol)<=$at));
                try {
                    $analysis=$engine->analyze($past,$symbol,$at);
                    $record=PaperRrAudit::capture(['yahoo'=>$symbol],['ok'=>true, 'evidence_origin'=>'historical_candle_file',
                        'research_input'=>['bars'=>$past,'analysis'=>$analysis]]);
                    foreach ($record['patterns'] as &$p) {
                        if ($p['status']==='added') $p['outcome']=PaperRrAudit::outcome($record,$p['candidate'],$raw,$asOf);
                    }
                    unset($p);
                    // Do not duplicate two years of candles for every symbol x day in a long replay.
                    unset($record['bars'], $record['analysis']);
                    $records[]=$record;
                } catch(Throwable $e) {
                    $records[]=['symbol'=>$symbol,'session'=>$at,'status'=>'unavailable',
                        'reason'=>'historical_analysis_failed','detail'=>$e->getMessage(),'patterns'=>[]];
                }
            }
        }
    }
    $outcomes=[];
    foreach($records as &$r) {
        foreach($r['patterns'] as &$p) {
            if($p['status']!=='added') continue;
            if (isset($o['input']) || !isset($p['outcome'])) {
                $raw=isset($prices[$r['symbol']])?$loadBars($prices[$r['symbol']]):$r['bars'];
                $p['outcome']=PaperRrAudit::outcome($r,$p['candidate'],$raw,$asOf);
            }
            $status=$p['outcome']['status']; $outcomes[$status]=($outcomes[$status]??0)+1;
        }
        unset($p);
    }
    unset($r);
    $summary=PaperRrAudit::summary($records);
    $output=['schema'=>1,'version'=>PaperRrAudit::VERSION,'recorded_at'=>time(),'asof'=>$asOf,
        'membership'=>$membership,'summary'=>$summary,'outcomes'=>$outcomes,'records'=>$records,
        'limitations'=>['RR is before costs; simulated outcomes include existing fees/slippage.',
            'Independent overlapping trades, not portfolio returns or guaranteed fills.',
            'No exchange-calendar completeness guarantee; missing sessions may censor results.',
            'Historical daily TOP100 membership cannot be inferred from union candle files.']];
    // Exclusive create: never overwrite an original observation or previous report.
    $fp=fopen($o['out'],'x');
    if($fp===false) throw new RuntimeException('Output exists or cannot be created');
    try {
        $json=PaperRrAudit::encode($output)."\n";
        if(fwrite($fp,$json)!==strlen($json)) throw new RuntimeException('Incomplete output write');
    } finally { fclose($fp); }
    echo PaperRrAudit::encode(['ok'=>true,'path'=>$o['out'],'summary'=>$summary,'outcomes'=>$outcomes,'membership'=>$membership])."\n";
} catch(Throwable $e) {
    fwrite(STDERR,$e->getMessage()."\n"); exit(1);
}
