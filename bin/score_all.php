<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\Account1Playbook;
use ChartEntryLab\AccountProfile;
use ChartEntryLab\EntryRepository;
use ChartEntryLab\ProposalService;
use ChartEntryLab\YahooChartClient;

$root = dirname(__DIR__);
$profileId = 'account1';
$symbols = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--profile=')) {
        $profileId = substr($arg, strlen('--profile='));
        continue;
    }
    $symbols[] = $arg;
}
$service = new ProposalService(
    new YahooChartClient($root . '/data/ohlcv'),
    profileId: $profileId,
    entries: new EntryRepository($root . '/data/entries.json'),
);
if ($symbols === []) {
    $symbols = AccountProfile::fromId($profileId)->coreSymbols ?? Account1Playbook::CORE_SYMBOLS;
}

$report = [];
foreach ($symbols as $symbol) {
    $result = $service->propose($symbol, useCache: true);
    if (!$result['ok']) {
        $report[] = [
            'symbol' => $symbol,
            'error' => $result['error'],
        ];
        continue;
    }
    $p = $result['proposal'] ?? [];
    $ne = is_array($p['new_entry'] ?? null) ? $p['new_entry'] : null;
    $report[] = [
        'symbol' => $result['symbol'],
        'score' => $p['score'] ?? null,
        'price' => $p['price'] ?? null,
        'action' => $p['action'] ?? null,
        'new_entry_sentence' => $ne['sentence'] ?? null,
        'new_entry' => $ne,
        'entry_zone' => $p['entry_zone'] ?? null,
        'invalidation' => $p['invalidation'] ?? null,
        'target_hint' => $p['target_hint'] ?? null,
        'size_hint' => $p['size_hint'] ?? null,
        'reason' => $p['reason'] ?? null,
    ];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
