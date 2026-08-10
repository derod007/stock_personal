<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 노라무 «차트 불법과외 -1편» (fm-10160571194) 규칙.
 *
 * 1) 선호 캔들: 장대양봉 → 도지 연속 → 거래량 없는 장대음봉 → 즉시장대양봉
 *    · 앞 매물대 돌파 후(상승 후)에서만 가산 / 하락 중이면 감점
 * 2) 수렴 돌파 추격 금지 → 돌파 후 윗구간 박스가 있어야 롱 가산
 * 3) 저점 대비 +40% 과도 랠리 후 타이트 구간은 수렴 불신
 */
final class NoramuLesson1
{
    /**
     * @param list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}> $candles
     * @return array{
     *   candle_recipe:bool,
     *   candle_context:?string,
     *   upper_box_after_breakout:bool,
     *   breakout_without_box:bool,
     *   extended_rally_40:bool,
     *   score_bonus:int,
     *   note:string
     * }
     */
    public function analyze(array $candles): array
    {
        $n = count($candles);
        if ($n < 40) {
            return $this->empty('봉 부족');
        }

        $recipe = $this->findCandleRecipe($candles);
        $box = $this->detectBreakoutUpperBox($candles);
        $extended = $this->isExtendedRally($candles);

        $bonus = 0;
        $bits = [];

        if ($recipe['matched']) {
            $ctx = $recipe['context'];
            if ($ctx === 'after_supply_break') {
                $bonus += 12;
                $bits[] = '선호캔들(매물대 돌파 후)';
            } elseif ($ctx === 'in_decline') {
                $bonus -= 10;
                $bits[] = '선호캔들이지만 하락 중(승률 낮음)';
            } else {
                $bonus += 4;
                $bits[] = '선호캔들(중립 위치)';
            }
        }

        if ($box['upper_box']) {
            $bonus += 12;
            $bits[] = '돌파 후 윗구간 박스';
        } elseif ($box['breakout_no_box']) {
            $bonus -= 6;
            $bits[] = '수렴·돌파만 있고 윗박스 없음(추격 금지)';
        }

        if ($extended && ($box['tight_recent'] || $recipe['matched'])) {
            $bonus -= 5;
            $bits[] = '저점대비 +40% 후 타이트 구간(수렴 불신)';
        }

        $bonus = max(-15, min(25, $bonus));
        $note = $bits === []
            ? '불법과외1: 해당 패턴 없음'
            : '불법과외1: ' . implode(' · ', $bits);

        return [
            'candle_recipe' => $recipe['matched'],
            'candle_context' => $recipe['context'],
            'upper_box_after_breakout' => $box['upper_box'],
            'breakout_without_box' => $box['breakout_no_box'],
            'extended_rally_40' => $extended,
            'score_bonus' => $bonus,
            'note' => $note,
        ];
    }

    /**
     * @return array{
     *   candle_recipe:bool,
     *   candle_context:?string,
     *   upper_box_after_breakout:bool,
     *   breakout_without_box:bool,
     *   extended_rally_40:bool,
     *   score_bonus:int,
     *   note:string
     * }
     */
    private function empty(string $note): array
    {
        return [
            'candle_recipe' => false,
            'candle_context' => null,
            'upper_box_after_breakout' => false,
            'breakout_without_box' => false,
            'extended_rally_40' => false,
            'score_bonus' => 0,
            'note' => '불법과외1: ' . $note,
        ];
    }

