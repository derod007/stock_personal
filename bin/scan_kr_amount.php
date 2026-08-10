<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryRepository;
use ChartEntryLab\KrAmountLeadersClient;
use ChartEntryLab\KrAmountScanner;
use ChartEntryLab\ProposalService;
use ChartEntryLab\YahooChartClient;

$root = dirname(__DIR__);
$profileId = 'account1';
$limit = 100;
$useCache = true;
$leadersOnly = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--profile=')) {
        $profileId = substr($arg, strlen('--profile='));
        continue;
    }
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, strlen('--limit='));
        continue;
    }
    if ($arg === '--no-cache') {
        $useCache = false;
        continue;
    }
    if ($arg === '--leaders-only') {
        $leadersOnly = true;
        continue;
    }
}

$cacheDir = $root . '/data/raw/cache';
$leaders = new KrAmountLeadersClient($cacheDir);

if ($leadersOnly) {
    $rows = $leaders->topByAmount($limit, useCache: $useCache);
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$service = new ProposalService(
    new YahooChartClient($root . '/data/ohlcv'),
    profileId: $profileId,
    entries: new EntryRepository($root . '/data/entries.json'),
);
$scanner = new KrAmountScanner($leaders, $service, $cacheDir);

$report = $scanner->scan(
    limit: $limit,
    useCache: $useCache,
    useYahooCache: $useCache,
    yahooMaxAgeSeconds: $useCache ? 600 : 0,
    onProgress: static function (int $i, int $total, string $yahoo, string $name): void {
        fwrite(STDERR, sprintf("[%d/%d] %s %s\n", $i, $total, $yahoo, $name));
    },
);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['ok'] ? 0 : 1);
