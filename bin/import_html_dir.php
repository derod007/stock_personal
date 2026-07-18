<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryRepository;
use ChartEntryLab\EntrySignalExtractor;
use ChartEntryLab\FmkoreaPostParser;

/**
 * 브라우저로 저장한 게시글 HTML 디렉터리를 파싱해 entries.json에 병합.
 *
 * Usage:
 *   php bin/import_html_dir.php data/raw/browser_posts
 */

$root = dirname(__DIR__);
$dir = $argv[1] ?? ($root . '/data/raw/browser_posts');
if (!is_dir($dir)) {
    fwrite(STDERR, "Directory not found: {$dir}\n");
    exit(1);
}

$parser = new FmkoreaPostParser();
$extractor = new EntrySignalExtractor();
$repo = new EntryRepository($root . '/data/entries.json');

$files = glob($dir . '/*.{html,htm}', GLOB_BRACE) ?: [];
$allEvents = [];
foreach ($files as $file) {
    $html = (string) file_get_contents($file);
    $parsed = $parser->parse($html);
    if (($parsed['document_srl'] ?? null) === null && preg_match('/(\d{8,})/', basename($file), $m)) {
        $parsed['document_srl'] = $m[1];
    }
    $events = $extractor->extract($parsed);
    foreach ($events as $e) {
        $e['import_file'] = basename($file);
        $allEvents[] = $e;
    }
    echo basename($file) . ' => ' . count($events) . " events\n";
}

$added = $repo->mergeMany($allEvents);
echo json_encode([
    'files' => count($files),
    'events' => count($allEvents),
    'added_or_updated_new_ids' => $added,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
