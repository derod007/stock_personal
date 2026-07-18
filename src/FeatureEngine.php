<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 노라무식 공개 규칙을 수치 피처로 변환.
 * - 수평 자리(스윙 고/저)
 * - 이평(5/10/20/60)
 * - 저점 상승 / 고점 하락
 * - 직반 되돌림(절반구간)
 * - 최근 하방 슈팅(긴 음봉)
 */
final class FeatureEngine
{
    /**
     * @param list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}> $candles
     * @return array<string, float|int|bool|null>
     */
    public function extract(array $candles, ?float $asOfPrice = null): array
    {
        if (count($candles) < 30) {
            throw new \InvalidArgumentException('Need at least 30 candles');
        }

        $closes = array_column($candles, 'close');
        $highs = array_column($candles, 'high');
        $lows = array_column($candles, 'low');
        $volumes = array_column($candles, 'volume');
        $n = count($candles);
        $last = $candles[$n - 1];
        $price = $asOfPrice ?? $last['close'];

        $ma5 = $this->sma($closes, 5);
        $ma10 = $this->sma($closes, 10);
        $ma20 = $this->sma($closes, 20);
        $ma60 = $this->sma($closes, 60);

        $swingHigh = max(array_slice($highs, -20));
        $swingLow = min(array_slice($lows, -20));
        $priorSwingLow = min(array_slice($lows, -40, 20));
        $priorSwingHigh = max(array_slice($highs, -40, 20));

        $halfRetrace = $swingLow + (($swingHigh - $swingLow) * 0.5);
        $flushBar = false;
        foreach (array_slice($candles, -5) as $bar) {
            $range = max($bar['high'] - $bar['low'], 0.0001);
            $body = $bar['open'] - $bar['close'];
            if ($body / $range >= 0.7 && ($bar['high'] - $bar['low']) / max($bar['close'], 0.0001) >= 0.08) {
                $flushBar = true;
                break;
            }
        }

        $higherLow = $swingLow > $priorSwingLow;
        $lowerHigh = $swingHigh < $priorSwingHigh;
        $avgVol20 = array_sum(array_slice($volumes, -20)) / 20;
        $volSpike = $avgVol20 > 0 ? ($last['volume'] / $avgVol20) : null;

        $distHalfPct = $halfRetrace > 0 ? (($price - $halfRetrace) / $halfRetrace) * 100 : null;
        $distMa20Pct = $ma20 > 0 ? (($price - $ma20) / $ma20) * 100 : null;

        // 눌림 롱 점수(0~100): 구조 회복 + 절반대 근접 + 과열 아님
        $score = 0;
        if ($higherLow) {
            $score += 25;
        }
        if (!$lowerHigh) {
            $score += 10; // 고점 회복 여지
        }
        if ($flushBar) {
            $score += 15; // 하방 슈팅 후
        }
        if ($distHalfPct !== null && abs($distHalfPct) <= 3.0) {
            $score += 25;
        } elseif ($distHalfPct !== null && abs($distHalfPct) <= 6.0) {
            $score += 12;
        }
        if ($price >= $ma20 && $ma5 > $ma10) {
            $score += 15;
        }
        if ($volSpike !== null && $volSpike >= 1.5) {
            $score += 10;
        }

        return [
            'asof_kst' => $last['time_kst'],
            'price' => round($price, 4),
            'ma5' => round($ma5, 4),
            'ma10' => round($ma10, 4),
            'ma20' => round($ma20, 4),
            'ma60' => $ma60 > 0 ? round($ma60, 4) : null,
            'swing_high_20' => round($swingHigh, 4),
            'swing_low_20' => round($swingLow, 4),
            'prior_swing_low_20' => round($priorSwingLow, 4),
            'higher_low' => $higherLow,
            'lower_high' => $lowerHigh,
            'half_retrace' => round($halfRetrace, 4),
            'dist_half_pct' => $distHalfPct !== null ? round($distHalfPct, 3) : null,
            'dist_ma20_pct' => $distMa20Pct !== null ? round($distMa20Pct, 3) : null,
            'flush_bar_recent' => $flushBar,
            'vol_spike' => $volSpike !== null ? round($volSpike, 3) : null,
            'pullback_long_score' => min(100, $score),
            'invalidation_level' => round($swingLow, 4),
            'suggested_target_half_to_high' => round(($halfRetrace + $swingHigh) / 2, 4),
        ];
    }

    /**
     * @param list<float> $values
     */
    private function sma(array $values, int $period): float
    {
        if (count($values) < $period) {
            return 0.0;
        }
        $slice = array_slice($values, -$period);
        return array_sum($slice) / $period;
    }
}
