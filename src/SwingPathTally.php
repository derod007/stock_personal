<?php

declare(strict_types=1);

namespace ChartEntryLab;

/** Read-only totals of stored swing paths. Not a win rate and not a market sample. */
final class SwingPathTally
{
    public const VERSION = 'swing_path_tally_v1';

    /**
     * @param list<string> $symbols
     * @return array<string,mixed>
     */
    public static function run(string $dir, array $symbols, string $confirmFrom, string $confirmTo, string $asOf): array
    {
        $started = hrtime(true);
        $base = [
            'version' => self::VERSION,
            'research_version' => SwingResearch::VERSION,
            'confirm_from' => $confirmFrom,
            'confirm_to' => $confirmTo,
            'as_of' => $asOf,
            'calendars' => SwingResearch::calendarSources(),
            'files' => [],
            'errors' => [],
            'notes' => self::notes(),
            'observations' => [],
            'groups' => self::summarize([]),
            'elapsed_ms' => 0,
            'peak_bytes' => memory_get_peak_usage(true),
        ];
        if (!self::day($confirmFrom) || !self::day($confirmTo) || !self::day($asOf) || $confirmFrom > $confirmTo || $asOf < $confirmFrom) {
            $base['errors'][] = '확인일 범위와 관찰 종료일을 확인해 주세요.';
            return $base;
        }
        $rows = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim($symbol));
            if (!preg_match('/^[A-Z0-9.^=-]+$/', $symbol)) {
                $base['errors'][] = $symbol . ' 종목 이름이 올바르지 않습니다.';
                continue;
            }
            $loaded = SwingResearch::load($dir, $symbol);
            $base['files'][] = ['symbol' => $symbol, 'sha256' => $loaded['sha256'], 'bytes' => $loaded['bytes']];
            if ($loaded['error'] !== null) {
                $base['errors'][] = $symbol . ' ' . $loaded['error'];
                continue;
            }
            $replay = SwingResearch::replay($loaded['bars'], $symbol, $confirmFrom, $asOf);
            $kept = self::select($replay['signals'], $confirmFrom, $confirmTo);
            foreach (SwingResearch::observe($loaded['bars'], $symbol, $kept, $asOf) as $row) {
                $rows[] = $row;
            }
        }
        $base['observations'] = array_map(self::compact(...), $rows);
        $base['groups'] = self::summarize($base['observations']);
        $base['elapsed_ms'] = (hrtime(true) - $started) / 1e6;
        $base['peak_bytes'] = memory_get_peak_usage(true);
        return $base;
    }

    /** @param list<array<string,mixed>> $signals @return list<array<string,mixed>> */
    public static function select(array $signals, string $from, string $to): array
    {
        $seen = [];
        $out = [];
        foreach ($signals as $signal) {
            $day = $signal['confirm_day'] ?? null;
            if (!is_string($day) || $day < $from || $day > $to) {
                continue;
            }
            $key = (string) (float) ($signal['breakout_level'] ?? 0) . '|' . (string) ($signal['breakout_day'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $signal;
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public static function summarize(array $rows): array
    {
        $seen = [];
        $groups = ['손익비 조건 통과' => [], '손익비 부족' => [], '저항 미확정' => []];
        foreach ($rows as $row) {
            $key = (string) ($row['symbol'] ?? '') . '|' . (string) (float) ($row['breakout_level'] ?? 0) . '|' . (string) ($row['breakout_day'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $name = self::group($row['wait_reason'] ?? null);
            if ($name === null || !isset($groups[$name])) {
                continue;
            }
            $groups[$name][] = $row;
        }
        $out = [];
        foreach ($groups as $name => $items) {
            $symbols = [];
            foreach ($items as $row) {
                $symbols[(string) ($row['symbol'] ?? '')] = true;
            }
            $out[] = ['label' => $name, 'signals' => count($items), 'symbols' => count($symbols), 'horizons' => self::horizons($items)];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $reports @return array<string,mixed> */
    public static function combine(array $reports): array
    {
        $blank = self::combined(null, [], [], [], []);
        if ($reports === []) {
            $blank['errors'][] = '합칠 보고서가 없습니다.';
            return $blank;
        }
        $stamps = [];
        foreach ($reports as $report) {
            $stamps[] = self::stamp(is_array($report) ? $report : []);
        }
        $encoded = array_map(static fn (?array $stamp): string => $stamp === null ? '' : json_encode($stamp, JSON_UNESCAPED_UNICODE), $stamps);
        if (count(array_unique($encoded)) !== 1 || $encoded[0] === '') {
            $blank['errors'][] = '확인일 범위, 관찰 종료일, 버전, 달력 해시가 다른 보고서는 합치지 않습니다.';
            return $blank;
        }
        $files = [];
        $fileMark = [];
        $fileClash = [];
        $rows = [];
        $rowBody = [];
        $rowClash = [];
        $errors = [];
        foreach ($reports as $report) {
            foreach ($report['errors'] ?? [] as $error) {
                if (is_string($error) && $error !== '') {
                    $errors[$error] = true;
                }
            }
            $own = [];
            $ownEmpty = [];
            foreach ($report['files'] ?? [] as $file) {
                if (!is_array($file)) {
                    continue;
                }
                $symbol = (string) ($file['symbol'] ?? '');
                $hash = (string) ($file['sha256'] ?? '');
                if ($symbol === '' || $hash === '') {
                    if ($symbol !== '' && $hash === '') {
                        $ownEmpty[$symbol] = true;
                    } else {
                        $errors['입력 해시가 없는 종목은 합치지 않습니다.'] = true;
                    }
                    continue;
                }
                $mark = json_encode(['sha256' => $hash, 'bytes' => $file['bytes'] ?? null], JSON_UNESCAPED_UNICODE);
                if (isset($own[$symbol]) && $own[$symbol] !== $mark) {
                    $fileClash[$symbol] = true;
                    $errors[$symbol . ' 입력 해시가 묶음마다 다릅니다.'] = true;
                }
                $own[$symbol] = $mark;
                if (isset($fileMark[$symbol]) && $fileMark[$symbol] !== $mark) {
                    $fileClash[$symbol] = true;
                    $errors[$symbol . ' 입력 해시가 묶음마다 다릅니다.'] = true;
                    continue;
                }
                if (!isset($fileMark[$symbol])) {
                    $fileMark[$symbol] = $mark;
                    $files[$symbol] = ['symbol' => $symbol, 'sha256' => $hash, 'bytes' => $file['bytes'] ?? null];
                }
            }
            $notedEmpty = [];
            foreach ($report['observations'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $symbol = (string) ($row['symbol'] ?? '');
                if (!isset($own[$symbol]) || isset($ownEmpty[$symbol])) {
                    $reason = isset($ownEmpty[$symbol]) ? '입력 해시가 비어 있습니다.' : '같은 보고서의 입력 해시가 없습니다.';
                    $errors[$symbol . ' ' . (string) ($row['breakout_day'] ?? '') . ' ' . $reason] = true;
                    if (isset($ownEmpty[$symbol])) {
                        $notedEmpty[$symbol] = true;
                    }
                    continue;
                }
                $key = self::observationKey($row);
                $body = self::observationBody($row);
                if (isset($rowBody[$key]) && $rowBody[$key] !== $body) {
                    $rowClash[$key] = true;
                    $errors[$symbol . ' ' . (string) ($row['breakout_day'] ?? '') . ' 관찰값이 묶음마다 다릅니다.'] = true;
                    continue;
                }
                if (!isset($rowBody[$key])) {
                    $rowBody[$key] = $body;
                    $rows[$key] = json_decode($body, true);
                }
            }
            foreach (array_keys($ownEmpty) as $symbol) {
                if (!isset($notedEmpty[$symbol])) {
                    $errors[$symbol . ' 입력 해시가 비어 있습니다.'] = true;
                }
            }
        }
        foreach (array_keys($fileClash) as $symbol) {
            unset($files[$symbol]);
            foreach (array_keys($rows) as $key) {
                if (str_starts_with($key, $symbol . '|')) {
                    unset($rows[$key]);
                }
            }
        }
        foreach (array_keys($rowClash) as $key) {
            unset($rows[$key]);
        }
        ksort($files);
        ksort($rows);
        ksort($errors);
        $kept = array_values($rows);
        return self::combined($stamps[0], array_values($files), array_keys($errors), $kept);
    }

    /** @param array<string,mixed>|null $stamp @param list<array<string,mixed>> $files @param list<string> $errors @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private static function combined(?array $stamp, array $files, array $errors, array $rows): array
    {
        return [
            'version' => $stamp['version'] ?? null,
            'research_version' => $stamp['research_version'] ?? null,
            'confirm_from' => $stamp['confirm_from'] ?? null,
            'confirm_to' => $stamp['confirm_to'] ?? null,
            'as_of' => $stamp['as_of'] ?? null,
            'calendars' => $stamp['calendars'] ?? [],
            'notes' => self::notes(),
            'files' => $files,
            'errors' => $errors,
            'observations' => $rows,
            'groups' => self::summarize($rows),
        ];
    }

    /** @param array<string,mixed> $report @return array<string,mixed>|null */
    private static function stamp(array $report): ?array
    {
        foreach (['version', 'research_version', 'confirm_from', 'confirm_to', 'as_of'] as $key) {
            if (!is_string($report[$key] ?? null) || $report[$key] === '') {
                return null;
            }
        }
        if (!is_array($report['calendars'] ?? null) || $report['calendars'] === []) {
            return null;
        }
        $calendars = [];
        foreach ($report['calendars'] as $calendar) {
            if (!is_array($calendar) || !is_string($calendar['file'] ?? null) || !is_string($calendar['sha256'] ?? null) || $calendar['sha256'] === '') {
                return null;
            }
            $calendars[] = [
                'file' => $calendar['file'],
                'from' => (string) ($calendar['from'] ?? ''),
                'to' => (string) ($calendar['to'] ?? ''),
                'sha256' => $calendar['sha256'],
            ];
        }
        usort($calendars, static fn (array $left, array $right): int => strcmp($left['file'], $right['file']));
        return [
            'version' => $report['version'],
            'research_version' => $report['research_version'],
            'confirm_from' => $report['confirm_from'],
            'confirm_to' => $report['confirm_to'],
            'as_of' => $report['as_of'],
            'calendars' => $calendars,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function observationKey(array $row): string
    {
        return (string) ($row['symbol'] ?? '') . '|' . (string) (float) ($row['breakout_level'] ?? 0) . '|' . (string) ($row['breakout_day'] ?? '');
    }

    /** @param array<string,mixed> $row */
    private static function observationBody(array $row): string
    {
        $horizons = [];
        foreach ([5, 10, 20] as $n) {
            $horizon = is_array($row['horizons'][$n] ?? null) ? $row['horizons'][$n] : [];
            $horizons[$n] = [
                'status' => $horizon['status'] ?? null,
                'close_change' => $horizon['close_change'] ?? null,
                'max_up' => $horizon['max_up'] ?? null,
                'max_down' => $horizon['max_down'] ?? null,
            ];
        }
        return json_encode([
            'symbol' => $row['symbol'] ?? null,
            'breakout_level' => $row['breakout_level'] ?? null,
            'breakout_day' => $row['breakout_day'] ?? null,
            'confirm_day' => $row['confirm_day'] ?? null,
            'wait_reason' => $row['wait_reason'] ?? null,
            'horizons' => $horizons,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function compact(array $row): array
    {
        $horizons = [];
        foreach ([5, 10, 20] as $n) {
            $horizon = $row['horizons'][$n] ?? ['status' => '미확정', 'close_change' => null, 'max_up' => null, 'max_down' => null];
            $horizons[$n] = [
                'status' => $horizon['status'],
                'close_change' => $horizon['close_change'],
                'max_up' => $horizon['max_up'],
                'max_down' => $horizon['max_down'],
            ];
        }
        return [
            'symbol' => $row['symbol'] ?? null,
            'breakout_level' => $row['breakout_level'] ?? null,
            'breakout_day' => $row['breakout_day'] ?? null,
            'confirm_day' => $row['confirm_day'] ?? null,
            'wait_reason' => $row['wait_reason'] ?? null,
            'horizons' => $horizons,
        ];
    }

    /** @return list<string> */
    public static function notes(): array
    {
        return [
            '상승 비율은 매매 승률이 아닙니다.',
            '같은 종목의 겹치는 관찰 기간은 독립된 거래가 아닙니다.',
            '현재 저장 종목으로 계산한 결과이며 과거 전체 시장을 대표하지 않습니다.',
        ];
    }

    private static function group(?string $reason): ?string
    {
        return match ($reason) {
            '손익비가 1보다 큽니다. 추천 점수는 아닙니다.' => '손익비 조건 통과',
            '손익비가 1 이하이면 대기합니다.' => '손익비 부족',
            '다음 저항 미확정' => '저항 미확정',
            default => null,
        };
    }

    /** @param list<array<string,mixed>> $items @return array<int,array<string,mixed>> */
    private static function horizons(array $items): array
    {
        $out = [];
        foreach ([5, 10, 20] as $n) {
            $changes = [];
            $ups = [];
            $downs = [];
            $counts = ['완료' => 0, '미완료' => 0, '미확정' => 0];
            foreach ($items as $row) {
                $horizon = $row['horizons'][$n] ?? ['status' => '미확정'];
                $status = (string) ($horizon['status'] ?? '미확정');
                if (isset($counts[$status])) {
                    $counts[$status]++;
                }
                if ($status !== '완료' || !is_numeric($horizon['close_change'] ?? null)) {
                    continue;
                }
                $changes[] = (float) $horizon['close_change'];
                $ups[] = (float) $horizon['max_up'];
                $downs[] = (float) $horizon['max_down'];
            }
            $used = count($changes);
            $rose = 0;
            foreach ($changes as $change) {
                if ($change > 0) {
                    $rose++;
                }
            }
            $out[$n] = [
                'complete' => $counts['완료'],
                'incomplete' => $counts['미완료'],
                'undetermined' => $counts['미확정'],
                'close_change_mean' => $used === 0 ? null : array_sum($changes) / $used,
                'close_change_median' => self::median($changes),
                'close_change_n' => $used,
                'rose_ratio' => $used === 0 ? null : $rose / $used,
                'rose_n' => $used,
                'max_up_median' => self::median($ups),
                'max_up_n' => $used,
                'max_down_median' => self::median($downs),
                'max_down_n' => $used,
            ];
        }
        return $out;
    }

    /** @param list<float> $values */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    private static function day(string $day): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        return $parsed !== false && $parsed->format('Y-m-d') === $day;
    }
}
