<?php

declare(strict_types=1);

namespace ChartEntryLab;

/** Attach a fixed 60-session index average to frozen peer-compare signals. */
final class SwingIndexTrend
{
    public const VERSION = 'swing_index_trend_v1';
    public const WINDOW = 60;
    /** @var list<string> */
    public const GAP_DAYS = ['2025-10-03', '2025-10-06', '2025-10-07'];
    /** @var list<string> */
    public const GROUPS = ['손익비 조건 통과', '손익비 부족', '저항 미확정'];
    /** @var list<string> */
    public const CALENDARS = [
        'docs/type1-krx-input-v1/trading-calendar.json',
        'docs/type1-holdout-v1/trading-calendar.json',
        'docs/krx-prep-bars-before-20241008/trading-calendar.json',
        'docs/historical-top100-20241008-20251002/trading-calendar.json',
        'docs/historical-top100-20251008-20261008/trading-calendar.json',
    ];

    /** @param list<array{from:string,to:string,closed:array<string,int>}> $ranges */
    public static function dayKind(string $day, array $ranges): string
    {
        $flags = self::flags($day, $ranges);
        if ($flags === []) {
            return 'unknown';
        }
        if (count(array_unique($flags)) > 1) {
            return 'conflict';
        }
        return $flags[0];
    }

    /** @param array<string,string> $bars @param list<string> $opens @param list<array{from:string,to:string,closed:array<string,int>}> $ranges @return array{status:string,reason:?string} */
    public static function judge(string $day, array $bars, array $opens, array $ranges): array
    {
        $kind = self::dayKind($day, $ranges);
        if ($kind === 'unknown') {
            return self::undetermined('calendar_unknown');
        }
        if ($kind === 'conflict') {
            return self::undetermined('calendar_conflict');
        }
        if ($kind !== 'open') {
            return self::undetermined('calendar_closed');
        }
        $prior = [];
        foreach ($opens as $open) {
            if ($open > $day) {
                break;
            }
            $prior[] = $open;
        }
        if (count($prior) < self::WINDOW || $prior[array_key_last($prior)] !== $day) {
            return self::undetermined('insufficient');
        }
        $window = array_slice($prior, -self::WINDOW);
        foreach (self::GAP_DAYS as $gap) {
            if ($gap > $window[0] && $gap < $day) {
                return self::undetermined('gap_window');
            }
        }
        // openDays omits uncertain dates; do not silently extend a window across them.
        for ($date = new \DateTimeImmutable($window[0]); $date->format('Y-m-d') < $day; $date = $date->modify('+1 day')) {
            $windowKind = self::dayKind($date->format('Y-m-d'), $ranges);
            if ($windowKind === 'unknown' || $windowKind === 'conflict') {
                return self::undetermined('calendar_' . $windowKind);
            }
        }
        $cents = [];
        foreach ($window as $open) {
            if (!array_key_exists($open, $bars)) {
                return self::undetermined('missing_bar');
            }
            $value = self::cents((string) $bars[$open]);
            if ($value === null) {
                return self::undetermined('bad_close');
            }
            $cents[] = $value;
        }
        $last = $cents[self::WINDOW - 1];
        $sum = array_sum($cents);
        return $last * self::WINDOW > $sum
            ? ['status' => 'pass', 'reason' => null]
            : ['status' => 'fail', 'reason' => null];
    }

