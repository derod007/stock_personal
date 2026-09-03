<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 노라무 «새벽반 전용 고점판독기법» (fm-10237234875).
 *
 * 강한 상승 뒤 고점 돌파 실패 → 고점 하향 → 파동 중심/이전 파동 하단 시험
 * → 상승 추세선 이탈을 단계적으로 판독한다. 가격을 상하 반전해 역저점도 본다.
 */
final class NoramuTopPattern
{
    /**
     * @param list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}> $candles
     * @return array<string,mixed>
     */
    public function analyze(array $candles): array
    {
        if (count($candles) < 45) {
            return $this->empty('봉 부족');
        }

        $top = $this->detectTop($candles);
        $anchor = max(array_column($candles, 'high')) + min(array_column($candles, 'low'));
        $mirrored = array_map(static function (array $bar) use ($anchor): array {
            return array_merge($bar, [
                'open' => $anchor - (float) $bar['open'],
                'high' => $anchor - (float) $bar['low'],
                'low' => $anchor - (float) $bar['high'],
                'close' => $anchor - (float) $bar['close'],
            ]);
        }, $candles);
        $inverse = $this->detectTop($mirrored);

        $topPenalty = ($top['phase'] ?? '') === 'bounce_confirmed'
            ? 0
            : match ($top['status']) {
                'confirmed' => -22,
                'warning' => -12,
                'watch' => -6,
                default => 0,
            };
        // 역저점은 칼날 잡기 방지를 위해 확인 전에는 가산하지 않는다.
        $bottomBonus = match ($inverse['status']) {
            'confirmed' => 12,
            'warning' => 5,
            default => 0,
        };
        $adjustment = max(-22, min(12, $topPenalty + $bottomBonus));

        $top['peak'] = $this->roundLevel($top['peak']);
        $top['midpoint'] = $this->roundLevel($top['midpoint']);
        $top['wave_floor'] = $this->roundLevel($top['wave_floor']);
        $top['trend_support'] = $this->roundLevel($top['trend_support']);
        $inverse = $this->unmirror($inverse, $anchor);

        $bits = [];
        if ($top['status'] !== 'none') {
            $bits[] = '고점 ' . $this->statusLabel($top['status'])
                . ($top['phase'] !== 'none' ? '(' . $this->phaseLabel($top['phase']) . ')' : '');
        }
        if ($inverse['status'] !== 'none') {
            $bits[] = '역저점 ' . $this->statusLabel($inverse['status'])
                . ($inverse['phase'] !== 'none' ? '(' . $this->phaseLabel($inverse['phase']) . ')' : '');
        }

        return [
            'source_srl' => '10237234875',
            'top_status' => $top['status'],
            'top_phase' => $top['phase'],
            'inverse_bottom_status' => $inverse['status'],
            'inverse_bottom_phase' => $inverse['phase'],
            'score_adjustment' => $adjustment,
            'top' => $top,
            'inverse_bottom' => $inverse,
            'note' => $bits === []
                ? '고점판독기법: 뚜렷한 고점 붕괴·역저점 패턴 없음'
                : '고점판독기법: ' . implode(' · ', $bits),
        ];
    }

