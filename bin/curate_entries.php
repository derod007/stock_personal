<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryCurator;
use ChartEntryLab\EntryRepository;

$root = dirname(__DIR__);
$path = $root . '/data/entries.json';
$dryRun = in_array('--dry-run', $argv, true);

$repo = new EntryRepository($path);
$curator = new EntryCurator();
$result = $curator->curate($repo->all());

if (!$dryRun) {
    $repo->writeAll($result['entries']);
}

echo ($dryRun ? '[dry-run] ' : '') . 'curated entries_count=' . count($result['entries']) . PHP_EOL;
foreach ($result['summary'] as $key => $count) {
    echo sprintf("  %s=%d\n", $key, $count);
}

$byUse = [];
foreach ($result['entries'] as $e) {
    $use = (string) ($e['learning_use'] ?? '?');
    $byUse[$use][] = sprintf(
        '%s | %s | %s',
        $e['id'] ?? '?',
        $e['symbol'] ?? '?',
        implode(',', $e['learning_reasons'] ?? [])
    );
}

foreach (['needs_review', 'structure_only', 'full', 'ignore'] as $bucket) {
    if (empty($byUse[$bucket])) {
        continue;
    }
    echo PHP_EOL . "--- {$bucket} ---" . PHP_EOL;
    foreach ($byUse[$bucket] as $line) {
        echo $line . PHP_EOL;
    }
}
