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
use ChartEntryLab\YahooChartClient;

$profileId = isset($_GET['profile']) ? (string) $_GET['profile'] : 'account1';
if (!in_array($profileId, ['account1', 'custom', 'isa'], true)) {
    $profileId = 'account1';
}
$profile = AccountProfile::fromId($profileId);
$tabInfo = AlphaEntries::normalizeTab(isset($_GET['tab']) ? (string) $_GET['tab'] : null);
$tab = $tabInfo['id'];
$tabLabel = $tabInfo['label'];

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

$scanMode = isset($_GET['scan']) && (string) $_GET['scan'] === 'kr_amount' && $tab === AlphaEntries::TAB_NORAMU;
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
    );
    if (isset($_GET['format']) && (string) $_GET['format'] === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($scanReport, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}

$hintSymbols = $profile->coreSymbols ?? ['MU', 'SNDK', 'NVDA', 'AAPL', '005930.KS', '005380.KS'];
$tvSymbol = null;
if (is_array($result) && $result['ok'] && !empty($result['symbol'])) {
    $tvSymbol = SymbolMap::toTradingView((string) $result['symbol']);
}

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

/** 클릭하면 설명 말풍선이 나오는 ? 버튼 (텍스트의 줄바꿈 유지) */
if (!function_exists('tip')) {
    function tip(string $text): string
    {
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
$explain = is_array($proposal) ? ($proposal['explain'] ?? null) : null;
$newEntry = is_array($proposal) ? ($proposal['new_entry'] ?? null) : null;
$tvUrl = is_array($result) ? ($result['tradingview_url'] ?? null) : null;
$entryLearnedAuthor = is_array($proposal) ? ($proposal['entry_learned_author'] ?? null) : null;
$qsBase = 'tab=' . rawurlencode($tab) . '&profile=' . rawurlencode($profileId);
$qsProfile = $qsBase;
$shellClass = $scanMode ? 'shell shell--wide' : 'shell';
$pageTitle = match (true) {
    $scanMode => 'noramu — 국장 거래대금 스캔',
    $tab === AlphaEntries::TAB_DIGINGONYOU => 'noramu+ — 디깅온유',
    $tab === AlphaEntries::TAB_MERGED => 'noramu+ — 합침',
    default => 'noramu — 진입 보조',
};
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+KR:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="bg" aria-hidden="true"></div>
  <main class="<?= h($shellClass) ?>">
    <header class="brand">
      <p class="brand__name"><?= $tab === AlphaEntries::TAB_NORAMU ? 'noramu' : 'noramu+' ?></p>
      <p class="brand__sub">
        <?php if ($tab === AlphaEntries::TAB_DIGINGONYOU): ?>
          디깅온유 글 데이터 · 같은 차트 규칙으로 점수
        <?php elseif ($tab === AlphaEntries::TAB_MERGED): ?>
          노라무 + 디깅온유 합침 · 피드·점수 맥락을 함께 사용
        <?php else: ?>
          노라무식 차트 읽기 · 티커 넣으면 사는 구간·손절선·새로 살 가격 힌트
        <?php endif; ?>
      </p>
    </header>

    <nav class="tabs" aria-label="데이터 소스">
      <?php
        $tabDefs = [
            AlphaEntries::TAB_NORAMU => '노라무',
            AlphaEntries::TAB_DIGINGONYOU => '디깅온유',
            AlphaEntries::TAB_MERGED => '합침',
        ];
        foreach ($tabDefs as $tid => $tlabel):
            $href = '?tab=' . rawurlencode($tid) . '&profile=' . rawurlencode($profileId);
            if ($input !== '') {
                $href .= '&symbol=' . rawurlencode($input);
            }
      ?>
        <a
          class="tabs__link<?= $tab === $tid ? ' is-active' : '' ?>"
          href="<?= h($href) ?>"
          <?= $tab === $tid ? 'aria-current="page"' : '' ?>
        ><?= h($tlabel) ?></a>
      <?php endforeach; ?>
    </nav>

    <form class="search" method="get" action="">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <div class="profile-row">
        <label class="search__label" for="profile">
          계좌 프로필
          <?= tip("1번 계좌: 메모리 스윙 · 나눠서 조금씩
커스텀: 같은 차트 규칙 · 비중만 조금 여유
ISA: 문턱 더 높고 비중 작게

레버는 어느 프로필이든 본주로만 바꿉니다.") ?>
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
          placeholder="AAPL, NVDA, 005380.KS, 하이닉스, 애플…"
          autocomplete="off"
          autofocus
        >
        <button type="submit">차트 읽기</button>
      </div>
      <p class="search__help">
        미장 티커 그대로 · 국장은 <code>005380.KS</code> / 코스닥 <code>035420.KQ</code> · 아래는 빠른 링크일 뿐 종목 제한이 아닙니다.
      </p>
      <p class="search__hints">
        <?php foreach ($hintSymbols as $sym): ?>
          <a href="?<?= h($qsProfile) ?>&symbol=<?= rawurlencode($sym) ?>"><?= h($sym) ?></a>
        <?php endforeach; ?>
      </p>
    </form>

    <?php if ($tab === AlphaEntries::TAB_NORAMU): ?>
    <section class="panel panel--scan">
      <div class="scan__head">
        <div>
          <h2>
            국장 거래대금 스캔
            <?= tip("네이버 코스피·코스닥 거래량 상위 표의 거래대금을 합쳐
재정렬한 뒤 TOP N에 노라무 점수(1~100)를 붙입니다.

· 점수 = 눌림 롱 구조 점수
· 진입 추천 = 내려올 때 나눠서/관심 구간 + 새로 살 가격 있음

첫 실행은 Yahoo 일봉을 받아 수 분 걸릴 수 있습니다.
결과·현재가·신규진입 문장은 약 5분 캐시됩니다.
«새로고침»은 네이버 순위와 Yahoo 시세를 다시 받습니다.") ?>
          </h2>
          <p class="scan__meta">코스피+코스닥 · 거래대금 상위 · 프로필 <?= h($profile->label) ?></p>
        </div>
        <div class="scan__actions">
          <a class="btn-scan" href="?<?= h($qsProfile) ?>&scan=kr_amount&limit=100">거래대금 TOP 100 점수</a>
          <a class="btn-scan btn-scan--ghost" href="?<?= h($qsProfile) ?>&scan=kr_amount&limit=30">빠른 30</a>
          <?php if ($scanMode): ?>
            <a class="btn-scan btn-scan--ghost" href="?<?= h($qsProfile) ?>&scan=kr_amount&limit=<?= (int) $scanLimit ?>&refresh=1">새로고침</a>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($scanMode && is_array($scanReport)): ?>
        <?php
          $scanSummary = is_array($scanReport['summary'] ?? null) ? $scanReport['summary'] : [];
          $scanRows = is_array($scanReport['rows'] ?? null) ? $scanReport['rows'] : [];
        ?>
        <?php if (empty($scanReport['ok'])): ?>
          <p class="scan__error" role="alert"><?= h((string) ($scanReport['error'] ?? '스캔 실패')) ?></p>
        <?php else: ?>
          <p class="scan__summary">
            <?= (int) ($scanSummary['total'] ?? 0) ?>종 ·
            점수 <?= (int) ($scanSummary['scored'] ?? 0) ?> ·
            진입 추천 <strong><?= (int) ($scanSummary['recommend'] ?? 0) ?></strong>
            <span class="scan__time"><?= h((string) ($scanReport['fetched_at'] ?? '')) ?> KST</span>
          </p>
          <div class="scan-table-wrap">
            <table class="scan-table">
              <thead>
                <tr>
                  <th>대금순위</th>
                  <th>종목</th>
                  <th>시장</th>
                  <th>현재가</th>
                  <th>거래대금</th>
                  <th>점수</th>
                  <th>진입</th>
                  <th>행동</th>
                  <th>신규진입</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($scanRows as $sr): ?>
                  <?php
                    $sym = (string) ($sr['yahoo'] ?? '');
                    $rec = !empty($sr['entry_recommend']);
                    $amtEok = is_numeric($sr['amount_million'] ?? null)
                        ? number_format((float) $sr['amount_million'] / 100, 0) . '억'
                        : '—';
                    $scanPx = $sr['price'] ?? $sr['naver_price'] ?? null;
                    $scanPxDec = is_numeric($scanPx) && (float) $scanPx >= 100 ? 0 : 2;
                  ?>
                  <tr<?= $rec ? ' class="is-recommend"' : '' ?>>
                    <td class="mono"><?= (int) ($sr['amount_rank'] ?? 0) ?></td>
                    <td>
                      <a href="?<?= h($qsProfile) ?>&symbol=<?= rawurlencode($sym) ?>">
                        <?= h((string) ($sr['name'] ?? $sym)) ?>
                      </a>
                      <span class="scan-code mono"><?= h($sym) ?></span>
                    </td>
                    <td><?= h((string) ($sr['market'] ?? '')) ?></td>
                    <td class="mono"><?= h(fmtNum($scanPx, $scanPxDec)) ?></td>
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
                    <td><?= h((string) ($sr['action_label'] ?? $sr['action'] ?? '—')) ?></td>
                    <td class="scan-entry"><?= h((string) ($sr['new_entry_sentence'] ?? $sr['error'] ?? '—')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="scan__note">
            표는 진입 추천·점수 순으로 정렬되어 있습니다. 거래대금 원래 순위는 «대금순위» 열을 보세요.
            CLI: <code>php bin/scan_kr_amount.php --profile=<?= h($profileId) ?> --limit=100</code>
          </p>
        <?php endif; ?>
      <?php else: ?>
        <p class="scan__idle">버튼을 누르면 국장 거래대금 상위 종목에 노라무 점수와 진입 추천 여부를 매깁니다.</p>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($result !== null && !$result['ok']): ?>
      <section class="panel panel--error" role="alert">
        <h2>조회 실패</h2>
        <p><?= h($result['error'] ?? 'unknown') ?></p>
      </section>
    <?php endif; ?>

    <?php if (is_array($proposal)): ?>
      <section class="panel panel--result">
        <div class="result__head">
          <div>
            <h2><?= h((string) ($result['symbol'] ?? '')) ?></h2>
            <p class="profile-tag"><?= h($profile->label) ?> · 탭 <?= h($tabLabel) ?></p>
          </div>
          <p class="score" data-action="<?= h((string) $proposal['action']) ?>">
            <span class="score__num"><?= h((string) ($proposal['score'] ?? '—')) ?></span>
            <span class="score__label">
              <?php if ($tab === AlphaEntries::TAB_DIGINGONYOU): ?>
                디깅온유 기법 점수
                <?= tip("급락 후 저점대에 다시 들어오는 규칙으로
현재 일봉을 채점합니다 (0~100).

노라무 중간 가격 점수와 다릅니다.
과거 글 절대가가 아니라 오늘 차트 그림입니다.") ?>
              <?php else: ?>
                공통 차트 점수
                <?= tip("Yahoo 일봉 점수(0~100)입니다.
노라무식 중간 가격·저점 올림 +
불법과외1(선호캔들·돌파후 윗박스) 가산입니다.") ?>
              <?php endif; ?>
            </span>
          </p>
        </div>

        <?php if (is_array($perspective)): ?>
          <div class="perspective" data-mode="<?= h((string) ($perspective['mode'] ?? '')) ?>">
            <p class="perspective__title">
              <?= h($tabLabel) ?> 관점
              <?= tip("차트 점수는 공통이고, 이 블록은 탭에 연결된 작성자 글에서
매수/매도/지금은 안 삼 방향과 언급 가격을 읽은 결과입니다.

· 노라무 탭 → 노라무 entries
· 디깅온유 탭 → 디깅온유 entries
· 합침 → 둘을 나란히 비교") ?>
            </p>
            <p class="perspective__summary"><?= h((string) ($perspective['summary'] ?? '')) ?></p>
            <?php if (!empty($proposal['author_action_label'])): ?>
              <p class="perspective__action">
                작성자 쪽 행동: <strong><?= h((string) $proposal['author_action_label']) ?></strong>
                <?php if (!empty($proposal['chart_action'])): ?>
                  <span class="perspective__chart">· 차트만 보면 <code><?= h((string) $proposal['chart_action']) ?></code></span>
                <?php endif; ?>
              </p>
            <?php endif; ?>
            <?php if (is_array($entryLearnedAuthor) && isset($entryLearnedAuthor['price'])): ?>
              <p class="perspective__level mono">
                작성자 언급 매수가 중앙값: <?= h(fmtNum($entryLearnedAuthor['price'], 0)) ?>
                (n=<?= (int) ($entryLearnedAuthor['sample_count'] ?? 0) ?>)
              </p>
            <?php endif; ?>

            <div class="perspective__authors">
              <?php foreach ((array) ($perspective['authors'] ?? []) as $ap): ?>
                <?php if (!is_array($ap)) {
                    continue;
                } ?>
                <div class="perspective-card">
                  <p class="perspective-card__head">
                    <span class="author-badge"><?= h((string) ($ap['author'] ?? '')) ?></span>
                    <span><?= h((string) ($ap['author_action_label'] ?? '')) ?></span>
                    <span class="perspective-card__n">글 <?= (int) ($ap['post_count'] ?? 0) ?>건</span>
                  </p>
                  <p class="perspective-card__sum"><?= h((string) ($ap['summary'] ?? '')) ?></p>
                  <?php
                    $tl = is_array($ap['trade_levels'] ?? null) ? $ap['trade_levels'] : null;
                    $tz = is_array($tl['entry_zone'] ?? null) ? $tl['entry_zone'] : null;
                  ?>
                  <?php if ($tz !== null || (is_array($tl) && isset($tl['invalidation']))): ?>
                    <p class="perspective-card__levels mono">
                      <?php if ($tz !== null): ?>
                        구간 <?= h(fmtNum($tz['low'] ?? null, 0)) ?>–<?= h(fmtNum($tz['high'] ?? null, 0)) ?>
                      <?php endif; ?>
                      <?php if (is_array($tl) && isset($tl['invalidation'])): ?>
                        · 무효 <?= h(fmtNum($tl['invalidation'], 0)) ?>
                      <?php endif; ?>
                    </p>
                  <?php endif; ?>
                  <?php if (!empty($ap['posts']) && is_array($ap['posts'])): ?>
                    <ul class="perspective-posts">
                      <?php foreach (array_slice($ap['posts'], 0, 4) as $post): ?>
                        <?php if (!is_array($post)) {
                            continue;
                        } ?>
                        <li>
                          <?php if (!empty($post['url'])): ?>
                            <a href="<?= h((string) $post['url']) ?>" target="_blank" rel="noopener"><?= h((string) ($post['title'] ?: '글')) ?></a>
                          <?php else: ?>
                            <?= h((string) ($post['title'] ?: '글')) ?>
                          <?php endif; ?>
                          <span class="perspective-posts__meta">
                            <?= h(substr((string) ($post['posted_at_kst'] ?? ''), 0, 10)) ?>
                            · <?= h((string) ($post['stance'] ?? '')) ?>
                          </span>
                          <span class="perspective-posts__snip"><?= h((string) ($post['snippet'] ?? '')) ?></span>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <p class="action">
          <?= h(is_array($explain) ? (string) ($explain['action_label'] ?? $proposal['action']) : (string) $proposal['action']) ?>
          <code class="action__code"><?= h((string) $proposal['action']) ?></code>
          <?= tip("탭 관점을 반영한 최종 행동 코드입니다.

차트만의 행동은 «차트만 보면»에 남아 있습니다.
· 지금은 안 삼 → 지켜보기
· 내려올 때 나눠서 / 관심 → 사는 후보
· 새로 사지 말 것 → 쫓아 사기·신규 비권고") ?>
        </p>
        <?php if (is_array($newEntry)): ?>
          <div class="new-entry" data-status="<?= h((string) ($newEntry['status'] ?? '')) ?>">
            <p class="new-entry__label">
              적정 신규 진입가
              <?= tip("“지금 시장가로 사라”가 아닙니다.
지금 차트 기준으로, 이 가격대면 새로 나눠서 사기를 검토할 수 있다는 뜻입니다.

예: 현재가 120만 → 70~75만이면 새로 가능

손절선 아래이거나 숏 쪽이면
→ 새로 살 가격 없음") ?>
            </p>
            <p class="new-entry__sentence"><?= h((string) ($newEntry['sentence'] ?? '')) ?></p>
            <?php if (($newEntry['note'] ?? '') !== ''): ?>
              <p class="new-entry__note"><?= h((string) $newEntry['note']) ?></p>
            <?php endif; ?>
            <?php if (!empty($newEntry['available']) && isset($newEntry['low'], $newEntry['high'])): ?>
              <p class="new-entry__range mono">
                <?= h(fmtNum($newEntry['low'], 2)) ?> – <?= h(fmtNum($newEntry['high'], 2)) ?>
                <?php if (isset($newEntry['price'])): ?>
                  <span>(중심 <?= h(fmtNum($newEntry['price'], 2)) ?>)</span>
                <?php endif; ?>
              </p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if (is_array($explain)): ?>
          <p class="zone-status" data-vs="<?= h((string) ($explain['price_vs_zone'] ?? '')) ?>">
            <?= h((string) ($explain['price_vs_zone_label'] ?? '')) ?>
            <?= tip("현재가가 관심 사는 구간 · 손절선 대비 어디에 있는지입니다.

· 구간 위 → 쫓아 사는 구간
· 구간 안 → 관심 사는 구간
· 구간 아래 → 중간 가격보다 낮음
· 손절선 아래 → 차트 그림이 깨진 쪽") ?>
          </p>
        <?php endif; ?>
        <?php if (is_array($proposal['underlying_proxy'] ?? null)): ?>
          <p class="proxy-note">
            레버 치환: <?= h((string) $proposal['underlying_proxy']['source_instrument']) ?>
            → <?= h((string) $proposal['underlying_proxy']['spot']) ?>
            <?= !empty($proposal['underlying_proxy']['inverse']) ? '(인버스·방향 반전)' : '' ?>
            <?= tip("SOXS · 코루 · 2배 등 레버/인버스를
직접 사지 않고 본주 차트로 바꿔 봅니다.

· 인버스(SOXS) 롱 → 본주(MU) 숏 바이어스
· 레버 호가 ≠ 본주 진입가") ?>
          </p>
        <?php endif; ?>
        <p class="reason">
          <?= h((string) ($proposal['reason'] ?? '')) ?>
          <?= tip("점수 · 구조 · 최근 시황/레버 바이어스를
합쳐 나온 한 줄 판단입니다.

자동매매 신호가 아니라 보조 설명입니다.") ?>
        </p>
        <?php if (is_string($tvUrl) && $tvUrl !== ''): ?>
          <p class="tv-link">
            <a href="<?= h($tvUrl) ?>" target="_blank" rel="noopener noreferrer">TradingView 새 창</a>
          </p>
        <?php endif; ?>

        <?php if (is_string($tvSymbol) && $tvSymbol !== ''): ?>
          <div class="chart-panel">
            <p class="chart-panel__label">
              일봉 차트 (구조 확인용)
              <?= tip("TradingView 일봉입니다.
숫자로 나온 중간 가격 · 손절선을 눈으로 맞춰 볼 때 씁니다.

차트 위 선은 아직 자동 표시되지 않으니
아래 숫자를 기준으로 보시면 됩니다.") ?>
            </p>
            <div class="tradingview-widget-container" id="tv_chart_host">
              <div id="tv_chart"></div>
            </div>
          </div>
          <script src="https://s3.tradingview.com/tv.js"></script>
          <script>
            document.addEventListener('DOMContentLoaded', () => {
              if (typeof TradingView === 'undefined') return;
              new TradingView.widget({
                container_id: 'tv_chart',
                symbol: <?= json_encode($tvSymbol, JSON_UNESCAPED_UNICODE) ?>,
                interval: 'D',
                timezone: 'Asia/Seoul',
                theme: 'light',
                style: '1',
                locale: 'kr',
                toolbar_bg: '#f3efe6',
                enable_publishing: false,
                hide_top_toolbar: false,
                hide_legend: false,
                save_image: false,
                height: 420,
                width: '100%',
              });
            });
          </script>
        <?php endif; ?>

        <?php
          $levelMethodLabel = (string) ($proposal['level_method_label'] ?? '');
          $isDgoLevels = $tab === AlphaEntries::TAB_DIGINGONYOU
              || str_contains((string) ($proposal['level_method'] ?? ''), 'digingonyou');
          $zoneLabel = $isDgoLevels ? '급락대 관심 구간 (기법)' : '관심 사는 구간 (중간 가격)';
          $invLabel = $isDgoLevels ? '손절선 (급락 저점)' : '손절선 (최근 저점)';
          $tgtLabel = $isDgoLevels ? '익절 힌트 — 올라오면 나눠 팔기' : '익절 힌트 — 규칙';
          $chartZone = is_array($proposal['chart_entry_zone'] ?? null) ? $proposal['chart_entry_zone'] : null;
          $chartInv = $proposal['chart_invalidation'] ?? null;
        ?>
        <?php if ($levelMethodLabel !== ''): ?>
          <p class="level-method"><?= h($levelMethodLabel) ?></p>
        <?php endif; ?>
        <dl class="levels">
          <div>
            <dt>현재가 <?= tip("Yahoo 시세입니다.
당일 종가가 아직 안 찍혀도 regularMarketPrice(현재가)를 씁니다.
탭과 무관하게 같은 시세입니다.") ?></dt>
            <dd><?php
              $px = $proposal['price'] ?? null;
              $pxDec = is_numeric($px) && (float) $px >= 100 ? 0 : 2;
              echo h(fmtNum($px, $pxDec));
            ?></dd>
          </div>
          <div<?= is_array($explain) && ($explain['price_vs_zone'] ?? '') !== 'in_zone' ? ' class="levels__dim"' : '' ?>>
            <dt><?= h($zoneLabel) ?> <?= tip($isDgoLevels
                ? "디깅온유 기법: 지금 차트에서 급락한 저점 ~ 저점+8%.\n과거 글의 169만 같은 절대가가 아니라,\n오늘 급락대를 씁니다.\n노라무식 중간 가격과 다릅니다."
                : "최근 고점과 저점의 중간(절반) ±4%입니다.\n노라무식 “중간쯤에서 내려올 때” 자리입니다.") ?></dt>
            <dd>
              <?php if (is_array($zone)): ?>
                <?= h(fmtNum($zone['low'] ?? null, 2)) ?> – <?= h(fmtNum($zone['high'] ?? null, 2)) ?>
                <small><?= h((string) ($zone['rule'] ?? '')) ?></small>
              <?php else: ?>
                —
              <?php endif; ?>
              <?php if ($isDgoLevels && is_array($chartZone)): ?>
                <small class="levels__alt">차트 중간 가격(참고): <?= h(fmtNum($chartZone['low'] ?? null, 2)) ?> – <?= h(fmtNum($chartZone['high'] ?? null, 2)) ?></small>
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt><?= h($invLabel) ?> <?= tip($isDgoLevels
                ? "디깅온유: 손절·매수가 아래(약 −5%)로 잡은 손절선입니다.\n노라무 최근 저점과 숫자가 다를 수 있습니다."
                : "최근 저점입니다. 이 아래로 깨지면 이번 매수 계획은 끝 후보입니다.") ?></dt>
            <dd>
              <?= h(fmtNum($proposal['invalidation'] ?? null, 4)) ?>
              <?php if ($isDgoLevels && $chartInv !== null): ?>
                <small class="levels__alt">차트 최근 저점(참고): <?= h(fmtNum($chartInv, 4)) ?></small>
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt><?= h($tgtLabel) ?> <?= tip($isDgoLevels
                ? "글의 매도/익절 언급 또는 매수가 대비 대략 +12% 추정입니다."
                : "중간 가격~최근 고점 사이 등 차트 규칙 익절 후보입니다.") ?></dt>
            <dd>
              <?php if (is_array($target)): ?>
                <?= h(fmtNum($target['price'] ?? null, 4)) ?>
                <small><?= h((string) ($target['rule'] ?? '')) ?></small>
              <?php else: ?>
                —
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt>익절 참고 — 과거 글 <?= tip("노라무 원글에 명시된 목표가들의
중앙값(참고용)입니다.

현재 차트의 “적정 목표가”가 아닙니다.
글이 없으면 비어 있습니다.") ?></dt>
            <dd>
              <?php if (is_array($targetLearned)): ?>
                <?= h(fmtNum($targetLearned['price'] ?? null, 4)) ?>
                <small>글 <?= h((string) ($targetLearned['sample_count'] ?? 0)) ?>건 중앙값 · <?= h(is_array($explain) ? (string) ($explain['target_learned_note'] ?? '') : '참고용') ?></small>
              <?php else: ?>
                — <small>명시 목표가 있는 원글 없음 (현재 적정가 아님)</small>
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt>손절 참고 — 과거 글 <?= tip("원글에 적힌 손절가들의
중앙값(참고용)입니다.

위 “손절선”과는 별개입니다.
지금 차트 손절 후보는 손절선 쪽을 우선하세요.") ?></dt>
            <dd>
              <?php if (is_array($stopLearned)): ?>
                <?= h(fmtNum($stopLearned['price'] ?? null, 4)) ?>
                <small>글 <?= h((string) ($stopLearned['sample_count'] ?? 0)) ?>건 중앙값 · 지금 차트 손절선과 별개</small>
              <?php else: ?>
                —
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt>비중 <?= tip("계좌 프로필(1번 / 커스텀 / ISA)에 따른
나눠서 사기 · 현금 힌트입니다.

실제 주문 비중은 본인 판단이며,
프로그램은 제안만 합니다.") ?></dt>
            <dd><?= h((string) ($proposal['size_hint'] ?? '—')) ?></dd>
          </div>
          <div>
            <dt>고저 잡기 <?= tip("최근 고점 · 저점을 어떻게 잡았는지입니다.

flush_pre_peak:
최근 급락(몸통 / 긴 아래꼬리)
이후 · 직전 고점을 쓰는 방식입니다.

이 고저로 중간 가격 · 손절선이 계산됩니다.") ?></dt>
            <dd><code><?= h((string) ($proposal['swing_method'] ?? '—')) ?></code></dd>
          </div>
          <div>
            <dt>1h 보조 <?= tip("1시간봉 거래량이 최근 평균보다
크게 튀었는지입니다.

일봉 구조를 대체하지 않습니다.
“이상 거래량 있으니 차트 한 번 더 보라”는
보조 메모입니다.") ?></dt>
            <dd>
              <?= !empty($proposal['unusual_volume_1h']) ? '이상 거래량' : '평범' ?>
              <small><?= h((string) ($proposal['hourly_note'] ?? '')) ?></small>
            </dd>
          </div>
          <div>
            <dt>불법과외1 <?= tip("노라무 «차트 불법과외 -1편» 규칙입니다.

· 선호 캔들: 장대양→도지→거래량없는장대음→장대양
  (매물대 돌파 후만 가산, 하락 중이면 감점)
· 수렴 돌파 추격 금지 → 돌파 후 윗구간 박스
· 저점 대비 +40% 후 타이트 구간은 불신

점수에 ±가산되며, 돌파만 있으면 추격 롱을 막습니다.") ?></dt>
            <dd>
              <?php
                $l1bits = [];
                if (!empty($proposal['lesson1_candle_recipe'])) {
                    $l1bits[] = '선호캔들';
                }
                if (!empty($proposal['lesson1_upper_box'])) {
                    $l1bits[] = '윗박스';
                }
                if (!empty($proposal['lesson1_breakout_no_box'])) {
                    $l1bits[] = '돌파만(박스없음)';
                }
                $bonus = $proposal['lesson1_score_bonus'] ?? 0;
                echo h($l1bits !== [] ? implode(' · ', $l1bits) : '해당없음');
                if (is_numeric($bonus) && (int) $bonus !== 0) {
                    echo ' <small>가산 ' . h(((int) $bonus > 0 ? '+' : '') . (string) (int) $bonus) . '</small>';
                }
              ?>
              <small><?= h((string) ($proposal['lesson1_note'] ?? '')) ?></small>
            </dd>
          </div>
        </dl>
      </section>
    <?php elseif ($input === ''): ?>
      <section class="panel panel--idle">
        <p>티커를 넣으면 <strong>지금 차트</strong>에 노라무식 규칙(급락→저점 올림→중간에서 내려올 때→손절선·새로 살 가격 + 불법과외1 선호캔들·윗박스)을 적용합니다.</p>
        <p class="idle__note">지원 종목은 5개가 아닙니다. Yahoo에 있는 티커면 됩니다. 1번 계좌 프로필의 빠른 링크 5종은 <em>힌트</em>일 뿐이고, 학습 라벨·알림 배치만 그 리스트를 씁니다.</p>
        <p class="idle__note">예: <code>NVDA</code>, <code>AAPL</code>, <code>005380.KS</code>(현대차), <code>035420.KQ</code>(네이버)</p>
      </section>
    <?php endif; ?>

    <section class="panel panel--entries">
      <div class="entries__head">
        <h2>
          <?php if ($tab === AlphaEntries::TAB_DIGINGONYOU): ?>
            디깅온유 글·이벤트
          <?php elseif ($tab === AlphaEntries::TAB_MERGED): ?>
            합침 피드 (노라무 + 디깅온유)
          <?php else: ?>
            최근 원글·시드 이벤트
          <?php endif; ?>
        </h2>
        <p class="entries__meta">
          <?= h($tabLabel) ?> ·
          <?= $tab === AlphaEntries::TAB_NORAMU ? 'full / structure_only' : 'full / structure_only / needs_review' ?>
          · 엔진 스냅샷 제외 · <?= count($entriesView) ?>건
        </p>
      </div>
      <div class="entries-table-wrap">
        <table class="entries-table">
          <thead>
            <tr>
              <th>날짜</th>
              <?php if ($showAuthorCol): ?><th>작성자</th><?php endif; ?>
              <th>심볼</th>
              <th>use</th>
              <th>side</th>
              <th>가격</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($entriesView === []): ?>
              <tr>
                <td colspan="<?= $showAuthorCol ? 7 : 6 ?>">
                  <?php if ($tab === AlphaEntries::TAB_DIGINGONYOU): ?>
                    디깅온유 데이터가 없습니다. <code>php bin/ingest_digingonyou.php</code> 를 실행하세요.
                  <?php else: ?>
                    표시할 이벤트가 없습니다.
                  <?php endif; ?>
                </td>
              </tr>
            <?php endif; ?>
            <?php foreach ($entriesView as $e): ?>
              <?php
                $sym = (string) ($e['symbol'] ?? '');
                $yahoo = SymbolMap::toYahoo($sym);
                $scoreHref = $yahoo !== null
                    ? '?' . $qsProfile . '&symbol=' . rawurlencode($yahoo)
                    : null;
                $rowTv = $yahoo !== null ? SymbolMap::tradingViewUrl($yahoo) : null;
                $posted = (string) ($e['posted_at_kst'] ?? '');
                $postedShort = $posted !== '' ? substr($posted, 0, 10) : '—';
                $author = (string) ($e['source_author'] ?? $e['author'] ?? '');
                $priceBits = [];
                if (isset($e['entry_price'])) {
                    $priceBits[] = 'E ' . $e['entry_price'];
                }
                if (isset($e['stop_price'])) {
                    $priceBits[] = 'S ' . $e['stop_price'];
                }
                if (isset($e['target_price'])) {
                    $priceBits[] = 'T ' . $e['target_price'];
                }
              ?>
              <tr>
                <td><?= h($postedShort) ?></td>
                <?php if ($showAuthorCol): ?>
                  <td><span class="author-badge"><?= h($author !== '' ? $author : '—') ?></span></td>
                <?php endif; ?>
                <td>
                  <?php if ($scoreHref !== null): ?>
                    <a href="<?= h($scoreHref) ?>"><?= h($sym) ?></a>
                  <?php else: ?>
                    <?= h($sym !== '' ? $sym : '—') ?>
                  <?php endif; ?>
                  <?php if (!empty($e['title'])): ?>
                    <span class="scan-code"><?= h(mb_substr((string) $e['title'], 0, 36)) ?></span>
                  <?php endif; ?>
                </td>
                <td><code><?= h((string) ($e['learning_use'] ?? '')) ?></code></td>
                <td><?= h((string) ($e['side'] ?? '')) ?></td>
                <td class="mono"><?= h($priceBits !== [] ? implode(' · ', $priceBits) : '—') ?></td>
                <td class="entries__links">
                  <?php if (!empty($e['source_url'])): ?>
                    <a href="<?= h((string) $e['source_url']) ?>" target="_blank" rel="noopener">글</a>
                  <?php endif; ?>
                  <?php if ($rowTv !== null): ?>
                    <a href="<?= h($rowTv) ?>" target="_blank" rel="noopener">TV</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <footer class="foot">
      <p>개인 연구용 · 최종 매매 결정은 본인 책임 · <a href="docs/roadmap-2026-07-18.md">로드맵</a></p>
    </footer>
  </main>
  <script>
    document.querySelector('.search')?.addEventListener('submit', () => {
      document.body.classList.add('is-loading');
    });
    document.querySelectorAll('.btn-scan').forEach((el) => {
      el.addEventListener('click', () => document.body.classList.add('is-loading'));
    });

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
