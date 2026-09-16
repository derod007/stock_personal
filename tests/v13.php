<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bin/bootstrap.php';
require dirname(__DIR__) . '/bin/paper/NaverSessionPatch.php';

function v13(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException($name);
    }
    echo "OK {$name}\n";
}

$quotes = [
    '2026-09-15' => ['date' => '2026-09-15', 'open' => 100.0, 'high' => 110.0, 'low' => 90.0, 'close' => 105.0, 'volume' => 123],
];
$day = new DateTimeImmutable('2026-09-15 09:00:00', new DateTimeZone('Asia/Seoul'));
$bars = [[
    'time' => $day->getTimestamp(),
    'time_kst' => $day->format('Y-m-d H:i:s'),
    'open' => 1.0, 'high' => 2.0, 'low' => 0.5, 'close' => 1.5, 'volume' => 9,
]];
[$patched, $count] = PaperNaverSessionPatch::patchBars($bars, $quotes);
v13($count === 1, 'same-day bar overlayed');
v13($patched[0]['close'] === 105.0 && $patched[0]['volume'] === 123, '본장 OHLC+volume applied');
v13($patched[0]['session'] === 'krx_regular', 'session tag');
v13($patched[0]['time_kst'] === '2026-09-15 15:30:00', 'close stamped at 15:30 KST');

$midnight = new DateTimeImmutable('2026-09-16 00:30:00', new DateTimeZone('Asia/Seoul'));
$late = [[
    'time' => $midnight->getTimestamp(),
    'time_kst' => $midnight->format('Y-m-d H:i:s'),
    'open' => 1.0, 'high' => 2.0, 'low' => 0.5, 'close' => 1.5, 'volume' => 9,
]];
[$patchedLate, $lateCount] = PaperNaverSessionPatch::patchBars($late, $quotes);
v13($lateCount === 1 && $patchedLate[0]['close'] === 105.0, 'pre-open next calendar day maps to previous 본장');

$us = [[
    'time' => $day->getTimestamp(),
    'time_kst' => $day->format('Y-m-d H:i:s'),
    'open' => 10.0, 'high' => 11.0, 'low' => 9.0, 'close' => 10.5, 'volume' => 4,
]];
[$untouched, $none] = PaperNaverSessionPatch::patchBars($us, []);
v13($none === 0 && $untouched[0]['close'] === 10.5, 'no quotes leaves Yahoo bars');

$php = PHP_BINARY;
$win = dirname(__DIR__) . '/bin/paper_schedule_window.php';
$cases = [
    ['kr', '2026-09-16T20:19:00+09:00', 10],
    ['kr', '2026-09-16T20:20:00+09:00', 0],
    ['kr', '2026-09-16T23:59:00+09:00', 0],
    ['kr', '2026-09-17T00:00:00+09:00', 10],
    ['kr', '2026-09-19T21:00:00+09:00', 10],
    ['us', '2026-09-16T06:19:00+09:00', 10],
    ['us', '2026-09-16T06:20:00+09:00', 0],
    ['us', '2026-09-16T11:59:00+09:00', 0],
    ['us', '2026-09-16T12:00:00+09:00', 10],
];
foreach ($cases as [$market, $now, $want]) {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($win) . ' ' . escapeshellarg($market) . ' --now=' . escapeshellarg($now);
    exec($cmd, $out, $code);
    v13($code === $want, "window {$market} {$now} => {$want}");
    $out = [];
}
exec(escapeshellarg($php) . ' ' . escapeshellarg($win) . ' kr --force --now=' . escapeshellarg('2026-09-16T10:00:00+09:00'), $fout, $fcode);
v13($fcode === 0, 'force ignores window');

echo "v13: naver session patch and schedule window passed\n";
