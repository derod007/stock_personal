<?php

declare(strict_types=1);

/**
 * 프로필 문턱 이상 종목 알림(콘솔 + 선택 웹훅).
 *
 * Usage:
 *   php bin/alert_watch.php
 *   php bin/alert_watch.php --profile=isa
 *   php bin/alert_watch.php MU SNDK --profile=account1
 *   php bin/alert_watch.php --webhook=https://discord.com/api/webhooks/...
 *   # 또는 환경변수 NORAMU_ALERT_WEBHOOK
 */

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\Account1Playbook;
use ChartEntryLab\AccountProfile;
use ChartEntryLab\AlertWebhook;
use ChartEntryLab\HourlyAssist;
use ChartEntryLab\ProposalService;
use ChartEntryLab\YahooChartClient;

$root = dirname(__DIR__);
$profileId = 'account1';
$webhookArg = null;
$dryWebhook = false;
$symbols = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--profile=')) {
        $profileId = substr($arg, strlen('--profile='));
        continue;
    }
    if (str_starts_with($arg, '--webhook=')) {
        $webhookArg = substr($arg, strlen('--webhook='));
        continue;
    }
    if ($arg === '--dry-webhook') {
        $dryWebhook = true;
        continue;
    }
    $symbols[] = $arg;
}

$profile = AccountProfile::fromId($profileId);
if ($symbols === []) {
    $symbols = $profile->coreSymbols ?? Account1Playbook::CORE_SYMBOLS;
}

$client = new YahooChartClient($root . '/data/ohlcv');
$service = new ProposalService($client, profileId: $profileId);
$hourly = new HourlyAssist($client);

$alerts = [];
$watched = [];
foreach ($symbols as $symbol) {
    $result = $service->propose($symbol, useCache: false);
    if (!$result['ok']) {
        $watched[] = ['symbol' => $symbol, 'error' => $result['error']];
        continue;
    }
    $p = $result['proposal'] ?? [];
    $score = (int) ($p['score'] ?? 0);
    $action = (string) ($p['action'] ?? '');
    $h = $hourly->analyze((string) $result['symbol']);

    $row = [
        'symbol' => $result['symbol'],
        'profile' => $profileId,
        'score' => $score,
        'action' => $action,
        'invalidation' => $p['invalidation'] ?? null,
        'entry_zone' => $p['entry_zone'] ?? null,
        'hourly' => $h,
        'reason' => $p['reason'] ?? '',
        'tradingview_url' => $result['tradingview_url'] ?? null,
    ];

    $isAlert = in_array($action, ['add_on_pullback', 'watchlist_buy_zone'], true)
        || $score >= $profile->watchScoreThreshold;
    if ($isAlert) {
        $alerts[] = $row;
    }
    $watched[] = $row;
}

$webhookResult = null;
$webhook = AlertWebhook::fromEnvOrArg($webhookArg);
if ($webhook !== null && $alerts !== []) {
    if ($dryWebhook) {
        $webhookResult = ['ok' => true, 'http_code' => 0, 'error' => null, 'dry' => true];
    } else {
        $webhookResult = $webhook->sendAlerts($profileId, $alerts);
    }
}

$out = [
    'generated_at_kst' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format(DATE_ATOM),
    'profile' => $profileId,
    'thresholds' => [
        'add' => $profile->addScoreThreshold,
        'watch' => $profile->watchScoreThreshold,
        'trim' => $profile->trimScoreThreshold,
    ],
    'alert_count' => count($alerts),
    'alerts' => $alerts,
    'watched' => $watched,
    'webhook' => $webhookResult,
];

$dir = $root . '/data/alerts';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$stamp = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Ymd-His');
$path = $dir . '/alert-' . $stamp . '.json';
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'file' => $path,
    'profile' => $profileId,
    'alert_count' => count($alerts),
    'webhook' => $webhookResult,
    'alerts' => array_map(static fn(array $a): array => [
        'symbol' => $a['symbol'],
        'score' => $a['score'],
        'action' => $a['action'],
        'unusual_volume_1h' => $a['hourly']['unusual_volume'] ?? false,
        'reason' => $a['reason'],
    ], $alerts),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if ($alerts === []) {
    echo "No alerts at watch/add thresholds.\n";
}
exit(0);
