<?php

declare(strict_types=1);

require __DIR__ . '/../bin/bootstrap.php';

use ChartEntryLab\CandleClock;
use ChartEntryLab\SwingPeerCompare;
use ChartEntryLab\SwingResearch;
use ChartEntryLab\SwingPathTally;

$checks = 0;
function check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($name);
    }
    $checks++;
}
function fails(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), $message), 'Expected failure: ' . $message);
        return;
    }
    throw new RuntimeException('Did not reject: ' . $message);
}
function stamp(string $day): int
{
    return (new DateTimeImmutable($day . ' 09:00:00', new DateTimeZone('Asia/Seoul')))->getTimestamp();
}
$calendar = new ReflectionMethod(SwingResearch::class, 'krxDay');
$bars = [];
for ($day = new DateTimeImmutable('2025-11-03'); $day->format('Y-m-d') <= '2025-12-05'; $day = $day->modify('+1 day')) {
    $date = $day->format('Y-m-d');
    if ($calendar->invoke(null, $date) === 'open') {
        $bars[] = ['time' => stamp($date), 'open' => 100, 'high' => 200, 'low' => 50, 'close' => 100, 'volume' => 1];
    }
}
function changed(array $bars, float $close): array
{
    foreach ($bars as &$bar) {
        if ($bar['time'] > stamp('2025-11-07')) {
            $bar['close'] = $close;
        }
    }
    return $bars;
}
function signal(string $symbol, float $change): array
{
    return ['symbol' => $symbol, 'confirm_day' => '2025-11-07', 'breakout_day' => '2025-11-05',
        'breakout_level' => 90, 'wait_reason' => '손익비가 1보다 큽니다. 추천 점수는 아닙니다.',
        'horizons' => array_fill_keys([5, 10, 20], ['status' => '완료', 'close_change' => $change, 'max_up' => 1, 'max_down' => -0.5])];
}
$data = ['000001.KS' => changed($bars, 110), '000002.KS' => changed($bars, 120),
    '000003.KS' => changed($bars, 140), '000004.KQ' => changed($bars, 190)];
$signals = [signal('000001.KS', 0.1), signal('000002.KS', 0.2)];
$result = SwingPeerCompare::compare($data, $signals, '2025-12-05');
check(abs($result['observations'][0]['horizons'][20]['peer_median'] - 0.3) < 1e-12, 'Self excluded and KQ excluded');
check(abs($result['observations'][1]['horizons'][20]['peer_median'] - 0.25) < 1e-12, 'Same-date signals have different exclusion medians');
check($result['observations'][0]['eligible_peers'] === 2, 'A peer with a signal remains eligible');
$missing = $data;
$missing['000002.KS'] = array_values(array_filter($missing['000002.KS'], fn ($b) => $b['time'] !== stamp('2025-11-10')));
$r = SwingPeerCompare::compare($missing, $signals, '2025-12-05');
check($r['observations'][0]['eligible_peers'] === 2 && $r['observations'][0]['horizons'][20]['undetermined'] === 1, 'Missing future bar does not exclude peer');
check($r['pools']['.KS|2025-11-07']['eligible']['000002.KS']['ref_open'] === null, 'Missing first open not replaced');
$short = ['000001.KS' => $data['000001.KS'], '000002.KS' => array_slice($data['000002.KS'], 1)];
$r = SwingPeerCompare::compare($short, [$signals[0]], '2025-12-05');
check($r['observations'][0]['excluded_peers'] === 1 && $r['groups'][0]['horizons'][20]['n'] === 0, 'Future bars do not satisfy five prior bars; empty peers not zero');
$late = $data;
$late['000002.KS'][4]['available_at'] = stamp('2025-11-10');
$r = SwingPeerCompare::compare($late, [$signals[0]], '2025-12-05');
check($r['observations'][0]['eligible_peers'] === 1, 'Late availability cannot qualify at confirmation');
$later = $data;
$later['000002.KS'][] = ['time' => stamp('2025-12-08'), 'open' => 100, 'high' => 10000, 'low' => 1, 'close' => 9000];
check(SwingPeerCompare::compare($later, $signals, '2025-12-05') === $result, 'Bars beyond observation cutoff have no effect');
$r = SwingPeerCompare::compare($data, $signals, '2025-11-14');
check($r['observations'][0]['horizons'][20]['incomplete'] === 2, 'Short observation window stays incomplete');
$bad = $data;
$bad['000002.KS'][6]['close'] = 500;
$r = SwingPeerCompare::compare($bad, $signals, '2025-12-05');
check($r['observations'][0]['horizons'][20]['undetermined'] === 1, 'Invalid OHLC is not repaired');
$byDay = [];
foreach (CandleClock::completed($data['000001.KS'], '000001.KS', PHP_INT_MAX) as $b) {
    $byDay[gmdate('Y-m-d', $b['time'] + 32400)] = $b;
}
$asOf = stamp('2025-12-05') + 23400;
$price = SwingResearch::pricePath($byDay, '000001.KS', '2025-11-07', $asOf);
$original = SwingResearch::observe($data['000001.KS'], '000001.KS', [$signals[0]], '2025-12-05')[0];
check($price['horizons'] === $original['horizons'], 'Shared price horizons equal signal path');

