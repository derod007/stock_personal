<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryRepository;

$repo = new EntryRepository(dirname(__DIR__) . '/data/entries.json');
$rows = $repo->all();
echo 'entries_count=' . count($rows) . PHP_EOL;
foreach ($rows as $r) {
    echo sprintf(
        "%s | %s | side=%s | entry=%s | stop=%s | tp=%s | %s\n",
        $r['id'] ?? '?',
        $r['symbol'] ?? '?',
        $r['side'] ?? '?',
        $r['entry_price'] ?? '-',
        $r['stop_price'] ?? ($r['stop_rule'] ?? '-'),
        $r['target_price'] ?? '-',
        mb_substr((string) ($r['raw_quote'] ?? ''), 0, 48)
    );
}
