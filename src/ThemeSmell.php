<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 노라무식 «테마 냄새».
 * 테마가 아직 안 올라도 대금이 한 번 찔러 보면 다음날 구경 후보.
 * 추격 매수 신호가 아니다.
 */
final class ThemeSmell
{
    public const LESSON_ID = 'noramu_theme_smell_v1';

    private const MIN_RANGE_PCT = 6.0;
    private const MIN_VOL = 2.0;
    private const IGNITE_VOL = 3.0;
    private const FAIL_CLOSE_LOC = 0.25;
    private const IGNITE_CLOSE_LOC = 0.4;

    /**
     * @param list<array{open:float,high:float,low:float,close:float,volume:int}> $candles
     * @return array{
     *   status:string,
     *   label:string,
     *   note:string,
     *   vol_vs_prev:?float,
     *   vol_vs_avg:?float,
     *   range_pct:?float,
     *   close_loc:?float,
     *   change_pct:?float
     * }
     */
    public function fromCandles(array $candles): array
    {
        $empty = $this->empty();
        $n = count($candles);
        if ($n < 2) {
            return $empty;
        }

        $last = $candles[$n - 1];
        $prev = $candles[$n - 2];
        $high = (float) $last['high'];
        $low = (float) $last['low'];
        $close = (float) $last['close'];
        $vol = (int) $last['volume'];
        $prevVol = (int) $prev['volume'];
        $prevClose = (float) $prev['close'];

        if ($low <= 0 || $high < $low || $vol <= 0) {
            return $empty;
        }

        $rangePct = (($high - $low) / $low) * 100;
        $span = $high - $low;
        $closeLoc = $span > 0 ? ($close - $low) / $span : 0.5;
        $volPrev = $prevVol > 0 ? $vol / $prevVol : null;
        $avg = $this->avgVolume($candles, 20);
        $volAvg = $avg > 0 ? $vol / $avg : null;
        $volFire = max($volPrev ?? 0.0, $volAvg ?? 0.0);
        $changePct = $prevClose > 0 ? (($close - $prevClose) / $prevClose) * 100 : null;

        $status = 'none';
        if ($rangePct >= self::MIN_RANGE_PCT && $volFire >= self::MIN_VOL) {
            if ($closeLoc <= self::FAIL_CLOSE_LOC) {
                $status = 'poke_fail';
            } elseif (
                $volFire >= self::IGNITE_VOL
                && $closeLoc >= self::IGNITE_CLOSE_LOC
                && ($changePct === null || $changePct >= 0)
            ) {
                $status = 'ignite';
            } else {
                $status = 'poke';
            }
        }

        return [
            'status' => $status,
            'label' => $this->label($status),
            'note' => $this->note($status, $volFire, $rangePct, $closeLoc),
            'vol_vs_prev' => $volPrev !== null ? round($volPrev, 2) : null,
            'vol_vs_avg' => $volAvg !== null ? round($volAvg, 2) : null,
            'range_pct' => round($rangePct, 2),
            'close_loc' => round($closeLoc, 3),
            'change_pct' => $changePct !== null ? round($changePct, 2) : null,
        ];
    }

    /**
     * @return array{
     *   status:string,
     *   label:string,
     *   note:string,
     *   vol_vs_prev:?float,
     *   vol_vs_avg:?float,
     *   range_pct:?float,
     *   close_loc:?float,
     *   change_pct:?float
     * }
     */
    public function empty(): array
    {
        return [
            'status' => 'none',
            'label' => '',
            'note' => '',
            'vol_vs_prev' => null,
            'vol_vs_avg' => null,
            'range_pct' => null,
            'close_loc' => null,
            'change_pct' => null,
        ];
    }

    public function label(string $status): string
    {
        return match ($status) {
            'ignite' => '냄새·점화',
            'poke_fail' => '냄새·못지킴',
            'poke' => '테마냄새',
            default => '',
        };
    }

    /**
     * @param list<array{volume:int}> $candles
     */
    private function avgVolume(array $candles, int $window): float
    {
        $n = count($candles);
        $start = max(0, $n - 1 - $window);
        $end = $n - 1;
        $sum = 0.0;
        $count = 0;
        for ($i = $start; $i < $end; $i++) {
            $sum += (int) $candles[$i]['volume'];
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    private function note(string $status, float $volFire, float $rangePct, float $closeLoc): string
    {
        if ($status === 'none') {
            return '';
        }

        $bits = sprintf(
            '거래량 %.1f배 · 변동 %.1f%% · 종가위치 %.0f%%',
            $volFire,
            $rangePct,
            $closeLoc * 100
        );

        return match ($status) {
            'ignite' => '테마 점화 냄새. 추격 말고 구경. ' . $bits,
            'poke_fail' => '돈이 찔러 보고 종가를 못 지킴. 내려오면 관심. ' . $bits,
            default => '테마 냄새. 구경만. ' . $bits,
        };
    }
}