// Full verifier fixture: no production prices, no network, one preserved batch.
$root = sys_get_temp_dir() . '/swing-peer-' . bin2hex(random_bytes(5));
function put(string $file, array $data): void
{
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }
    file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));
}
try {
    $universe = json_decode(file_get_contents(__DIR__ . '/../docs/swing-path-compare-2025h1/universe.json'), true);
    put($root . '/docs/swing-path-compare-2025h1/universe.json', $universe);
    $files = [];
    foreach ($universe['included'] as $entry) {
        $s = $entry['symbol'];
        $file = $root . '/prices/' . $s . '_2y_1d_closed_v2.json';
        put($file, $bars);
        $files[] = ['symbol' => $s, 'sha256' => hash_file('sha256', $file), 'bytes' => filesize($file)];
    }
    $calendars = SwingResearch::calendarSources();
    foreach ($calendars as $c) {
        mkdir(dirname($root . '/' . $c['file']), 0777, true);
        copy(__DIR__ . '/../' . $c['file'], $root . '/' . $c['file']);
    }
    $observations = [signal($files[0]['symbol'], 0.1), signal($files[1]['symbol'], 0.2)];
    $batch = ['version' => SwingPathTally::VERSION, 'research_version' => SwingResearch::VERSION,
        'confirm_from' => '2025-11-07', 'confirm_to' => '2025-11-07', 'as_of' => '2025-12-05',
        'calendars' => $calendars, 'files' => $files, 'errors' => [], 'observations' => $observations];
    $exp = $root . '/experiment';
    put($exp . '/inputs.json', ['files' => $files, 'calendars' => $calendars]);
    put($exp . '/batches/batch-1.json', $batch);
    put($exp . '/batches/manifest.json', ['files' => [['file' => 'batch-1.json', 'sha256' => hash_file('sha256', $exp . '/batches/batch-1.json')]]]);
    $combined = SwingPathTally::combine([$batch]);
    put($exp . '/observations.json', $combined['observations']);
    unset($combined['files'], $combined['observations']);
    put($exp . '/summary.json', $combined);
    $run = fn () => SwingPeerCompare::run($root, $exp, $root . '/prices');
    check(count($run()['observations']) === 2, 'Verified fixture executes');
    put($exp . '/observations.json', array_reverse($observations));
    check(count($run()['observations']) === 2, 'Preserved observation order does not change identity');
    put($exp . '/observations.json', [$observations[0], $observations[0]]);
    fails($run, 'Duplicate frozen observation');
    put($exp . '/observations.json', $observations);
    $path = $root . '/prices/' . $files[0]['symbol'] . '_2y_1d_closed_v2.json';
    $saved = file_get_contents($path);
    file_put_contents($path, $saved . ' ');
    fails($run, 'Input hash mismatch');
    file_put_contents($path, $saved);
    put($exp . '/observations.json', []);
    fails($run, 'Frozen observations');
    file_put_contents($exp . '/batches/batch-1.json', '[]');
    fails($run, 'Input hash mismatch');
} finally {
    // Only the newly created random test directory is removed.
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
echo "SWING_PEER_OK $checks checks\n";
