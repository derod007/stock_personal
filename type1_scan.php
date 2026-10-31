<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';
require_once __DIR__.'/bin/type1/Classifier.php';
require_once __DIR__.'/bin/type1/Scanner.php';
require_once __DIR__.'/bin/type1/Page.php';

use ChartEntryLab\KrAmountLeadersClient;
use ChartEntryLab\YahooChartClient;
use ChartEntryLab\Type1Scan\Classifier;
use ChartEntryLab\Type1Scan\Scanner;

@set_time_limit(600);
$asOf = time();
$refresh = ($_GET['refresh'] ?? '') === '1';
try {
    $leaders = new KrAmountLeadersClient(__DIR__.'/data/type1-cache/leaders');
    $prices = new YahooChartClient(__DIR__.'/data/type1-cache/ohlcv');
    $scanner = new Scanner(new Classifier(__DIR__.'/data/type1/centers.json'));
    $report = $scanner->scan(
        static fn() => $leaders->topByAmount(100, useCache:false, market:'all'),
        static fn($symbol) => $prices->fetch($symbol, range:'2y', interval:'1d', useCache:!$refresh, maxAgeSeconds:$refresh ? 0 : 600),
        $asOf,
    );
} catch (Throwable $e) {
    $report = ['ok'=>false,'as_of'=>date(DATE_ATOM,$asOf),'rows'=>[],'errors'=>[],
        'summary'=>['total'=>0,'evaluated'=>0,'matches'=>0,'errors'=>0],
        'error'=>'유형1 스캔을 준비하지 못했습니다. 기준 파일과 서버 캐시 폴더 권한을 확인해 주세요.'];
}
$report['scope'] = ($_GET['scope'] ?? '') === 'breakout' ? 'breakout' : 'all';
header('Cache-Control: no-store');
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    $report['sort'] = ($_GET['sort'] ?? '') === 'near' ? 'near' : 'amount';
    \ChartEntryLab\Type1Scan\render($report);
}
