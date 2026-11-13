<?php

declare(strict_types=1);

require __DIR__ . '/../bin/bootstrap.php';

use ChartEntryLab\SwingPathTally;
use ChartEntryLab\SwingResearch;

function tally(bool $ok, string $why): void
{
    if (!$ok) {
        throw new RuntimeException($why);
    }
    echo "TALLY_PASS $why\n";
}

function tallyRow(string $day, string $reason, string $status, ?float $change, string $breakout = '2024-10-28'): array
{
    $horizon = ['status' => $status, 'close_change' => $change, 'max_up' => $change === null ? null : $change + 0.01, 'max_down' => $change === null ? null : $change - 0.01];
    return [
        'symbol' => 'TEST.KS', 'breakout_level' => 30.0, 'breakout_day' => $breakout, 'confirm_day' => '2024-11-01',
        'wait_reason' => $reason, 'horizons' => [5 => $horizon, 10 => $horizon, 20 => $horizon],
    ];
}

$pass = '손익비가 1보다 큽니다. 추천 점수는 아닙니다.';
$one = tallyRow('2024-10-28', $pass, '완료', 0.1);
$again = tallyRow('2024-10-28', $pass, '완료', 0.9);
$groups = SwingPathTally::summarize([$one, $again]);
$passed = $groups[0];
tally($passed['label'] === '손익비 조건 통과' && $passed['signals'] === 1
    && $passed['horizons'][5]['close_change_n'] === 1 && $passed['horizons'][5]['close_change_mean'] === 0.1, 'a repeated signal is counted once');

$open = tallyRow('2024-10-29', '다음 저항 미확정', '미확정', null, '2024-10-29');
$mixed = SwingPathTally::summarize([$one, $open]);
tally($mixed[0]['horizons'][5]['complete'] === 1 && $mixed[0]['horizons'][5]['undetermined'] === 0
    && $mixed[2]['signals'] === 1 && $mixed[2]['horizons'][5]['undetermined'] === 1
    && $mixed[2]['horizons'][5]['close_change_mean'] === null && $mixed[2]['horizons'][5]['close_change_n'] === 0
    && $mixed[0]['horizons'][5]['close_change_mean'] === 0.1, 'an undetermined horizon stays out of the average');

$low = tallyRow('2024-10-30', '손익비가 1 이하이면 대기합니다.', '완료', -0.2, '2024-10-30');
$other = tallyRow('2024-10-31', $pass, '완료', 0.3, '2024-10-31');
$split = SwingPathTally::summarize([$one, $other, $low]);
tally($split[0]['signals'] === 2 && $split[1]['signals'] === 1 && $split[2]['signals'] === 0
    && $split[0]['horizons'][5]['close_change_mean'] === 0.2 && $split[0]['horizons'][5]['close_change_median'] === 0.2
    && $split[0]['horizons'][5]['rose_ratio'] == 1 && $split[0]['horizons'][5]['rose_n'] === 2
    && $split[0]['horizons'][5]['max_up_n'] === 2, 'passed and short reward stay in separate groups');

$signal = ['confirm_day' => '2024-11-01', 'breakout_level' => 30.0, 'breakout_day' => '2024-10-28', 'wait_reason' => $pass];
tally(count(SwingPathTally::select([$signal, $signal], '2024-11-01', '2024-11-01')) === 1
    && SwingPathTally::select([$signal], '2024-10-31', '2024-10-31') === []
    && SwingPathTally::select([$signal], '2024-11-02', '2024-11-02') === []
    && count(SwingPathTally::select([$signal], '2024-10-31', '2024-11-01')) === 1, 'confirmation days outside the range stay out');

