<?php

declare(strict_types=1);

require __DIR__ . '/bin/bootstrap.php';

$cookie = (string) ($_COOKIE['swing_plan_csrf'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $cookie) !== 1) {
    $cookie = bin2hex(random_bytes(16));
    setcookie('swing_plan_csrf', $cookie, ['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
}
$dir = getenv('SWING_PLAN_DIR');
if (!is_string($dir) || $dir === '') {
    $dir = __DIR__ . '/data/swing-plans';
}
$bars = getenv('SWING_BAR_DIR');
if (!is_string($bars) || $bars === '') {
    $bars = __DIR__ . '/data/ohlcv';
}
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : null;
$html = ChartEntryLab\SwingResearch::view(__DIR__ . '/data/ohlcv', $_GET);
echo ChartEntryLab\SwingPlan::insert($html, ChartEntryLab\SwingPlan::section($dir, $_GET, is_array($post) ? $post : null, $cookie, $bars));
