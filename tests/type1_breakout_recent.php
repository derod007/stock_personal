<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
foreach (['Tracker', 'Store', 'Charts', 'BreakoutRetest', 'Page'] as $f) {
    require __DIR__.'/../bin/type1/'.$f.'.php';
}
use ChartEntryLab\Type1Scan\{BreakoutRetest as R, Store};

$n = 0;
function check($x, $m)
{
    global $n;
    if (!$x) {
        throw new RuntimeException($m);
    }
    $n++;
}

$dir = sys_get_temp_dir().'/breakout-recent-'.bin2hex(random_bytes(8));
mkdir($dir);
mkdir($dir.'/breakout-retest');

try {
    $d28 = '2026-10-28';
    $d29 = '2026-10-29';
    $d30 = '2026-10-30';
    $state = ['last_day' => $d30, 'captures' => []];

    foreach ([$d28, $d29, $d30] as $day) {
        $snap = ['day' => $day, 'as_of' => strtotime($day.' 20:20 +0900'), 'days' => [$d28, $d29, $d30], 'prices' => []];
        Store::atomic($dir.'/snapshot-'.$day.'.json', $snap);
        $state['captures'][$day] = [
            'snapshot_sha256' => hash('sha256', json_encode($snap, JSON_THROW_ON_ERROR)),
            'as_of' => $snap['as_of'],
            'rows' => [
                ['symbol' => '005930.KS', 'error' => null, 'classification' => ['matches' => false, 'analysis_date' => $day]],
                ['symbol' => '000001.KS', 'error' => '가격 누락', 'classification' => null],
                ['symbol' => '000002.KS', 'error' => '봉 부족', 'classification' => null],
                ['symbol' => '000003.KS', 'error' => '합성 봉', 'classification' => null],
                ['symbol' => '000004.KS', 'error' => '미완료', 'classification' => null],
                ['symbol' => '000005.KS', 'error' => '조회 실패', 'classification' => null],
            ],
        ];
    }

    // 10/28: four confirmed breakouts, all next opens already above the line.
    $rows28 = [];
    foreach (['111111.KS', '222222.KS', '333333.KS', '444444.KS'] as $s) {
        $rows28[$s] = ['level' => 100, 'status' => '대상 아님', 'open' => 101, 'daily_open' => 101];
    }
    Store::atomic($dir.'/breakout-retest/'.$d28.'.json', [
        'version' => 1,
        'anchor' => $d28,
        'day' => $d29,
        'snapshot_sha256' => $state['captures'][$d28]['snapshot_sha256'],
        'rows' => $rows28,
    ]);

    // 10/29 and 10/30: zero confirmed breakouts, still recorded.
    foreach ([$d29, $d30] as $day) {
        Store::atomic($dir.'/breakout-retest/'.$day.'.json', [
            'version' => 1,
            'anchor' => $day,
            'day' => '2026-10-31',
            'snapshot_sha256' => $state['captures'][$day]['snapshot_sha256'],
            'rows' => [],
        ]);
    }

    $recent = R::recentSummaries($dir, $state, 10);
    check(count($recent) === 3, 'three recent days');
    check($recent[0]['day'] === $d30 && $recent[1]['day'] === $d29 && $recent[2]['day'] === $d28, 'newest first');

    check($recent[2]['confirmed'] === 4, '10/28 confirmed 4');
    check(str_starts_with($recent[2]['observation']['label'], '회복 관찰 대상 0건'), '10/28 not 미회복');
    check(!str_contains($recent[2]['observation']['label'], '미회복'), '10/28 must not invent 미회복');
    check(($recent[2]['observation']['counts']['대상 아님'] ?? 0) === 4, '10/28 above-line opens');
    check($recent[2]['recent_candidate'] === true, 'latest candidate badge on 10/28');
    check($recent[2]['unevaluable'] === 5, '10/28 unevaluable separate');

    check($recent[1]['confirmed'] === 0 && $recent[0]['confirmed'] === 0, '10/29 and 10/30 confirmed 0');
    check($recent[1]['observation']['label'] === '종가 돌파 확인 0건', '10/29 zero confirmed label');
    check($recent[0]['observation']['label'] === '종가 돌파 확인 0건', '10/30 zero confirmed label');
    check($recent[1]['unevaluable'] === 5 && $recent[0]['unevaluable'] === 5, 'unevaluable still shown');
    check($recent[1]['recent_candidate'] === false && $recent[0]['recent_candidate'] === false, 'no badge on zero days');

    // Latest day waiting for next session: no retest file yet.
    unlink($dir.'/breakout-retest/'.$d30.'.json');
    $waiting = R::recentSummaries($dir, $state, 10);
    $latest = $waiting[0];
    check($latest['day'] === $d30 && $latest['observation']['state'] === '대기', 'latest waits without inventing 미회복');
    check($latest['observation']['label'] === '다음 거래일 관찰 대기', 'waiting label');

    // Missing older retest must stay 기록 없음, not 미회복.
    unlink($dir.'/breakout-retest/'.$d28.'.json');
    $missing = R::recentSummaries($dir, $state, 10);
    $row28 = null;
    foreach ($missing as $row) {
        if ($row['day'] === $d28) {
            $row28 = $row;
        }
    }
    check($row28 && $row28['observation']['state'] === '기록 없음', 'missing historical record');
    check(!str_contains($row28['observation']['label'], '미회복'), 'missing is not 미회복');

    // Hash mismatch → 미확정.
    Store::atomic($dir.'/breakout-retest/'.$d29.'.json', [
        'version' => 1,
        'anchor' => $d29,
        'day' => $d30,
        'snapshot_sha256' => 'deadbeef',
        'rows' => ['005930.KS' => ['status' => '미회복']],
    ]);
    $bad = R::recentSummaries($dir, $state, 10);
    $row29 = null;
    foreach ($bad as $row) {
        if ($row['day'] === $d29) {
            $row29 = $row;
        }
    }
    check($row29 && $row29['observation']['state'] === '미확정', 'hash mismatch uncertain');
    check(!str_contains($row29['observation']['label'], '미회복'), 'mismatch does not count 미회복');

    $report = [
        'ok' => true,
        'as_of' => date(DATE_ATOM),
        'source' => 'saved',
        'saved_day' => $d30,
        'scope' => 'breakout',
        'sort' => 'amount',
        'rows' => [],
        'errors' => [],
        'summary' => ['total' => 0, 'evaluated' => 0, 'matches' => 0, 'errors' => 0],
        'recent' => $waiting,
        'saved_days' => [$d28, $d29, $d30],
        'error' => null,
    ];
    ob_start();
    \ChartEntryLab\Type1Scan\render($report);
    $html = ob_get_clean();
    check(str_contains($html, '최근 마감 기록'), 'section title');
    check(str_contains($html, 'type1_breakout.php?day=2026-10-28'), 'day link');
    check(str_contains($html, '다음 거래일 관찰 대기'), 'waiting text');
    check(!str_contains($html, '미회복 4건'), 'no invented unrecovered count');

    foreach (['all', 'breakout'] as $scope) {
    $report['scope'] = $scope;
    $report['sort'] = 'near';
    ob_start();
    \ChartEntryLab\Type1Scan\render($report);
    $html = ob_get_clean();
    preg_match_all('/href="([^"]+)"/', $html, $links);
    $recentLinks = 0;
    foreach ($links[1] as $href) {
        $url = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
        if (!str_starts_with($url, 'type1_breakout.php?day=')) continue;
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        check(($query['scope'] ?? null) === $scope, 'recent link preserves scope');
        check(($query['sort'] ?? null) === ($scope === 'breakout' ? 'near' : null), 'recent link preserves applicable sort');
        $recentLinks++;
    }
    check($recentLinks >= 3, 'recent date links checked');
    }
} finally {
    foreach (glob($dir.'/breakout-retest/*') ?: [] as $f) {
        unlink($f);
    }
    if (is_dir($dir.'/breakout-retest')) {
        rmdir($dir.'/breakout-retest');
    }
    foreach (glob($dir.'/*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($dir);
}

echo "$n checks passed\n";
