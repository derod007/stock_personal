<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$options = getopt('', ['dir:', 'symbols:', 'confirm-from:', 'confirm-to:', 'as-of:']);
foreach (['dir', 'symbols', 'confirm-from', 'confirm-to', 'as-of'] as $key) {
    if (!isset($options[$key]) || $options[$key] === '') {
        fwrite(STDERR, "필요: --dir --symbols --confirm-from --confirm-to --as-of\n");
        exit(1);
    }
}
$symbols = array_values(array_filter(array_map('trim', explode(',', (string) $options['symbols'])), static fn (string $symbol): bool => $symbol !== ''));
$report = ChartEntryLab\SwingPathTally::run(
    (string) $options['dir'],
    $symbols,
    (string) $options['confirm-from'],
    (string) $options['confirm-to'],
    (string) $options['as-of']
);
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
exit($report['errors'] === [] ? 0 : 1);
