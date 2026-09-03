<?php

declare(strict_types=1);

require __DIR__ . '/bin/bootstrap.php';

use ChartEntryLab\AccountProfile;
use ChartEntryLab\AlphaEntries;
use ChartEntryLab\EntryRepository;
use ChartEntryLab\KrAmountLeadersClient;
use ChartEntryLab\KrAmountScanner;
use ChartEntryLab\LearnedLevels;
use ChartEntryLab\ProposalService;
use ChartEntryLab\SymbolMap;
use ChartEntryLab\SectorMap;
use ChartEntryLab\YahooChartClient;

$profileId = isset($_GET['profile']) ? (string) $_GET['profile'] : 'account1';
if (!in_array($profileId, ['account1', 'custom', 'isa'], true)) {
    $profileId = 'account1';
}
$profile = AccountProfile::fromId($profileId);
$tabInfo = AlphaEntries::normalizeTab(isset($_GET['tab']) ? (string) $_GET['tab'] : null);
$tab = $tabInfo['id'];
$tabLabel = $tabInfo['label'];

$uiMode = isset($_GET['mode']) ? (string) $_GET['mode'] : 'simple';
if (!in_array($uiMode, ['simple', 'analyze'], true)) {
    $uiMode = 'simple';
}
$isAnalyze = $uiMode === 'analyze';

$noramuRepo = new EntryRepository(__DIR__ . '/data/entries.json');
$dgoRepo = new EntryRepository(__DIR__ . '/data/alpha/digingonyou_entries.json');
$noramuEntries = $noramuRepo->all();
$dgoEntries = $dgoRepo->all();
$scoringRows = AlphaEntries::scoringEntries($tab, $noramuEntries, $dgoEntries);
$scoreRepo = EntryRepository::fromArray($scoringRows);

$service = new ProposalService(
    new YahooChartClient(__DIR__ . '/data/ohlcv'),
    profileId: $profileId,
    entries: $scoreRepo,
    lens: $tab,
);

$input = isset($_GET['symbol']) ? trim((string) $_GET['symbol']) : '';
$result = $input !== '' ? $service->propose($input, useCache: true) : null;

$scanType = isset($_GET['scan']) ? (string) $_GET['scan'] : '';
$scanMode = in_array($scanType, ['kr_amount', 'kr_amount_kospi'], true)
    && $tab === AlphaEntries::TAB_NORAMU;
