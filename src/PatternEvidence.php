<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Display evidence only. No rule evaluation, selection, price calculation or orders. */
final class PatternEvidence
{
    public static function capture(array $proposal): array
    {
        $mode=$proposal['analysis_mode']??'unknown';
        // Intraday diagnostics must never borrow the current price for a completed signal.
        $plan=$mode==='intraday'?($proposal['completed_trade_plan']??[]):($proposal['trade_plan']??[]);
        return ['schema'=>1,'basis'=>$mode==='intraday'?'completed_reference':$mode,
            'plan'=>array_intersect_key(is_array($plan)?$plan:[],array_flip([
                'pattern','status','ready','reason','data_asof','context','context_applied','diagnostics',
            ]))];
    }
}
