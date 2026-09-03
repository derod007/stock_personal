<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 1~3일 급등 뒤 급락. 점수가 눌림처럼 보여도 중간가는 허공인 자리.
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
                    $afterLow = min($afterLow, (float) $candles[$i]['low']);
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

                $score = $spikePct + ($retrace * 20);
                if ($best !== null && $score <= $best['score']) {
                    continue;
                }

                $closeLoc = ($lastClose - $pre) / $range;
                $confirmed = $retrace >= self::CONFIRMED_RETRACE || $closeLoc <= 0.30;
                $best = [
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
            'status' => $status,
            'label' => $status === 'confirmed' ? '급등후급락(확정)' : '급등후급락',
            'note' => sprintf(
                '최근 %d일 급등 %+.1f%% 뒤 고가 대비 %.1f%% 반납(되돌림 %.0f%%). 중간 반등은 허공. 급등 전 가로가 지지되는지 보기.',
                (int) $best['burst'],
                (float) $best['spike_pct'],
                (float) $best['drop_pct'],
                (float) $best['retrace'] * 100
            ),
            'score_adjustment' => $status === 'confirmed' ? self::CONFIRMED_PENALTY : self::WARNING_PENALTY,
            'spike_pct' => round((float) $best['spike_pct'], 2),
            'retrace' => round((float) $best['retrace'], 3),
            'drop_from_peak_pct' => round((float) $best['drop_pct'], 2),
            'burst_bars' => (int) $best['burst'],
            'peak_bars_ago' => (int) $best['ago'],
        ];
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
