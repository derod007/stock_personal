<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\Account1Playbook;
use ChartEntryLab\FeatureEngine;
use ChartEntryLab\YahooChartClient;

$symbol = $argv[1] ?? null;
if ($symbol === null) {
    fwrite(STDERR, "Usage: php bin/score_symbol.php SYMBOL\n");
    exit(1);
}

$client = new YahooChartClient(dirname(__DIR__) . '/data/ohlcv');
$engine = new FeatureEngine();
$playbook = new Account1Playbook();

$bars = $client->fetch($symbol, '3mo', '1d', useCache: false);
$features = $engine->extract($bars);
$decision = $playbook->decide($features, $symbol);

echo json_encode([
    'symbol' => $symbol,
    'features' => $features,
    'account1_decision' => $decision,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