    /**
     * @param list<array{high:float,low:float,close:float}> $candles
     * @return array<string,mixed>
     */
    private function detectTop(array $candles): array
    {
        $n = count($candles);
        $from = max(0, $n - 65);
        $latestAllowedPeak = $n - 7;
        if ($latestAllowedPeak <= $from + 12) {
            return $this->emptySide();
        }

        $peakIdx = $from + 12;
        $peak = (float) $candles[$peakIdx]['high'];
        for ($i = $peakIdx + 1; $i <= $latestAllowedPeak; $i++) {
            if ((float) $candles[$i]['high'] > $peak) {
                $peak = (float) $candles[$i]['high'];
                $peakIdx = $i;
            }
        }

        $priorLow = INF;
        $priorLowIdx = $from;
        for ($i = $from; $i < $peakIdx; $i++) {
            if ((float) $candles[$i]['low'] < $priorLow) {
                $priorLow = (float) $candles[$i]['low'];
                $priorLowIdx = $i;
            }
        }
        if (!is_finite($priorLow) || $priorLow <= 0 || $peak <= $priorLow) {
            return $this->emptySide();
        }

        $rallyPct = (($peak - $priorLow) / $priorLow) * 100;
        $afterFrom = $peakIdx + 2;
        if ($rallyPct < 18.0 || $afterFrom >= $n - 2) {
            return $this->emptySide();
        }

        $afterHigh = 0.0;
        for ($i = $afterFrom; $i < $n; $i++) {
            $afterHigh = max($afterHigh, (float) $candles[$i]['high']);
        }
        $failedBreak = $afterHigh >= $peak * 0.90 && $afterHigh <= $peak * 1.02;

        $afterLen = $n - $afterFrom;
        $split = $afterFrom + intdiv($afterLen, 2);
        $earlyHigh = 0.0;
        $lateHigh = 0.0;
        for ($i = $afterFrom; $i < $split; $i++) {
            $earlyHigh = max($earlyHigh, (float) $candles[$i]['high']);
        }
        for ($i = $split; $i < $n; $i++) {
            $lateHigh = max($lateHigh, (float) $candles[$i]['high']);
        }
        $lowerHighs = $earlyHigh > 0 && $lateHigh <= $earlyHigh * 0.99;

        $midpoint = $priorLow + (($peak - $priorLow) * 0.5);
        $latestClose = (float) $candles[$n - 1]['close'];
        $midpointTouches = 0;
        for ($i = $afterFrom; $i < $n; $i++) {
            if (
                (float) $candles[$i]['low'] <= $midpoint * 1.01
                && (float) $candles[$i]['high'] >= $midpoint * 0.99
            ) {
                $midpointTouches++;
            }
        }
        $midpointLost = $midpointTouches >= 3 && $latestClose < $midpoint * 0.99;

        $pivotLows = $this->pivotLows($candles, $from, $peakIdx);
        $waveFloor = count($pivotLows) >= 2
            ? (float) $pivotLows[count($pivotLows) - 2]['price']
            : $midpoint;
        $recentLow = min(array_column(array_slice($candles, -8), 'low'));
        $waveFloorTouched = $recentLow <= $waveFloor * 1.015;
        $waveFloorBreached = $recentLow < $waveFloor * 0.995;
        $touchIdx = null;
        for ($i = $afterFrom; $i < $n; $i++) {
            if ((float) $candles[$i]['low'] <= $waveFloor * 1.015) {
                $touchIdx = $i;
                break;
            }
        }

        $trendSupport = null;
        $trendBroken = false;
        $atr = $this->atr($candles, 14);
        if (count($pivotLows) >= 2) {
            $a = $pivotLows[count($pivotLows) - 2];
            $b = $pivotLows[count($pivotLows) - 1];
            if ($b['idx'] > $a['idx'] && $b['price'] > $a['price']) {
                $slope = ($b['price'] - $a['price']) / ($b['idx'] - $a['idx']);
                $trendSupport = $b['price'] + ($slope * (($n - 1) - $b['idx']));
                $buffer = max(($atr ?? 0.0) * 0.25, $trendSupport * 0.005);
                $trendBroken = $latestClose < $trendSupport - $buffer;
            }
        } elseif ($peakIdx > $priorLowIdx + 8) {
            $slope = ($peak - $priorLow) / ($peakIdx - $priorLowIdx);
            $trendSupport = $priorLow + ($slope * (($n - 1) - $priorLowIdx));
            $buffer = max(($atr ?? 0.0) * 0.25, $trendSupport * 0.005);
            $trendBroken = $latestClose < $trendSupport - $buffer;
        }

        $status = 'none';
        if ($failedBreak) {
            $status = 'watch';
            if ($lowerHighs && ($midpointLost || $trendBroken)) {
                $status = 'warning';
            }
            if ($lowerHighs && $trendBroken && $waveFloorTouched) {
                $status = 'confirmed';
            }
        }

        $bounceConfirmed = false;
        if ($touchIdx !== null && ($n - $touchIdx) >= 6) {
            $touchLow = INF;
            for ($i = $touchIdx; $i <= min($n - 1, $touchIdx + 2); $i++) {
                $touchLow = min($touchLow, (float) $candles[$i]['low']);
            }
            $reboundEnd = $n - 4;
            $reboundHigh = 0.0;
            for ($i = $touchIdx + 1; $i <= $reboundEnd; $i++) {
                $reboundHigh = max($reboundHigh, (float) $candles[$i]['high']);
            }
            $recentHigherLow = min(array_column(array_slice($candles, -3), 'low')) > $touchLow * 1.01;
            $bounceConfirmed = $reboundHigh > 0
                && $recentHigherLow
                && $latestClose >= $reboundHigh * 0.98;
        }
        $phase = 'none';
        if ($bounceConfirmed && $status === 'confirmed') {
            $phase = 'bounce_confirmed';
        } elseif ($status === 'confirmed' && $waveFloorBreached) {
            $phase = 'capitulation';
        } elseif (in_array($status, ['warning', 'confirmed'], true)) {
            $phase = 'distribution';
        } elseif ($status === 'watch') {
            $phase = 'top_watch';
        }

        return [
            'status' => $status,
            'phase' => $phase,
            'peak' => $peak,
            'midpoint' => $midpoint,
            'wave_floor' => $waveFloor,
            'trend_support' => $trendSupport,
            'prior_rally_pct' => round($rallyPct, 2),
            'failed_break' => $failedBreak,
            'lower_highs' => $lowerHighs,
            'midpoint_touch_count' => $midpointTouches,
            'midpoint_lost' => $midpointLost,
            'trend_broken' => $trendBroken,
            'wave_floor_touched' => $waveFloorTouched,
            'wave_floor_breached' => $waveFloorBreached,
            'bounce_confirmed' => $bounceConfirmed,
        ];
    }

