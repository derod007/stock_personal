<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryRepository;
use ChartEntryLab\ProposalService;
use ChartEntryLab\YahooChartClient;

$root = dirname(__DIR__);
$profileId = 'account1';
$symbol = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--profile=')) {
        $profileId = substr($arg, strlen('--profile='));
        continue;
    }
    if ($symbol === null) {
        $symbol = $arg;
    }
}

if ($symbol === null) {
    fwrite(STDERR, "Usage: php bin/score_symbol.php SYMBOL [--profile=account1|custom|isa]\n");
    exit(1);
}

$service = new ProposalService(
    new YahooChartClient($root . '/data/ohlcv'),
    profileId: $profileId,
    entries: new EntryRepository($root . '/data/entries.json'),
);
$result = $service->propose($symbol, useCache: false);
if (!$result['ok']) {
    fwrite(STDERR, ($result['error'] ?? 'failed') . PHP_EOL);
    exit(1);
}

$explain = $result['proposal']['explain'] ?? null;
$newEntry = $result['proposal']['new_entry'] ?? null;
if (is_array($newEntry)) {
    fwrite(STDERR, '【적정 신규 진입】 ' . (string) ($newEntry['sentence'] ?? '') . "\n");
    if (($newEntry['note'] ?? '') !== '') {
        fwrite(STDERR, (string) $newEntry['note'] . "\n");
    }
}
if (is_array($explain)) {
    fwrite(STDERR, '—— ' . (string) ($explain['action_label'] ?? '') . " ——\n");
    fwrite(STDERR, (string) ($explain['price_vs_zone_label'] ?? '') . "\n\n");
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
