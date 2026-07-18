<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryRepository;
use ChartEntryLab\EntrySignalExtractor;
use ChartEntryLab\FmkoreaClient;
use ChartEntryLab\FmkoreaPostParser;
use ChartEntryLab\FmkoreaSearchParser;
use ChartEntryLab\NoramuScraper;

$root = dirname(__DIR__);
$maxPages = 5;
$refresh = false;
$dryRun = false;
$nick = '노라무';

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--pages=(\d+)$/', $arg, $m)) {
        $maxPages = (int) $m[1];
    } elseif ($arg === '--refresh') {
        $refresh = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--nick=(.+)$/u', $arg, $m)) {
        $nick = $m[1];
    } elseif ($arg === '--help' || $arg === '-h') {
        echo <<<TXT
Usage:
  php bin/scrape_noramu.php [--pages=5] [--refresh] [--dry-run] [--nick=노라무]

Options:
  --pages=N   닉네임 검색 페이지 수 (기본 5, 너무 크면 차단될 수 있음)
  --refresh   HTML 캐시 무시하고 재요청
  --dry-run   entries.json에 쓰지 않고 통계/미리보기만
  --nick=     기본 노라무

TXT;
        exit(0);
    }
}

$scraper = new NoramuScraper(
    new FmkoreaClient($root . '/data/raw/fmkorea'),
    new FmkoreaSearchParser(),
    new FmkoreaPostParser(),
    new EntrySignalExtractor(),
    new EntryRepository($root . '/data/entries.json'),
);

echo "Scraping nick={$nick} pages={$maxPages} refresh=" . ($refresh ? 'yes' : 'no') . " dryRun=" . ($dryRun ? 'yes' : 'no') . PHP_EOL;

try {
    $stats = $scraper->run($nick, $maxPages, $refresh, $dryRun);
    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (($stats['events_added'] ?? 0) > 0 || $dryRun) {
        echo "entries: {$root}/data/entries.json" . PHP_EOL;
    }
    exit($stats['errors'] === [] ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
