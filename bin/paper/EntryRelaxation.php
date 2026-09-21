<?php
declare(strict_types=1);
use ChartEntryLab\CandleClock;
use ChartEntryLab\ChartPlanEngine;
use ChartEntryLab\PaperJournal;
use ChartEntryLab\PriceCandidate;

/** Isolated hypothesis: only the pullback volume ratio changes, .85 -> .95. */
final class PaperEntryRelaxation
{
    public const GATES = ['rising_structure','atr_valid','valid_zone','near_ma20','volume_contracted',
        'recovery_close','bullish_candle','volume_recovery','fresh_confirmation'];

    public static function onlyVolumeMissing(array $plan): bool
    {
        $g=$plan['diagnostics']['patterns']['trend_pullback']['gates']??[];
        foreach(self::GATES as $key) {
            if(!array_key_exists($key,$g) || $g[$key]!==($key!=='volume_contracted'))return false;
        }
        return true;
    }

    public static function apply(array $snapshot,array $history): array
    {
        if(!self::onlyVolumeMissing($snapshot['plan']) || !empty($snapshot['plan']['ready']))return $snapshot;
        $at=$snapshot['session'];$symbol=$snapshot['symbol'];
        $past=array_values(array_filter($history,fn($b)=>CandleClock::closeTime($b,$symbol)<=$at));
        if(hash('sha256',PaperJournal::encode($past))!==$snapshot['input_hash'])throw new RuntimeException('Research input hash mismatch');
        $bars=CandleClock::completed($past,$symbol,$at);
        $analysis=(new ChartPlanEngine())->analyze($bars,$symbol,$at);
        if(PaperJournal::encode($analysis['plan'])!==PaperJournal::encode($snapshot['plan']))throw new RuntimeException('Stored signal does not match current engine');
        $snapshot['plan']=self::fromAnalysis($analysis,$bars);
        return $snapshot;
    }

    public static function fromAnalysis(array $analysis,array $bars): array
    {
        $plan=$analysis['plan'];
        if(count($bars)<45 || !self::onlyVolumeMissing($plan) || !empty($plan['ready'])
            || !empty($plan['diagnostics']['patterns']['breakout_retest']['ready'])
            || in_array($plan['status'],['risk_blocked','stale_data','blocked'],true))return $plan;
        $base=array_sum(array_column(array_slice($bars,-24,20),'volume'))/20;
        $pull=array_sum(array_column(array_slice($bars,-4,3),'volume'))/3;
        if($base<=0 || $pull>=$base*0.95)return $plan;
        $last=$bars[array_key_last($bars)];$atr=(float)$analysis['features']['atr14'];
        $entry=PriceCandidate::trunc((float)$last['close']);
        $stop=PriceCandidate::trunc(min(array_column(array_slice($bars,-10),'low'))-0.2*$atr);
        $target=PriceCandidate::trunc(max(array_column(array_slice($bars,-21,20),'high')));
        $rr=$entry>$stop?($target-$entry)/($entry-$stop):0.0;
        // Same final trend and reward/risk gates as the baseline engine.
        $context=$plan['context'];
        if($context['daily']==='down' || ($context['weekly']==='down' && ($context['daily']!=='up' || $rr<2.0)))return $plan;
        if($stop<=0 || $entry<=$stop || $target<=$entry || $rr<1.5)return $plan;
        $pattern=$plan['diagnostics']['patterns']['trend_pullback'];
        $pattern=array_replace($pattern,['ready'=>true,'status'=>'ready','entry'=>$entry,'stop'=>$stop,'target'=>$target,
            'reward_risk'=>round($rr,3),'target_rule'=>'prior_20_bar_high','signal_at'=>$last['available_at'],
            'volume_contracted'=>true,'reason'=>'연구용: 눌림 거래량 95% 미만, 나머지 확인·위험 조건 통과']);
        $pattern['gates']['volume_contracted']=true;
        foreach(['ready','status','entry','stop','target','reward_risk','target_rule','signal_at','reason','volume_contracted','gates'] as $k)$plan[$k]=$pattern[$k];
        $plan['pattern']=$pattern['version'];$plan['version']=$pattern['version'];
        $plan['confirmation_status']='confirmed';
        $plan['diagnostics']['patterns']['trend_pullback']=$pattern;
        $plan['diagnostics']['selected_pattern']=$plan['pattern'];$plan['diagnostics']['final_status']='ready';$plan['diagnostics']['order_ready']=true;
        $plan['research_change']=['gate'=>'volume_contracted','baseline_ratio'=>0.85,'candidate_ratio'=>0.95,'observed_ratio'=>$pull/$base];
        return $plan;
    }
}
