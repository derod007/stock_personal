<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 1~3일 급등 뒤 종가 되돌림과 사전 지지 종가 이탈을 함께 확인.
 * 리스트에서 지우지 않고 경고·감점·신규진입 보류로 다룬다.
 */
final class SpikeDump
{
    private const MIN_SPIKE_PCT = 20.0;
    private const MIN_RETRACE = 0.50;
    private const CONFIRMED_RETRACE = 0.70;
    private const MIN_DROP_FROM_PEAK_PCT = 6.0;
    private const PEAK_LOOKBACK = 8;
    private const MAX_BURST = 3;
    private const WARNING_PENALTY = -10;
    private const CONFIRMED_PENALTY = -16;

    /**
     * @param list<array{open:float,high:float,low:float,close:float,volume:int}> $candles
     * @return array{
     *   status:string,
     *   label:string,
     *   note:string,
     *   score_adjustment:int,
     *   spike_pct:?float,
     *   retrace:?float,
     *   drop_from_peak_pct:?float,
     *   burst_bars:?int,
     *   peak_bars_ago:?int
     * }
     */
    public function analyze(array $candles): array
    {
        $n = count($candles);
        if ($n < 8) {
            return $this->empty();
        }

        $best = null;
        $peakFrom = max(1, $n - self::PEAK_LOOKBACK);
        for ($peak = $peakFrom; $peak < $n; $peak++) {
            $peakHigh = (float) $candles[$peak]['high'];
            if ($peakHigh <= 0) {
                continue;
            }
            for ($burst = 1; $burst <= self::MAX_BURST; $burst++) {
                $start = $peak - $burst + 1;
                if ($start < 1) {
                    continue;
                }
                $pre = (float) $candles[$start - 1]['close'];
                if ($pre <= 0) {
                    continue;
                }
                $spikePct = (($peakHigh / $pre) - 1.0) * 100;
                if ($spikePct < self::MIN_SPIKE_PCT) {
                    continue;
                }

                $afterLow = $peakHigh;
                for ($i = $peak; $i < $n; $i++) {
                    // Daily lows do not establish a high -> low sequence, especially on the peak bar.
                    $afterLow = min($afterLow, (float) $candles[$i]['close']);
                }
                $range = $peakHigh - $pre;
                if ($range <= 0) {
                    continue;
                }
                $retrace = ($peakHigh - $afterLow) / $range;
                $dropPct = (($peakHigh - $afterLow) / $peakHigh) * 100;
                if ($retrace < self::MIN_RETRACE || $dropPct < self::MIN_DROP_FROM_PEAK_PCT) {
                    continue;
                }

                $lastClose = (float) $candles[$n - 1]['close'];
                if ($lastClose >= $peakHigh * 0.98) {
                    continue;
                }

                $support = $this->supportBeforeBurst($candles, $start, $peak);
                // Touches, lower wicks and closes holding/reclaiming support do not block entry.
                if ($support === null || $lastClose >= $support['price']) {
                    continue;
                }

                $score = $spikePct + ($retrace * 20);
                if ($best !== null && $score <= $best['score']) {
                    continue;
                }

                $closeLoc = ($lastClose - $pre) / $range;
                $confirmed = $retrace >= self::CONFIRMED_RETRACE || $closeLoc <= 0.30;
                $best = [
                    'support' => $support,
                    'last_close' => $lastClose,
                    'score' => $score,
                    'confirmed' => $confirmed,
                    'spike_pct' => $spikePct,
                    'retrace' => $retrace,
                    'drop_pct' => $dropPct,
                    'burst' => $burst,
                    'ago' => ($n - 1) - $peak,
                ];
            }
        }

        if ($best === null) {
            return $this->empty();
        }

        $status = $best['confirmed'] ? 'confirmed' : 'warning';

        return [
            'rule_version' => 'close_support_v2',
            'retrace_basis' => 'close',
            'support_price' => $best['support']['price'],
            'support_source' => $best['support']['source'],
            'support_index' => $best['support']['index'],
            'last_close' => $best['last_close'],
            'status' => $status,
            'label' => $status === 'confirmed' ? '급등후급락(확정)' : '급등후급락',
            'note' => sprintf(
                '최근 %d일 급등 %+.1f%% 뒤 종가 기준 고가 대비 %.1f%% 반납(되돌림 %.0f%%). %s %.3f를 종가 %.3f로 이탈.',
                (int) $best['burst'],
                (float) $best['spike_pct'],
                (float) $best['drop_pct'],
                (float) $best['retrace'] * 100,
                $best['support']['label'], $best['support']['price'], $best['last_close']
            ),
            'score_adjustment' => $status === 'confirmed' ? self::CONFIRMED_PENALTY : self::WARNING_PENALTY,
            'spike_pct' => round((float) $best['spike_pct'], 2),
            'retrace' => round((float) $best['retrace'], 3),
            'drop_from_peak_pct' => round((float) $best['drop_pct'], 2),
            'burst_bars' => (int) $best['burst'],
            'peak_bars_ago' => (int) $best['ago'],
        ];
    }

    /** Freeze support using only bars preceding this burst; no future pivots. */
    private function supportBeforeBurst(array $candles, int $start, int $peak): ?array
    {
        $from = max(0, $start - 20);
        $prior = array_slice($candles, $from, $start - $from);
        if (count($prior) >= 5) {
            $high = max(array_column($prior, 'high'));
            // A wick above resistance is not a confirmed breakout.
            for ($i = $start; $i <= $peak; $i++) {
                if ((float) $candles[$i]['close'] > $high) {
                    return ['price' => (float) $high, 'source' => 'pre_burst_20bar_high',
                        'index' => $from + array_search($high, array_column($prior, 'high'), true), 'label' => '급등 전 돌파 가격'];
                }
            }
        }
        // Last strict pivot whose two right-hand bars were already complete before the burst.
        for ($i = $start - 3; $i >= max(2, $start - 20); $i--) {
            $low = (float) $candles[$i]['low'];
            if ($low > 0 && $low < min((float)$candles[$i-2]['low'], (float)$candles[$i-1]['low'],
                    (float)$candles[$i+1]['low'], (float)$candles[$i+2]['low'])) {
                return ['price' => $low, 'source' => 'pre_burst_confirmed_low', 'index' => $i, 'label' => '급등 전 확인 저점'];
            }
        }
        $low = (float) $candles[$start-1]['low'];
        return $low > 0 ? ['price' => $low, 'source' => 'pre_burst_previous_low',
            'index' => $start-1, 'label' => '급등 직전 봉 저점'] : null;
    }

    /**
     * @return array{
     *   status:string,
     *   label:string,
     *   note:string,
     *   score_adjustment:int,
     *   spike_pct:?float,
     *   retrace:?float,
     *   drop_from_peak_pct:?float,
     *   burst_bars:?int,
     *   peak_bars_ago:?int
     * }
     */
    public function empty(): array
    {
        return [
            'status' => 'none',
            'label' => '',
            'note' => '',
            'score_adjustment' => 0,
            'spike_pct' => null,
            'retrace' => null,
            'drop_from_peak_pct' => null,
            'burst_bars' => null,
            'peak_bars_ago' => null,
        ];
    }
}