function seoulBar(string $day, float $open, float $high, float $low, float $close): array
{
    $stamp = (new DateTimeImmutable($day . ' 10:00:00', new DateTimeZone('Asia/Seoul')))->getTimestamp();
    return ['time' => $stamp, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => 1000];
}
$bars = [];
foreach (['2024-11-04', '2024-11-05', '2024-11-06', '2024-11-07', '2024-11-08'] as $i => $day) {
    $bars[] = seoulBar($day, 100, $i === 4 ? 112 : 101, 99, $i === 4 ? 110 : 100);
}
$seen = SwingResearch::observe($bars, 'TEST.KS', [[
    'confirm_day' => '2024-11-01', 'breakout_day' => '2024-10-28', 'breakout_level' => 30.0,
    'entry' => 36.0, 'resistance' => 80.0, 'reward_risk' => 2.0, 'wait_reason' => $pass,
]], '2024-11-08');
$matched = SwingPathTally::summarize($seen);
tally($seen[0]['horizons'][5]['status'] === '완료'
    && $matched[0]['horizons'][5]['close_change_n'] === 1
    && $matched[0]['horizons'][5]['close_change_mean'] === $seen[0]['horizons'][5]['close_change']
    && $matched[0]['horizons'][5]['max_up_median'] === $seen[0]['horizons'][5]['max_up']
    && $matched[0]['horizons'][5]['max_down_median'] === $seen[0]['horizons'][5]['max_down'], 'the total matches the single path');

$dir = sys_get_temp_dir() . '/swing-tally-' . getmypid();
mkdir($dir);
$source = [];
for ($i = 0; $i < 40; $i++) {
    $source[] = ['available_at' => 1700000000 + $i * 86400, 'open' => 10, 'high' => 10, 'low' => 9, 'close' => 10, 'volume' => 1000];
}
$source[4]['high'] = 80;
$source[16]['high'] = 30;
$source[20] = array_replace($source[20], ['open' => 30, 'high' => 32, 'low' => 28, 'close' => 31]);
$source[22] = array_replace($source[22], ['open' => 30, 'high' => 33, 'low' => 29, 'close' => 31]);
$source[24] = array_replace($source[24], ['open' => 35, 'high' => 36, 'low' => 34, 'close' => 36]);
foreach ($source as $i => $bar) {
    if ($i > 20 && $i !== 22 && $i !== 24) {
        $source[$i] = array_replace($bar, ['open' => 31, 'high' => 32, 'low' => 30, 'close' => 31]);
    }
}
$stored = [];
foreach ($source as $bar) {
    $stored[] = ['time' => $bar['available_at'], 'open' => $bar['open'], 'high' => $bar['high'], 'low' => $bar['low'], 'close' => $bar['close'], 'volume' => 1000];
}
$first = (new DateTimeImmutable('@' . $source[0]['available_at']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
$last = (new DateTimeImmutable('@' . $source[39]['available_at']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
$play = SwingResearch::replay($stored, 'TEST.KS', $first, $last);
$confirmed = array_values(array_filter($play['signals'], static fn (array $signal): bool => $signal['confirm_day'] !== null));
$path = $dir . '/TEST.KS_2y_1d_closed_v2.json';
file_put_contents($path, json_encode($stored));
$before = hash_file('sha256', $path);
$day = (string) $confirmed[0]['confirm_day'];
$next = (new DateTimeImmutable($day . ' 12:00:00'))->modify('+1 day')->format('Y-m-d');
$report = SwingPathTally::run($dir, ['TEST.KS'], $day, $day, $last);
$outside = SwingPathTally::run($dir, ['TEST.KS'], $next, $next, $last);
$solo = SwingResearch::observe($stored, 'TEST.KS', [$confirmed[0]], $last);
$notes = implode(' ', $report['notes']);
tally(count($confirmed) === 1 && hash_file('sha256', $path) === $before && $report['files'][0]['sha256'] === $before
    && $report['research_version'] === SwingResearch::VERSION && $report['calendars'][0]['sha256'] !== ''
    && $report['groups'][0]['signals'] === 1 && $outside['groups'][0]['signals'] === 0
    && $report['groups'][0]['horizons'][5]['close_change_mean'] === ($solo[0]['horizons'][5]['status'] === '완료' ? $solo[0]['horizons'][5]['close_change'] : null)
    && str_contains($notes, '상승 비율은 매매 승률이 아닙니다.')
    && str_contains($notes, '독립된 거래가 아닙니다.')
    && str_contains($notes, '과거 전체 시장을 대표하지 않습니다.')
    && !array_key_exists('win_rate', $report['groups'][0]['horizons'][5]), 'the stored run keeps the same path and the file');
echo "TALLY_OK\n";
