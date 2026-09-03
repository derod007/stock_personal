<?php

declare(strict_types=1);

/**
 * 중요한 에펨 글을 본문·전체 댓글·지연 로딩 이미지까지 별도 JSON으로 보존.
 *
 * Usage:
 *   php bin/archive_important_post.php 10237234875
 *   php bin/archive_important_post.php --refresh 10237234875
 */

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\FmkoreaClient;
use ChartEntryLab\FmkoreaPostParser;

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$refresh = in_array('--refresh', $args, true);
$srl = '';
foreach ($args as $arg) {
    if ($arg === '--refresh') {
        continue;
    }
    $digits = preg_replace('/\D+/', '', $arg) ?? '';
    if ($digits !== '') {
        $srl = $digits;
        break;
    }
}
if ($srl === '') {
    fwrite(STDERR, "Usage: php bin/archive_important_post.php [--refresh] SRL\n");
    exit(1);
}

$client = new FmkoreaClient($root . '/data/raw/cache', delaySeconds: 1.8);
$pages = $client->fetchDocumentPages($srl, useCache: !$refresh);
$post = (new FmkoreaPostParser())->parsePages($pages, '노라무');
$post['document_srl'] ??= $srl;

$archive = [
    'schema_version' => 1,
    'importance' => 'critical_lesson',
    'source_url' => 'https://www.fmkorea.com/' . $srl,
    'archived_at_kst' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format(DATE_ATOM),
    'comment_page_count' => max(0, count($pages) - 1),
    'image_count' => count($post['images'] ?? []),
    'comment_count' => count($post['comments'] ?? []),
    'post' => $post,
];

$dir = $root . '/data/important_posts';
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    throw new RuntimeException('Failed to create archive directory: ' . $dir);
}
$path = $dir . '/' . $srl . '.json';
$json = json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($path, $json . PHP_EOL) === false) {
    throw new RuntimeException('Failed to write archive: ' . $path);
}

echo json_encode([
    'path' => str_replace('\\', '/', substr($path, strlen($root) + 1)),
    'title' => $post['title'] ?? '',
    'comments' => $archive['comment_count'],
    'author_comments' => count($post['author_comments'] ?? []),
    'images' => $archive['image_count'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
