<?php

declare(strict_types=1);

/**
 * Usage:
 *   php bin/write_weekly_memo.php
 *   php bin/write_weekly_memo.php --profile=isa
 */

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\Account1Playbook;
use ChartEntryLab\AccountProfile;
use ChartEntryLab\HourlyAssist;
use ChartEntryLab\ProposalService;
use ChartEntryLab\YahooChartClient;

$root = dirname(__DIR__);
$profileId = 'account1';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--profile=')) {
        $profileId = substr($arg, strlen('--profile='));
    }
}

$profile = AccountProfile::fromId($profileId);
$client = new YahooChartClient($root . '/data/ohlcv');
$service = new ProposalService($client, profileId: $profileId);
$hourly = new HourlyAssist($client);

$symbols = $profile->coreSymbols ?? Account1Playbook::CORE_SYMBOLS;

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
$week = $now->format('o') . '-W' . $now->format('W');
$date = $now->format('Y-m-d');
$dir = $root . '/docs/weekly';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$suffix = $profileId === 'account1' ? '' : '-' . $profileId;
$path = $dir . '/' . $week . $suffix . '.md';

$rows = [];
foreach ($symbols as $symbol) {
    $result = $service->propose($symbol, useCache: true);
    if (!$result['ok']) {
        $rows[] = [
            'symbol' => $symbol,
            'score' => '-',
            'action' => 'error',
            'zone' => '-',
            'invalidation' => '-',
            'target' => '-',
            'hourly' => '-',
            'reason' => $result['error'] ?? 'error',
        ];
        continue;
    }
    $p = $result['proposal'] ?? [];
    $zone = $p['entry_zone'] ?? null;
    $zoneText = is_array($zone)
        ? sprintf('%.2f–%.2f', $zone['low'], $zone['high'])
        : '-';
    $h = $hourly->analyze((string) $result['symbol']);
    $rows[] = [
        'symbol' => $result['symbol'],
        'score' => $p['score'] ?? '-',
        'action' => $p['action'] ?? '-',
        'zone' => $zoneText,
        'invalidation' => $p['invalidation'] ?? '-',
        'target' => $p['target_hint']['price'] ?? '-',
        'hourly' => !empty($h['unusual_volume']) ? 'vol!' : '-',
        'reason' => $p['reason'] ?? '',
    ];
}

$lines = [];
$lines[] = "# 주간 메모 {$week} · {$profile->label}";
$lines[] = '';
$lines[] = "작성: {$date} KST · profile=`{$profileId}`";
$lines[] = '';
$lines[] = sprintf(
    '문턱: add≥%d / watch≥%d / trim<%d · 비중 %.0f–%.0f%%',
    $profile->addScoreThreshold,
    $profile->watchScoreThreshold,
    $profile->trimScoreThreshold,
    $profile->addSizeMinPct,
    $profile->addSizeMaxPct
);
$lines[] = '';
$lines[] = '### 스코어 요약';
$lines[] = '';
$lines[] = '| 종목 | score | action | entry_zone | invalidation | target_hint | 1h | 조치 |';
$lines[] = '|------|------:|--------|------------|-------------:|-------------|----|------|';
foreach ($rows as $r) {
    $lines[] = sprintf(
        '| %s | %s | %s | %s | %s | %s | %s |  |',
        $r['symbol'],
        (string) $r['score'],
        $r['action'],
        $r['zone'],
        (string) $r['invalidation'],
        (string) $r['target'],
        $r['hourly']
    );
}
$lines[] = '';
$lines[] = '### 자동 사유';
$lines[] = '';
foreach ($rows as $r) {
    $lines[] = sprintf('- **%s**: %s', $r['symbol'], $r['reason']);
}
$lines[] = '';
$lines[] = '### 메모';
$lines[] = '';
$lines[] = '- 구조/펀더 충돌 여부:';
$lines[] = '- 신규 매수 후보:';
$lines[] = '- 비중 축소 검토:';
$lines[] = '- 다음 주 볼 가격대:';
$lines[] = '';
$lines[] = '### 데이터 루틴';
$lines[] = '';
$lines[] = '- [ ] `php bin/scrape_noramu.php` 또는 import';
$lines[] = '- [ ] `php bin/curate_entries.php`';
$lines[] = '- [ ] `php bin/alert_watch.php --profile=' . $profileId;
$lines[] = '- [ ] `php bin/score_all.php --profile=' . $profileId;
$lines[] = '- [ ] `php bin/list_entries.php full`';
$lines[] = '';

file_put_contents($path, implode("\n", $lines));
echo "Wrote {$path}\n";
