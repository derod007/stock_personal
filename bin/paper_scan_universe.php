<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryRepository;
use ChartEntryLab\KrAmountLeadersClient;
use ChartEntryLab\KrAmountScanner;
use ChartEntryLab\PaperJournal;
use ChartEntryLab\PaperScanUniverse;
use ChartEntryLab\ProposalService;
use ChartEntryLab\YahooChartClient;

$o = getopt('', ['config:', 'limit:', 'cache']);
if (empty($o['config'])) {
    throw new InvalidArgumentException('--config required');
}
$config = json_decode((string) file_get_contents($o['config']), true, 512, JSON_THROW_ON_ERROR);
if (($config['universe'] ?? '') !== 'kr_amount_scan') {
    throw new InvalidArgumentException('Scan universe requires universe=kr_amount_scan');
}
$limit = isset($o['limit']) ? (int) $o['limit'] : (int) ($config['scan_limit'] ?? 100);
$limit = max(1, min(200, $limit));
$useCache = array_key_exists('cache', $o);

@set_time_limit(2400);
@ini_set('max_execution_time', '2400');

$root = dirname(__DIR__);
$cacheDir = $root . '/data/raw/cache';
$service = new ProposalService(
    new YahooChartClient($root . '/data/ohlcv'),
    profileId: 'account1',
    entries: new EntryRepository($root . '/data/entries.json'),
);
$scanner = new KrAmountScanner(new KrAmountLeadersClient($cacheDir), $service, $cacheDir);
$report = $scanner->scan(
    limit: $limit,
    useCache: $useCache,
    useYahooCache: $useCache,
    yahooMaxAgeSeconds: $useCache ? 600 : 0,
    onProgress: static function (int $i, int $total, string $yahoo, string $name): void {
        fwrite(STDERR, sprintf("[%d/%d] %s %s\n", $i, $total, $yahoo, $name));
    },
);
if (empty($report['ok'])) {
    fwrite(STDERR, 'Amount scan failed: ' . (string) ($report['error'] ?? 'unknown') . PHP_EOL);
    echo json_encode(['ok' => false, 'error' => $report['error'] ?? 'scan_failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

$directory = getenv('PAPER_STATE_DIR') ?: dirname(__DIR__, 2) . '/stock-personal-paper';
$path = $directory . '/' . $config['id'] . '-forward.json';
$held = [];
$heldSectors = [];
if (is_file($path)) {
    $state = (new PaperJournal($path))->read()['state'] ?? [];
    $held = array_keys($state['active'] ?? []);
    $heldSectors = ($state['sectors'] ?? []) + ($state['config']['symbols'] ?? []);
}

$symbols = PaperScanUniverse::symbols($report, $held, $heldSectors);
$candidates = [];
foreach ($report['rows'] as $row) {
    if (!empty($row['entry_recommend']) || !empty($row['buy_now'])) {
        $candidates[] = (string) $row['yahoo'];
    }
}

echo json_encode([
    'ok' => true,
    'symbols' => $symbols === [] ? new \stdClass() : $symbols,
    'held' => array_values($held),
    'candidates' => $candidates,
    'summary' => $report['summary'] ?? null,
    'fetched_at' => $report['fetched_at'] ?? null,
    'limit' => $limit,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
