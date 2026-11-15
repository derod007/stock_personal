<?php

declare(strict_types=1);

require __DIR__ . '/../bin/bootstrap.php';

use ChartEntryLab\SwingPlan;

function pp(bool $ok, string $why): void
{
    if (!$ok) {
        throw new RuntimeException($why);
    }
    echo "PATH_PASS $why\n";
}

function ppDir(string $name): string
{
    $dir = sys_get_temp_dir() . '/swing-path-' . $name . '-' . getmypid();
    if (is_dir($dir)) {
        ppRemove($dir);
    }
    mkdir($dir);

    return $dir;
}

function ppRemove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function ppBar(string $day, int $close, array $extra = []): array
{
    $zone = new DateTimeZone('Asia/Seoul');
    $time = (new DateTimeImmutable($day . ' 00:00:00', $zone))->getTimestamp();

    return $extra + [
        'time' => $time,
        'open' => $close - 1,
        'high' => $close + 1,
        'low' => $close - 2,
        'close' => $close,
        'volume' => $close * 10,
    ];
}

function ppWrite(string $dir, string $symbol, array $bars): string
{
    $path = $dir . '/' . $symbol . '_2y_1d_closed_v2.json';
    file_put_contents($path, json_encode($bars, JSON_UNESCAPED_UNICODE));

    return $path;
}

function ppSnap(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        if ($item->isFile()) {
            $out[$item->getPathname()] = hash_file('sha256', $item->getPathname());
        }
    }
    ksort($out);

    return $out;
}

function ppPost(array $extra = []): array
{
    return $extra + [
        'plan_form' => '1',
        'csrf' => '0123456789abcdef0123456789abcdef',
        'submit_id' => 's' . bin2hex(random_bytes(8)),
        'symbol' => '005930.KS',
        'hold' => '',
        'entry' => '전고점',
        'entry_frame' => '',
        'stop' => '',
        'stop_frame' => '',
        'target' => '',
        'note' => '',
        'source_url' => '',
        'as_of' => '2020-01-01T00:00',
    ];
}

$zone = new DateTimeZone('Asia/Seoul');
$bars = ppDir('bars');
$clockNow = new DateTimeImmutable('2026-11-18T10:00:00+09:00');
ppWrite($bars, '005930.KS', [
    ppBar('2026-11-16', 1016),
    ppBar('2026-11-17', 1017),
    ppBar('2026-11-18', 1018),
    ppBar('2026-11-19', 1019),
]);
foreach (['08:00:00', '12:00:00', '16:30:00'] as $clock) {
    $path = SwingPlan::afterRecord($bars, '005930.KS', '2026-11-16T' . $clock . '+09:00', $clockNow);
    $days = array_column($path['rows'], 'day');
    pp($path['start_day'] === '2026-11-17' && $days === ['2026-11-17'], 'a ' . $clock . ' record skips that session');
}
$past = SwingPlan::afterRecord($bars, '005930.KS', '2026-11-16T08:00:00+09:00', $clockNow);
pp($past['start_day'] === '2026-11-17' && !in_array('2026-11-19', array_column($past['rows'], 'day'), true) && !in_array('2026-11-18', array_column($past['rows'], 'day'), true), 'future and unfinished bars stay out');

ppWrite($bars, '000660.KS', [
    ppBar('2026-11-16', 2016),
    ppBar('2026-11-18', 2018),
    ppBar('2026-11-19', 2019),
]);
$second = SwingPlan::afterRecord($bars, '000660.KS', '2026-11-18T12:00:00+09:00', new DateTimeImmutable('2026-11-20T09:00:00+09:00'));
pp(array_column($second['rows'], 'day') === ['2026-11-19'] && $second['start_day'] === '2026-11-19', 'version two uses its own symbol and record time');

ppWrite($bars, '035720.KS', [ppBar('2026-11-17', 3017), ppBar('2026-11-19', 3019)]);
$gap = SwingPlan::afterRecord($bars, '035720.KS', '2026-11-16T08:00:00+09:00', new DateTimeImmutable('2026-11-20T09:00:00+09:00'));
pp(array_column($gap['rows'], 'day') === ['2026-11-17', '2026-11-19'], 'a missing day stays missing');

ppWrite($bars, '051910.KS', [ppBar('2026-11-19', 4019), ppBar('2026-11-20', 4020)]);
$late = SwingPlan::afterRecord($bars, '051910.KS', '2026-11-16T08:00:00+09:00', new DateTimeImmutable('2026-11-21T09:00:00+09:00'));
pp($late['start_day'] === '2026-11-17' && $late['rows'][0]['day'] === '2026-11-19', 'the first stored bar is not moved onto the start date');

$badBar = ppBar('2026-11-18', 5018);
$badBar['high'] = 1;
$badBar['low'] = 9;
ppWrite($bars, '068270.KS', [ppBar('2026-11-17', 5017), $badBar, ppBar('2026-11-19', 5019)]);
$invalid = SwingPlan::afterRecord($bars, '068270.KS', '2026-11-16T08:00:00+09:00', new DateTimeImmutable('2026-11-20T09:00:00+09:00'));
pp($invalid['invalid'] === 1 && array_column($invalid['rows'], 'day') === ['2026-11-17', '2026-11-19'], 'an invalid bar is skipped and the later bar stays');

ppWrite($bars, '012330.KS', [ppBar('2026-10-01', 6010)]);
$stale = SwingPlan::afterRecord($bars, '012330.KS', '2026-10-20T08:00:00+09:00', new DateTimeImmutable('2026-11-16T09:00:00+09:00'));
pp($stale['rows'] === [] && $stale['last_day'] === '2026-10-01', 'an old file keeps its last valid day');

