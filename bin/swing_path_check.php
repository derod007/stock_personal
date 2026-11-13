<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$root = dirname(__DIR__);
$errors = ChartEntryLab\SwingPathCheck::verify(
    $root . '/docs/swing-path-compare-2025h1/batches',
    $root . '/docs/swing-path-compare-2025h1/summary.json'
);
if ($errors === []) {
    echo "CHECK_OK\n";
    exit(0);
}
foreach ($errors as $error) {
    fwrite(STDERR, $error . "\n");
}
exit(1);
