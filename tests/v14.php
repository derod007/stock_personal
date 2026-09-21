<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bin/bootstrap.php';

use ChartEntryLab\PaperJournal;
use ChartEntryLab\PaperPortfolio;
use ChartEntryLab\PaperScanUniverse;

function v14(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException($name);
    }
    echo "OK {$name}\n";
}

$report = [
    'ok' => true,
    'rows' => [
        ['yahoo' => '005930.KS', 'entry_recommend' => true, 'buy_now' => false, 'sector_bucket' => 'semi'],
        ['yahoo' => '000660.KS', 'entry_recommend' => false, 'buy_now' => false, 'sector_bucket' => 'semi'],
        ['yahoo' => '005380.KS', 'entry_recommend' => false, 'buy_now' => true, 'sector' => 'auto'],
    ],
];
$picked = PaperScanUniverse::symbols($report, ['000660.KS'], ['000660.KS' => 'semi']);
v14(isset($picked['005930.KS'], $picked['005380.KS'], $picked['000660.KS']), 'recommendations plus held');
v14($picked['000660.KS'] === 'semi', 'held keeps sector');
$fresh = PaperScanUniverse::symbols($report, []);
v14(!isset($fresh['000660.KS']) && isset($fresh['005930.KS'], $fresh['005380.KS']), 'non-recommend excluded');

$a = ['id' => 'paper-kr', 'universe' => 'kr_amount_scan', 'scan_limit' => 100, 'symbols' => ['005930.KS' => 'semi']];
$b = ['id' => 'paper-kr', 'universe' => 'kr_amount_scan', 'scan_limit' => 100, 'symbols' => ['000660.KS' => 'semi']];
v14(
    hash('sha256', PaperJournal::encode(PaperScanUniverse::pinned($a)))
    === hash('sha256', PaperJournal::encode(PaperScanUniverse::pinned($b))),
    'daily names do not change pinned hash'
);
$us = ['id' => 'paper-us', 'symbols' => ['MU' => 'semi']];
$us2 = ['id' => 'paper-us', 'symbols' => ['AAPL' => 'tech']];
v14(
    hash('sha256', PaperJournal::encode(PaperScanUniverse::pinned($us)))
    !== hash('sha256', PaperJournal::encode(PaperScanUniverse::pinned($us2))),
    'fixed US watchlist stays pinned'
);

$cfg = [
    'id' => 'paper-kr', 'currency' => 'KRW', 'initial_cash' => 1000, 'max_positions' => 1,
    'risk_pct' => 0.01, 'position_pct' => 0.2, 'sector_pct' => 0.4, 'total_risk_pct' => 0.03,
    'universe' => 'kr_amount_scan', 'symbols' => [],
];
$state = PaperPortfolio::start($cfg, 'v', 'forward');
v14($state['sectors'] === [] && $state['cash'] === 1000.0, 'scan account can start without a watchlist');
try {
    unset($cfg['universe']);
    PaperPortfolio::start($cfg, 'v', 'forward');
    v14(false, 'empty symbols without universe');
} catch (InvalidArgumentException $e) {
    v14(true, 'empty symbols without universe rejected');
}

echo "v14: paper scan universe passed\n";

$outside = PaperScanUniverse::symbols(['ok' => true, 'rows' => []], ['035720.KS'], ['035720.KS' => 'internet']);
v14($outside === ['035720.KS' => 'internet'], 'holding outside scan remains without recommendations');
v14(PaperScanUniverse::symbols(['ok' => true, 'rows' => []]) === [], 'empty scan has no fixed fallback');
$dynamic = PaperScanUniverse::symbols(['ok' => true, 'rows' => [
    ['yahoo' => '123456.KQ', 'entry_recommend' => true, 'sector_bucket' => 'fixture'],
    ['yahoo' => '654321.KQ', 'buy_now' => true, 'sector_bucket' => 'fixture'],
    ['yahoo' => '005930.KS', 'score' => 100, 'entry_recommend' => false, 'buy_now' => false],
]]);
v14(array_keys($dynamic) === ['123456.KQ', '654321.KQ'], 'only recommended names, not a fixed three or ten');
$liveConfig = json_decode(file_get_contents(__DIR__ . '/../config/paper-kr.json'), true, 512, JSON_THROW_ON_ERROR);
v14($liveConfig['id'] === 'paper-kr' && $liveConfig['universe'] === 'kr_amount_scan'
    && $liveConfig['scan_limit'] === 100 && $liveConfig['symbols'] === [], 'KR operating config remains TOP100 dynamic');
// An existing holding still reaches the unchanged stop/fee engine without a scan recommendation.
$s = PaperPortfolio::start($liveConfig, 'fixture', 'forward');
$at = strtotime('2026-09-17 15:30:00 Asia/Seoul');
$plan = ['ready' => true, 'entry' => 100.0, 'stop' => 95.0, 'target' => 110.0,
    'signal_at' => $at - 2 * 86400, 'order_valid_bars' => 3];
$s['cash'] -= 1001.0;
$s['active']['035720.KS'] = ['plan' => $plan, 'history' => [
    ['available_at' => $at - 86400, 'open' => 100.0, 'high' => 103.0, 'low' => 99.0, 'close' => 101.0],
], 'quantity' => 10, 'reservation' => 1001.0, 'planned_risk' => 51.0, 'filled' => true,
    'entry_fill' => 100.0, 'entry_cost' => 1001.0, 'recorded_at' => $at - 2 * 86400, 'snapshot_key' => 'fixture'];
$s['sectors'] = $outside;
$events = [];
PaperPortfolio::advance($s, $at,
    ['035720.KS' => ['available_at' => $at, 'open' => 96.0, 'high' => 97.0, 'low' => 94.0, 'close' => 94.5]],
    ['035720.KS' => ['symbol' => '035720.KS', 'session' => $at, 'recorded_at' => $at + 10,
        'origin' => 'forward', 'input_hash' => 'fixture', 'plan' => ['ready' => false],
        'quality' => ['can_simulate' => true]]],
    function ($type, $payload) use (&$events) { $events[] = ['type' => $type, 'payload' => $payload]; }
);
v14($s['closed_trades'] === 1 && $s['active'] === [] && $s['realized'] < 0, 'off-scan holding still stops out');
v14(count(array_filter($events, fn($e) => $e['type'] === 'order')) === 0, 'no new order without confirmed plan');
echo "v14: dynamic universe additional checks passed\n";