$openBar = ppBar('2026-11-17', 7017, ['is_complete' => false]);
ppWrite($bars, '028260.KS', [$openBar, ppBar('2026-11-18', 7018)]);
$open = SwingPlan::afterRecord($bars, '028260.KS', '2026-11-16T08:00:00+09:00', new DateTimeImmutable('2026-11-20T09:00:00+09:00'));
pp($open['incomplete'] === 1 && array_column($open['rows'], 'day') === ['2026-11-18'], 'an unfinished bar is not filled in');

$absentDir = sys_get_temp_dir() . '/swing-path-absent-' . getmypid();
$absent = SwingPlan::afterRecord($absentDir, '005930.KS', '2026-11-16T08:00:00+09:00', $clockNow);
pp($absent['status'] === 'missing' && !is_dir($absentDir), 'a missing bar folder is not created');

$brokenPath = ppWrite($bars, '066570.KS', []);
file_put_contents($brokenPath, '{');
$brokenBytes = file_get_contents($brokenPath);
$broken = SwingPlan::afterRecord($bars, '066570.KS', '2026-11-16T08:00:00+09:00', $clockNow);
pp($broken['status'] === 'damaged' && file_get_contents($brokenPath) === $brokenBytes, 'a damaged bar file is not rewritten');

$plans = ppDir('plans');
$cookie = '0123456789abcdef0123456789abcdef';
$first = SwingPlan::accept($plans, ppPost(), $cookie, new DateTimeImmutable('2026-11-16T08:00:00+09:00'));
$revised = SwingPlan::accept($plans, ppPost([
    'plan_id' => $first['id'],
    'base_version' => '1',
    'reason' => '종목을 바꿈',
    'symbol' => '000660.KS',
    'as_of' => '2024-05-01T09:00',
]), $cookie, new DateTimeImmutable('2026-11-18T12:00:00+09:00'));
$viewNow = new DateTimeImmutable('2026-11-20T09:00:00+09:00');
$before = [ppSnap($plans), ppSnap($bars)];
$one = SwingPlan::section($plans, ['plan' => $first['id'], 'version' => '1'], null, $cookie, $bars, $viewNow);
$two = SwingPlan::section($plans, ['plan' => $first['id'], 'version' => '2'], null, $cookie, $bars, $viewNow);
pp([ppSnap($plans), ppSnap($bars)] === $before, 'looking up prices does not change plan or bar files');
pp(str_contains($one, '관찰 시작: 2026-11-17') && str_contains($one, '<td>2026-11-17</td>') && str_contains($one, '<td>1017</td>') && !str_contains($one, '<td>2026-11-16</td>') && !str_contains($one, '<td>2019</td>') && str_contains($one, '2020-01-01T00:00:00+09:00') && !str_contains($one, '2020-01-02'), 'the first version ignores the reference time');
pp(str_contains($two, '관찰 시작: 2026-11-19') && str_contains($two, '<td>2026-11-19</td>') && str_contains($two, '<td>2019</td>') && !str_contains($two, '<td>1017</td>') && !str_contains($two, '<td>2026-11-17</td>'), 'the second version keeps its own window');
pp(!str_contains($one, '다음 거래일') && !str_contains($one, '휴장') && !str_contains($one, '결측') && !str_contains($one, '연속된 거래일') && !str_contains($one, '수익률') && !str_contains($one, '체결'), 'the table does not judge gaps or results');

$long = [];
$cursor = new DateTimeImmutable('2026-01-02 00:00:00', $zone);
for ($i = 0; $i < 25; $i++) {
    $long[] = ppBar($cursor->modify('+' . $i . ' day')->format('Y-m-d'), 8000 + $i);
}
ppWrite($bars, '005380.KS', $long);
$saved = SwingPlan::accept($plans, ppPost(['symbol' => '005380.KS', 'as_of' => '']), $cookie, new DateTimeImmutable('2026-01-01T08:00:00+09:00'));
$again = [ppSnap($plans), ppSnap($bars)];
$page = SwingPlan::section($plans, ['plan' => $saved['id'], 'version' => '1'], null, $cookie, $bars, new DateTimeImmutable('2026-02-01T09:00:00+09:00'));
$next = SwingPlan::section($plans, ['plan' => $saved['id'], 'version' => '1', 'price_page' => '2'], null, $cookie, $bars, new DateTimeImmutable('2026-02-01T09:00:00+09:00'));
pp(str_contains($page, '<td>2026-01-02</td>') && str_contains($page, '<td>2026-01-21</td>') && !str_contains($page, '<td>2026-01-22</td>') && str_contains($page, '다음'), 'the first price page states what it cut');
pp(str_contains($next, '<td>2026-01-22</td>') && str_contains($next, '<td>2026-01-26</td>') && !str_contains($next, '<td>2026-01-02</td>') && str_contains($next, '이전'), 'the next page shows the rest');
pp([ppSnap($plans), ppSnap($bars)] === $again, 'paging does not change files');

$none = SwingPlan::section($plans, ['plan' => $saved['id'], 'version' => '9'], null, $cookie, $absentDir, $viewNow);
pp(str_contains($none, '보려는 버전이 없습니다') && !str_contains($none, '기록 이후 가격') && !is_dir($absentDir), 'a missing version does not read prices');
$damagedPlan = $plans . '/plans/p0123456789abcdef.json';
file_put_contents($damagedPlan, '{');
$damagedHtml = SwingPlan::section($plans, ['plan' => 'p0123456789abcdef', 'version' => '1'], null, $cookie, $absentDir, $viewNow);
pp(str_contains($damagedHtml, '손상되어 내용을 보여 주지 않습니다') && !str_contains($damagedHtml, '기록 이후 가격') && !is_dir($absentDir), 'a damaged plan does not read prices');

ppRemove($bars);
ppRemove($plans);
echo "PATH_DONE\n";