    /** @param list<array<string,mixed>> $observations @param array<string,string> $kospi @param array<string,string> $kosdaq @param list<string> $opens @param list<array{from:string,to:string,closed:array<string,int>}> $ranges @return list<array<string,mixed>> */
    public static function attach(array $observations, array $kospi, array $kosdaq, array $opens, array $ranges): array
    {
        $out = [];
        foreach ($observations as $row) {
            if (!isset($row['symbol'], $row['confirm_day'], $row['group'], $row['horizons']['20'])) {
                throw new \RuntimeException('Frozen signal is missing a field');
            }
            if (!in_array($row['group'], self::GROUPS, true)) {
                throw new \RuntimeException('Unknown signal group');
            }
            $suffix = substr((string) $row['symbol'], -3);
            $series = $suffix === '.KS' ? $kospi : ($suffix === '.KQ' ? $kosdaq : null);
            $judge = $series === null
                ? self::undetermined('market')
                : self::judge((string) $row['confirm_day'], $series, $opens, $ranges);
            $horizon = $row['horizons']['20'];
            $difference = $horizon['difference'] ?? null;
            if ($difference !== null) {
                $path = 'complete';
            } elseif (($horizon['signal_status'] ?? '') === '미완료') {
                $path = 'incomplete';
            } else {
                $path = 'undetermined';
            }
            $out[] = [
                'symbol' => $row['symbol'],
                'confirm_day' => $row['confirm_day'],
                'group' => $row['group'],
                'index_status' => $judge['status'],
                'index_reason' => $judge['reason'],
                'path_status' => $path,
                'difference' => $difference === null ? null : (float) $difference,
            ];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $signals @return list<array<string,mixed>> */
    public static function summarize(array $signals): array
    {
        $groups = [];
        foreach (self::GROUPS as $label) {
            $groups[$label] = [
                'label' => $label, 'signals' => 0,
                'index' => ['pass' => 0, 'fail' => 0, 'undetermined' => 0, 'reasons' => []],
                'path20' => ['complete' => 0, 'incomplete' => 0, 'undetermined' => 0],
                'base' => self::bucket(), 'pass_only' => self::bucket(),
            ];
        }
        foreach ($signals as $row) {
            if (!isset($groups[$row['group']])) {
                throw new \RuntimeException('Unknown signal group');
            }
            $group = &$groups[$row['group']];
            $group['signals']++;
            if ($row['index_status'] === 'undetermined') {
                $group['index']['undetermined']++;
                $reason = (string) ($row['index_reason'] ?? 'unknown');
                $group['index']['reasons'][$reason] = ($group['index']['reasons'][$reason] ?? 0) + 1;
            } elseif ($row['index_status'] === 'pass' || $row['index_status'] === 'fail') {
                $group['index'][$row['index_status']]++;
            } else {
                throw new \RuntimeException('Unknown index status');
            }
            if (!isset($group['path20'][$row['path_status']])) {
                throw new \RuntimeException('Unknown path status');
            }
            $group['path20'][$row['path_status']]++;
            $usable = ($row['index_status'] === 'pass' || $row['index_status'] === 'fail')
                && $row['path_status'] === 'complete' && $row['difference'] !== null;
            if ($usable) {
                self::push($group['base'], $row);
                if ($row['index_status'] === 'pass') {
                    self::push($group['pass_only'], $row);
                }
            }
            unset($group);
        }
        foreach ($groups as &$group) {
            self::finish($group['base']);
            self::finish($group['pass_only']);
            $group['median_delta'] = $group['base']['median'] === null || $group['pass_only']['median'] === null
                ? null : $group['pass_only']['median'] - $group['base']['median'];
            $group['retention'] = $group['base']['signals'] === 0 ? null : $group['pass_only']['signals'] / $group['base']['signals'];
            ksort($group['index']['reasons']);
        }
        unset($group);
        return array_values($groups);
    }

    /** @param list<array<string,mixed>> $left @param list<array<string,mixed>> $right */
    public static function sameSummary(array $left, array $right): bool
    {
        return self::canonical($left) === self::canonical($right);
    }

    public static function sealDirectory(string $dir): string
    {
        $lines = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $lines[] = basename($path) . ' ' . hash_file('sha256', $path);
        }
        sort($lines, SORT_STRING);
        return hash('sha256', implode("\n", $lines));
    }

    public static function requireMatch(string $actual, string $expected, string $label): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected) || !hash_equals($expected, $actual)) {
            throw new \RuntimeException('Input hash mismatch: ' . $label);
        }
    }

    /** @return array<string,string> */
    public static function loadCloses(string $path): array
    {
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($json['bars'] as $bar) {
            $date = $bar['date'];
            if (isset($out[$date])) {
                throw new \RuntimeException('Duplicate index date: ' . $date);
            }
            $out[$date] = (string) $bar['close'];
        }
        return $out;
    }

    /** @return list<array{from:string,to:string,closed:array<string,int>}> */
    public static function loadRanges(string $root): array
    {
        $ranges = [];
        foreach (self::CALENDARS as $file) {
            $json = json_decode((string) file_get_contents($root . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
            if (isset($json['search_range'])) {
                $span = $json['search_range'];
            } elseif (isset($json['range']['from'])) {
                $span = $json['range'];
            } else {
                $span = ['from' => $json['range'][0], 'to' => $json['range'][1]];
            }
            $closed = [];
            foreach ($json['closed_weekdays'] as $key => $unused) {
                $key = (string) $key;
                $closed[strlen($key) === 8 ? substr($key, 0, 4) . '-' . substr($key, 4, 2) . '-' . substr($key, 6, 2) : $key] = 1;
            }
            $ranges[] = ['from' => $span['from'], 'to' => $span['to'], 'closed' => $closed];
        }
        return $ranges;
    }

    /** @param list<array{from:string,to:string,closed:array<string,int>}> $ranges @return list<string> */
    public static function openDays(string $from, string $to, array $ranges): array
    {
        $zone = new \DateTimeZone('Asia/Seoul');
        $cursor = new \DateTimeImmutable($from . ' 12:00:00', $zone);
        $end = new \DateTimeImmutable($to . ' 12:00:00', $zone);
        $out = [];
        while ($cursor <= $end) {
            $day = $cursor->format('Y-m-d');
            if (self::dayKind($day, $ranges) === 'open') {
                $out[] = $day;
            }
            $cursor = $cursor->modify('+1 day');
        }
        return $out;
    }

    public static function writeNew(string $path, string $json): void
    {
        if (file_exists($path)) {
            throw new \RuntimeException('Refusing to overwrite existing result');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create result directory');
        }
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Refusing to overwrite existing result');
        }
        try {
            if (fwrite($handle, $json) !== strlen($json)) {
                throw new \RuntimeException('Incomplete result write');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param list<array{from:string,to:string,closed:array<string,int>}> $ranges @return list<string> */
    private static function flags(string $day, array $ranges): array
    {
        $week = (int) (new \DateTimeImmutable($day . ' 12:00:00', new \DateTimeZone('Asia/Seoul')))->format('N');
        if ($week >= 6) {
            return ['closed'];
        }
        $flags = [];
        foreach ($ranges as $range) {
            if ($day < $range['from'] || $day > $range['to']) {
                continue;
            }
            $flags[] = isset($range['closed'][$day]) ? 'closed' : 'open';
        }
        return $flags;
    }

    /** @return array{status:string,reason:?string} */
    private static function undetermined(string $reason): array
    {
        return ['status' => 'undetermined', 'reason' => $reason];
    }

    private static function cents(string $close): ?int
    {
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.(\d{1,2}))?$/D', $close, $match)) {
            return null;
        }
        $value = ((int) explode('.', $close, 2)[0]) * 100 + (int) str_pad($match[1] ?? '', 2, '0');
        return $value > 0 ? $value : null;
    }

    /** @return array<string,mixed> */
    private static function bucket(): array
    {
        return ['signals' => 0, 'values' => [], 'symbol_set' => [], 'day_set' => []];
    }

    /** @param array<string,mixed> $bucket @param array<string,mixed> $row */
    private static function push(array &$bucket, array $row): void
    {
        $bucket['signals']++;
        $bucket['values'][] = (float) $row['difference'];
        $bucket['symbol_set'][$row['symbol']] = 1;
        $bucket['day_set'][$row['confirm_day']] = 1;
    }

    /** @param array<string,mixed> $bucket */
    private static function finish(array &$bucket): void
    {
        $values = $bucket['values'];
        $symbols = count($bucket['symbol_set']);
        $days = count($bucket['day_set']);
        unset($bucket['values'], $bucket['symbol_set'], $bucket['day_set']);
        $bucket['symbols'] = $symbols;
        $bucket['days'] = $days;
        $bucket['median'] = self::median($values);
        $bucket['positive_count'] = count(array_filter($values, static fn (float $value): bool => $value > 0));
        $bucket['positive_ratio'] = $values === [] ? null : $bucket['positive_count'] / count($values);
    }

    /** @param list<float> $values */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $count = count($values);
        return $count % 2 ? (float) $values[intdiv($count, 2)] : ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
    }

    /** @param list<array<string,mixed>> $groups @return list<array<string,mixed>> */
    private static function canonical(array $groups): array
    {
        foreach ($groups as &$group) {
            foreach (['base', 'pass_only'] as $key) {
                foreach (['median', 'positive_ratio', 'median_delta', 'retention'] as $field) {
                    if ($field === 'median' || $field === 'positive_ratio') {
                        $group[$key][$field] = $group[$key][$field] === null ? null : round((float) $group[$key][$field], 8);
                    }
                }
            }
            $group['median_delta'] = $group['median_delta'] === null ? null : round((float) $group['median_delta'], 8);
            $group['retention'] = $group['retention'] === null ? null : round((float) $group['retention'], 8);
        }
        unset($group);
        return $groups;
    }
}
