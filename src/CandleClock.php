<?php

declare(strict_types=1);

namespace ChartEntryLab;

/** Daily signals use completed exchange sessions, never a bar's opening timestamp. */
final class CandleClock
{
    public static function closeTime(array $bar, string $symbol): int
    {
        if (isset($bar['available_at'])) {
            return is_numeric($bar['available_at']) ? (int) $bar['available_at']
                : (new \DateTimeImmutable((string) $bar['available_at']))->getTimestamp();
        }
        $kr = str_ends_with($symbol, '.KS') || str_ends_with($symbol, '.KQ');
        $tz = new \DateTimeZone($kr ? 'Asia/Seoul' : 'America/New_York');
        $stamp = isset($bar['time']) ? (int) $bar['time']
            : (new \DateTimeImmutable($bar['time_kst'], new \DateTimeZone('Asia/Seoul')))->getTimestamp();
        $day = (new \DateTimeImmutable('@' . $stamp))->setTimezone($tz)->format('Y-m-d');
        return (new \DateTimeImmutable($day . ($kr ? ' 15:30:00' : ' 16:00:00'), $tz))->getTimestamp();
    }

    public static function completed(array $bars, string $symbol, int $asOf): array
    {
        $out = [];
        foreach ($bars as $bar) {
            if (!empty($bar['synthetic']) || ($bar['is_complete'] ?? true) === false
                || self::closeTime($bar, $symbol) > $asOf) {
                continue;
            }
            foreach (['open', 'high', 'low', 'close'] as $key) {
                if (!isset($bar[$key]) || !is_numeric($bar[$key]) || !is_finite((float) $bar[$key]) || $bar[$key] <= 0) {
                    continue 2;
                }
            }
            if ($bar['low'] > min($bar['open'], $bar['close']) || $bar['high'] < max($bar['open'], $bar['close'])
                || $bar['high'] < $bar['low']) {
                continue;
            }
            $bar['available_at'] = self::closeTime($bar, $symbol);
            $out[$bar['available_at']] = $bar;
        }
        ksort($out, SORT_NUMERIC);
        return array_values($out);
    }
}
