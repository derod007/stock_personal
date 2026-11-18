<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\SwingIndexTrend;

$root = dirname(__DIR__);
$rawRoot = $root . '/data/raw/research/index-daily-20240901-20261008';
$output = $root . '/docs/swing-index-trend/result.json';
try {
    if (file_exists($output)) {
        throw new RuntimeException('Refusing to overwrite existing result');
    }
    $hashes = [];
    $hashFile = static function (string $rel) use ($root, &$hashes): string {
        $path = $root . '/' . $rel;
        if (!is_file($path)) {
            throw new RuntimeException('Missing input: ' . $rel);
        }
        $hashes[$rel] = hash_file('sha256', $path);
        return $hashes[$rel];
    };
    $read = static function (string $rel) use ($root): array {
        $json = json_decode((string) file_get_contents($root . '/' . $rel), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid JSON structure: ' . $rel);
        }
        return $json;
    };
    $manifest = $read('docs/swing-path-peer-compare/manifest.json');
    $series = $read('docs/index-daily-20240901-20261008/series.json');
    $hashFile('docs/swing-path-peer-compare/manifest.json');
    $hashFile('docs/index-daily-20240901-20261008/series.json');
    foreach ($manifest['files'] as $file) {
        $rel = 'docs/swing-path-peer-compare/' . $file['file'];
        SwingIndexTrend::requireMatch($hashFile($rel), $file['sha256'], $rel);
        $jsonHash = hash('sha256', (string) gzdecode((string) file_get_contents($root . '/' . $rel)));
        SwingIndexTrend::requireMatch($jsonHash, $file['json_sha256'], $rel . ' json');
        $hashes[$rel . ' json'] = $jsonHash;
    }
    foreach (['KOSPI' => 'kospi_dd_trd', 'KOSDAQ' => 'kosdaq_dd_trd'] as $market => $api) {
        $rel = 'data/raw/research/index-daily-20240901-20261008/normalized/' . $api . '.json';
        SwingIndexTrend::requireMatch($hashFile($rel), $series['markets'][$market]['normalized_sha256'], $rel);
        $seal = SwingIndexTrend::sealDirectory($rawRoot . '/raw/' . $api);
        SwingIndexTrend::requireMatch($seal, $series['markets'][$market]['raw_seal_sha256'], $api . ' raw');
        $hashes[$api . ' raw_seal'] = $seal;
    }
    foreach (SwingIndexTrend::CALENDARS as $rel) {
        $hashFile($rel);
    }
    foreach (['src/SwingIndexTrend.php', 'bin/swing_index_trend.php', 'docs/swing-index-trend/protocol.md'] as $rel) {
        $hashFile($rel);
    }
    $ranges = SwingIndexTrend::loadRanges($root);
    $from = $ranges[0]['from'];
    foreach ($ranges as $range) {
        if ($range['from'] < $from) {
            $from = $range['from'];
        }
    }
    $opens = SwingIndexTrend::openDays($from, '2026-10-08', $ranges);
    $kospi = SwingIndexTrend::loadCloses($rawRoot . '/normalized/kospi_dd_trd.json');
    $kosdaq = SwingIndexTrend::loadCloses($rawRoot . '/normalized/kosdaq_dd_trd.json');
    $experiments = [];
    foreach (['first' => 294, 'second' => 399] as $name => $expected) {
        $payload = json_decode((string) gzdecode((string) file_get_contents($root . '/docs/swing-path-peer-compare/' . $name . '.json.gz')), true, 512, JSON_THROW_ON_ERROR);
        $observations = $payload['observations'];
        unset($payload);
        if (count($observations) !== $expected) {
            throw new RuntimeException('Frozen observation count mismatch: ' . $name);
        }
        $signals = SwingIndexTrend::attach($observations, $kospi, $kosdaq, $opens, $ranges);
        $experiments[$name] = ['signals' => $signals, 'groups' => SwingIndexTrend::summarize($signals)];
    }
    foreach ($hashes as $rel => $hash) {
        if (str_contains($rel, ' ')) {
            continue;
        }
        if (hash_file('sha256', $root . '/' . $rel) !== $hash) {
            throw new RuntimeException('Input changed during comparison: ' . $rel);
        }
    }
    $report = [
        'version' => SwingIndexTrend::VERSION,
        'note' => '탐색 비교다. 추천 기능이 아니고 60일은 연구 가정이다.',
        'inputs' => $hashes,
        'experiments' => $experiments,
    ];
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    SwingIndexTrend::writeNew($output, $json);
    $primary = [];
    foreach ($experiments as $name => $experiment) {
        foreach ($experiment['groups'] as $group) {
            if ($group['label'] === '손익비 조건 통과') {
                $primary[$name] = ['base' => $group['base']['signals'], 'pass' => $group['pass_only']['signals'], 'delta' => $group['median_delta']];
            }
        }
    }
    echo json_encode(['output' => $output, 'primary' => $primary], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
