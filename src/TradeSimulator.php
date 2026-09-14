<?php

declare(strict_types=1);

namespace ChartEntryLab;

/** One long limit order, intrabar stop, first target, then time exit. No synthetic fills. */
final class TradeSimulator
{
    public function __construct(private readonly float $feeBps = 10.0, private readonly float $slippageBps = 5.0)
    {
        if ($feeBps < 0 || $slippageBps < 0 || !is_finite($feeBps) || !is_finite($slippageBps)) {
            throw new \InvalidArgumentException('Costs must be finite and non-negative');
        }
    }

    public function simulate(array $plan, array $bars, int $holdingBars): array
    {
        if ($holdingBars < 1) {
            throw new \InvalidArgumentException('Holding bars must be positive');
        }
        $out = ['status' => 'no_signal', 'complete' => false, 'bars' => 0, 'filled' => false,
            'entry_fill' => null, 'entry_at' => null, 'exit_fill' => null, 'exit_at' => null,
            'first_exit' => null, 'net_return_pct' => null, 'ret_close_pct' => null,
            'hit_stop' => false, 'hit_target' => false, 'ambiguous_bar' => false,
            'fee_bps_per_side' => $this->feeBps, 'slippage_bps' => $this->slippageBps];
        if (empty($plan['ready'])) {
            return $out;
        }
        $entry = (float) ($plan['entry'] ?? 0);
        $stop = (float) ($plan['stop'] ?? 0);
        $target = (float) ($plan['target'] ?? 0);
        if (!($stop > 0 && $stop < $entry && $entry < $target)) {
            return array_replace($out, ['status' => 'invalid_levels']);
        }
        $bars = array_values(array_filter($bars, static fn(array $b): bool => ($b['available_at'] ?? 0) > ($plan['signal_at'] ?? PHP_INT_MAX)));
        usort($bars, static fn(array $a, array $b): int => $a['available_at'] <=> $b['available_at']);
        $trailing = ($plan['exit_mode'] ?? 'fixed') === 'trailing';
        $trailDistance = (float) ($plan['trail_distance'] ?? 0);
        if ($trailing && (!is_finite($trailDistance) || $trailDistance <= 0)) {
            throw new \InvalidArgumentException('Trailing distance must be positive');
        }
        $ttl = (int) ($plan['order_valid_bars'] ?? 3);
        $filledAt = null;
        $fill = null;
        foreach ($bars as $i => $bar) {
            if ($filledAt === null) {
                if ($i >= $ttl) {
                    return array_replace($out, ['status' => 'unfilled', 'complete' => true]);
                }
                if ($bar['open'] <= $stop || $bar['open'] >= $target || $bar['high'] >= $target) {
                    // If target and limit both touch on an unopened order, daily OHLC cannot establish order.
                    return array_replace($out, ['status' => 'cancelled_before_entry', 'complete' => true,
                        'ambiguous_bar' => $bar['high'] >= $target && $bar['low'] <= $entry]);
                }
                if ($bar['low'] > $entry) {
                    continue;
                }
                $filledAt = $i;
                // A buy limit never fills above its limit. Gap improvement is reduced by modeled slippage.
                $fill = min($entry, (float) $bar['open'] * (1 + $this->slippageBps / 10000));
                $out['filled'] = true;
                $out['entry_fill'] = $fill;
                $out['entry_at'] = $bar['available_at'];
            }
            $out['bars'] = $i - $filledAt + 1;
            $stopHit = $bar['low'] <= $stop;
            $targetHit = !$trailing && $bar['high'] >= $target;
            $exit = null;
            $why = null;
            // On later sessions an opening gap resolves chronology; within a bar stop wins ties.
            if (!$trailing && $i > $filledAt && $bar['open'] >= $target) {
                $why = 'target';
                $exit = $target;
            } elseif ($stopHit) {
                $why = 'stop';
                $exit = min($stop, (float) $bar['open']) * (1 - $this->slippageBps / 10000);
                $out['ambiguous_bar'] = $targetHit;
            } elseif ($targetHit) {
                $why = 'target';
                $exit = $target;
            } elseif ($out['bars'] >= $holdingBars) {
                $why = 'time';
                $exit = (float) $bar['close'] * (1 - $this->slippageBps / 10000);
            }
            if ($exit !== null) {
                $net = (($exit * (1 - $this->feeBps / 10000)) / ($fill * (1 + $this->feeBps / 10000)) - 1) * 100;
                return array_replace($out, ['status' => 'closed', 'complete' => true, 'first_exit' => $why,
                    'exit_fill' => round($exit, 6), 'exit_at' => $bar['available_at'],
                    'hit_stop' => $why === 'stop', 'hit_target' => $why === 'target',
                    'net_return_pct' => round($net, 4), 'ret_close_pct' => round($net, 4)]);
            }
            // Only a completed close raises the stop for the NEXT session.
            if ($trailing) { $stop = max($stop, (float) $bar['close'] - $trailDistance); }
        }
        return array_replace($out, ['status' => $filledAt !== null ? 'incomplete' : (count($bars) >= $ttl ? 'unfilled' : 'pending'),
            'complete' => $filledAt === null && count($bars) >= $ttl]);
    }
}