    /**
     * @param list<array{open:float,high:float,low:float,close:float,volume:int}> $candles
     * @return array{matched:bool,context:?string,end_idx:?int}
     */
    private function findCandleRecipe(array $candles): array
    {
        $n = count($candles);
        $volumes = array_column($candles, 'volume');
        $avgVol = array_sum(array_slice($volumes, -20)) / max(1, min(20, $n));

        // 최근 18봉 끝에서 레시피 탐색 (끝봉 = 회복 장대양봉)
        for ($end = $n - 1; $end >= max(8, $n - 18); $end--) {
            if (!$this->isTallBull($candles[$end])) {
                continue;
            }
            $bearIdx = $end - 1;
            if ($bearIdx < 2 || !$this->isTallBear($candles[$bearIdx])) {
                continue;
            }
            // 거래량 없는 장대음봉
            $bearVol = (int) ($candles[$bearIdx]['volume'] ?? 0);
            if ($avgVol > 0 && $bearVol > $avgVol * 0.90) {
                continue;
            }

            $dojiCount = 0;
            $j = $bearIdx - 1;
            while ($j >= 1 && $dojiCount < 4 && $this->isDoji($candles[$j])) {
                $dojiCount++;
                $j--;
            }
            if ($dojiCount < 1) {
                continue;
            }
            if (!$this->isTallBull($candles[$j])) {
                continue;
            }

            $context = $this->recipeContext($candles, $j, $end);
            return [
                'matched' => true,
                'context' => $context,
                'end_idx' => $end,
            ];
        }

        return ['matched' => false, 'context' => null, 'end_idx' => null];
    }

    /**
     * @param list<array{open:float,high:float,low:float,close:float}> $candles
     */
    private function recipeContext(array $candles, int $startIdx, int $endIdx): string
    {
        $n = count($candles);
        $priorFrom = max(0, $startIdx - 25);
        $priorHighs = [];
        for ($i = $priorFrom; $i < $startIdx; $i++) {
            $priorHighs[] = (float) $candles[$i]['high'];
        }
        $priorHigh = $priorHighs !== [] ? max($priorHighs) : (float) $candles[$startIdx]['high'];
        $recipeClose = (float) $candles[$endIdx]['close'];

        // 앞 매물대(직전 고점) 돌파·근접 후
        if ($recipeClose >= $priorHigh * 0.98) {
            return 'after_supply_break';
        }

        // 하락 추세 중: 시작 전 12봉 종가가 뚜렷이 내려옴
        $ref = max(0, $startIdx - 12);
        $refClose = (float) $candles[$ref]['close'];
        $preClose = (float) $candles[$startIdx]['close'];
        if ($refClose > 0 && $preClose <= $refClose * 0.94) {
            return 'in_decline';
        }

        // 최근 창 하단부면 하락 중으로 간주
        $winFrom = max(0, $n - 30);
        $winLows = [];
        $winHighs = [];
        for ($i = $winFrom; $i < $n; $i++) {
            $winLows[] = (float) $candles[$i]['low'];
            $winHighs[] = (float) $candles[$i]['high'];
        }
        $wLow = min($winLows);
        $wHigh = max($winHighs);
        $span = max($wHigh - $wLow, 0.0001);
        if (($recipeClose - $wLow) / $span <= 0.35) {
            return 'in_decline';
        }

        return 'neutral';
    }

