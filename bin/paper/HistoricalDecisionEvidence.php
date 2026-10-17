<?php
declare(strict_types=1);
require_once __DIR__.'/DatedTop100Replay.php';
require_once __DIR__.'/DecisionEvidence.php';
use ChartEntryLab\{CandleClock,ChartPlanEngine,PaperQuality};

/** Recompute decisions from frozen past bars. Never call a portfolio, simulator, or journal writer. */
final class PaperHistoricalDecisionEvidence
{
    public const VERSION='historical_decision_evidence_v1';
    public static function encode(array $v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
    public static function inspect(array $info,string $symbol,int $session,string $dataset):array
    {
        $past=array_values(array_filter($info['rows'],static fn($b)=>CandleClock::closeTime($b,$symbol)<=$session));
        $source=['sha256'=>$info['bars_sha256'],'price_basis'=>PaperHistoryResearch::PRICE_BASIS,'kind'=>'research_dataset','dataset'=>$dataset];
        $q=PaperQuality::inspect($past,$symbol,$session,$source,[]);
        $valid=CandleClock::completed($past,$symbol,$session);
        // Match DatedTop100Replay: engine >=40 bars; quality eligibility is independent (>=60 etc.).
        $a=count($valid)>=40?(new ChartPlanEngine())->analyze($valid,$symbol,$session):null;
        $record=['symbol'=>$symbol,'name'=>$info['name'],'session'=>$session,'status'=>$a===null?'unavailable':'evaluated',
            'reason'=>$a===null?'insufficient_completed_bars_for_engine':null,
            'input_hash'=>hash('sha256',self::encode($past)),'quality'=>$q,'analysis'=>$a];
        $e=PaperDecisionEvidence::fromRecord($record);
        $e['origin']='historical_recomputed_not_contemporaneous';$e['dataset']=$dataset;
        $e['date']=PaperHistoryResearch::day($session);$e['name']=$info['name'];
        $e['engine_ready']=$a['plan']['ready']??false;
        $e['recommendation_eligible']=$q['can_simulate']&&!empty($a['plan']['ready']);
        $e['signal_plan']=$a===null?null:array_intersect_key($a['plan'],array_flip(['pattern','entry','stop','target','reward_risk','signal_at','order_valid_bars']));
        $e['analysis_ran']=$a!==null;$e['input_bars']=count($past);
        $e['last_input_close']=$past===[]?null:max(array_map(static fn($b)=>CandleClock::closeTime($b,$symbol),$past));
        return $e;
    }
    public static function verifySignals(array $rows,array $expected):void
    {
        $got=[];$want=[];
        foreach($rows as $r)if($r['recommendation_eligible'])$got[$r['symbol']]=$r['signal_plan'];
        foreach($expected as $s)$want[$s['symbol']]=$s;
        ksort($got);ksort($want);
        if(array_keys($got)!==array_keys($want))throw new RuntimeException('Baseline recommendation membership mismatch');
        foreach($want as $sym=>$s){
            if($got[$sym]['pattern']!==$s['pattern'])throw new RuntimeException('Baseline pattern mismatch '.$sym);
            foreach(['entry','stop','target','reward_risk','signal_at','order_valid_bars'] as $k)
                if(!is_numeric($got[$sym][$k]??null)||!is_numeric($s[$k]??null)||abs($got[$sym][$k]-$s[$k])>1e-6)
                    throw new RuntimeException('Baseline level mismatch '.$sym.' '.$k);
        }
    }
    public static function writeNew(string $path,array $value):void
    {
        $bytes=self::encode($value)."\n";$f=@fopen($path,'x');
        if($f===false)throw new RuntimeException('Refusing overwrite or invalid output '.$path);
        try{if(fwrite($f,$bytes)!==strlen($bytes))throw new RuntimeException('Short write '.$path);}finally{fclose($f);}
    }
}
