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