    /**
     * @param list<array{open:float,high:float,low:float,close:float,volume:int}> $candles
     * @return array{upper_box:bool,breakout_no_box:bool,tight_recent:bool}
     */
    private function detectBreakoutUpperBox(array $candles): array
    {
        $n = count($candles);
        $tightRecent = $this->isTightRange($candles, $n - 8, $n - 1, 0.08);

        // 최근 35봉에서 수렴(타이트) 구간 찾기
        for ($end = $n - 6; $end >= max(15, $n - 35); $end--) {
            for ($len = 8; $len <= 16; $len++) {
                $start = $end - $len + 1;
                if ($start < 5) {
                    continue;
                }
                if (!$this->isTightRange($candles, $start, $end, 0.085)) {
                    continue;
                }

                $consolHigh = 0.0;
                $consolLow = INF;
                for ($i = $start; $i <= $end; $i++) {
                    $consolHigh = max($consolHigh, (float) $candles[$i]['high']);
                    $consolLow = min($consolLow, (float) $candles[$i]['low']);
                }

                // 돌파봉
                $breakIdx = null;
                for ($i = $end + 1; $i < min($n, $end + 4); $i++) {
                    if ((float) $candles[$i]['close'] > $consolHigh * 1.005) {
                        $breakIdx = $i;
                        break;
                    }
                }
                if ($breakIdx === null) {
                    continue;
                }

                // 돌파 후 윗구간 박스 (3~10봉)
                $postEnd = min($n - 1, $breakIdx + 10);
                if ($postEnd - $breakIdx < 2) {
                    // 돌파만 있고 박스 미형성
                    return [
                        'upper_box' => false,
                        'breakout_no_box' => true,
                        'tight_recent' => $tightRecent,
                    ];
                }

                $postHigh = 0.0;
                $postLow = INF;
                $held = true;
                $consolMid = ($consolHigh + $consolLow) / 2;
                for ($i = $breakIdx; $i <= $postEnd; $i++) {
                    $postHigh = max($postHigh, (float) $candles[$i]['high']);
                    $postLow = min($postLow, (float) $candles[$i]['low']);
                    if ((float) $candles[$i]['close'] < $consolMid) {
                        $held = false;
                    }
                }
                $postMid = ($postHigh + $postLow) / 2;
                $postRangePct = $postMid > 0 ? ($postHigh - $postLow) / $postMid : 1;
                $nearHigh = $postLow >= $consolHigh * 0.97;

                if ($held && $nearHigh && $postRangePct <= 0.12) {
                    return [
                        'upper_box' => true,
                        'breakout_no_box' => false,
                        'tight_recent' => $tightRecent,
                    ];
                }

                return [
                    'upper_box' => false,
                    'breakout_no_box' => true,
                    'tight_recent' => $tightRecent,
                ];
            }
        }

        return [
            'upper_box' => false,
            'breakout_no_box' => false,
            'tight_recent' => $tightRecent,
        ];
    }

    /**
     * @param list<array{high:float,low:float,close:float}> $candles
     */
    private function isTightRange(array $candles, int $from, int $to, float $maxPct): bool
    {
        if ($to <= $from) {
            return false;
        }
        $hi = 0.0;
        $lo = INF;
        for ($i = $from; $i <= $to; $i++) {
            $hi = max($hi, (float) $candles[$i]['high']);
            $lo = min($lo, (float) $candles[$i]['low']);
        }
        $mid = ($hi + $lo) / 2;
        if ($mid <= 0) {
            return false;
        }
        return (($hi - $lo) / $mid) <= $maxPct;
    }

    /**
     * @param list<array{low:float,close:float}> $candles
     */
    private function isExtendedRally(array $candles): bool
    {
        $n = count($candles);
        $from = max(0, $n - 40);
        $low = INF;
        for ($i = $from; $i < $n; $i++) {
            $low = min($low, (float) $candles[$i]['low']);
        }
        $price = (float) $candles[$n - 1]['close'];
        if ($low <= 0) {
            return false;
        }
        return (($price - $low) / $low) >= 0.40;
    }

    /**
     * @param array{open:float,high:float,low:float,close:float} $bar
     */
    private function isTallBull(array $bar): bool
    {
        $range = max((float) $bar['high'] - (float) $bar['low'], 0.0001);
        $body = (float) $bar['close'] - (float) $bar['open'];
        if ($body <= 0) {
            return false;
        }
        $rangePct = $range / max(abs((float) $bar['close']), 0.0001);
        return ($body / $range) >= 0.55 && $rangePct >= 0.022;
    }

    /**
     * @param array{open:float,high:float,low:float,close:float} $bar
     */
    private function isTallBear(array $bar): bool
    {
        $range = max((float) $bar['high'] - (float) $bar['low'], 0.0001);
        $body = (float) $bar['open'] - (float) $bar['close'];
        if ($body <= 0) {
            return false;
        }
        $rangePct = $range / max(abs((float) $bar['close']), 0.0001);
        return ($body / $range) >= 0.55 && $rangePct >= 0.022;
    }

    /**
     * @param array{open:float,high:float,low:float,close:float} $bar
     */
    private function isDoji(array $bar): bool
    {
        $range = max((float) $bar['high'] - (float) $bar['low'], 0.0001);
        $body = abs((float) $bar['close'] - (float) $bar['open']);
        return ($body / $range) <= 0.32;
    }
}
