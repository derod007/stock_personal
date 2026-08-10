<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 노라무식 공개 규칙을 수치 피처로 변환.
 * - 구조 스윙: 최근 하방 슈팅(또는 피벗 저점) 이후·이전의 의미 있는 고저
 * - 폴백: 고정 20봉 high/low
 * - 이평·절반 되돌림·눌림 롱 점수
 * - 불법과외1편: 선호 캔들·돌파 후 윗박스 (`NoramuLesson1`)
 */
final class FeatureEngine
{
    /**
     * @param list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}> $candles
     * @return array<string, float|int|bool|string|null>
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

        $windowHigh = max(array_slice($highs, -20));
        $windowLow = min(array_slice($lows, -20));
        $priorWindowLow = min(array_slice($lows, -40, 20));
        $priorWindowHigh = max(array_slice($highs, -40, 20));

        $structure = $this->detectStructureSwing($candles);
        $swingHigh = $structure['swing_high'];
        $swingLow = $structure['swing_low'];
        $priorSwingLow = $structure['prior_swing_low'] ?? $priorWindowLow;
        $swingMethod = $structure['method'];

        $halfRetrace = $swingLow + (($swingHigh - $swingLow) * 0.5);
        $flushBar = $structure['flush_bar_recent'];
        $flushLow = $structure['flush_low'];

        $higherLow = $swingLow > $priorSwingLow;
        $lowerHigh = $swingHigh < $priorWindowHigh;
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
            $score += 10;
        }
        if ($flushBar) {
            $score += 15;
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

        $lesson1 = (new NoramuLesson1())->analyze($candles);
        $score = max(0, min(100, $score + (int) $lesson1['score_bonus']));

        return [
            'asof_kst' => $last['time_kst'],
            'price' => round($price, 4),
            'ma5' => round($ma5, 4),
            'ma10' => round($ma10, 4),
            'ma20' => round($ma20, 4),
            'ma60' => $ma60 > 0 ? round($ma60, 4) : null,
            'swing_high_20' => round($windowHigh, 4),
            'swing_low_20' => round($windowLow, 4),
            'prior_swing_low_20' => round($priorWindowLow, 4),
            'swing_high' => round($swingHigh, 4),
            'swing_low' => round($swingLow, 4),
            'prior_swing_low' => round($priorSwingLow, 4),
            'swing_method' => $swingMethod,
            'flush_low' => $flushLow !== null ? round($flushLow, 4) : null,
            'higher_low' => $higherLow,
            'lower_high' => $lowerHigh,
            'half_retrace' => round($halfRetrace, 4),
            'dist_half_pct' => $distHalfPct !== null ? round($distHalfPct, 3) : null,
            'dist_ma20_pct' => $distMa20Pct !== null ? round($distMa20Pct, 3) : null,
            'flush_bar_recent' => $flushBar,
            'vol_spike' => $volSpike !== null ? round($volSpike, 3) : null,
            'pullback_long_score' => $score,
            'invalidation_level' => round($swingLow, 4),
            'suggested_target_half_to_high' => round(($halfRetrace + $swingHigh) / 2, 4),
            'lesson1_candle_recipe' => $lesson1['candle_recipe'],
            'lesson1_candle_context' => $lesson1['candle_context'],
            'lesson1_upper_box' => $lesson1['upper_box_after_breakout'],
            'lesson1_breakout_no_box' => $lesson1['breakout_without_box'],
            'lesson1_extended_rally_40' => $lesson1['extended_rally_40'],
            'lesson1_score_bonus' => $lesson1['score_bonus'],
            'lesson1_note' => $lesson1['note'],
        ];
    }

    /**
     * 최근 하방 슈팅 저점 + 그 이전 구간 고점 = 내러티브 스윙.
     * 슈팅이 없으면 피벗 저점·그 이전 피벗 고점.
     *
     * @param list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}> $candles
     * @return array{
     *   swing_high:float,
     *   swing_low:float,
     *   prior_swing_low:float,
     *   flush_bar_recent:bool,
     *   flush_low:?float,
     *   method:string
     * }
     */
    private function detectStructureSwing(array $candles): array
    {
        $n = count($candles);
        $highs = array_column($candles, 'high');
        $lows = array_column($candles, 'low');

        $windowHigh = max(array_slice($highs, -20));
        $windowLow = min(array_slice($lows, -20));
        $priorWindowLow = min(array_slice($lows, -40, 20));

        $flushIdx = $this->findStructuralFlushIndex($candles, lookback: 12);
        if ($flushIdx !== null) {
            $flushLow = (float) $candles[$flushIdx]['low'];
            $structLow = $flushLow;

            // 붕괴 전 피크: 슈팅 직전 15봉 고점 (먼 스파이크 제외)
            $preLen = 15;
            $from = max(0, $flushIdx - $preLen);
            $preFlushHighs = array_slice($highs, $from, max(1, $flushIdx - $from));
            $structHigh = $preFlushHighs !== [] ? (float) max($preFlushHighs) : (float) $windowHigh;

            // 피벗 고점(슈팅 직전)이 윈도우 맥스에 가까우면 그 값을 저항으로
            $pivots = $this->findPivots($candles, wing: 2);
            foreach (array_reverse($pivots['highs']) as $pivot) {
                if ($pivot['idx'] < $flushIdx && $pivot['idx'] >= $flushIdx - 18) {
                    if ($pivot['price'] >= $structHigh * 0.90 && $pivot['price'] <= $structHigh * 1.01) {
                        $structHigh = (float) $pivot['price'];
                    }
                    break;
                }
            }

            // prior low: 피크 이전 저점과 40봉 윈도우 저점 중 더 낮은 쪽 (저점 상향 판별용)
            $peakIdx = $from;
            for ($i = $from; $i < $flushIdx; $i++) {
                if ((float) $candles[$i]['high'] >= $structHigh * 0.999) {
                    $peakIdx = $i;
                }
            }
            $priorFrom = max(0, $peakIdx - 25);
            $priorSlice = array_slice($lows, $priorFrom, max(1, $peakIdx - $priorFrom));
            $priorLow = (float) $priorWindowLow;
            if ($priorSlice !== []) {
                $priorLow = min($priorLow, (float) min($priorSlice));
            }

            if ($structHigh > $structLow * 1.02) {
                return [
                    'swing_high' => $structHigh,
                    'swing_low' => $structLow,
                    'prior_swing_low' => $priorLow,
                    'flush_bar_recent' => ($n - 1 - $flushIdx) <= 5,
                    'flush_low' => $flushLow,
                    'method' => 'flush_pre_peak',
                ];
            }
        }

        $pivots = $this->findPivots($candles, wing: 2);
        $recentLow = null;
        foreach (array_reverse($pivots['lows']) as $pivot) {
            if ($pivot['idx'] >= $n - 30) {
                $recentLow = $pivot;
                break;
            }
        }
        if ($recentLow !== null) {
            $priorHigh = null;
            foreach (array_reverse($pivots['highs']) as $pivot) {
                if ($pivot['idx'] < $recentLow['idx'] && $pivot['idx'] >= $recentLow['idx'] - 30) {
                    $priorHigh = $pivot;
                    break;
                }
            }
            $earlierLow = null;
            foreach (array_reverse($pivots['lows']) as $pivot) {
                if ($pivot['idx'] < $recentLow['idx'] - 3) {
                    $earlierLow = $pivot;
                    break;
                }
            }
            if ($priorHigh !== null && $priorHigh['price'] > $recentLow['price'] * 1.02) {
                return [
                    'swing_high' => (float) $priorHigh['price'],
                    'swing_low' => (float) $recentLow['price'],
                    'prior_swing_low' => (float) ($earlierLow['price'] ?? $priorWindowLow),
                    'flush_bar_recent' => $this->findStructuralFlushIndex($candles, lookback: 5) !== null,
                    'flush_low' => null,
                    'method' => 'pivot',
                ];
            }
        }

        return [
            'swing_high' => (float) $windowHigh,
            'swing_low' => (float) $windowLow,
            'prior_swing_low' => (float) $priorWindowLow,
            'flush_bar_recent' => $this->findStructuralFlushIndex($candles, lookback: 5) !== null,
            'flush_low' => null,
            'method' => 'window_20',
        ];
    }

