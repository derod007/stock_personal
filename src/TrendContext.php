<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Uses only completed daily bars and excludes the unfinished exchange week. */
final class TrendContext
{
    public function analyze(array $bars, string $symbol, int $asOf): array
    {
        $tz = new \DateTimeZone(str_ends_with($symbol, '.KS') || str_ends_with($symbol, '.KQ') ? 'Asia/Seoul' : 'America/New_York');
        $now = (new \DateTimeImmutable('@' . $asOf))->setTimezone($tz);
        $weeks = [];
        foreach ($bars as $b) {
            $d = (new \DateTimeImmutable('@' . $b['available_at']))->setTimezone($tz);
            $key = $d->format('o-W');
            $weeks[$key][] = $b;
        }
        // Do not use Monday-Thursday of the current week. Friday data must already be completed.
        if ((int) $now->format('N') <= 5) {
            $key = $now->format('o-W');
            $last = isset($weeks[$key]) ? end($weeks[$key]) : null;
            if ($last !== null) {
                $d = (new \DateTimeImmutable('@' . $last['available_at']))->setTimezone($tz);
                if ((int) $d->format('N') < 5) { unset($weeks[$key]); }
            }
        }
        $weekly = [];
        foreach ($weeks as $key => $rows) {
            $last = end($rows);
            $weekly[] = ['close' => $last['close'], 'available_at' => $last['available_at'], 'week' => $key];
        }
        $dailyState = $this->state(array_column($bars, 'close'), 20, 5);
        $weeklyState = $this->state(array_column($weekly, 'close'), 20, 3);
        $labels = ['up' => '상승', 'down' => '하락', 'range' => '횡보', 'unknown' => '자료 부족'];
        return ['daily' => $dailyState, 'weekly' => $weeklyState, 'weekly_bars' => count($weekly),
            'weekly_asof' => $weekly !== [] ? end($weekly)['available_at'] : null,
            'label' => '주봉 ' . $labels[$weeklyState] . ' / 일봉 ' . $labels[$dailyState],
            'warning' => $weeklyState === 'down' ? '큰 추세 하락: 가격 후보는 참고하며 진입은 추가 확인 필요'
                : ($weeklyState === 'unknown' ? '완료 주봉 부족: 상위 추세 미확인' : '')];
    }

    private function state(array $closes, int $period, int $slope): string
    {
        if (count($closes) < $period + $slope) { return 'unknown'; }
        $ma = array_sum(array_slice($closes, -$period)) / $period;
        $old = array_sum(array_slice($closes, -$period - $slope, $period)) / $period;
        $close = (float) end($closes);
        if ($close > $ma && $ma > $old * 1.002) { return 'up'; }
        if ($close < $ma && $ma < $old * 0.998) { return 'down'; }
        return 'range';
    }
}