    /**
     * @param list<array{low:float}> $candles
     * @return list<array{idx:int,price:float}>
     */
    private function pivotLows(array $candles, int $from, int $to): array
    {
        $out = [];
        for ($i = max($from + 2, 2); $i <= min($to - 2, count($candles) - 3); $i++) {
            $low = (float) $candles[$i]['low'];
            if (
                $low <= (float) $candles[$i - 1]['low']
                && $low < (float) $candles[$i - 2]['low']
                && $low <= (float) $candles[$i + 1]['low']
                && $low < (float) $candles[$i + 2]['low']
            ) {
                $out[] = ['idx' => $i, 'price' => $low];
            }
        }
        return $out;
    }

    /**
     * @param list<array{high:float,low:float,close:float}> $candles
     */
    private function atr(array $candles, int $period): ?float
    {
        $n = count($candles);
        if ($n < 2) {
            return null;
        }
        $from = max(1, $n - $period);
        $sum = 0.0;
        $count = 0;
        for ($i = $from; $i < $n; $i++) {
            $high = (float) $candles[$i]['high'];
            $low = (float) $candles[$i]['low'];
            $prevClose = (float) $candles[$i - 1]['close'];
            $sum += max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
            $count++;
        }
        return $count > 0 ? $sum / $count : null;
    }

    /** @param array<string,mixed> $side */
    private function unmirror(array $side, float $anchor): array
    {
        foreach (['peak', 'midpoint', 'wave_floor', 'trend_support'] as $key) {
            $side[$key] = isset($side[$key]) && is_numeric($side[$key])
                ? $this->roundLevel($anchor - (float) $side[$key])
                : null;
        }
        return $side;
    }

    /** @return array<string,mixed> */
    private function empty(string $reason): array
    {
        return [
            'source_srl' => '10237234875',
            'top_status' => 'none',
            'top_phase' => 'none',
            'inverse_bottom_status' => 'none',
            'inverse_bottom_phase' => 'none',
            'score_adjustment' => 0,
            'top' => $this->emptySide(),
            'inverse_bottom' => $this->emptySide(),
            'note' => '고점판독기법: ' . $reason,
        ];
    }

    /** @return array<string,mixed> */
    private function emptySide(): array
    {
        return [
            'status' => 'none',
            'phase' => 'none',
            'peak' => null,
            'midpoint' => null,
            'wave_floor' => null,
            'trend_support' => null,
            'prior_rally_pct' => null,
            'failed_break' => false,
            'lower_highs' => false,
            'midpoint_touch_count' => 0,
            'midpoint_lost' => false,
            'trend_broken' => false,
            'wave_floor_touched' => false,
            'wave_floor_breached' => false,
            'bounce_confirmed' => false,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'confirmed' => '확정 경고',
            'warning' => '경고',
            'watch' => '관찰',
            default => '해당없음',
        };
    }

    private function phaseLabel(string $phase): string
    {
        return match ($phase) {
            'distribution' => '분산·하락 진행',
            'capitulation' => '파동 하단 이탈',
            'bounce_confirmed' => '반등 확인',
            'top_watch' => '고점 약화 관찰',
            default => '단계 없음',
        };
    }

    private function roundLevel(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 4) : null;
    }
}
