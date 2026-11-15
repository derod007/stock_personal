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

    /** ok, incomplete, future, or invalid. The same checks completed() uses. */
    public static function acceptance(array $bar, string $symbol, int $asOf): string
    {
        if (!empty($bar['synthetic']) || ($bar['is_complete'] ?? true) === false) {
            return 'incomplete';
        }
        if (self::closeTime($bar, $symbol) > $asOf) {
            return 'future';
        }
        foreach (['open', 'high', 'low', 'close'] as $key) {
            if (!isset($bar[$key]) || !is_numeric($bar[$key]) || !is_finite((float) $bar[$key]) || $bar[$key] <= 0) {
                return 'invalid';
            }
        }
        if ($bar['low'] > min($bar['open'], $bar['close']) || $bar['high'] < max($bar['open'], $bar['close'])
            || $bar['high'] < $bar['low']) {
            return 'invalid';
        }

        return 'ok';
    }

    public static function completed(array $bars, string $symbol, int $asOf): array
    {
        $out = [];
        foreach ($bars as $bar) {
            if (self::acceptance($bar, $symbol, $asOf) !== 'ok') {
                continue;
            }
            $bar['available_at'] = self::closeTime($bar, $symbol);
            $out[$bar['available_at']] = $bar;
        }
        ksort($out, SORT_NUMERIC);
        return array_values($out);
    }
}