    /**
     * lookback 안 슈팅 후보 중 저점이 가장 깊은 봉 (구조적 하방 슈팅).
     * - 긴 음봉 몸통, 또는
     * - 긴 아래꼬리(지지 실패 후 저점 찍고 회복) — 노라무식 “하방 슈팅”
     *
     * @param list<array{open:float,high:float,low:float,close:float}> $candles
     */
    private function findStructuralFlushIndex(array $candles, int $lookback): ?int
    {
        $n = count($candles);
        $start = max(0, $n - $lookback);
        $bestIdx = null;
        $bestLow = null;
        for ($i = $start; $i < $n; $i++) {
            if (!$this->isFlushBar($candles[$i])) {
                continue;
            }
            $low = (float) $candles[$i]['low'];
            if ($bestIdx === null || $low < $bestLow) {
                $bestIdx = $i;
                $bestLow = $low;
            }
        }
        return $bestIdx;
    }

    /**
     * @param array{open:float,high:float,low:float,close:float} $bar
     */
    private function isFlushBar(array $bar): bool
    {
        $range = max($bar['high'] - $bar['low'], 0.0001);
        $rangePct = ($bar['high'] - $bar['low']) / max(abs($bar['close']), 0.0001);
        if ($rangePct < 0.06) {
            return false;
        }

        $body = $bar['open'] - $bar['close'];
        $bodyRatio = $body / $range;
        if ($bodyRatio >= 0.65) {
            return true;
        }

        $lowerWick = min($bar['open'], $bar['close']) - $bar['low'];
        $lowerWickRatio = $lowerWick / $range;
        // 아래꼬리 슈팅: 저점을 깊게 찍고 몸통은 위쪽에 회복
        return $lowerWickRatio >= 0.55 && $rangePct >= 0.08;
    }

    /**
     * @param list<array{high:float,low:float}> $candles
     * @return array{highs:list<array{idx:int,price:float}>,lows:list<array{idx:int,price:float}>}
     */
    private function findPivots(array $candles, int $wing): array
    {
        $n = count($candles);
        $highs = [];
        $lows = [];
        for ($i = $wing; $i < $n - $wing; $i++) {
            $isHigh = true;
            $isLow = true;
            for ($j = $i - $wing; $j <= $i + $wing; $j++) {
                if ($j === $i) {
                    continue;
                }
                if ($candles[$j]['high'] > $candles[$i]['high']) {
                    $isHigh = false;
                }
                if ($candles[$j]['low'] < $candles[$i]['low']) {
                    $isLow = false;
                }
            }
            if ($isHigh) {
                $highs[] = ['idx' => $i, 'price' => (float) $candles[$i]['high']];
            }
            if ($isLow) {
                $lows[] = ['idx' => $i, 'price' => (float) $candles[$i]['low']];
            }
        }
        return ['highs' => $highs, 'lows' => $lows];
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
