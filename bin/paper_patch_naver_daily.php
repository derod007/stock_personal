<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/paper/NaverSessionPatch.php';

use ChartEntryLab\NaverDailyQuotes;

$o = getopt('', ['dir:']);
if (empty($o['dir'])) {
    throw new InvalidArgumentException('--dir required');
}
$dir = $o['dir'];
if (!is_dir($dir)) {
    throw new InvalidArgumentException('Invalid --dir');
}

$naver = new NaverDailyQuotes(dirname(__DIR__) . '/data/raw/cache/naver', 60);
$result = PaperNaverSessionPatch::applyDirectory($dir, $naver);
echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
