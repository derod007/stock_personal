<?php

declare(strict_types=1);

function paper_esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * @param array{
 *   title:string,
 *   page:string,
 *   account?:string,
 *   mode?:string,
 *   experiment?:string,
 *   week?:string
 * } $opts
 */
function paper_open(array $opts): void
{
    $title = $opts['title'];
    $page = $opts['page'];
    $account = $opts['account'] ?? 'paper-us';
    $mode = $opts['mode'] ?? 'forward';
    $experiment = $opts['experiment'] ?? 'us-identity';
    $week = $opts['week'] ?? '';
    $q = 'account=' . rawurlencode($account) . '&mode=' . rawurlencode($mode);
    if ($week !== '') {
        $q .= '&week=' . rawurlencode($week);
    }
    $scripts = [
        'account' => 'paper.php',
        'diagnostics' => 'paper_diagnostics.php',
        'trades' => 'paper_trades.php',
        'weekly' => 'paper_weekly.php',
        'compare' => 'paper_compare.php',
        'revisions' => 'paper_revisions.php',
        'entry' => 'paper_entry.php',
    ];
    $script = $scripts[$page] ?? 'paper.php';
    $isKr = str_contains($account, '-kr') || str_starts_with($experiment, 'kr-');
    if ($page === 'compare') {
        $usHref = 'paper_compare.php?experiment=us-identity&mode=' . rawurlencode($mode);
        $krHref = 'paper_compare.php?experiment=kr-identity&mode=' . rawurlencode($mode);
    } else {
        $usHref = $script . '?' . preg_replace('/account=[^&]*/', 'account=paper-us', $q);
        $krHref = $script . '?' . preg_replace('/account=[^&]*/', 'account=paper-kr', $q);
    }
    $on = static fn (string $p): string => $p === $page ? ' is-active' : '';
    $now = static fn (string $p): string => $p === $page ? ' aria-current="page"' : '';
    ?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <title><?= paper_esc($title) ?></title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="bg" aria-hidden="true"></div>
  <main class="shell shell--wide">
    <header class="brand">
      <p class="brand__name">모의 계좌</p>
      <p class="brand__sub">실제 주문 없음 · 본장 종가로 매일 기록</p>
    </header>
    <div class="topbar">
      <nav class="tabs" aria-label="화면">
        <a class="tabs__link" href="index.php">차트</a>
        <a class="tabs__link is-active" href="paper.php?<?= paper_esc($q) ?>" aria-current="page">모의 계좌</a>
      </nav>
      <nav class="mode-toggle" aria-label="시장">
        <a class="mode-toggle__link<?= $isKr ? '' : ' is-active' ?>" href="<?= paper_esc((string) $usHref) ?>"<?= $isKr ? '' : ' aria-current="page"' ?>>미국</a>
        <a class="mode-toggle__link<?= $isKr ? ' is-active' : '' ?>" href="<?= paper_esc((string) $krHref) ?>"<?= $isKr ? ' aria-current="page"' : '' ?>>한국</a>
      </nav>
    </div>
    <nav class="tabs paper-tabs" aria-label="모의 계좌 화면">
      <a class="tabs__link<?= $on('account') ?>" href="paper.php?<?= paper_esc($q) ?>"<?= $now('account') ?>>기록</a>
      <a class="tabs__link<?= $on('diagnostics') ?>" href="paper_diagnostics.php?<?= paper_esc($q) ?>"<?= $now('diagnostics') ?>>실행·추천</a>
      <a class="tabs__link<?= $on('trades') ?>" href="paper_trades.php?<?= paper_esc($q) ?>"<?= $now('trades') ?>>거래</a>
      <a class="tabs__link<?= $on('weekly') ?>" href="paper_weekly.php?<?= paper_esc($q) ?>"<?= $now('weekly') ?>>주간</a>
      <a class="tabs__link<?= $on('compare') ?>" href="paper_compare.php?experiment=<?= paper_esc($isKr ? 'kr-identity' : 'us-identity') ?>&amp;mode=<?= paper_esc($mode) ?>"<?= $now('compare') ?>>비교</a>
      <a class="tabs__link<?= $on('entry') ?>" href="paper_entry.php?<?= paper_esc($q) ?>"<?= $now('entry') ?>>진입 조건</a>
      <a class="tabs__link<?= $on('revisions') ?>" href="paper_revisions.php?<?= paper_esc($q) ?>"<?= $now('revisions') ?>>데이터 변경</a>
    </nav>
<?php
}

function paper_close(): void
{
    echo '</main></body></html>';
}
