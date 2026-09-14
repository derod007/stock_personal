<?php
declare(strict_types=1);
namespace ChartEntryLab;

final class ResearchMetrics
{
    public static function summarize(array $trades): array
    {
        $closed = array_values(array_filter($trades, fn($t) => $t['status'] === 'closed'));
        usort($closed, fn($a,$b) => $a['exit_at'] <=> $b['exit_at']);
        $returns = array_column($closed, 'net_return_pct');
        $wins = array_values(array_filter($returns, fn($r) => $r > 0));
        $losses = array_values(array_filter($returns, fn($r) => $r < 0));
        $equity = $peak = 1.0; $dd = 0.0;
        foreach ($returns as $r) { $equity *= 1 + $r/100; $peak=max($peak,$equity); $dd=max($dd,1-$equity/$peak); }
        $mean = fn($x) => count($x) ? array_sum($x)/count($x) : null;
        return ['orders'=>count($trades), 'closed'=>count($closed),
            'status_counts'=>array_count_values(array_column($trades,'status')),
            'expectancy_pct'=>$mean($returns), 'win_rate'=>count($closed) ? count($wins)/count($closed) : null,
            'avg_win_pct'=>$mean($wins), 'avg_loss_pct'=>$mean($losses),
            'payoff_ratio'=>count($wins) && count($losses) ? $mean($wins)/abs($mean($losses)) : null,
            'profit_factor'=>count($losses) ? array_sum($wins)/abs(array_sum($losses)) : null,
            'closed_trade_drawdown_pct'=>count($closed) ? $dd*100 : null];
    }
}
