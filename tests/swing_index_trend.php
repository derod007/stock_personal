<?php

declare(strict_types=1);

require __DIR__ . '/../bin/bootstrap.php';

use ChartEntryLab\SwingIndexTrend;

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

$root = dirname(__DIR__);
$frozen = hash_file('sha256', $root . '/docs/swing-path-peer-compare/first.json.gz');
$secondFrozen = hash_file('sha256', $root . '/docs/swing-path-peer-compare/second.json.gz');
$ops = tempnam(sys_get_temp_dir(), 'idx');
file_put_contents($ops, 'operational');
$opsHash = hash_file('sha256', $ops);

$ranges = [['from' => '2024-01-01', 'to' => '2024-06-30', 'closed' => []]];
$all = SwingIndexTrend::openDays('2024-01-01', '2024-06-30', $ranges);
$sixty = array_slice($all, 0, 60);
$day = $sixty[59];
$shortRanges = [['from' => '2024-01-01', 'to' => $day, 'closed' => []]];
$opens = SwingIndexTrend::openDays('2024-01-01', $day, $shortRanges);
check(count($opens) === 60, 'exactly 60 open days');
$bars = array_fill_keys($opens, '100.00');
check(SwingIndexTrend::judge($day, $bars, $opens, $shortRanges) === ['status' => 'fail', 'reason' => null], 'equal close fails');
$bars[$day] = '200.00';
check(SwingIndexTrend::judge($day, $bars, $opens, $shortRanges)['status'] === 'pass', 'close above average passes');
$withFuture = $bars + ['2024-12-31' => '1.00'];
check(SwingIndexTrend::judge($day, $withFuture, $opens, $shortRanges)['status'] === 'pass', 'future close is ignored');

$earlier = $sixty[58];
$fewRanges = [['from' => '2024-01-01', 'to' => $earlier, 'closed' => []]];
$fewOpens = SwingIndexTrend::openDays('2024-01-01', $earlier, $fewRanges);
check(count($fewOpens) === 59, '59 open days');
check(SwingIndexTrend::judge($earlier, array_fill_keys($fewOpens, '100.00'), $fewOpens, $fewRanges)['reason'] === 'insufficient', '59 days are undetermined');

$missing = $bars;
unset($missing[$sixty[10]]);
check(SwingIndexTrend::judge($day, $missing, $opens, $shortRanges)['reason'] === 'missing_bar', 'calendar gap is undetermined');
$bad = $bars;
$bad[$sixty[10]] = '0';
check(SwingIndexTrend::judge($day, $bad, $opens, $shortRanges)['reason'] === 'bad_close', 'non-positive close is undetermined');
$bad[$sixty[10]] = 'nope';
check(SwingIndexTrend::judge($day, $bad, $opens, $shortRanges)['reason'] === 'bad_close', 'non-numeric close is undetermined');
check(SwingIndexTrend::judge('2024-01-07', $bars, $opens, $shortRanges)['reason'] === 'calendar_closed', 'Sunday is closed');

$conflict = [
    ['from' => '2024-01-01', 'to' => $day, 'closed' => []],
    ['from' => '2024-01-01', 'to' => $day, 'closed' => [$day => 1]],
];
check(SwingIndexTrend::judge($day, $bars, $opens, $conflict)['reason'] === 'calendar_conflict', 'calendar conflict is undetermined');
check(SwingIndexTrend::judge($day, $bars, $opens, [])['reason'] === 'calendar_unknown', 'missing calendar is undetermined');

$interiorDay = $sixty[10];
$interiorRanges = [
    ['from' => '2024-01-01', 'to' => '2024-06-30', 'closed' => []],
    ['from' => $interiorDay, 'to' => $interiorDay, 'closed' => [$interiorDay => 1]],
];
$interiorOpens = SwingIndexTrend::openDays('2024-01-01', '2024-06-30', $interiorRanges);
$laterDay = $all[60];
check(SwingIndexTrend::judge($laterDay, array_fill_keys($all, '100.00'), $interiorOpens, $interiorRanges)['reason'] === 'calendar_conflict', 'interior conflict cannot be skipped');
$interiorRanges = [
    ['from' => '2024-01-01', 'to' => $sixty[9], 'closed' => []],
    ['from' => $sixty[11], 'to' => '2024-06-30', 'closed' => []],
];
$interiorOpens = SwingIndexTrend::openDays('2024-01-01', '2024-06-30', $interiorRanges);
check(SwingIndexTrend::judge($laterDay, array_fill_keys($all, '100.00'), $interiorOpens, $interiorRanges)['reason'] === 'calendar_unknown', 'interior calendar hole cannot be skipped');

$gapRanges = [['from' => '2025-05-01', 'to' => '2025-11-28', 'closed' => []]];
$gapOpens = SwingIndexTrend::openDays('2025-05-01', '2025-11-28', $gapRanges);
$gapBars = array_fill_keys($gapOpens, '100.00');
check(SwingIndexTrend::judge('2025-11-28', $gapBars, $gapOpens, $gapRanges)['reason'] === 'gap_window', 'window through the three unclassified days is undetermined');
check(SwingIndexTrend::judge('2025-08-29', $gapBars, $gapOpens, $gapRanges)['status'] === 'fail', 'window before the unclassified days can be judged');

