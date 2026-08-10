<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryRepository;

$repo = new EntryRepository(dirname(__DIR__) . '/data/entries.json');
$rows = $repo->all();
$filter = $argv[1] ?? null; // full|structure_only|needs_review|ignore

echo 'entries_count=' . count($rows) . PHP_EOL;

$counts = [];
foreach ($rows as $r) {
    $use = (string) ($r['learning_use'] ?? 'unset');
    $counts[$use] = ($counts[$use] ?? 0) + 1;
}
echo 'learning_use: ' . json_encode($counts, JSON_UNESCAPED_UNICODE) . PHP_EOL;

foreach ($rows as $r) {
    $use = (string) ($r['learning_use'] ?? 'unset');
    if ($filter !== null && $use !== $filter) {
        continue;
    }
    echo sprintf(
        "%s | %s | use=%s | exit=%s | side=%s | entry=%s | stop=%s | tp=%s | %s\n",
        $r['id'] ?? '?',
        $r['symbol'] ?? '?',
        $use,
        $r['exit_reason'] ?? '-',
        $r['side'] ?? '?',
        $r['entry_price'] ?? '-',
        $r['stop_price'] ?? ($r['stop_rule'] ?? '-'),
        $r['target_price'] ?? '-',
        mb_substr((string) ($r['raw_quote'] ?? ''), 0, 48)
    );
}
