<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\CandleClock;
use ChartEntryLab\ChartPlanEngine;
use ChartEntryLab\EntryBacktester;
use ChartEntryLab\TradeSimulator;
use ChartEntryLab\SymbolMap;
use ChartEntryLab\YahooChartClient;

// Optional local OHLCV JSON permits reproducible, offline evaluation.
$opts = getopt('', ['symbol:', 'file:', 'split:', 'holding:', 'fee-bps:', 'slippage-bps:', 'profile:']);
$symbol = SymbolMap::resolveInput((string) ($opts['symbol'] ?? 'MU'));
if ($symbol === null) {
    throw new InvalidArgumentException('Unknown symbol');
}
$bars = isset($opts['file']) ? json_decode((string) file_get_contents($opts['file']), true, 512, JSON_THROW_ON_ERROR)
    : (new YahooChartClient(dirname(__DIR__) . '/data/ohlcv'))->fetch($symbol, '5y', '1d', useCache: true);
$bars = CandleClock::completed($bars, $symbol, time());
if (count($bars) < 80) {
    throw new RuntimeException('Need at least 80 completed bars');
}
$split = isset($opts['split']) ? (new DateTimeImmutable($opts['split'], new DateTimeZone('Asia/Seoul')))->getTimestamp()
    : $bars[(int) floor(count($bars) * 0.7)]['available_at'];
$holding = (int) ($opts['holding'] ?? 10);
$engine = new ChartPlanEngine();
$sim = new TradeSimulator((float) ($opts['fee-bps'] ?? 10), (float) ($opts['slippage-bps'] ?? 5));
$groups = ['development' => [], 'holdout' => []];
$results = [];
$busyUntil = 0;
for ($i = 39; $i < count($bars); $i++) {
    $asOf = $bars[$i]['available_at'];
    if ($asOf <= $busyUntil) {
        continue;
    }
    $plan = $engine->analyze(array_slice($bars, 0, $i + 1), $symbol, $asOf, (string) ($opts['profile'] ?? 'account1'))['plan'];
    if (!$plan['ready']) {
        continue;
    }
    $group = $asOf < $split ? 'development' : 'holdout';
    $forward = array_slice($bars, $i + 1);
    if ($group === 'development') {
        // Never let training-period trade outcomes consume holdout prices.
        $forward = array_values(array_filter($forward, static fn($b): bool => $b['available_at'] < $split));
    }
    $trade = $sim->simulate($plan, $forward, $holding);
    $groups[$group][] = $trade;
    $results[] = ['group' => $group, 'plan' => $plan, 'trade' => $trade];
    // Single pending order/position per symbol; no overlapping copies of the same trade.
    $endIndex = min(count($bars) - 1, $i + ($plan['order_valid_bars'] ?? 3));
    $busyUntil = $trade['exit_at'] ?? ($trade['filled'] ? $bars[array_key_last($bars)]['available_at'] : $bars[$endIndex]['available_at']);
    if ($group === 'development' && $busyUntil >= $split) {
        $busyUntil = $split - 1;
    }
}
$reporter = new EntryBacktester();
echo json_encode(['symbol' => $symbol, 'version' => ChartEntryLab\BreakoutRetest::VERSION,
    'split_at' => $split, 'holding_bars' => $holding,
    'notes' => 'Fixed-rule research. Means are per closed trade, not portfolio returns. No tuning or author imitation claim.',
    'summary' => array_map(static fn($g) => $reporter->aggregate($g), $groups), 'results' => $results],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
