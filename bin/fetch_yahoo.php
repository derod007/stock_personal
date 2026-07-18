<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\YahooChartClient;

$symbol = $argv[1] ?? null;
$range = $argv[2] ?? '3mo';
$interval = $argv[3] ?? '1d';

if ($symbol === null) {
    fwrite(STDERR, "Usage: php bin/fetch_yahoo.php SYMBOL [range] [interval]\n");
    exit(1);
}

$client = new YahooChartClient(dirname(__DIR__) . '/data/ohlcv');
$rows = $client->fetch($symbol, $range, $interval, useCache: false);
echo sprintf("Fetched %d bars for %s (%s/%s)\n", count($rows), $symbol, $range, $interval);
echo "Last: " . json_encode($rows[array_key_last($rows)], JSON_UNESCAPED_UNICODE) . PHP_EOL;
