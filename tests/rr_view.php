<?php
declare(strict_types=1);
require __DIR__ . '/../bin/bootstrap.php';
require __DIR__ . '/../bin/paper/RrView.php';

use ChartEntryLab\ChartPlanEngine;

function ck(bool $v, string $label): void
{
    if (!$v) {
        throw new RuntimeException($label);
    }
    echo "OK $label\n";
}

$bars = [];
$start = new DateTimeImmutable('2025-10-06');
for ($i = 0; $i < 60; $i++) {
    $close = $i < 30 ? 50 + $i : 80 + ($i - 30) * 0.4;
    if ($i >= 55) {
        $close = [88, 86, 84.5, 84, 89.8][$i - 55];
    }
    $close += 1000;
    $open = $i === 59 ? 1084.2 : $close - 0.2;
    $day = $start->modify('+' . $i . ' weekdays')->format('Y-m-d');
    $at = (new DateTimeImmutable($day . ' 15:30:00', new DateTimeZone('Asia/Seoul')))->getTimestamp();
    $bars[] = ['time' => $at, 'time_kst' => $day . ' 15:30:00', 'available_at' => $at, 'open' => $open, 'high' => $close + 0.8,
        'low' => min($open, $close) - 0.4, 'close' => $close, 'volume' => $i >= 56 && $i <= 58 ? 500 : ($i === 59 ? 1400 : 1000)];
}
$at = (int) end($bars)['available_at'];
$analysis = (new ChartPlanEngine())->analyze($bars, '005930.KS', $at);
$record = PaperRrAudit::capture(['yahoo' => '005930.KS', 'name' => '삼성전자', 'rank' => 3], [
    'ok' => true,
    'research_input' => ['bars' => $bars, 'analysis' => $analysis],
]);
$bundle = ['version' => PaperRrAudit::VERSION, 'records' => [$record], 'summary' => PaperRrAudit::summary([$record])];
$rows = PaperRrView::rows($bundle);
$pull = null;
foreach ($rows as $row) {
    if ($row['pattern'] === 'trend_pullback') {
        $pull = $row;
    }
}
ck(is_array($pull) && $pull['raw_status'] === 'rejected_rr' && $pull['included'] === true, 'view shows confirmed RR rejection');
ck(is_numeric($pull['limit']) && (float) $pull['limit'] < (float) $pull['entry'] && (float) $pull['limit_rr'] >= 1.5, 'view shows lower limit that meets 1.5');
ck($pull['blockers'] === '', 'no extra blocker on the eligible case');
ck(!isset($pull['bars']), 'view row does not carry candle history');
$blocked = $analysis;
$blocked['plan']['status'] = 'risk_blocked';
$blockedRecord = $record;
$blockedRecord['patterns'] = PaperRrAudit::evaluate($blocked, $record['quality']);
$blockedRows = PaperRrView::rows(['records' => [$blockedRecord]]);
$blockedPull = null;
foreach ($blockedRows as $row) {
    if ($row['pattern'] === 'trend_pullback') {
        $blockedPull = $row;
    }
}
ck(is_array($blockedPull) && str_contains($blockedPull['blockers'], '기존 위험 차단') && $blockedPull['included'] === false, 'other blockers stay visible');
$perf = PaperRrView::performance([$record], [], $at);
ck($perf['limit']['signals'] === 1 && $perf['baseline']['signals'] === 0, 'comparison counts research limit separately from baseline');
ck($perf['limit']['unfilled'] + $perf['limit']['signals'] >= 1, 'outcome is attached without writing an order');
$dir = sys_get_temp_dir() . '/rr-view-' . bin2hex(random_bytes(4));
mkdir($dir);
$name = '20260922-000000-' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($dir . '/' . $name, PaperRrAudit::encode($bundle));
file_put_contents($dir . '/ignore.txt', 'x');
ck(PaperRrView::files($dir) === [$name], 'only audit bundles are listed');
ck(PaperRrView::load($dir . '/' . $name)['version'] === PaperRrAudit::VERSION, 'bundle loads');
echo "RR_VIEW_PASS\n";