$scanMarket = $scanType === 'kr_amount_kospi' ? 'kospi' : 'all';
$scanParam = $scanMarket === 'kospi' ? 'kr_amount_kospi' : 'kr_amount';
$scanLimit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
$scanLimit = max(10, min(100, $scanLimit));
$scanRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';
$scanReport = null;
if ($scanMode) {
    @set_time_limit(600);
    @ini_set('max_execution_time', '600');
    $cacheDir = __DIR__ . '/data/raw/cache';
    $noramuService = new ProposalService(
        new YahooChartClient(__DIR__ . '/data/ohlcv'),
        profileId: $profileId,
        entries: $noramuRepo,
    );
    $scanner = new KrAmountScanner(
        new KrAmountLeadersClient($cacheDir),
        $noramuService,
        $cacheDir,
    );
    $scanReport = $scanner->scan(
        limit: $scanLimit,
        useCache: !$scanRefresh,
        useYahooCache: !$scanRefresh,
        yahooMaxAgeSeconds: $scanRefresh ? 0 : 600,
        market: $scanMarket,
    );
    if (isset($_GET['format']) && (string) $_GET['format'] === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($scanReport, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}

$hintSymbols = $profile->coreSymbols ?? ['MU', 'SNDK', 'NVDA', 'AAPL', '005930.KS', '005380.KS'];

$learnedFilter = new LearnedLevels();
$feedEntries = AlphaEntries::forTab($tab, $noramuEntries, $dgoEntries);
usort($feedEntries, static function (array $a, array $b): int {
    return strcmp((string) ($b['posted_at_kst'] ?? ''), (string) ($a['posted_at_kst'] ?? ''));
});
$allowUses = $tab === AlphaEntries::TAB_NORAMU
    ? ['full', 'structure_only']
    : ['full', 'structure_only', 'needs_review'];
$entriesView = array_slice(array_values(array_filter(
    $feedEntries,
    static function (array $e) use ($learnedFilter, $allowUses): bool {
        if (!in_array(($e['learning_use'] ?? ''), $allowUses, true)) {
            return false;
        }
        return !$learnedFilter->isEngineSnapshot($e);
    }
)), 0, $tab === AlphaEntries::TAB_MERGED ? 30 : 24);
$showAuthorCol = $tab !== AlphaEntries::TAB_NORAMU;

if (!function_exists('h')) {
    function h(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('fmtNum')) {
    function fmtNum(mixed $v, int $decimals = 2): string
    {
        if (!is_numeric($v)) {
            return '—';
        }
        return number_format((float) $v, $decimals, '.', ',');
    }
}

if (!function_exists('levelPct')) {
    function levelPct(mixed $level, mixed $entry): string
    {
        if (!is_numeric($level) || !is_numeric($entry) || (float) $entry <= 0) {
            return '';
        }

        return sprintf('%+.1f%%', (((float) $level - (float) $entry) / (float) $entry) * 100);
    }
}

if (!function_exists('memoLevel')) {
    function memoLevel(mixed $level, mixed $entry, int $decimals): string
    {
        if (!is_numeric($level)) {
            return '—';
        }
        $pct = levelPct($level, $entry);

        return fmtNum($level, $decimals) . '원' . ($pct !== '' ? ' (' . $pct . ')' : '');
    }
}

if (!function_exists('etaText')) {
    function etaText(mixed $eta): string
    {
        if (!is_array($eta) || ($eta['label'] ?? '') === '') {
            return '';
        }

        return (string) $eta['label'];
    }
}

if (!function_exists('etaPair')) {
    function etaPair(mixed $a, mixed $b): string
    {
        $la = etaText($a);
        $lb = etaText($b);
        if ($la !== '' && $lb !== '' && $la !== $lb) {
            return $la . ' / ' . $lb;
        }

        return $la !== '' ? $la : $lb;
    }
}

/** 분석 모드에서만 ? 말풍선. 간단 모드에서는 빈 문자열. */
if (!function_exists('tip')) {
    function tip(string $text): string
    {
        global $isAnalyze;
        if (!$isAnalyze) {
            return '';
        }
        static $n = 0;
        $n++;
        $id = 'tip-panel-' . $n;
        $body = nl2br(h(trim($text)), false);
        return '<button type="button" class="tip" aria-expanded="false" aria-controls="' . h($id) . '">'
            . '<span class="tip__q" aria-hidden="true">?</span>'
            . '<span class="visually-hidden">설명</span>'
            . '<span class="tip__bubble" id="' . h($id) . '" role="tooltip" hidden>' . $body . '</span>'
            . '</button>';
    }
}

$proposal = is_array($result) ? ($result['proposal'] ?? null) : null;
$perspective = is_array($result) ? ($result['perspective'] ?? null) : null;
if ($perspective === null && is_array($proposal)) {
    $perspective = is_array($proposal['perspective'] ?? null) ? $proposal['perspective'] : null;
}
$zone = is_array($proposal) ? ($proposal['entry_zone'] ?? null) : null;
$target = is_array($proposal) ? ($proposal['target_hint'] ?? null) : null;
$targetLearned = is_array($proposal) ? ($proposal['target_learned'] ?? null) : null;
$stopLearned = is_array($proposal) ? ($proposal['stop_learned'] ?? null) : null;
$entryLearned = is_array($proposal) ? ($proposal['entry_learned'] ?? null) : null;
$explain = is_array($proposal) ? ($proposal['explain'] ?? null) : null;
$newEntry = is_array($proposal) ? ($proposal['new_entry'] ?? null) : null;
$entryLearnedAuthor = is_array($proposal) ? ($proposal['entry_learned_author'] ?? null) : null;
$qsBase = 'tab=' . rawurlencode($tab)
    . '&profile=' . rawurlencode($profileId)
    . '&mode=' . rawurlencode($uiMode);
$qsProfile = $qsBase;
$shellClass = $scanMode ? 'shell shell--wide' : 'shell shell--decide';
$pageTitle = match (true) {
    $scanMode && $scanMarket === 'kospi' => '코스피 거래대금 스캔',
    $scanMode => '국장 통합 거래대금 스캔',
    $tab === AlphaEntries::TAB_DIGINGONYOU => '기타',
    $tab === AlphaEntries::TAB_MERGED => '합침',
    default => '진입 보조',
};
$sectorBucket = is_array($proposal) ? (string) ($proposal['sector_bucket'] ?? 'other') : 'other';
$sectorLabel = is_array($proposal) ? (string) ($proposal['sector_label'] ?? '기타') : '기타';
$stockName = is_array($proposal) ? trim((string) ($proposal['name'] ?? '')) : '';
$marketLabel = is_array($proposal) ? (string) ($proposal['market_label'] ?? '') : '';
if ($stockName === '' && is_array($result) && !empty($result['symbol'])) {
    $stockName = (string) (SymbolMap::koreanName((string) $result['symbol']) ?? '');
}
if ($marketLabel === '' && is_array($result) && !empty($result['symbol'])) {
    $marketLabel = (string) (SymbolMap::marketLabel((string) $result['symbol']) ?? '');
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <title><?= h($pageTitle) ?></title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="ui-mode-<?= h($uiMode) ?>" aria-busy="false">
  <div class="bg" aria-hidden="true"></div>
  <main class="<?= h($shellClass) ?>">
    <header class="brand">
      <p class="brand__name"><?= $tab === AlphaEntries::TAB_NORAMU ? '차트' : '차트+' ?></p>
      <p class="brand__sub">티커로 판단 · 손절·관심구간<?= $tab === AlphaEntries::TAB_NORAMU ? ' · 거래대금 스캔은 별도 화면' : '' ?></p>
      <button
        class="global-help__button"
        type="button"
        aria-expanded="false"
        aria-controls="global-help"
        aria-label="용어 도움말 열기"
      >?</button>
      <aside class="global-help" id="global-help" role="dialog" aria-labelledby="global-help-title" hidden>
        <div class="global-help__head">
          <h2 id="global-help-title">용어 도움말</h2>
          <button class="global-help__close" type="button" aria-label="도움말 닫기">×</button>
        </div>
        <section class="global-help__section">
          <h3>행동 문구</h3>
          <dl>
            <div>
              <dt>내려올 때 나눠서 사기 검토</dt>
              <dd>차트 구조와 점수는 괜찮지만 지금 시장가로 추격하지 않고, 추천 진입 구간까지 내려오면 현금을 여러 번 나눠 진입할 수 있다는 뜻입니다.</dd>
            </div>
            <div>
              <dt>관심 — 중간 가격대 근처</dt>
              <dd>고점과 저점의 중간 부근입니다. 후보로 지켜보되 점수·저점·거래량 확인 전 전량 매수하라는 뜻은 아닙니다.</dd>
            </div>
            <div>
              <dt>지금은 안 삼 (지켜보기)</dt>
              <dd>매수 조건이 부족하거나 현재 가격이 좋은 진입 자리가 아니라는 뜻입니다. 현금을 유지하며 새 구조를 기다립니다.</dd>
            </div>
            <div>
              <dt>새로 사지 말 것 · 갖고 있으면 줄이기 검토</dt>
              <dd>차트 그림이 약하거나 고점 위험이 큰 상태입니다. 신규 매수보다 반등할 때 기존 비중을 줄이는 쪽을 우선 검토합니다.</dd>
            </div>
            <div>
              <dt>차단 — 본주 티커로 다시 조회</dt>
              <dd>레버리지·인버스 상품은 직접 매매가를 제안하지 않습니다. 연결된 본주 차트로 방향과 가격대를 다시 확인합니다.</dd>
            </div>
            <div>
              <dt>진입 추천</dt>
              <dd>행동 조건과 새로 살 가격이 모두 있는 종목입니다. 추천 진입 가격에 도달했는지는 별도로 확인해야 합니다.</dd>
            </div>
          </dl>
        </section>
        <section class="global-help__section">
          <h3>저점체크</h3>
          <dl>
            <div>
              <dt>저점관찰</dt>
              <dd>큰 하락 뒤 이전 저점 부근을 다시 시험한 단계. 아직 매수 신호는 아니며 점수 변화가 없습니다.</dd>
            </div>
            <div>
              <dt>저점후보(+5)</dt>
              <dd>저점을 지키고 높이면서 파동 중심 회복 또는 하락 추세선 돌파가 나타난 단계. +5점입니다.</dd>
            </div>
            <div>
              <dt>저점확인(+12)</dt>
              <dd>저점 방어·저점 상승·하락 추세선 돌파·상단 재시험이 함께 확인된 단계. +12점입니다.</dd>
            </div>
          </dl>
          <p>관찰 → 후보 → 확인 순으로 반등 구조가 강해집니다. 저점확인도 상승을 보장하지는 않습니다.</p>
        </section>
        <section class="global-help__section">
          <h3>가격·위험 용어</h3>
          <dl>
            <div>
              <dt>추천 진입</dt>
              <dd>현재 차트의 고점·저점과 지지 구조로 계산한 분할매수 중심 가격입니다. 현재가와 같다는 뜻은 아닙니다.</dd>
            </div>
            <div>
              <dt>관심구간</dt>
              <dd>추천 진입 중심을 기준으로 위아래 약 4% 범위입니다. 차트 구조가 살아 있을 때만 유효합니다.</dd>
            </div>
            <div>
              <dt>손절 2단</dt>
              <dd>타이트 손절은 가까운 저점, 넓은 손절은 여러 번 지지된 가로 매물대입니다. 계획한 진입가보다 아래인 가격만 사용합니다.</dd>
            </div>
            <div>
              <dt>익절 2단</dt>
              <dd>1차는 직전 고점에서 일부 청산, 2차는 같은 파동 폭의 측정 목표 또는 그 사이 가로 저항입니다.</dd>
            </div>
            <div>
              <dt>도달 예상</dt>
              <dd>최근 ATR로 현재가부터 목표 가격까지의 거리를 환산한 거래일 수입니다. 해당 날짜에 도착한다는 예측이 아닙니다.</dd>
            </div>
            <div>
              <dt>점수</dt>
              <dd>저점 상승·거래량·추세·관심구간 거리 등에 패턴 가감점을 더한 0~100 구조 점수입니다. 수익 확률 자체는 아닙니다. 스캔 기본 정렬은 이 점수 순입니다.</dd>
            </div>
          </dl>
        </section>
        <section class="global-help__section">
          <h3>스캔·패턴 용어</h3>
          <dl>
            <div>
              <dt>섹터</dt>
              <dd>종목이 속한 업종 묶음입니다. 반도체·전자, 자동차·조선, 금융, 바이오, 소프트웨어, 에너지·소재, 소비재, 산업재, 운송, ETF로 나눕니다.</dd>
            </div>
            <div>
              <dt>거래대금</dt>
              <dd>주가와 거래량을 곱한 실제 매매 규모입니다. 순위는 다음 금융 거래대금 순입니다. 거래량 순위와 다릅니다.</dd>
            </div>
            <div>
              <dt>거래대금 스캔(코스피)</dt>
              <dd>코스닥을 제외하고 코스피 거래대금 상위 종목만 점수·진입·패턴 조건으로 분석합니다.</dd>
            </div>
            <div>
              <dt>지금 진입순</dt>
              <dd>관심 구간에 들어와 나눠 사기를 검토하는 종목을 위로 올립니다. 이미 구간 위에서 쫓아 사는 자리와 손절선이 깨진 그림은 아래로 둡니다. 같은 단계면 점수 순입니다.</dd>
            </div>
            <div>
              <dt>오늘 돈 몰린 곳</dt>
              <dd>테마 상승률과 거래대금 급증을 묶어 보여 주는 시장 흐름입니다. 급등 종목을 즉시 추격하라는 신호는 아닙니다.</dd>
            </div>
            <div>
              <dt>고점관찰(-6)</dt>
              <dd>고점 돌파에 실패해 약화 가능성을 추적하는 초기 단계입니다.</dd>
            </div>
            <div>
              <dt>고점경고(-12)</dt>
              <dd>고점 하향과 파동 중심 또는 추세선 훼손이 함께 나타난 상태입니다. 신규 매수보다 위험 관리가 우선입니다.</dd>
            </div>
            <div>
              <dt>고점확정(-22)</dt>
              <dd>고점 하향·추세선 이탈·파동 하단 시험이 함께 확인된 강한 경고입니다. 반등 시 비중 축소를 우선 검토합니다.</dd>
            </div>
            <div>
              <dt>급등후급락(-10/-16)</dt>
              <dd>최근 1~3일 +20% 이상 급등한 뒤 고가를 절반 이상 반납한 자리입니다. 점수가 눌림처럼 보여도 중간 반등은 허공입니다. 리스트에서 지우지 않고 경고·감점하며, 신규 매수는 보류합니다.</dd>
            </div>
            <div>
              <dt>테마냄새</dt>
              <dd>테마가 아직 안 올라도 거래량이 터지고 장중 고가를 찍은 자리입니다. 시초가 추격이 아니라 구경·내려오면 관심입니다.</dd>
            </div>
            <div>
              <dt>냄새·못지킴</dt>
              <dd>돈이 찔러 봤는데 종가가 저가 근처입니다. 높은 자리에서 못 지킨 것이므로 내려오면 봅니다.</dd>
            </div>
            <div>
              <dt>냄새·점화</dt>
              <dd>선도주가 대금과 함께 올라 붙는 그림입니다. 테마가 켜질 수는 있어도 지금 가격 추격은 아닙니다.</dd>
            </div>
            <div>
              <dt>불법과외1</dt>
              <dd>돌파만 보고 추격하지 않고 윗구간 박스와 거래량 없는 눌림 뒤 재돌파를 확인하는 패턴 규칙입니다.</dd>
            </div>
          </dl>
        </section>
      </aside>
    </header>

    <div class="topbar">
      <nav class="tabs" aria-label="화면">
        <?php
          $tabDefs = [
              AlphaEntries::TAB_NORAMU => '차트',
              AlphaEntries::TAB_DIGINGONYOU => '기타',
              AlphaEntries::TAB_MERGED => '합침',
          ];
          foreach ($tabDefs as $tid => $tlabel):
              $href = '?tab=' . rawurlencode($tid)
                  . '&profile=' . rawurlencode($profileId)
                  . '&mode=' . rawurlencode($uiMode);
              if ($input !== '' && !$scanMode) {
                  $href .= '&symbol=' . rawurlencode($input);
              }
              $tabActive = $tab === $tid && !$scanMode;
        ?>
          <a
            class="tabs__link<?= $tabActive ? ' is-active' : '' ?>"
            href="<?= h($href) ?>"
            <?= $tabActive ? 'aria-current="page"' : '' ?>
          ><?= h($tlabel) ?></a>
        <?php endforeach; ?>
        <?php if ($tab === AlphaEntries::TAB_NORAMU || $scanMode): ?>
          <a
            class="tabs__link<?= $scanMode && $scanMarket === 'all' ? ' is-active' : '' ?>"
            href="?<?= h($qsProfile) ?>&scan=kr_amount&limit=100"
            <?= $scanMode && $scanMarket === 'all' ? 'aria-current="page"' : '' ?>
          >거래대금 스캔</a>
          <a
            class="tabs__link<?= $scanMode && $scanMarket === 'kospi' ? ' is-active' : '' ?>"
            href="?<?= h($qsProfile) ?>&scan=kr_amount_kospi&limit=100"
            <?= $scanMode && $scanMarket === 'kospi' ? 'aria-current="page"' : '' ?>
          >거래대금 스캔(코스피)</a>
        <?php endif; ?>
      </nav>
      <nav class="mode-toggle" aria-label="표시 모드">
        <?php
          $modeQs = static function (string $m) use ($tab, $profileId, $input, $scanMode, $scanLimit, $scanParam): string {
              $q = 'tab=' . rawurlencode($tab)
                  . '&profile=' . rawurlencode($profileId)
                  . '&mode=' . rawurlencode($m);
              if ($scanMode) {
                  $q .= '&scan=' . rawurlencode($scanParam) . '&limit=' . (int) $scanLimit;
              } elseif ($input !== '') {
                  $q .= '&symbol=' . rawurlencode($input);
              }
              return $q;
          };
        ?>
        <a class="mode-toggle__link<?= !$isAnalyze ? ' is-active' : '' ?>" href="?<?= h($modeQs('simple')) ?>" data-mode="simple"<?= !$isAnalyze ? ' aria-current="page"' : '' ?>>간단</a>
        <a class="mode-toggle__link<?= $isAnalyze ? ' is-active' : '' ?>" href="?<?= h($modeQs('analyze')) ?>" data-mode="analyze"<?= $isAnalyze ? ' aria-current="page"' : '' ?>>분석</a>
      </nav>
    </div>

    <?php if ($scanMode): ?>
    <section class="panel panel--scan">
      <div class="scan__head">
        <div>
          <h2>
            <?= $scanMarket === 'kospi' ? '코스피 거래대금 스캔' : '국장 거래대금 스캔' ?>
            <?= tip(($scanMarket === 'kospi'
                ? "네이버 코스피 거래대금 TOP N에 구조 점수를 붙입니다."
                : "네이버 코스피·코스닥 거래대금 TOP N에 구조 점수를 붙입니다.")
                . "
상단 «오늘 돈 몰린 곳»은 네이버 테마 등락 + 대금 TOP 급등(ETF 제외).
«테마 냄새»는 테마가 아직 안 올라도 대금이 한 번 찔러 본 곳. 구경·내려오면이지 추격이 아닙니다.
진입 추천 = 내려올 때 나눠서/관심 + 새로 살 가격 있음.
결과는 약 5분 캐시. «새로고침»은 시세를 다시 받습니다.") ?>
          </h2>
          <p class="scan__meta">
            <?= $scanMarket === 'kospi' ? '코스피 전용' : '코스피+코스닥' ?> · <?= h($profile->label) ?>
            · <a href="?<?= h($qsProfile) ?>">티커 분석으로</a>
          </p>
        </div>
        <div class="scan__actions">
          <a class="btn-scan" href="?<?= h($qsProfile) ?>&scan=<?= h($scanParam) ?>&limit=100">TOP 100</a>
          <a class="btn-scan btn-scan--ghost" href="?<?= h($qsProfile) ?>&scan=<?= h($scanParam) ?>&limit=30">빠른 30</a>
          <a class="btn-scan btn-scan--ghost" href="?<?= h($qsProfile) ?>&scan=<?= h($scanParam) ?>&limit=<?= (int) $scanLimit ?>&refresh=1">새로고침</a>
        </div>
      </div>
      <?php if (is_array($scanReport)): ?>
        <?php
          $scanSummary = is_array($scanReport['summary'] ?? null) ? $scanReport['summary'] : [];
          $scanRows = is_array($scanReport['rows'] ?? null) ? $scanReport['rows'] : [];
          $scanBucketsUsed = [];
          $scanBuyNowCount = 0;
          foreach ($scanRows as $srRow) {
              $b = (string) ($srRow['sector_bucket'] ?? 'other');
              $scanBucketsUsed[$b] = SectorMap::BUCKETS[$b] ?? '기타';
              if (!empty($srRow['buy_now'])) {
                  $scanBuyNowCount++;
              }
          }
        ?>
        <?php if (empty($scanReport['ok'])): ?>
          <p class="scan__error" role="alert"><?= h((string) ($scanReport['error'] ?? '스캔 실패')) ?></p>
        <?php else: ?>
          <div class="scan-stats" aria-label="스캔 요약">
            <div class="scan-stat">
              <span>대상 종목</span>
              <strong class="mono"><?= (int) ($scanSummary['total'] ?? 0) ?></strong>
            </div>
            <div class="scan-stat">
              <span>점수 계산</span>
              <strong class="mono"><?= (int) ($scanSummary['scored'] ?? 0) ?></strong>
            </div>
            <div class="scan-stat scan-stat--accent">
              <span>진입 추천</span>
              <strong class="mono"><?= (int) ($scanSummary['recommend'] ?? 0) ?></strong>
            </div>
            <div class="scan-stat">
              <span>테마냄새</span>
              <strong class="mono"><?= (int) ($scanSummary['smell'] ?? 0) ?></strong>
            </div>
            <div class="scan-stat">
              <span>구경만</span>
              <strong class="mono"><?= (int) ($scanSummary['lagging'] ?? 0) ?></strong>
            </div>
            <div class="scan-stat">
              <span>수집 시각</span>
              <strong class="scan__time"><?= h((string) ($scanReport['fetched_at'] ?? '')) ?></strong>
            </div>
          </div>
          <?php
            $flow = is_array($scanReport['money_flow'] ?? null) ? $scanReport['money_flow'] : null;
            $flowThemes = is_array($flow['themes'] ?? null) ? $flow['themes'] : [];
            $flowSpikes = is_array($flow['amount_spikes'] ?? null) ? $flow['amount_spikes'] : [];
            $flowSmellThemes = is_array($flow['smell_themes'] ?? null) ? $flow['smell_themes'] : [];
            $flowSmellClusters = is_array($flow['smell_clusters'] ?? null) ? $flow['smell_clusters'] : [];
            $flowSmellRows = [];
            foreach ($scanRows as $srSmell) {
                if (($srSmell['theme_smell_status'] ?? 'none') === 'none') {
                    continue;
                }
                $flowSmellRows[] = $srSmell;
            }
          ?>
          <?php if ($flowThemes !== [] || $flowSpikes !== []): ?>
            <div class="flow-box">
              <p class="flow-box__title">
                오늘 돈 몰린 곳
                <?= tip("네이버 테마 전일대비. 원전·식품·보험처럼 돈이 한쪽으로 몰리면 여기 뜹니다.
점수·추천과 무관. 급등 추격 신호가 아님.") ?>
              </p>
              <?php if ($flowThemes !== []): ?>
                <div class="flow-chips" aria-label="테마 자금">
                  <?php foreach ($flowThemes as $th): ?>
                    <?php
                      $thChg = (float) ($th['change_pct'] ?? 0);
                      $thLead = implode(' · ', array_slice(is_array($th['leaders'] ?? null) ? $th['leaders'] : [], 0, 2));
                    ?>
                    <span class="flow-chip<?= $thChg >= 0 ? ' is-up' : ' is-down' ?>">
                      <?= h((string) ($th['name'] ?? '')) ?>
                      <b><?= h(sprintf('%+.1f%%', $thChg)) ?></b>
                      <?php if ($thLead !== ''): ?>
                        <small><?= h($thLead) ?></small>
                      <?php endif; ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if ($flowSpikes !== []): ?>
                <p class="flow-spikes">
                  대금 TOP 급등
                  <?php foreach ($flowSpikes as $sp): ?>
                    <?php $spYahoo = (string) ($sp['yahoo'] ?? ''); ?>
                    <a href="?<?= h($qsProfile) ?>&symbol=<?= rawurlencode($spYahoo) ?>">
                      <?= h((string) ($sp['name'] ?? '')) ?>
                      <?= h(sprintf('%+.1f%%', (float) ($sp['change_pct'] ?? 0))) ?>
                    </a>
                  <?php endforeach; ?>
                </p>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if (is_array($flow)): ?>
            <div class="flow-box flow-box--smell">
              <p class="flow-box__title">
                테마 냄새 · 구경만
                <?= tip("테마 등락은 거의 없는데, 대금 TOP에 그 테마 선도주가 보입니다.
돈이 한 번 찔러 본 자리. 시초가 추격 금지, 내려오면 관심.") ?>
              </p>
              <?php if ($flowSmellThemes !== []): ?>
                <div class="flow-chips" aria-label="아직 안 오른 테마">
                  <?php foreach ($flowSmellThemes as $th): ?>
                    <?php
                      $thChg = (float) ($th['change_pct'] ?? 0);
                      $thMoney = [];
                      foreach (is_array($th['in_amount_top'] ?? null) ? $th['in_amount_top'] : [] as $hit) {
                          $hitName = trim((string) ($hit['name'] ?? ''));
                          if ($hitName !== '') {
                              $thMoney[] = $hitName;
                          }
                      }
                      $thLead = implode(' · ', array_slice($thMoney !== [] ? $thMoney : (is_array($th['leaders'] ?? null) ? $th['leaders'] : []), 0, 2));
                    ?>
                    <span class="flow-chip is-smell<?= $thChg >= 0 ? ' is-up' : ' is-down' ?>">
                      <?= h((string) ($th['name'] ?? '')) ?>
                      <b><?= h(sprintf('%+.1f%%', $thChg)) ?></b>
                      <?php if ($thLead !== ''): ?>
                        <small><?= h($thLead) ?></small>
                      <?php endif; ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="flow-spikes">오늘은 아직 안 오른 테마에 대금 TOP이 없습니다.</p>
              <?php endif; ?>
              <?php if ($flowSmellClusters !== []): ?>
                <div class="flow-chips" aria-label="테마 냄새 묶음">
                  <?php foreach ($flowSmellClusters as $cl): ?>
                    <span class="flow-chip is-smell">
                      <?= h((string) ($cl['sector_label'] ?? '')) ?>
                      <b><?= (int) ($cl['count'] ?? 0) ?>종목</b>
                      <?php
                        $clNames = implode(' · ', array_slice(is_array($cl['names'] ?? null) ? $cl['names'] : [], 0, 3));
                      ?>
                      <?php if ($clNames !== ''): ?>
                        <small><?= h($clNames) ?></small>
                      <?php endif; ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if ($flowSmellRows !== []): ?>
                <p class="flow-spikes">
                  대금 TOP 냄새
                  <?php foreach (array_slice($flowSmellRows, 0, 8) as $sp): ?>
                    <?php $spYahoo = (string) ($sp['yahoo'] ?? ''); ?>
                    <a href="?<?= h($qsProfile) ?>&symbol=<?= rawurlencode($spYahoo) ?>">
                      <?= h((string) ($sp['name'] ?? $spYahoo)) ?>
                      <?= h((string) ($sp['theme_smell_label'] ?? '테마냄새')) ?>
                    </a>
                  <?php endforeach; ?>
                </p>
              <?php endif; ?>
            </div>
          <?php elseif (is_array($flow) && ($flow['error'] ?? '') !== '' && $flowThemes === [] && $flowSpikes === []): ?>
            <p class="scan__note">테마 흐름: <?= h((string) $flow['error']) ?></p>
          <?php endif; ?>
          <div class="scan-toolbar">
            <div class="scan-sort" id="scan-sort" role="group" aria-label="정렬">
              <button type="button" class="sector-chip is-active" data-sort="score" aria-pressed="true">점수순</button>
              <button type="button" class="sector-chip" data-sort="entry" aria-pressed="false">지금 진입순<?php if ($scanBuyNowCount > 0): ?> (<?= (int) $scanBuyNowCount ?>)<?php endif; ?></button>
            </div>
          <?php if ($scanBucketsUsed !== [] || (int) ($scanSummary['smell'] ?? 0) > 0 || (int) ($scanSummary['lagging'] ?? 0) > 0 || (int) ($scanSummary['spike_dump'] ?? 0) > 0): ?>
            <div class="sector-filters" id="sector-filters" role="group" aria-label="업종 필터">
              <button type="button" class="sector-chip is-active" data-sector="all" aria-pressed="true">전체</button>
              <?php if ((int) ($scanSummary['lagging'] ?? 0) > 0): ?>
                <button type="button" class="sector-chip sector-chip--smell" data-filter="lagging" aria-pressed="false">구경만 (<?= (int) $scanSummary['lagging'] ?>)</button>
              <?php endif; ?>
              <?php if ((int) ($scanSummary['smell'] ?? 0) > 0): ?>
                <button type="button" class="sector-chip sector-chip--smell" data-filter="smell" aria-pressed="false">테마냄새 (<?= (int) $scanSummary['smell'] ?>)</button>
              <?php endif; ?>
              <?php if ((int) ($scanSummary['spike_dump'] ?? 0) > 0): ?>
                <button type="button" class="sector-chip sector-chip--top-risk" data-filter="spike-dump" aria-pressed="false">급등후급락 (<?= (int) $scanSummary['spike_dump'] ?>)</button>
              <?php endif; ?>
              <?php foreach ($scanBucketsUsed as $bKey => $bLabel): ?>
                <button type="button" class="sector-chip sector-chip--<?= h($bKey) ?>" data-sector="<?= h($bKey) ?>" aria-pressed="false"><?= h($bLabel) ?></button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          </div>
          <div class="scan-table-wrap">
            <table class="scan-table">
              <thead>
                <tr>
                  <th>대금순위</th>
                  <th>종목</th>
                  <th>업종</th>
                  <th>현재가</th>
                  <th>등락</th>
                  <th>대금</th>
                  <th>점수</th>
                  <th>진입</th>
                  <th class="scan-col-action">행동</th>
                  <th class="scan-col-entry">신규진입</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($scanRows as $si => $sr): ?>
                  <?php
                    $sym = (string) ($sr['yahoo'] ?? '');
                    $rec = !empty($sr['entry_recommend']);
                    $l1 = !empty($sr['lesson1_hit']);
                    $topRiskStatus = (string) ($sr['top_pattern_status'] ?? 'none');
                    $topRisk = in_array($topRiskStatus, ['warning', 'confirmed'], true);
                    $topRiskLabel = $topRiskStatus === 'confirmed' ? '고점확정(-22)' : '고점경고(-12)';
                    $bottomStatus = (string) ($sr['inverse_bottom_status'] ?? 'none');
                    $bottomCheck = in_array($bottomStatus, ['watch', 'warning', 'confirmed'], true);
                    $bottomLabel = match ($bottomStatus) {
                        'confirmed' => '저점확인(+12)',
                        'warning' => '저점후보(+5)',
                        'watch' => '저점관찰',
                        default => '',
                    };
                    $laggingTheme = !empty($sr['lagging_theme']);
                    $smellStatus = (string) ($sr['theme_smell_status'] ?? 'none');
                    $smellHit = $smellStatus !== 'none';
                    $smellLabel = (string) ($sr['theme_smell_label'] ?? '테마냄새');
                    $spikeDumpStatus = (string) ($sr['spike_dump_status'] ?? 'none');
                    $spikeDumpHit = $spikeDumpStatus !== 'none';
                    $spikeDumpLabel = (string) ($sr['spike_dump_label'] ?? '급등후급락');
                    $amtEok = is_numeric($sr['amount_million'] ?? null)
                        ? number_format((float) $sr['amount_million'] / 100, 0) . '억'
                        : '—';
                    $scanPx = $sr['price'] ?? $sr['naver_price'] ?? null;
                    $scanPxDec = is_numeric($scanPx) && (float) $scanPx >= 100 ? 0 : 2;
                    $srBucket = (string) ($sr['sector_bucket'] ?? 'other');
                    $srLabel = (string) ($sr['sector_label'] ?? SectorMap::BUCKETS[$srBucket] ?? '기타');
                    $trClass = 'sec-' . $srBucket
                        . ($rec ? ' is-recommend' : '')
                        . ($l1 ? ' is-lesson1' : '')
                        . ($topRisk ? ' is-top-risk' : '')
                        . ($bottomCheck ? ' is-bottom-check' : '')
                        . ($smellHit ? ' is-smell' : '')
                        . ($spikeDumpHit ? ' is-spike-dump' : '');
                    $entryText = (string) ($sr['new_entry_sentence'] ?? $sr['error'] ?? '—');
                    $l1Title = (string) ($sr['lesson1_note'] ?? '불법과외1 패턴');
                    $topRiskTitle = (string) ($sr['top_pattern_note'] ?? '고점 붕괴 경고');
                  ?>
                  <tr class="<?= h($trClass) ?>" data-sector="<?= h($srBucket) ?>" data-smell="<?= $smellHit ? '1' : '0' ?>" data-lagging="<?= $laggingTheme ? '1' : '0' ?>" data-spike-dump="<?= $spikeDumpHit ? '1' : '0' ?>" data-score="<?= isset($sr['score']) && is_numeric($sr['score']) ? (int) $sr['score'] : -1 ?>" data-buy-now="<?= !empty($sr['buy_now']) ? '1' : '0' ?>" data-entry-status="<?= h((string) ($sr['entry_status'] ?? '')) ?>" data-entry-rec="<?= $rec ? '1' : '0' ?>" data-orig="<?= (int) $si ?>">
                    <td class="mono"><?= (int) ($sr['amount_rank'] ?? 0) ?></td>
                    <td>
                      <a href="?<?= h($qsProfile) ?>&symbol=<?= rawurlencode($sym) ?>">
                        <?= h((string) ($sr['name'] ?? $sym)) ?>
                      </a>
                      <?php if ($l1): ?>
                        <span class="badge badge--lesson1" title="<?= h($l1Title) ?>">불법과외</span>
                      <?php endif; ?>
                      <?php if ($topRisk): ?>
                        <span class="badge badge--top-risk" title="<?= h($topRiskTitle) ?>"><?= h($topRiskLabel) ?></span>
                      <?php endif; ?>
                      <?php if ($spikeDumpHit): ?>
                        <span class="badge badge--top-risk" title="<?= h((string) ($sr['spike_dump_note'] ?? '1~3일 급등 후 급락')) ?>"><?= h($spikeDumpLabel !== '' ? $spikeDumpLabel : '급등후급락') ?></span>
                      <?php endif; ?>
                      <?php if ($bottomCheck): ?>
                        <span class="badge badge--bottom-check" title="<?= h((string) ($sr['top_pattern_note'] ?? '역저점 패턴')) ?>"><?= h($bottomLabel) ?></span>
                      <?php endif; ?>
                      <?php if ($laggingTheme): ?>
                        <span class="badge badge--smell" title="테마는 아직 안 올랐는데 대금 TOP에 선도주"><?= h((string) ($sr['lagging_theme_name'] ?? '구경만')) ?></span>
                      <?php endif; ?>
                      <?php if ($smellHit): ?>
                        <span class="badge badge--smell" title="<?= h((string) ($sr['theme_smell_note'] ?? '테마 냄새 · 구경만')) ?>"><?= h($smellLabel !== '' ? $smellLabel : '테마냄새') ?></span>
                      <?php endif; ?>
                      <span class="scan-code mono"><?= h($sym) ?></span>
                    </td>
                    <td><span class="sector-badge sector-badge--<?= h($srBucket) ?>"><?= h($srLabel) ?></span></td>
                    <td class="mono"><?= h(fmtNum($scanPx, $scanPxDec)) ?></td>
                    <td class="mono<?= is_numeric($sr['change_pct'] ?? null) ? (((float) $sr['change_pct'] >= 0) ? ' is-up' : ' is-down') : '' ?>">
                      <?= h(is_numeric($sr['change_pct'] ?? null) ? sprintf('%+.1f%%', (float) $sr['change_pct']) : '—') ?>
                    </td>
                    <td class="mono"><?= h($amtEok) ?></td>
                    <td class="mono score-cell"><?= h(isset($sr['score']) ? (string) $sr['score'] : '—') ?></td>
                    <td>
                      <?php if (!empty($sr['ok']) && $rec): ?>
                        <span class="badge badge--ok">추천</span>
                      <?php elseif (!empty($sr['ok'])): ?>
                        <span class="badge">보류</span>
                      <?php else: ?>
                        <span class="badge badge--err">오류</span>
                      <?php endif; ?>
                    </td>
                    <td class="scan-col-action"><?= h((string) ($sr['action_label'] ?? $sr['action'] ?? '—')) ?></td>
                    <td class="scan-entry scan-col-entry"><?= h($entryText) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="scan__note">
            기본은 <strong>점수순</strong>입니다. «지금 진입순»은 관심 구간 안에서 나눠 사기 검토가 되는 종목을 위로 올립니다. «대금순위»는 거래대금 원래 순위입니다.
            종목 클릭 → 티커 분석. 빨간 «불법과외» = 불법과외1 패턴.
          </p>
        <?php endif; ?>
      <?php else: ?>
        <p class="scan__idle">TOP 100 / 빠른 30으로 스캔을 실행하세요.</p>
      <?php endif; ?>
    </section>
    <?php else: ?>

    <form class="search" method="get" action="">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <input type="hidden" name="mode" value="<?= h($uiMode) ?>">
      <div class="profile-row">
        <label class="search__label" for="profile">
          계좌 프로필
          <?= tip("1번 계좌: 메모리 스윙 · 나눠서
커스텀: 비중 여유 · ISA: 문턱 높고 비중 작게
레버는 본주로만 바꿉니다.") ?>
        </label>
        <select id="profile" name="profile" onchange="this.form.submit()">
          <?php foreach (AccountProfile::all() as $p): ?>
            <option value="<?= h($p->id) ?>" <?= $p->id === $profileId ? 'selected' : '' ?>>
              <?= h($p->label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <label class="search__label" for="symbol">티커 또는 종목명</label>
      <div class="search__row">
        <input
          id="symbol"
          name="symbol"
          type="text"
          value="<?= h($input) ?>"
          placeholder="AAPL, NVDA, 하이닉스…"
          autocomplete="off"
          autofocus
        >
        <button type="submit">차트 읽기</button>
      </div>
      <p class="search__hints">
        <?php foreach ($hintSymbols as $sym): ?>
          <a href="?<?= h($qsProfile) ?>&symbol=<?= rawurlencode($sym) ?>"><?= h($sym) ?></a>
        <?php endforeach; ?>
        <?php if ($tab === AlphaEntries::TAB_NORAMU): ?>
          <a class="search__hints-scan" href="?<?= h($qsProfile) ?>&scan=kr_amount&limit=100">거래대금 스캔 →</a>
        <?php endif; ?>
      </p>
    </form>

    <?php if ($result !== null && !$result['ok']): ?>
      <section class="panel panel--error" role="alert">
        <h2>조회 실패</h2>
        <p><?= h($result['error'] ?? 'unknown') ?></p>
      </section>
    <?php endif; ?>

    <?php if (is_array($proposal)): ?>
      <?php
        $actionLabel = is_array($explain)
            ? (string) ($explain['action_label'] ?? $proposal['action'])
            : (string) $proposal['action'];
        $oneLine = '';
        if (is_array($newEntry) && ($newEntry['sentence'] ?? '') !== '') {
            $oneLine = (string) $newEntry['sentence'];
        } elseif (is_array($explain) && ($explain['summary'] ?? '') !== '') {
            $oneLine = (string) $explain['summary'];
        } else {
            $oneLine = (string) ($proposal['reason'] ?? '');
        }
        $px = $proposal['price'] ?? null;
        $pxDec = is_numeric($px) && (float) $px >= 100 ? 0 : 2;
        $stopTight = $proposal['invalidation_tight'] ?? null;
        $stopWide = $proposal['invalidation_wide'] ?? $proposal['invalidation'] ?? null;
        $dgoActive = !empty($proposal['digingonyou_method']['ok']);
        $targetTight = $dgoActive
            ? (is_array($target) && is_numeric($target['price'] ?? null) ? $target['price'] : null)
            : ($proposal['target_tight'] ?? (is_array($target) ? ($target['price'] ?? null) : null));
        $targetWide = $dgoActive
            ? null
            : ($proposal['target_wide'] ?? (is_array($target) ? ($target['wide'] ?? null) : null));
        $eta = is_array($proposal['eta'] ?? null) ? $proposal['eta'] : [];
        $etaStop = etaPair($eta['stop_tight'] ?? null, $eta['stop_wide'] ?? null);
        $etaTarget = etaPair($eta['target_tight'] ?? null, $eta['target_wide'] ?? null);
        $entryAvailable = is_array($newEntry) && !empty($newEntry['available']);
        $structureBroken = is_array($newEntry) && ($newEntry['status'] ?? '') === 'structure_broken';
        $entryMid = is_array($zone) && is_numeric($zone['mid'] ?? null)
            ? (float) $zone['mid']
            : (is_array($newEntry) && is_numeric($newEntry['price'] ?? null) ? (float) $newEntry['price'] : null);
        $entry2nd = is_array($zone) && is_numeric($zone['low'] ?? null) ? (float) $zone['low'] : null;
        $supportLv = is_numeric($proposal['level_support'] ?? null) ? (float) $proposal['level_support'] : null;
        if (
            $supportLv !== null
            && $entryMid !== null
            && $supportLv < $entryMid
            && ($stopWide === null || $supportLv > (float) $stopWide)
        ) {
            $entry2nd = $supportLv;
        }
        $liveEntryMid = $entryAvailable ? $entryMid : null;
        $liveEntry2nd = $entryAvailable ? $entry2nd : null;
        $memoNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
        $memoDate = $memoNow->format('n/j');
        $memoTime = $memoNow->format('H:i');
        $memoCurrent = is_numeric($px) ? fmtNum($px, $pxDec) . '원' : '—';
        if (isset($proposal['score']) && is_numeric($proposal['score'])) {
            $memoCurrent .= ' (현재 ' . (int) $proposal['score'] . '점)';
        }
        $memoEntry = is_numeric($liveEntryMid)
            ? fmtNum($liveEntryMid, $pxDec) . '원'
                . (is_numeric($liveEntry2nd) && abs((float) $liveEntry2nd - (float) $liveEntryMid) > 0.01
                    ? ' / ' . fmtNum($liveEntry2nd, $pxDec) . '원'
                    : '')
            : '—';
        $memoStop = is_numeric($stopTight)
            ? memoLevel($stopTight, $px, $pxDec)
                . (is_numeric($stopWide) && abs((float) $stopWide - (float) $stopTight) > 0.01
                    ? ' / ' . memoLevel($stopWide, $px, $pxDec)
                    : '')
            : '—';
        $memoTarget = is_numeric($targetTight)
            ? memoLevel($targetTight, $px, $pxDec)
                . (is_numeric($targetWide) && abs((float) $targetWide - (float) $targetTight) > 0.01
                    ? ' / ' . memoLevel($targetWide, $px, $pxDec)
                    : '')
            : '—';
        $memoStopEta = $etaStop !== '' ? ' (' . $etaStop . ' 소요)' : '';
        $memoTargetEta = $etaTarget !== '' ? ' (' . $etaTarget . ' 소요)' : '';
        if ($structureBroken) {
            $brokenStop = is_numeric($stopWide) ? $stopWide : ($proposal['invalidation'] ?? null);
            $memoText = sprintf(
                "%s일 (%s) 현재가 %s\n새로 살 가격 없음 — 손절선%s 이탈\n%s",
                $memoDate,
                $memoTime,
                $memoCurrent,
                is_numeric($brokenStop) ? ' ' . fmtNum($brokenStop, $pxDec) . '원' : '',
                is_array($newEntry) && ($newEntry['note'] ?? '') !== ''
                    ? (string) $newEntry['note']
                    : '저점이 올라오고 새 중간 가격이 잡힐 때까지 기다리세요.'
            );
        } elseif (!$entryAvailable) {
            $memoText = sprintf(
                "%s일 (%s) 현재가 %s\n새로 살 가격 없음\n%s",
                $memoDate,
                $memoTime,
                $memoCurrent,
                $oneLine !== '' ? $oneLine : '차트 그림이 잡힐 때까지 기다리세요.'
            );
        } else {
            $memoText = sprintf(
                "%s일 (%s) 현재가 %s\n%s 진입\n%s 손절%s\n%s 익절%s",
                $memoDate,
                $memoTime,
                $memoCurrent,
                $memoEntry,
                $memoStop,
                $memoStopEta,
                $memoTarget,
                $memoTargetEta
            );
        }
      ?>
      <?php
        $lvList = is_array($proposal['levels'] ?? null) ? $proposal['levels'] : [];
        $scoreBreakdown = is_array($proposal['score_breakdown'] ?? null)
            ? $proposal['score_breakdown']
            : null;
        $scoreItems = is_array($scoreBreakdown['items'] ?? null)
            ? $scoreBreakdown['items']
            : [];
        $zoneLabelText = is_array($explain) ? (string) ($explain['price_vs_zone_label'] ?? '') : '';
        $l1bits = [];
        if (!empty($proposal['lesson1_candle_recipe'])) {
            $l1bits[] = '선호캔들';
        }
        if (!empty($proposal['lesson1_upper_box'])) {
            $l1bits[] = '윗박스';
        }
        if (!empty($proposal['lesson1_breakout_no_box'])) {
            $l1bits[] = '돌파만';
        }
        $l1bonus = $proposal['lesson1_score_bonus'] ?? 0;
        $topPatternStatus = (string) ($proposal['top_pattern_status'] ?? 'none');
        $topPatternPhase = (string) ($proposal['top_pattern_phase'] ?? 'none');
        $inverseBottomStatus = (string) ($proposal['inverse_bottom_status'] ?? 'none');
        $topPatternAdjustment = (int) ($proposal['top_pattern_adjustment'] ?? 0);
        $topPatternPoints = $topPatternPhase === 'bounce_confirmed'
            ? 0
            : match ($topPatternStatus) {
                'confirmed' => -22,
                'warning' => -12,
                'watch' => -6,
                default => 0,
            };
        $inverseBottomPoints = match ($inverseBottomStatus) {
            'confirmed' => 12,
            'warning' => 5,
            default => 0,
        };
        $patternStatusKo = static fn (string $status): string => match ($status) {
            'confirmed' => '확정 경고',
            'warning' => '경고',
            'watch' => '관찰',
            default => '해당없음',
        };
        $patternPhaseKo = static fn (string $phase): string => match ($phase) {
            'distribution' => '분산·하락 진행',
            'capitulation' => '파동 하단 이탈',
            'bounce_confirmed' => '반등 확인',
            'top_watch' => '고점 약화 관찰',
            default => '',
        };
        $swingMethodKo = match ((string) ($proposal['swing_method'] ?? '')) {
            'flush_pre_peak' => '하방 슈팅 직전 고점',
            'pivot' => '꺾인 고점·저점',
            'window_20' => '최근 20봉 최고·최저',
            default => (string) ($proposal['swing_method'] ?? '—'),
        };
        $hourlyUnusual = !empty($proposal['unusual_volume_1h']);
        $hourlyLabel = $hourlyUnusual ? '1시간 거래량 급증' : '1시간 거래량 평범';
      ?>
      <section class="panel panel--result" data-sector="<?= h($sectorBucket) ?>">
        <div class="result__head">
          <div class="result__identity">
            <h2>
              <?php if ($stockName !== ''): ?>
                <?= h($stockName) ?>
                <span class="result__ticker"><?= h((string) ($result['symbol'] ?? '')) ?></span>
              <?php else: ?>
                <?= h((string) ($result['symbol'] ?? '')) ?>
              <?php endif; ?>
            </h2>
            <p class="result__meta">
              <span class="sector-dot sector-dot--<?= h($sectorBucket) ?>" aria-hidden="true"></span>
              <?php if ($marketLabel !== ''): ?>
                <?= h($marketLabel) ?>
                <span class="result__meta-sep">·</span>
              <?php endif; ?>
              <?= h($sectorLabel) ?>
              <span class="result__meta-sep">·</span>
              <?= h($profile->label) ?>
            </p>
          </div>
          <p class="score" data-action="<?= h((string) $proposal['action']) ?>" title="구조 점수">
            <span class="score__num"><?= h((string) ($proposal['score'] ?? '—')) ?></span>
            <span class="score__label">점수<?= tip("일봉 구조 점수 0~100. 불법과외1·가로 매물대·고점 붕괴/역저점·급등후급락 가감 포함.") ?></span>
          </p>
        </div>

        <p class="action" data-action="<?= h((string) $proposal['action']) ?>">
          <?= h($actionLabel) ?>
          <?php if ($isAnalyze): ?>
            <code class="action__code"><?= h((string) $proposal['action']) ?></code>
          <?php endif; ?>
        </p>
        <?php
          $smellStatusUi = (string) ($proposal['theme_smell_status'] ?? 'none');
          $smellLabelUi = (string) ($proposal['theme_smell_label'] ?? '');
          $smellNoteUi = (string) ($proposal['theme_smell_note'] ?? '');
        ?>
        <?php if ($smellStatusUi !== 'none'): ?>
          <p class="smell-rec" data-smell="<?= h($smellStatusUi) ?>">
            <?= h($smellLabelUi !== '' ? $smellLabelUi : '테마냄새') ?>
            <?php if ($smellNoteUi !== ''): ?>
              <span class="smell-rec__hint"><?= h($smellNoteUi) ?></span>
            <?php endif; ?>
          </p>
        <?php endif; ?>
        <?php
          $spikeDumpStatusUi = (string) ($proposal['spike_dump_status'] ?? 'none');
          $spikeDumpLabelUi = (string) ($proposal['spike_dump_label'] ?? '');
          $spikeDumpNoteUi = (string) ($proposal['spike_dump_note'] ?? '');
        ?>
        <?php if ($spikeDumpStatusUi !== 'none'): ?>
          <p class="smell-rec smell-rec--risk" data-spike-dump="<?= h($spikeDumpStatusUi) ?>">
            <?= h($spikeDumpLabelUi !== '' ? $spikeDumpLabelUi : '급등후급락') ?>
            <?php if ($spikeDumpNoteUi !== ''): ?>
              <span class="smell-rec__hint"><?= h($spikeDumpNoteUi) ?></span>
            <?php endif; ?>
          </p>
        <?php endif; ?>
        <?php if ($liveEntryMid !== null && $entryAvailable): ?>
          <p class="entry-rec">
            추천 진입
            <span class="mono"><?= h(fmtNum($liveEntryMid, $pxDec)) ?></span>
            <?php if ($liveEntry2nd !== null && abs($liveEntry2nd - $liveEntryMid) / max($liveEntryMid, 0.0001) > 0.008): ?>
              <span class="entry-rec__split">1차 <?= h(fmtNum($liveEntryMid, $pxDec)) ?> · 2차 <?= h(fmtNum($liveEntry2nd, $pxDec)) ?></span>
            <?php endif; ?>
            <?php if (is_numeric($px) && (float) $px > $entryMid * 1.01): ?>
              <span class="entry-rec__hint">지금가보다 아래 · 내려오면</span>
            <?php elseif (is_numeric($px) && $entry2nd !== null && (float) $px >= $entry2nd && (float) $px <= ($zone['high'] ?? $entryMid)): ?>
              <span class="entry-rec__hint">관심구간 안</span>
            <?php endif; ?>
          </p>
        <?php endif; ?>

        <div class="metric-grid" aria-label="핵심 가격 요약">
          <article class="metric">
            <span class="metric__label">현재가</span>
            <strong class="metric__value mono"><?= h(fmtNum($px, $pxDec)) ?></strong>
            <small><?= h((string) ($proposal['asof_kst'] ?? '')) ?></small>
          </article>
          <article class="metric metric--accent">
            <span class="metric__label">추천 진입</span>
            <?php if ($structureBroken): ?>
              <strong class="metric__value">없음</strong>
              <small>손절선 이탈 · 이번 그림 무효</small>
            <?php elseif ($liveEntryMid !== null): ?>
              <strong class="metric__value mono">
                <?= h(fmtNum($liveEntryMid, $pxDec)) ?>
                <?php if ($liveEntry2nd !== null && abs($liveEntry2nd - $liveEntryMid) / max($liveEntryMid, 0.0001) > 0.008): ?>
                  <span>/ <?= h(fmtNum($liveEntry2nd, $pxDec)) ?></span>
                <?php endif; ?>
              </strong>
              <small><?= h($zoneLabelText !== '' ? $zoneLabelText : '차트 구조 관심구간') ?></small>
            <?php else: ?>
              <strong class="metric__value">없음</strong>
              <small><?= h($zoneLabelText !== '' ? $zoneLabelText : '새로 살 가격 없음') ?></small>
            <?php endif; ?>
          </article>
          <article class="metric metric--danger">
            <span class="metric__label">손절</span>
            <?php if ($structureBroken): ?>
              <strong class="metric__value mono"><?= h(fmtNum($stopWide ?? $proposal['invalidation'] ?? null, $pxDec)) ?></strong>
              <small>이미 이탈</small>
            <?php else: ?>
              <strong class="metric__value mono">
                <?= h(fmtNum($stopTight, $pxDec)) ?>
                <?php if (is_numeric($stopWide) && is_numeric($stopTight) && abs((float) $stopWide - (float) $stopTight) > 0.01): ?>
                  <span>/ <?= h(fmtNum($stopWide, $pxDec)) ?></span>
                <?php endif; ?>
              </strong>
              <small><?= h(levelPct($stopWide ?? $stopTight, $px)) ?><?= $etaStop !== '' ? ' · ' . h($etaStop) : '' ?></small>
            <?php endif; ?>
          </article>
          <article class="metric metric--success">
            <span class="metric__label">익절</span>
            <?php if ($structureBroken || !$entryAvailable): ?>
              <strong class="metric__value">—</strong>
              <small>이번 그림 무효</small>
            <?php else: ?>
              <strong class="metric__value mono">
                <?= h(fmtNum($targetTight, $pxDec)) ?>
                <?php if (is_numeric($targetWide) && is_numeric($targetTight) && abs((float) $targetWide - (float) $targetTight) > 0.01): ?>
                  <span>/ <?= h(fmtNum($targetWide, $pxDec)) ?></span>
                <?php endif; ?>
              </strong>
              <small><?= h(levelPct($targetTight, $px)) ?><?= $etaTarget !== '' ? ' · ' . h($etaTarget) : '' ?></small>
            <?php endif; ?>
          </article>
        </div>

        <div class="card-grid">
          <article class="info-card">
            <h3 class="info-card__title">진입 · 가격</h3>
            <dl class="info-card__rows">
              <div>
                <dt>현재가</dt>
                <dd class="mono"><?= h(fmtNum($px, $pxDec)) ?></dd>
              </div>
              <div>
                <dt>추천 진입<?= tip("최근 고점·저점의 중간(절반 되돌림).
글에 숫자가 없어도 이 규칙을 씁니다.
1차=중간, 2차=관심구간 하단 또는 가로 지지.
손절선을 이미 깨면 이 숫자는 추천이 아닙니다.") ?></dt>
                <dd class="mono">
                  <?php if ($liveEntryMid !== null): ?>
                    <?= h(fmtNum($liveEntryMid, $pxDec)) ?>
                    <?php if ($liveEntry2nd !== null && abs($liveEntry2nd - $liveEntryMid) / max($liveEntryMid, 0.0001) > 0.008): ?>
                      <small>2차 <?= h(fmtNum($liveEntry2nd, $pxDec)) ?></small>
                    <?php endif; ?>
                  <?php else: ?>
                    없음
                    <?php if ($structureBroken && $entryMid !== null): ?>
                      <small>예전 중간가 <?= h(fmtNum($entryMid, $pxDec)) ?> · 근거 아님</small>
                    <?php endif; ?>
                  <?php endif; ?>
                </dd>
              </div>
              <div>
                <dt>관심구간</dt>
                <dd class="mono">
                  <?php if (is_array($zone)): ?>
                    <?= h(fmtNum($zone['low'] ?? null, $pxDec)) ?> – <?= h(fmtNum($zone['high'] ?? null, $pxDec)) ?>
                    <?php if ($structureBroken): ?>
                      <small>예전 그림 · 지금은 근거 아님</small>
                    <?php endif; ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </dd>
              </div>
              <div>
                <dt>손절<?= tip("괄호 퍼센트는 현재가 기준 등락률.") ?></dt>
                <dd class="mono">
                  <?php if (is_numeric($stopTight) && is_numeric($stopWide) && abs((float) $stopTight - (float) $stopWide) > 0.01): ?>
                    <?= h(fmtNum($stopTight, $pxDec)) ?>
                    <span class="level-pct"><?= h(levelPct($stopTight, $px)) ?></span>
                    <span class="dim">/</span>
                    <?= h(fmtNum($stopWide, $pxDec)) ?>
                    <span class="level-pct"><?= h(levelPct($stopWide, $px)) ?></span>
                  <?php else: ?>
                    <?php $singleStop = $stopWide ?? $stopTight; ?>
                    <?= h(fmtNum($singleStop, $pxDec)) ?>
                    <span class="level-pct"><?= h(levelPct($singleStop, $px)) ?></span>
                  <?php endif; ?>
                </dd>
              </div>
              <div>
                <dt>익절<?= tip("1차=직전 스윙 고점(일부 청산) / 2차=같은 스윙 폭을 고점 위로 한 번 더.\n고점과 2차 사이에 가로 저항이 있으면 2차는 그 벽.\n괄호 퍼센트는 현재가 기준 등락률.\n과거 글 목표가는 참고만.") ?></dt>
                <dd class="mono">
                  <?php if (is_numeric($targetTight) && is_numeric($targetWide) && abs((float) $targetTight - (float) $targetWide) > 0.01): ?>
                    <?= h(fmtNum($targetTight, $pxDec)) ?>
                    <span class="level-pct"><?= h(levelPct($targetTight, $px)) ?></span>
                    <span class="dim">/</span>
                    <?= h(fmtNum($targetWide, $pxDec)) ?>
                    <span class="level-pct"><?= h(levelPct($targetWide, $px)) ?></span>
                  <?php else: ?>
                    <?php $singleTarget = $targetWide ?? $targetTight ?? (is_array($target) ? ($target['price'] ?? null) : null); ?>
                    <?= h(fmtNum($singleTarget, $pxDec)) ?>
                    <span class="level-pct"><?= h(levelPct($singleTarget, $px)) ?></span>
                  <?php endif; ?>
                </dd>
              </div>
              <?php if ($etaStop !== '' || $etaTarget !== ''): ?>
              <div>
                <dt>도달<?= tip("날짜 예측이 아닙니다.\n최근 14봉 하루 평균 움직임(ATR)으로, 지금가에서 그 가격까지 거리를 나눈 거래일 거리입니다.\n실제로 그 방향으로 갈지는 모릅니다.") ?></dt>
                <dd>
                  <?php if ($etaStop !== ''): ?>
                    <small>손절 <?= h($etaStop) ?></small>
                  <?php endif; ?>
                  <?php if ($etaTarget !== ''): ?>
                    <small>익절 <?= h($etaTarget) ?></small>
                  <?php endif; ?>
                </dd>
              </div>
              <?php endif; ?>
            </dl>
            <?php if ($zoneLabelText !== ''): ?>
              <p class="info-card__status" data-vs="<?= h((string) ($explain['price_vs_zone'] ?? '')) ?>">
                <?= h($zoneLabelText) ?>
              </p>
            <?php endif; ?>
            <?php if ($oneLine !== ''): ?>
              <p class="info-card__note"><?= h($oneLine) ?></p>
            <?php endif; ?>
            <?php
              $learnedEntryPx = is_array($entryLearnedAuthor) && is_numeric($entryLearnedAuthor['price'] ?? null)
                  ? $entryLearnedAuthor['price']
                  : (is_array($entryLearned) ? ($entryLearned['price'] ?? null) : null);
            ?>
            <?php if (is_numeric($learnedEntryPx)): ?>
              <p class="info-card__foot">글 언급 진입(참고) <?= h(fmtNum($learnedEntryPx, $pxDec)) ?> · 지금 차트 추천 진입과 별개</p>
            <?php elseif (($proposal['size_hint'] ?? '') !== ''): ?>
              <p class="info-card__foot">비중 · <?= h((string) $proposal['size_hint']) ?></p>
            <?php endif; ?>
          </article>

          <article class="info-card memo-card">
            <h3 class="info-card__title">토스 메모용</h3>
            <textarea class="memo-card__text" id="toss-memo" readonly><?= h($memoText) ?></textarea>
            <button class="memo-card__copy" type="button" data-copy-target="toss-memo">메모 형식 복사</button>
            <p class="info-card__foot">토스 메모에 그대로 붙여넣을 수 있는 형식 · 퍼센트는 현재가 기준.</p>
          </article>

          <article class="info-card">
            <h3 class="info-card__title">손절 · 매물대</h3>
            <dl class="info-card__rows">
              <div>
                <dt>손절 2단<?= tip(!empty($proposal['stop_plan_adjusted'])
                    ? "예정 진입가보다 아래에 있는 구조 저점·가로 지지만 표시.\n현재가 기준 당일 저가가 진입가보다 높으면 손절에서 제외."
                    : "타이트=당일 저가 / 넓게=가로 매물대.") ?></dt>
                <dd class="mono">
                  <?= h(fmtNum($stopTight, $pxDec)) ?> <span class="dim">/</span> <?= h(fmtNum($stopWide, $pxDec)) ?>
                  <small>구조 <?= h(fmtNum($proposal['invalidation_structural'] ?? null, $pxDec)) ?></small>
                </dd>
              </div>
              <div>
                <dt>가로 매물대<?= tip("같은 가격대 접촉 횟수. 전환=저항→지지.") ?></dt>
                <dd>
                  <?php if ($lvList === []): ?>
                    —
                  <?php else: ?>
                    <span class="level-chip-row">
                      <?php foreach (array_slice($lvList, 0, 4) as $lv): ?>
                        <span class="level-chip mono">
                          <?= h(fmtNum($lv['price'] ?? null, 0)) ?>
                          <small><?= h((string) ($lv['touches'] ?? 0)) ?>회<?= !empty($lv['flip']) ? '·전환' : '' ?></small>
                        </span>
                      <?php endforeach; ?>
                    </span>
                  <?php endif; ?>
                </dd>
              </div>
              <div>
                <dt>저점 상향</dt>
                <dd class="mono">
                  <?= h((string) ($proposal['rising_lows_count'] ?? 0)) ?>회
                  <span class="dim">·</span>
                  고점 <?= h(is_numeric($proposal['dist_swing_high_pct'] ?? null) ? sprintf('%+.1f%%', (float) $proposal['dist_swing_high_pct']) : '—') ?>
                </dd>
              </div>
            </dl>
            <?php if (($proposal['levels_note'] ?? '') !== ''): ?>
              <p class="info-card__foot"><?= h((string) $proposal['levels_note']) ?></p>
            <?php endif; ?>
          </article>

          <?php if ($isAnalyze): ?>
            <?php if ($scoreBreakdown !== null && $scoreItems !== []): ?>
              <article class="info-card score-detail-card">
                <div class="score-detail__head">
                  <h3 class="info-card__title">점수 상세</h3>
                  <strong class="score-detail__total">
                    <?= h((string) ($scoreBreakdown['final_score'] ?? $proposal['score'] ?? '—')) ?> / 100
                  </strong>
                </div>
                <p class="score-detail__formula">
                  기본 <?= h((string) ($scoreBreakdown['base_score'] ?? 0)) ?> / <?= h((string) ($scoreBreakdown['base_max'] ?? 100)) ?>
                  <span>+</span>
                  불법과외 <?= h(sprintf('%+d', (int) ($scoreBreakdown['lesson_bonus'] ?? 0))) ?>
                  <span>+</span>
                  가로 지지 <?= h(sprintf('%+d', (int) ($scoreBreakdown['level_bonus'] ?? 0))) ?>
                  <span>+</span>
                  고점판독 <?= h(sprintf('%+d', (int) ($scoreBreakdown['top_pattern_adjustment'] ?? 0))) ?>
                  <span>+</span>
                  급등후급락 <?= h(sprintf('%+d', (int) ($scoreBreakdown['spike_dump_adjustment'] ?? 0))) ?>
                  <?php if ((int) ($scoreBreakdown['cap_adjustment'] ?? 0) !== 0): ?>
                    <span>→ 100점 상한 <?= h(sprintf('%+d', (int) $scoreBreakdown['cap_adjustment'])) ?></span>
                  <?php endif; ?>
                </p>
                <div class="score-detail__list">
                  <?php foreach ($scoreItems as $item): ?>
                    <?php
                      $earned = (int) ($item['earned'] ?? 0);
                      $max = (int) ($item['max'] ?? 0);
                      $isAdjustment = array_key_exists('min', $item)
                          || in_array((string) ($item['key'] ?? ''), ['lesson1', 'horizontal_support', 'top_pattern', 'spike_dump'], true);
                      $scoreText = $isAdjustment
                          ? sprintf('%+d점', $earned)
                          : sprintf('%d / %d점', $earned, $max);
                      if (($item['key'] ?? '') === 'lesson1') {
                          $scoreText .= sprintf(' (범위 %+d~+%d)', (int) ($item['min'] ?? -15), $max);
                      } elseif (($item['key'] ?? '') === 'horizontal_support') {
                          $scoreText .= ' (최대 +8)';
                      } elseif (($item['key'] ?? '') === 'top_pattern') {
                          $scoreText .= sprintf(' (범위 %+d~+%d)', (int) ($item['min'] ?? -22), $max);
                      } elseif (($item['key'] ?? '') === 'spike_dump') {
                          $scoreText .= sprintf(' (범위 %+d~+%d)', (int) ($item['min'] ?? -16), $max);
                      }
                    ?>
                    <div class="score-detail__item" data-status="<?= h((string) ($item['status'] ?? 'neutral')) ?>">
                      <div class="score-detail__item-head">
                        <strong><?= h((string) ($item['label'] ?? '')) ?></strong>
                        <span class="mono"><?= h($scoreText) ?></span>
                      </div>
                      <p><?= h((string) ($item['detail'] ?? '')) ?></p>
                    </div>
                  <?php endforeach; ?>
                </div>
                <p class="info-card__foot">
                  기본 점수는 100점 만점입니다. 불법과외·가로 지지·고점판독 가감 후 최종 점수는 0~100으로 제한됩니다.
                </p>
              </article>
            <?php endif; ?>

            <article class="info-card">
              <h3 class="info-card__title">패턴 · 보조</h3>
              <dl class="info-card__rows">
                <div>
                  <dt>불법과외1<?= tip("선호캔들·윗박스·돌파 추격 금지.") ?></dt>
                  <dd>
                    <?= h($l1bits !== [] ? implode(' · ', $l1bits) : '해당없음') ?>
                    <?php if (is_numeric($l1bonus) && (int) $l1bonus !== 0): ?>
                      <small><?= h(((int) $l1bonus > 0 ? '+' : '') . (string) (int) $l1bonus) ?></small>
                    <?php endif; ?>
                  </dd>
                </div>
                <div>
                  <dt>고점판독<?= tip("강한 상승 뒤 고점 돌파 실패·고점 하향·파동 중심/하단 시험·상승 추세선 이탈을 단계적으로 확인합니다.\n차트를 상하 반전한 같은 규칙으로 역저점도 봅니다.") ?></dt>
                  <dd>
                    고점 <?= h($patternStatusKo($topPatternStatus)) ?>
                    <?php if ($topPatternPoints !== 0): ?>
                      <span class="pattern-points pattern-points--danger"><?= h(sprintf('(%+d)', $topPatternPoints)) ?></span>
                    <?php endif; ?>
                    <?php if ($patternPhaseKo($topPatternPhase) !== ''): ?>
                      <small><?= h($patternPhaseKo($topPatternPhase)) ?></small>
                    <?php endif; ?>
                    <span class="dim">·</span>
                    저점체크 <?= h($patternStatusKo($inverseBottomStatus)) ?>
                    <?php if ($inverseBottomPoints !== 0): ?>
                      <span class="pattern-points pattern-points--success"><?= h(sprintf('(%+d)', $inverseBottomPoints)) ?></span>
                    <?php endif; ?>
                    <?php if ($topPatternAdjustment !== 0): ?>
                      <small>합계 <?= h(sprintf('%+d점', $topPatternAdjustment)) ?></small>
                    <?php endif; ?>
                  </dd>
                </div>
                <div>
                  <dt>스윙 고저<?= tip("관심구간(고점과 저점의 중간)을 잡을 때 어떤 고점·저점을 썼는지.\n· 하방 슈팅 직전 고점: 아래로 길게 빠진 봉이 있으면, 그 저점과 빠지기 직전 고점.\n· 꺾인 고점·저점: 그런 슈팅이 없으면 최근 꺾인 자리.\n· 최근 20봉: 그것도 없으면 그냥 20일 최고·최저.") ?></dt>
                  <dd><?= h($swingMethodKo) ?></dd>
                </div>
                <div>
                  <dt>1시간봉<?= tip("일봉 점수를 바꾸지 않는 보조 메모. 최근 1시간 거래량이 직전 평균의 1.8배 이상이면 급증. 평범이면 일봉 구조만 보면 됨.") ?></dt>
                  <dd><?= h($hourlyLabel) ?></dd>
                </div>
                <div>
                  <dt>과거 글 참고</dt>
                  <dd class="mono">
                    익절 <?= h(is_array($targetLearned) ? fmtNum($targetLearned['price'] ?? null, $pxDec) : '—') ?>
                    <span class="dim">·</span>
                    손절 <?= h(is_array($stopLearned) ? fmtNum($stopLearned['price'] ?? null, $pxDec) : '—') ?>
                  </dd>
                </div>
              </dl>
              <?php if (is_array($proposal['underlying_proxy'] ?? null)): ?>
                <p class="info-card__foot">
                  레버 <?= h((string) $proposal['underlying_proxy']['source_instrument']) ?>
                  → <?= h((string) $proposal['underlying_proxy']['spot']) ?>
                  <?= !empty($proposal['underlying_proxy']['inverse']) ? '(인버스)' : '' ?>
                </p>
              <?php endif; ?>
            </article>

            <?php if (is_array($perspective) && ($perspective['summary'] ?? '') !== ''): ?>
              <article class="info-card">
                <h3 class="info-card__title"><?= h($tabLabel) ?> 관점</h3>
                <p class="info-card__note"><?= h((string) $perspective['summary']) ?></p>
                <?php if (($proposal['reason'] ?? '') !== '' && $proposal['reason'] !== $oneLine): ?>
                  <p class="info-card__foot"><?= h((string) $proposal['reason']) ?></p>
                <?php endif; ?>
              </article>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </section>
    <?php elseif ($input === ''): ?>
      <section class="panel panel--idle">
        <h2>종목을 검색해 보세요</h2>
        <p>티커를 넣으면 관심구간·손절·익절을 바로 보여 줍니다.</p>
        <p class="idle__note">예: <code>NVDA</code>, <code>하이닉스</code>, <code>005380.KS</code></p>
        <?php if ($tab === AlphaEntries::TAB_NORAMU): ?>
          <p class="idle__note"><a href="?<?= h($qsProfile) ?>&scan=kr_amount&limit=100">국장 거래대금 스캔으로 후보 찾기 →</a></p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php endif; /* !scanMode */ ?>

    <footer class="foot">
      <p>개인 연구용 · 최종 매매 결정은 본인 책임 · <a href="docs/roadmap-2026-07-18.md">로드맵</a></p>
    </footer>
  </main>
  <script>
    document.querySelector('.search')?.addEventListener('submit', () => {
      document.body.classList.add('is-loading');
      document.body.setAttribute('aria-busy', 'true');
    });
    document.querySelectorAll('.btn-scan').forEach((el) => {
      el.addEventListener('click', () => {
        document.body.classList.add('is-loading');
        document.body.setAttribute('aria-busy', 'true');
      });
    });

    document.querySelectorAll('[data-copy-target]').forEach((button) => {
      button.addEventListener('click', async () => {
        const target = document.getElementById(button.getAttribute('data-copy-target'));
        if (!target) return;
        try {
          await navigator.clipboard.writeText(target.value);
          const original = button.textContent;
          button.textContent = '복사 완료';
          setTimeout(() => { button.textContent = original; }, 1400);
        } catch {
          target.focus();
          target.select();
          document.execCommand('copy');
        }
      });
    });

    (function () {
      const KEY = 'chart_ui_mode';
      const params = new URLSearchParams(location.search);
      if (!params.has('mode')) {
        const saved = localStorage.getItem(KEY);
        if (saved === 'simple' || saved === 'analyze') {
          params.set('mode', saved);
          location.replace(location.pathname + '?' + params.toString() + location.hash);
          return;
        }
      } else {
        localStorage.setItem(KEY, params.get('mode'));
      }
      document.querySelectorAll('.mode-toggle__link').forEach((a) => {
        a.addEventListener('click', () => {
          const m = a.getAttribute('data-mode');
          if (m) localStorage.setItem(KEY, m);
        });
      });
    })();

    (function () {
      const button = document.querySelector('.global-help__button');
      const panel = document.getElementById('global-help');
      const closeButton = panel?.querySelector('.global-help__close');
      if (!button || !panel) return;

      const close = () => {
        panel.hidden = true;
        button.setAttribute('aria-expanded', 'false');
      };
      const open = () => {
        panel.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        closeButton?.focus();
      };

      button.addEventListener('click', (event) => {
        event.stopPropagation();
        panel.hidden ? open() : close();
      });
      closeButton?.addEventListener('click', close);
      panel.addEventListener('click', (event) => event.stopPropagation());
      document.addEventListener('click', close);
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
          close();
          button.focus();
        }
      });
    })();

    (function () {
      const filters = document.getElementById('sector-filters');
      if (!filters) return;
      const rows = document.querySelectorAll('.scan-table tbody tr[data-sector]');
      filters.addEventListener('click', (e) => {
        const btn = e.target.closest('.sector-chip');
        if (!btn) return;
        const filter = btn.getAttribute('data-filter') || btn.getAttribute('data-sector') || 'all';
        filters.querySelectorAll('.sector-chip').forEach((c) => {
          c.classList.remove('is-active');
          c.setAttribute('aria-pressed', 'false');
        });
        btn.classList.add('is-active');
        btn.setAttribute('aria-pressed', 'true');
        rows.forEach((tr) => {
          const match = filter === 'all'
            || (filter === 'smell' && tr.getAttribute('data-smell') === '1')
            || (filter === 'lagging' && tr.getAttribute('data-lagging') === '1')
            || (filter === 'spike-dump' && tr.getAttribute('data-spike-dump') === '1')
            || tr.getAttribute('data-sector') === filter;
          tr.classList.toggle('is-hidden-sector', !match);
        });
      });
    })();

    (function () {
      const sortBar = document.getElementById('scan-sort');
      const tbody = document.querySelector('.scan-table tbody');
      if (!sortBar || !tbody) return;
      const rows = Array.from(tbody.querySelectorAll('tr'));
      const entryTier = (tr) => {
        if (tr.getAttribute('data-buy-now') === '1') return 5;
        if (tr.getAttribute('data-entry-rec') === '1') return 4;
        const st = tr.getAttribute('data-entry-status') || '';
        if (st === 'in_zone') return 3;
        if (st === 'below_half_wait_recover') return 2;
        if (st === 'wait_pullback') return 1;
        return 0;
      };
      const applySort = (mode) => {
        const sorted = rows.slice().sort((a, b) => {
          if (mode === 'entry') {
            const ta = entryTier(a);
            const tb = entryTier(b);
            if (ta !== tb) return tb - ta;
          }
          const sa = Number(a.getAttribute('data-score') || -1);
          const sb = Number(b.getAttribute('data-score') || -1);
          if (sa !== sb) return sb - sa;
          return Number(a.getAttribute('data-orig') || 0) - Number(b.getAttribute('data-orig') || 0);
        });
        sorted.forEach((tr) => tbody.appendChild(tr));
      };
      sortBar.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-sort]');
        if (!btn) return;
        const mode = btn.getAttribute('data-sort') || 'score';
        sortBar.querySelectorAll('[data-sort]').forEach((c) => {
          c.classList.remove('is-active');
          c.setAttribute('aria-pressed', 'false');
        });
        btn.classList.add('is-active');
        btn.setAttribute('aria-pressed', 'true');
        applySort(mode);
      });
    })();

    (function () {
      const closeAll = (except) => {
        document.querySelectorAll('.tip.is-open').forEach((btn) => {
          if (btn === except) return;
          btn.classList.remove('is-open');
          btn.setAttribute('aria-expanded', 'false');
          const bubble = btn.querySelector('.tip__bubble');
          if (bubble) bubble.hidden = true;
        });
      };

      document.querySelectorAll('.tip').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          const open = btn.classList.contains('is-open');
          closeAll();
          if (!open) {
            btn.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');
            const bubble = btn.querySelector('.tip__bubble');
            if (bubble) bubble.hidden = false;
          }
        });
      });

      document.addEventListener('click', () => closeAll());
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeAll();
      });
    })();
  </script>
</body>
</html>
