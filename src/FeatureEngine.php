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

        // 구조 저점을 종가가 이미 깨면 “저점 상승”은 끝난 그림이다.
        $structureIntact = $price >= $swingLow * 0.999;
        $higherLow = $swingLow > $priorSwingLow && $structureIntact;
        $lowerHigh = $swingHigh < $priorWindowHigh;
        $avgVol20 = array_sum(array_slice($volumes, -20)) / 20;
        $volSpike = $avgVol20 > 0 ? ($last['volume'] / $avgVol20) : null;

        $distHalfPct = $halfRetrace > 0 ? (($price - $halfRetrace) / $halfRetrace) * 100 : null;
        $distMa20Pct = $ma20 > 0 ? (($price - $ma20) / $ma20) * 100 : null;

        // 눌림 롱 기본 점수(100점): 각 항목을 분리해 UI에서 그대로 설명한다.
        $higherLowPoints = $higherLow ? 25 : 0;
        $highHoldPoints = !$lowerHigh ? 10 : 0;
        $flushPoints = $flushBar ? 15 : 0;
        $halfPoints = 0;
        if ($distHalfPct !== null && abs($distHalfPct) <= 3.0) {
            $halfPoints = 25;
        } elseif ($distHalfPct !== null && abs($distHalfPct) <= 6.0) {
            $halfPoints = 12;
        }
        $trendPass = $price >= $ma20 && $ma5 > $ma10;
        $trendPoints = $trendPass ? 15 : 0;
        $volumePass = $volSpike !== null && $volSpike >= 1.5;
        $volumePoints = $volumePass ? 10 : 0;
        $baseScore = $higherLowPoints
            + $highHoldPoints
            + $flushPoints
            + $halfPoints
            + $trendPoints
            + $volumePoints;

        $lesson1 = (new NoramuLesson1())->analyze($candles);
        $lessonBonus = (int) $lesson1['score_bonus'];
        $afterLessonScore = max(0, min(100, $baseScore + $lessonBonus));

        // 반복 접촉 가로 레벨(매물대) — 노라무 “넓게 보면 13400 이탈만 안 하면” 규칙
        $levelInfo = (new HorizontalLevels())->analyze($candles, (float) $price);
        $levelSupport = is_array($levelInfo['support'] ?? null) ? $levelInfo['support'] : null;
        $levelResistance = is_array($levelInfo['resistance'] ?? null) ? $levelInfo['resistance'] : null;
        $levelBonus = $levelSupport !== null ? ($levelSupport['flip'] ? 8 : 4) : 0;
        $topPattern = (new NoramuTopPattern())->analyze($candles);
        $topPatternAdjustment = (int) ($topPattern['score_adjustment'] ?? 0);
        $spikeDump = (new SpikeDump())->analyze($candles);
        $spikeDumpAdjustment = (int) ($spikeDump['score_adjustment'] ?? 0);
        $scoreBeforeCap = $afterLessonScore + $levelBonus + $topPatternAdjustment + $spikeDumpAdjustment;
        $score = max(0, min(100, $scoreBeforeCap));
        $themeSmell = (new ThemeSmell())->fromCandles($candles);
        $scoreBreakdown = [
            'base_score' => $baseScore,
            'base_max' => 100,
            'lesson_bonus' => $lessonBonus,
            'level_bonus' => $levelBonus,
            'top_pattern_adjustment' => $topPatternAdjustment,
            'spike_dump_adjustment' => $spikeDumpAdjustment,
            'before_cap' => $scoreBeforeCap,
            'cap_adjustment' => $score - $scoreBeforeCap,
            'final_score' => $score,
            'items' => [
                [
                    'key' => 'higher_low',
                    'label' => '저점 상승',
                    'earned' => $higherLowPoints,
                    'max' => 25,
                    'status' => $higherLow ? 'pass' : 'fail',
                    'detail' => sprintf(
                        '구조 저점 %s / 이전 저점 %s. 현재 구조 저점이 이전보다 높으면 통과.%s',
                        $this->scoreNumber($swingLow),
                        $this->scoreNumber($priorSwingLow),
                        $structureIntact ? '' : ' 현재가가 구조 저점 아래라 저점 상승은 깨진 상태.',
                    ),
                ],
                [
                    'key' => 'high_hold',
                    'label' => '고점 유지·돌파',
                    'earned' => $highHoldPoints,
                    'max' => 10,
                    'status' => !$lowerHigh ? 'pass' : 'fail',
                    'detail' => sprintf(
                        '구조 고점 %s / 이전 20봉 고점 %s. 고점을 낮추지 않아야 통과.',
                        $this->scoreNumber($swingHigh),
                        $this->scoreNumber($priorWindowHigh),
                    ),
                ],
                [
                    'key' => 'flush',
                    'label' => '최근 하방 슈팅',
                    'earned' => $flushPoints,
                    'max' => 15,
                    'status' => $flushBar ? 'pass' : 'fail',
                    'detail' => $flushBar
                        ? '최근 5봉 안에 큰 음봉 또는 긴 아래꼬리 하방 슈팅이 있음.'
                        : '최근 5봉 안에 조건을 만족하는 하방 슈팅이 없음.',
                ],
                [
                    'key' => 'half_retrace',
                    'label' => '중간 가격 근접',
                    'earned' => $halfPoints,
                    'max' => 25,
                    'status' => $halfPoints === 25 ? 'pass' : ($halfPoints === 12 ? 'partial' : 'fail'),
                    'detail' => sprintf(
                        '현재가와 중간값 %s의 거리 %s. ±3%% 이내 25점, ±3~6%% 12점, 그 밖 0점.',
                        $this->scoreNumber($halfRetrace),
                        $distHalfPct !== null ? sprintf('%+.2f%%', $distHalfPct) : '계산 불가',
                    ),
                ],
                [
                    'key' => 'trend',
                    'label' => '단기 추세',
                    'earned' => $trendPoints,
                    'max' => 15,
                    'status' => $trendPass ? 'pass' : 'fail',
                    'detail' => sprintf(
                        '현재가 %s ≥ 20일선 %s, 5일선 %s > 10일선 %s를 모두 만족해야 통과.',
                        $this->scoreNumber($price),
                        $this->scoreNumber($ma20),
                        $this->scoreNumber($ma5),
                        $this->scoreNumber($ma10),
                    ),
                ],
                [
                    'key' => 'volume',
                    'label' => '거래량 증가',
                    'earned' => $volumePoints,
                    'max' => 10,
                    'status' => $volumePass ? 'pass' : 'fail',
                    'detail' => sprintf(
                        '오늘 거래량이 20일 평균의 %s배. 1.50배 이상이면 통과.',
                        $volSpike !== null ? number_format($volSpike, 2) : '계산 불가',
                    ),
                ],
                [
                    'key' => 'lesson1',
                    'label' => '불법과외1 가감점',
                    'earned' => $lessonBonus,
                    'min' => -15,
                    'max' => 25,
                    'status' => $lessonBonus > 0 ? 'bonus' : ($lessonBonus < 0 ? 'penalty' : 'neutral'),
                    'detail' => (string) $lesson1['note']
                        . ' (선호캔들·돌파 후 윗박스·과도 랠리 조합, -15~+25점)',
                ],
                [
                    'key' => 'horizontal_support',
                    'label' => '가로 지지',
                    'earned' => $levelBonus,
                    'max' => 8,
                    'status' => $levelBonus > 0 ? 'bonus' : 'neutral',
                    'detail' => $levelSupport !== null
                        ? sprintf(
                            '지지 %s, %d회 접촉%s. 일반 지지 +4점, 저항→지지 전환 +8점.',
                            $this->scoreNumber((float) $levelSupport['price']),
                            (int) $levelSupport['touches'],
                            !empty($levelSupport['flip']) ? '·저항→지지 전환' : '',
                        )
                        : '3회 이상 반복 접촉한 현재가 아래 가로 지지가 없음.',
                ],
                [
                    'key' => 'top_pattern',
                    'label' => '고점 붕괴·역저점',
                    'earned' => $topPatternAdjustment,
                    'min' => -22,
                    'max' => 12,
                    'status' => $topPatternAdjustment < 0
                        ? 'penalty'
                        : ($topPatternAdjustment > 0 ? 'bonus' : 'neutral'),
                    'detail' => (string) ($topPattern['note'] ?? '')
                        . ' (고점 관찰/경고/확정 -6/-12/-22점, 확인된 역저점 +5/+12점)',
                ],
                [
                    'key' => 'spike_dump',
                    'label' => '급등 후 급락',
                    'earned' => $spikeDumpAdjustment,
                    'min' => -16,
                    'max' => 0,
                    'status' => $spikeDumpAdjustment < 0 ? 'penalty' : 'neutral',
                    'detail' => ((string) ($spikeDump['note'] ?? '') !== ''
                        ? (string) $spikeDump['note']
                        : '최근 1~3일 급등 뒤 고가를 반납한 그림 없음.')
                        . ' (경고 -10점, 확정 -16점)',
                ],
            ],
        ];

        $risingLows = $this->countRisingLows($candles);
        // “오늘 저가 이탈 안 하면” — 타이트 손절은 마지막 봉의 저가 그대로
        $stopTight = (float) $last['low'];

        // 손절선: 구조 스윙 저점이 너무 멀면 가로 지지대를 우선한다
        $invalidation = (float) $swingLow;
        $invalidationRule = 'structure_swing_low';
        if (
            $levelSupport !== null
            && $levelSupport['price'] > $swingLow
            && $levelSupport['price'] < $price * 0.999
            && $levelSupport['price'] > $price * 0.75
            && $levelSupport['touches'] >= 3
        ) {
            $invalidation = (float) $levelSupport['price'];
            $invalidationRule = 'horizontal_level_' . $levelSupport['touches'] . 'touch';
        }

        $targets = $this->scaleOutTargets($swingHigh, $swingLow, $levelInfo['levels'] ?? []);
        $atr = $this->atr($candles, 14);
        $eta = $this->etaBundle($price, $atr, round($stopTight, 4), round($invalidation, 4), $targets['tight'], $targets['wide']);

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
            'score_breakdown' => $scoreBreakdown,
            'invalidation_level' => round($invalidation, 4),
            'invalidation_rule' => $invalidationRule,
            'invalidation_structural' => round($swingLow, 4),
            'stop_tight' => round($stopTight, 4),
            'stop_wide' => round($invalidation, 4),
            'target_tight' => $targets['tight'],
            'target_wide' => $targets['wide'],
            'target_wide_rule' => $targets['wide_rule'],
            'atr14' => $eta['atr'],
            'eta' => $eta,
            'level_support' => $levelSupport !== null ? $levelSupport['price'] : null,
            'level_support_touches' => $levelSupport !== null ? $levelSupport['touches'] : null,
            'level_support_flip' => $levelSupport !== null ? $levelSupport['flip'] : false,
            'level_support_last_kst' => $levelSupport !== null ? $levelSupport['last_kst'] : null,
            'level_resistance' => $levelResistance !== null ? $levelResistance['price'] : null,
            'level_resistance_touches' => $levelResistance !== null ? $levelResistance['touches'] : null,
            'levels' => $levelInfo['levels'] ?? [],
            'levels_note' => $levelInfo['note'] ?? '',
            'rising_lows_count' => $risingLows,
            'dist_swing_high_pct' => $swingHigh > 0 ? round((($price - $swingHigh) / $swingHigh) * 100, 3) : null,
            'lesson1_candle_recipe' => $lesson1['candle_recipe'],
            'lesson1_candle_context' => $lesson1['candle_context'],
            'lesson1_upper_box' => $lesson1['upper_box_after_breakout'],
            'lesson1_breakout_no_box' => $lesson1['breakout_without_box'],
            'lesson1_extended_rally_40' => $lesson1['extended_rally_40'],
            'lesson1_score_bonus' => $lesson1['score_bonus'],
            'lesson1_note' => $lesson1['note'],
            'top_pattern_status' => $topPattern['top_status'] ?? 'none',
            'top_pattern_phase' => $topPattern['top_phase'] ?? 'none',
            'inverse_bottom_status' => $topPattern['inverse_bottom_status'] ?? 'none',
            'inverse_bottom_phase' => $topPattern['inverse_bottom_phase'] ?? 'none',
            'top_pattern_adjustment' => $topPatternAdjustment,
            'top_pattern_note' => $topPattern['note'] ?? null,
            'top_pattern' => $topPattern['top'] ?? null,
            'inverse_bottom_pattern' => $topPattern['inverse_bottom'] ?? null,
            'theme_smell_status' => $themeSmell['status'],
            'theme_smell_label' => $themeSmell['label'],
            'theme_smell_note' => $themeSmell['note'],
            'theme_smell' => $themeSmell,
            'spike_dump_status' => $spikeDump['status'],
            'spike_dump_label' => $spikeDump['label'],
            'spike_dump_note' => $spikeDump['note'],
            'spike_dump_adjustment' => $spikeDumpAdjustment,
            'spike_dump' => $spikeDump,
        ];
    }

    /**
     * 익절 2단: 1차=직전 스윙 고점, 2차=같은 스윙 폭을 고점 위로 한 번 더.
     * 고점과 측정이동 사이에 가로 저항이 있으면 2차는 그 저항(먼저 맞는 벽).
     *
     * @param list<array{price?:float,role?:string,touches?:int}> $levels
     * @return array{tight:?float,wide:?float,wide_rule:string}
     */
    private function scaleOutTargets(float $swingHigh, float $swingLow, array $levels): array
    {
        $tight = round($swingHigh, 4);
        $range = $swingHigh - $swingLow;
        if ($range <= 0 || $swingHigh <= 0) {
            return ['tight' => $tight, 'wide' => null, 'wide_rule' => 'none'];
        }

        $measured = $swingHigh + $range;
        if ($measured <= $swingHigh * 1.005) {
            return ['tight' => $tight, 'wide' => null, 'wide_rule' => 'none'];
        }

        $wide = round($measured, 4);
        $wideRule = 'measured_move_1x';
        $floor = $swingHigh * 1.005;
        $ceil = $measured * 0.995;
        $nearest = null;
        $nearestTouches = 0;
        foreach ($levels as $lv) {
            if (!is_array($lv) || !is_numeric($lv['price'] ?? null)) {
                continue;
            }
            $px = (float) $lv['price'];
            $role = (string) ($lv['role'] ?? '');
            if ($role !== 'resistance' && $role !== 'at_price') {
                continue;
            }
            if ($px <= $floor || $px >= $ceil) {
                continue;
            }
            if ($nearest === null || $px < $nearest) {
                $nearest = $px;
                $nearestTouches = (int) ($lv['touches'] ?? 0);
            }
        }
        if ($nearest !== null) {
            $wide = round($nearest, 4);
            $wideRule = 'horizontal_resistance'
                . ($nearestTouches > 0 ? '_' . $nearestTouches . 'touch' : '');
        }

        return [
            'tight' => $tight,
            'wide' => $wide,
            'wide_rule' => $wideRule,
        ];
    }

    /**
     * 최근 피벗 저점이 계단식으로 올라온 횟수 (저점 높이며 고점 재도전 판별용).
     *
     * @param list<array{high:float,low:float}> $candles
     */
    private function countRisingLows(array $candles, int $lookback = 60, int $segments = 4): int
    {
        $window = array_slice($candles, -$lookback);
        $n = count($window);
        $segLen = intdiv($n, $segments);
        if ($segLen < 4) {
            return 0;
        }

        // 피벗 하나하나는 얕은 눌림에 흔들리므로, 구간별 최저가로 저점 추세를 본다
        $mins = [];
        for ($s = 0; $s < $segments; $s++) {
            $slice = array_slice($window, $n - ($segments - $s) * $segLen, $segLen);
            $lows = array_map(static fn(array $b): float => (float) $b['low'], $slice);
            $lows = array_filter($lows, static fn(float $v): bool => $v > 0);
            if ($lows === []) {
                return 0;
            }
            $mins[] = min($lows);
        }

        $rising = 0;
        for ($i = count($mins) - 1; $i >= 1; $i--) {
            if ($mins[$i] <= $mins[$i - 1] * 1.01) {
                break;
            }
            $rising++;
        }

        return $rising;
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

    private function scoreNumber(float $value): string
    {
        if (abs($value) >= 100) {
            return number_format($value, 0, '.', ',');
        }

        return number_format($value, 2, '.', ',');
    }

    /**
     * 최근 14봉 ATR. 목표가까지 “며칠 거리인지”만 가늠하는 속도 단위.
     *
     * @param list<array{high:float,low:float,close:float}> $candles
     */
    private function atr(array $candles, int $period = 14): ?float
    {
        $n = count($candles);
        if ($n < $period + 1) {
            return null;
        }
        $trs = [];
        for ($i = $n - $period; $i < $n; $i++) {
            $h = (float) $candles[$i]['high'];
            $l = (float) $candles[$i]['low'];
            $pc = (float) $candles[$i - 1]['close'];
            $trs[] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }

        return array_sum($trs) / count($trs);
    }

    /**
     * @return array{
     *   atr:?float,
     *   stop_tight:?array<string,mixed>,
     *   stop_wide:?array<string,mixed>,
     *   target_tight:?array<string,mixed>,
     *   target_wide:?array<string,mixed>
     * }
     */
    private function etaBundle(
        float $price,
        ?float $atr,
        ?float $stopTight,
        ?float $stopWide,
        ?float $targetTight,
        ?float $targetWide,
    ): array {
        return [
            'atr' => $atr !== null ? round($atr, 4) : null,
            'stop_tight' => $this->etaToLevel($price, $stopTight, $atr, 'down'),
            'stop_wide' => $this->etaToLevel($price, $stopWide, $atr, 'down'),
            'target_tight' => $this->etaToLevel($price, $targetTight, $atr, 'up'),
            'target_wide' => $this->etaToLevel($price, $targetWide, $atr, 'up'),
        ];
    }

    /**
     * @return array{status:string,days_lo:?int,days_hi:?int,label:string}|null
     */
    private function etaToLevel(float $price, ?float $level, ?float $atr, string $dir): ?array
    {
        if (!is_numeric($level) || $atr === null || $atr <= 0 || $price <= 0) {
            return null;
        }
        $level = (float) $level;
        $dist = $dir === 'up' ? $level - $price : $price - $level;
        if ($dist <= 0) {
            return [
                'status' => 'passed',
                'days_lo' => 0,
                'days_hi' => 0,
                'label' => $dir === 'up' ? '이미 위' : '이미 아래',
            ];
        }
        $raw = $dist / $atr;
        if ($raw < 0.4) {
            return [
                'status' => 'near',
                'days_lo' => 1,
                'days_hi' => 1,
                'label' => '오늘~1일',
            ];
        }
        $lo = max(1, (int) round($raw * 0.7));
        $hi = max($lo + ($raw >= 1.5 ? 1 : 0), (int) round($raw * 1.6));
        $hi = min(40, $hi);
        $lo = min($lo, $hi);
        $label = $lo === $hi
            ? '약 ' . $lo . '거래일'
            : '약 ' . $lo . '~' . $hi . '거래일';
        if ($lo >= 15) {
            $label = '3주 이상';
        } elseif ($lo >= 10) {
            $label = '약 2~3주';
        }

        return [
            'status' => 'ahead',
            'days_lo' => $lo,
            'days_hi' => $hi,
            'label' => $label,
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
