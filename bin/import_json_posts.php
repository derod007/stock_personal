<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryRepository;
use ChartEntryLab\EntrySignalExtractor;

/**
 * 브라우저/수동으로 뽑은 게시글 JSON 배열을 entries로 변환.
 *
 * 파일 형식: list of {
 *   document_srl, title, posted_at_kst, body, author_comments: string[]
 * }
 */

$root = dirname(__DIR__);
$file = $argv[1] ?? ($root . '/data/raw/browser_posts.json');
if (!is_file($file)) {
    fwrite(STDERR, "File not found: {$file}\n");
    exit(1);
}

/** @var list<array<string,mixed>> $posts */
$posts = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$extractor = new EntrySignalExtractor();
$repo = new EntryRepository($root . '/data/entries.json');

$all = [];
foreach ($posts as $post) {
    $normalized = [
        'document_srl' => isset($post['document_srl']) ? (string) $post['document_srl'] : null,
        'title' => (string) ($post['title'] ?? ''),
        'author' => (string) ($post['author'] ?? '노라무'),
        'posted_at_kst' => $post['posted_at_kst'] ?? null,
        'body' => (string) ($post['body'] ?? ''),
        'author_comments' => array_values(array_map('strval', $post['author_comments'] ?? [])),
    ];
    $events = $extractor->extract($normalized);
    echo ($normalized['document_srl'] ?? '?') . ' ' . $normalized['title'] . ' => ' . count($events) . "\n";
    foreach ($events as $e) {
        $all[] = $e;
    }
}

$added = $repo->mergeMany($all);
echo json_encode(['posts' => count($posts), 'events' => count($all), 'new_ids' => $added], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
