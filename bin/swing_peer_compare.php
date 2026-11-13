<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$options = getopt('', ['experiment:']);
$name = $options['experiment'] ?? '';
$experiments = ['first' => 'swing-path-compare-2025h1', 'second' => 'swing-path-validate-20251010'];
if (!isset($experiments[$name])) {
    fwrite(STDERR, "Usage: php bin/swing_peer_compare.php --experiment=first|second\n");
    exit(1);
}
$root = dirname(__DIR__);
$output = $root . '/docs/swing-path-peer-compare/' . $name . '.json';
try {
    if (file_exists($output)) {
        throw new RuntimeException('Refusing to overwrite existing comparison: ' . $output);
    }
    $report = ChartEntryLab\SwingPeerCompare::run($root, $root . '/docs/' . $experiments[$name], $root . '/data/ohlcv');
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $handle = fopen($output, 'x');
    if ($handle === false) {
        throw new RuntimeException('Cannot create comparison output');
    }
    try {
        if (fwrite($handle, $json) !== strlen($json)) {
            throw new RuntimeException('Incomplete output write; do not use this report');
        }
    } finally {
        fclose($handle);
    }
    echo json_encode(['output' => $output, 'groups' => $report['groups'],
        'elapsed_ms' => $report['elapsed_ms'], 'peak_bytes' => $report['peak_bytes']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
