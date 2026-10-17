<?php
declare(strict_types=1);

/** Lossless projection of observed engine decisions; never evaluates a replacement strategy. */
final class PaperDecisionEvidence
{
    public const VERSION = 'symbol_decision_evidence_v1';

    public static function codeHashes(): array
    {
        $root=dirname(__DIR__,2); $out=[];
        foreach(glob($root.'/src/*.php') as $path)$out['src/'.basename($path)]=hash_file('sha256',$path);
        $out['bin/paper/DecisionEvidence.php']=hash_file('sha256',__FILE__);
        ksort($out);return $out;
    }

    public static function fromRecord(array $record): array
    {
        $plan=$record['analysis']['plan']??[]; $patterns=[];
        foreach($plan['diagnostics']['patterns']??[] as $name=>$raw){
            $gates=[];
            // Missing gates are unknown, never false. Unknown detector-specific gates stay explicit.
            $expected=$name==='trend_pullback'
                ? ['rising_structure','atr_valid','valid_zone','near_ma20','volume_contracted','recovery_close','bullish_candle','volume_recovery','fresh_confirmation']
                : ($name==='trend_recovery' ? array_keys(\ChartEntryLab\TrendRecovery::GATES) : []);
            foreach(array_unique(array_merge($expected,array_keys($raw['gates']??[]))) as $key){
                $value=$raw['gates'][$key]??null;
                $gates[$key]=['state'=>$value===true?'pass':($value===false?'fail':'unknown'),
                    'observed_value'=>$value,'source'=>'analysis.plan.diagnostics.patterns.'.$name.'.gates.'.$key];
            }
            $patterns[$name]=['version'=>$raw['version']??null,'selected'=>isset($raw['version'],$plan['pattern'])?$raw['version']===$plan['pattern']:null,
                'status'=>$raw['status']??null,'reason'=>$raw['reason']??null,'ready'=>$raw['ready']??null,
                'gates'=>$gates,'gate_coverage'=>$expected===[]?'detector_details_not_provided':'known_gate_list',
                'evidence'=>$raw['evidence']??[],
                'levels'=>array_intersect_key($raw,array_flip(['entry','stop','target','reward_risk','signal_at','target_rule'])),
                'rr_evaluation'=>in_array($raw['status']??'', ['ready','rejected_rr'],true)?'terminal_status_observed':'unknown'];
        }
        $session=$record['session']??null;
        return ['version'=>self::VERSION,'symbol'=>$record['symbol']??null,'session'=>$session,
            'join_key'=>isset($record['symbol'],$session)?$record['symbol'].'@'.$session:null,
            'evidence_status'=>$record['status']??'unknown','unavailable_reason'=>$record['reason']??null,
            'unavailable_detail'=>$record['detail']??null,'input_hash'=>$record['input_hash']??null,
            'data_asof'=>$plan['data_asof']??null,'asof'=>$plan['asof']??null,
            'final'=>array_intersect_key($plan,array_flip(['version','status','reason','ready','pattern','context','context_applied','context_policy','reward_risk'])),
            'decision'=>$record['analysis']['decision']??null,
            'risk_observations'=>array_intersect_key($record['analysis']['features']??[],array_flip(['spike_dump_status','top_pattern_status','top_pattern_phase'])),
            'quality'=>$record['quality']??null,'measurements'=>$record['measurements']??[],
            'patterns'=>$patterns,'account_outcome'=>'not_observed_here',
            'interpretation'=>'Observed checks are not proof of a sole causal blocker; unknown is not failure.'];
    }
}
