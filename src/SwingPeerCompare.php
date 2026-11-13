<?php

declare(strict_types=1);

namespace ChartEntryLab;

/** Offline comparison of frozen signals with same-session, same-market peers. */
final class SwingPeerCompare
{
    public const VERSION = 'swing_peer_compare_v1';

    public static function run(string $root, string $experiment, string $prices): array
    {
        $started = hrtime(true);
        $hashes = [];
        foreach (['src/SwingPeerCompare.php', 'src/SwingResearch.php', 'src/SwingPathTally.php',
            'src/CandleClock.php', 'bin/swing_peer_compare.php', 'docs/swing-path-peer-compare/protocol.md'] as $file) {
            $path = dirname(__DIR__) . '/' . $file;
            $hashes[$path] = hash_file('sha256', $path);
        }
        $read = static function (string $file, ?string $expected = null) use (&$hashes): array {
            $bytes = @file_get_contents($file);
            if ($bytes === false) {
                throw new \RuntimeException('Missing input: ' . $file);
            }
            $hash = hash('sha256', $bytes);
            if ($expected !== null && ($expected === '' || $hash !== $expected)) {
                throw new \RuntimeException('Input hash mismatch: ' . $file);
            }
            $hashes[$file] = $hash;
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($value)) {
                throw new \RuntimeException('Invalid JSON structure: ' . $file);
            }
            return $value;
        };
        $input = $read($experiment . '/inputs.json');
        $manifest = $read($experiment . '/batches/manifest.json');
        $universe = $read($root . '/docs/swing-path-compare-2025h1/universe.json');
        $symbols = array_column($universe['included'], 'symbol');
        $expected = self::fileMap($input['files']);
        $names = array_keys($expected);
        sort($names);
        sort($symbols);
        if ($names !== $symbols || count(array_unique($symbols)) !== 236) {
            throw new \RuntimeException('Expected exact frozen 236-symbol universe');
        }
        $calendars = self::calendarMap($input['calendars']);
        if (self::calendarMap(SwingResearch::calendarSources()) !== $calendars) {
            throw new \RuntimeException('Research calendar mismatch');
        }
        foreach ($calendars as $file => $hash) {
            $read($root . '/' . $file, $hash);
        }
        $batches = [];
        $seen = [];
        $seenBatches = [];
        foreach ($manifest['files'] as $file) {
            $name = $file['file'];
            if (!preg_match('/^batch-[0-9]+\.json$/D', $name) || isset($seenBatches[$name])) {
                throw new \RuntimeException('Invalid or duplicate batch name');
            }
            $seenBatches[$name] = true;
            $batch = $read($experiment . '/batches/' . $name, $file['sha256']);
            if ($batch['errors'] !== [] || self::calendarMap($batch['calendars']) !== $calendars) {
                throw new \RuntimeException('Batch error or calendar mismatch');
            }
            foreach (self::fileMap($batch['files']) as $symbol => $hash) {
                if (isset($seen[$symbol]) || ($expected[$symbol] ?? null) !== $hash) {
                    throw new \RuntimeException('Duplicate or mismatched batch input: ' . $symbol);
                }
                $seen[$symbol] = $hash;
            }
            $batches[] = $batch;
        }
        ksort($seen);
        if ($seen !== $expected) {
            throw new \RuntimeException('Incomplete batch universe');
        }
        $combined = SwingPathTally::combine($batches);
        $observations = $read($experiment . '/observations.json');
        if ($combined['errors'] !== [] || self::observationMap($combined['observations']) !== self::observationMap($observations)
            || $combined['version'] !== SwingPathTally::VERSION
            || $combined['research_version'] !== SwingResearch::VERSION) {
            throw new \RuntimeException('Frozen observations or research version mismatch');
        }
        $savedSummary = $read($experiment . '/summary.json');
        $summary = $combined;
        unset($summary['observations'], $summary['files']);
        if (json_encode($savedSummary, JSON_THROW_ON_ERROR) !== json_encode($summary, JSON_THROW_ON_ERROR)) {
            throw new \RuntimeException('Frozen summary mismatch');
        }
        $bars = [];
        foreach ($expected as $symbol => $hash) {
            $bars[$symbol] = $read($prices . '/' . $symbol . '_2y_1d_closed_v2.json', $hash);
        }
        $result = self::compare($bars, $observations, $combined['as_of']);
        foreach ($hashes as $file => $hash) {
            if (hash_file('sha256', $file) !== $hash) {
                throw new \RuntimeException('Input changed during comparison: ' . $file);
            }
        }
        $relativeHashes = [];
        foreach ($hashes as $file => $hash) {
            $relativeHashes[str_replace('\\', '/', substr($file, strlen($root) + 1))] = $hash;
        }
        return [
            'version' => self::VERSION,
            'research_version' => SwingResearch::VERSION,
            'tally_version' => SwingPathTally::VERSION,
            'experiment' => basename($experiment),
            'confirm_from' => $combined['confirm_from'], 'confirm_to' => $combined['confirm_to'],
            'as_of' => $combined['as_of'], 'input_hashes' => $relativeHashes,
            'input_unchanged' => true, 'elapsed_ms' => (hrtime(true) - $started) / 1e6,
            'peak_bytes' => memory_get_peak_usage(true),
        ] + $result;
    }

    /** Pure calculation; peers are selected using only information available at confirmation. */
    public static function compare(array $bars, array $observations, string $asOf): array
    {
        $end = self::closeOf($asOf);
        $indexed = [];
        foreach ($bars as $symbol => $raw) {
            $indexed[$symbol] = [];
            foreach (CandleClock::completed($raw, $symbol, $end) as $bar) {
                $day = gmdate('Y-m-d', (int) $bar['time'] + 9 * 3600);
                $indexed[$symbol][$day] = $bar;
            }
        }
        $pools = [];
        $rows = [];
        $groups = [];
        foreach (['손익비 조건 통과', '손익비 부족', '저항 미확정'] as $label) {
            $groups[$label] = ['label' => $label, 'signals' => 0, 'horizons' => []];
            foreach ([5, 10, 20] as $n) {
                $groups[$label]['horizons'][$n] = ['differences' => [], 'signal_incomplete' => 0,
                    'signal_undetermined' => 0, 'no_completed_peers' => 0];
            }
        }
        foreach ($observations as $signal) {
            $symbol = $signal['symbol'];
            $day = $signal['confirm_day'];
            $market = substr($symbol, -3);
            if (!in_array($market, ['.KS', '.KQ'], true) || !isset($indexed[$symbol])) {
                throw new \RuntimeException('Unknown signal symbol');
            }
            $key = $market . '|' . $day;
            if (!isset($pools[$key])) {
                $pool = ['market' => $market, 'confirm_day' => $day, 'eligible' => [], 'excluded' => []];
                $cutoff = self::closeOf($day);
                foreach ($indexed as $peer => $byDay) {
                    if (substr($peer, -3) !== $market) {
                        continue;
                    }
                    $known = array_filter($byDay, static fn (array $bar, string $d): bool =>
                        $d <= $day && $bar['available_at'] <= $cutoff, ARRAY_FILTER_USE_BOTH);
                    if (count($known) < 5 || !isset($known[$day])) {
                        $pool['excluded'][$peer] = count($known) < 5 ? 'fewer_than_5_completed' : 'no_confirmation_bar';
                        continue;
                    }
                    $path = SwingResearch::pricePath($byDay, $peer, $day, $end);
                    $pool['eligible'][$peer] = ['ref_day' => $path['ref_day'], 'ref_open' => $path['ref_open'], 'horizons' => []];
                    foreach ([5, 10, 20] as $n) {
                        $h = $path['horizons'][$n];
                        $pool['eligible'][$peer]['horizons'][$n] = ['status' => $h['status'], 'close_change' => $h['close_change']];
                    }
                }
                $pools[$key] = $pool;
            }
            $pool = $pools[$key];
            $peers = $pool['eligible'];
            $excluded = $pool['excluded'];
            unset($peers[$symbol], $excluded[$symbol]);
            $label = match ($signal['wait_reason']) {
                '손익비가 1보다 큽니다. 추천 점수는 아닙니다.' => '손익비 조건 통과',
                '손익비가 1 이하이면 대기합니다.' => '손익비 부족',
                '다음 저항 미확정' => '저항 미확정',
                default => throw new \RuntimeException('Unknown signal group'),
            };
            $groups[$label]['signals']++;
            $row = ['symbol' => $symbol, 'breakout_day' => $signal['breakout_day'],
                'breakout_level' => $signal['breakout_level'], 'confirm_day' => $day,
                'group' => $label, 'pool' => $key, 'eligible_peers' => count($peers),
                'excluded_peers' => count($excluded), 'horizons' => []];
            foreach ([5, 10, 20] as $n) {
                $values = [];
                $counts = ['complete' => 0, 'incomplete' => 0, 'undetermined' => 0];
                foreach ($peers as $peer) {
                    $h = $peer['horizons'][$n];
                    $status = match ($h['status']) {
                        '완료' => 'complete', '미완료' => 'incomplete', '미확정' => 'undetermined',
                        default => throw new \RuntimeException('Unknown path status'),
                    };
                    $counts[$status]++;
                    if ($status === 'complete') {
                        $values[] = $h['close_change'];
                    }
                }
                $h = $signal['horizons'][$n];
                $median = self::median($values);
                $difference = $h['status'] === '완료' && $median !== null ? $h['close_change'] - $median : null;
                $reason = $h['status'] === '미완료' ? 'signal_incomplete'
                    : ($h['status'] !== '완료' ? 'signal_undetermined' : ($median === null ? 'no_completed_peers' : null));
                $row['horizons'][$n] = $counts + ['signal_status' => $h['status'],
                    'signal_change' => $h['close_change'], 'peer_median' => $median,
                    'difference' => $difference, 'unavailable_reason' => $reason];
                if ($difference !== null) {
                    $groups[$label]['horizons'][$n]['differences'][] = $difference;
                } else {
                    $groups[$label]['horizons'][$n][$reason]++;
                }
            }
            $rows[] = $row;
        }
        foreach ($groups as &$group) {
            foreach ($group['horizons'] as &$h) {
                $values = $h['differences'];
                unset($h['differences']);
                $h['n'] = count($values);
                $h['median_difference'] = self::median($values);
                $h['positive_count'] = count(array_filter($values, static fn (float $v): bool => $v > 0));
                $h['positive_ratio'] = $values === [] ? null : $h['positive_count'] / count($values);
            }
            unset($h);
        }
        unset($group);
        return ['groups' => array_values($groups), 'observations' => $rows, 'pools' => $pools];
    }

    private static function observationMap(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = $row['symbol'] . '|' . (string) (float) $row['breakout_level'] . '|' . $row['breakout_day'];
            if (isset($out[$key])) {
                throw new \RuntimeException('Duplicate frozen observation');
            }
            $out[$key] = json_encode($row, JSON_THROW_ON_ERROR);
        }
        ksort($out);
        return $out;
    }

    private static function fileMap(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            $symbol = $file['symbol'];
            if (!preg_match('/^[0-9]{6}\.(KS|KQ)$/D', $symbol) || isset($out[$symbol])
                || !preg_match('/^[a-f0-9]{64}$/D', $file['sha256'])) {
                throw new \RuntimeException('Invalid or duplicate file hash');
            }
            $out[$symbol] = $file['sha256'];
        }
        ksort($out);
        return $out;
    }

    private static function calendarMap(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            if (isset($out[$file['file']]) || !preg_match('/^[a-f0-9]{64}$/D', $file['sha256'])) {
                throw new \RuntimeException('Invalid calendar hash');
            }
            $out[$file['file']] = $file['sha256'];
        }
        ksort($out);
        return $out;
    }

    private static function closeOf(string $day): int
    {
        return (new \DateTimeImmutable($day . ' 15:30:00', new \DateTimeZone('Asia/Seoul')))->getTimestamp();
    }

    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $n = count($values);
        return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