$kospi = $bars;
$kosdaq = array_fill_keys($opens, '100.00');
$signals = SwingIndexTrend::attach([
    ['symbol' => '000001.KS', 'confirm_day' => $day, 'group' => '손익비 조건 통과', 'horizons' => ['20' => ['difference' => 2.0, 'signal_status' => '완료']]],
    ['symbol' => '000002.KQ', 'confirm_day' => $day, 'group' => '손익비 조건 통과', 'horizons' => ['20' => ['difference' => 0.0, 'signal_status' => '완료']]],
], $kospi, $kosdaq, $opens, $shortRanges);
check($signals[0]['index_status'] === 'pass' && $signals[1]['index_status'] === 'fail', 'KS and KQ use different indexes');

$rows = [
    ['symbol' => '000001.KS', 'confirm_day' => '2025-01-02', 'group' => '손익비 조건 통과', 'index_status' => 'pass', 'index_reason' => null, 'path_status' => 'complete', 'difference' => 2.0],
    ['symbol' => '000002.KS', 'confirm_day' => '2025-01-03', 'group' => '손익비 조건 통과', 'index_status' => 'fail', 'index_reason' => null, 'path_status' => 'complete', 'difference' => 0.0],
    ['symbol' => '000003.KS', 'confirm_day' => '2025-01-06', 'group' => '손익비 조건 통과', 'index_status' => 'undetermined', 'index_reason' => 'gap_window', 'path_status' => 'complete', 'difference' => 10.0],
    ['symbol' => '000004.KS', 'confirm_day' => '2025-01-02', 'group' => '손익비 조건 통과', 'index_status' => 'pass', 'index_reason' => null, 'path_status' => 'incomplete', 'difference' => null],
];
$summary = SwingIndexTrend::summarize($rows);
$primary = $summary[0];
check($primary['signals'] === 4 && $primary['index']['pass'] === 2 && $primary['index']['fail'] === 1 && $primary['index']['reasons']['gap_window'] === 1, 'index counts keep undetermined apart');
check($primary['base']['signals'] === 2 && $primary['base']['median'] === 1.0 && $primary['base']['positive_count'] === 1, 'base set excludes undetermined and incomplete');
check($primary['pass_only']['signals'] === 1 && $primary['pass_only']['median'] === 2.0 && $primary['median_delta'] === 1.0 && $primary['retention'] === 0.5, 'pass subset is compared inside the same base set');
check($primary['base']['symbols'] === 2 && $primary['base']['days'] === 2 && $primary['pass_only']['days'] === 1, 'symbol and confirmation-day counts');
check(SwingIndexTrend::sameSummary($summary, SwingIndexTrend::summarize($rows)), 'resummarize matches');
$empty = SwingIndexTrend::summarize([]);
check($empty[0]['signals'] === 0 && $empty[0]['base']['median'] === null && $empty[0]['retention'] === null && $empty[1]['signals'] === 0, 'empty group stays empty');

fails(static fn () => SwingIndexTrend::requireMatch('abc', str_repeat('a', 64), 'sample'), 'Input hash mismatch');
$dir = sys_get_temp_dir() . '/index-seal-' . bin2hex(random_bytes(4));
mkdir($dir);
file_put_contents($dir . '/b.json', 'b');
file_put_contents($dir . '/a.json', 'a');
$seal = SwingIndexTrend::sealDirectory($dir);
check($seal === hash('sha256', 'a.json ' . hash('sha256', 'a') . "\n" . 'b.json ' . hash('sha256', 'b')), 'raw seal order');
$out = $dir . '/result.json';
SwingIndexTrend::writeNew($out, '{"ok":1}' . "\n");
fails(static fn () => SwingIndexTrend::writeNew($out, '{"ok":2}' . "\n"), 'Refusing to overwrite');
check(file_get_contents($out) === '{"ok":1}' . "\n", 'existing result bytes stay');

$result = $root . '/docs/swing-index-trend/result.json';
if (is_file($result)) {
    $saved = json_decode((string) file_get_contents($result), true, 512, JSON_THROW_ON_ERROR);
    foreach ($saved['experiments'] as $experiment) {
        check(SwingIndexTrend::sameSummary(SwingIndexTrend::summarize($experiment['signals']), $experiment['groups']), 'saved signals resummarize');
    }
}
check(hash_file('sha256', $root . '/docs/swing-path-peer-compare/first.json.gz') === $frozen, 'first comparison file unchanged');
check(hash_file('sha256', $root . '/docs/swing-path-peer-compare/second.json.gz') === $secondFrozen, 'second comparison file unchanged');
check(hash_file('sha256', $ops) === $opsHash, 'operational marker unchanged');
unlink($ops);

echo "INDEX_TREND_OK {$checks}\n";
