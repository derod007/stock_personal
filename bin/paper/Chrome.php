<?php

declare(strict_types=1);

function paper_esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function paper_ko(string $code): string
{
    $map = [
        'forward' => '당시 기록',
        'catchup' => '사후 기록',
        'replay' => '과거 재현',
        'execution' => '주문 실행 결과',
        'unknown' => '기록 없음',
        'snapshot' => '당시 판단',
        'decision' => '주문 제외',
        'order' => '주문',
        'fill' => '체결',
        'exit' => '청산',
        'order_cancelled' => '주문 취소',
        'account_started' => '계좌 시작',
        'account_halted' => '계좌 중단',
        'arm_event' => '실험 기록',
        'data_revision' => '데이터 변경',
        'trend_pullback' => '상승 눌림',
        'trend_pullback_v1' => '상승 눌림',
        'breakout_retest' => '돌파 후 재지지',
        'breakout_retest_v1' => '돌파 후 재지지',
        'ready' => '확인됨',
        'rejected_rr' => '손익비 부족',
        'await_confirmation' => '확인 대기',
        'await_retest' => '재지지 대기',
        'wait_pullback' => '눌림 대기',
        'no_setup' => '패턴 없음',
        'risk_blocked' => '위험 차단',
        'context_wait' => '큰 추세 보류',
        'blocked' => '매수 금지',
        'stale_data' => '일봉이 오래됨',
        'invalidated' => '패턴 무효',
        'expired' => '기간 만료',
        'insufficient_data' => '봉 부족',
        'chart_query_failed' => '차트 조회 실패',
        'cached_evidence_unavailable' => '당시 근거 없음',
        'unavailable' => '평가 불가',
        'warning' => '확인 필요',
        'ok' => '사용 가능',
        'complete' => '관측 완료',
        'open' => '보유 중',
        'stop' => '손절',
        'target' => '목표가',
        'time' => '보유기간 만료',
        'closed' => '청산 완료',
        'unfilled' => '기간 내 미체결',
        'pending' => '이후 봉 부족',
        'incomplete' => '아직 보유 중',
        'awaiting_bars' => '거래봉 누적 대기',
        'missing_or_blocked_data' => '누락 또는 품질 차단',
        'available' => '계산 가능',
        'no_future_bars' => '이후 봉 없음',
        'no_signal' => '신호 없음',
        'invalid_levels' => '가격이 맞지 않음',
        'cancelled_before_entry' => '진입 전 취소',
        'historical_revision' => '과거 가격이 바뀜',
        'historical_revision_or_missing' => '과거 일봉이 바뀜',
        'future_quality_blocked' => '이후 봉 품질 차단',
        'input_hash_mismatch' => '원본이 다름',
        'unpriced_position' => '보유 가격 확인 불가',
        'signal_not_confirmed' => '진입 조건 미충족',
        'data_quality' => '데이터 품질로 제외',
        'retrospective_signal' => '사후 기록이라 신규 주문 제외',
        'already_active' => '이미 보유하거나 주문 중',
        'position_limit' => '동시 보유 한도',
        'cash_or_risk_or_sector_limit' => '현금·위험·업종 한도',
        'invalid_plan' => '가격 계획 오류',
        'missing_source' => '출처 기록 없음',
        'insufficient_history' => '일봉이 60개 미만',
        'missing_session' => '해당 거래일 봉 없음',
        'invalid_recent_ohlcv' => '최근 봉이 비정상',
        'duplicate_session' => '같은 거래일 봉이 중복',
        'excluded_invalid_history' => '비정상 과거 봉 제외',
        'provider_disagreement' => '시세 출처가 다름',
        'recent_provider_disagreement' => '최근 시세 출처가 다름',
        'price_basis_not_recorded' => '가격 기준이 기록되지 않음',
        'corporate_action_adjustment_unverified' => '액면분할 등 보정 미확인',
        'provider_daily_with_existing_naver_merge' => '일봉 · 네이버 보정',
        'preview_local_cache' => '로컬 일봉 미리보기',
        'baseline' => '기존 추천',
        'candidate' => '지정가 후보',
        'limit' => '지정가 후보',
        'added' => '연구에 포함',
        'KRW' => '원',
        'USD' => '달러',
        'matched' => '결과가 같음',
        'mismatch' => '결과가 다름',
        'not_identity' => '같은 전략 검증이 아님',
        'identity_mismatch' => '같은 전략인데 결과가 다름',
        'collect' => '시세 수집',
        'scan' => '거래대금 스캔',
        'naver_session' => '네이버 시세',
        'account' => '계좌 기록',
        'source' => '원본 계좌',
        'comparison' => '비교 계좌',
        'removed' => '봉이 빠짐',
        'changed' => '값이 바뀜',
        'key_order_only' => '값 순서만 다름',
        'input_difference' => '입력 값이 다름',
        'hash_mismatch_without_row_difference' => '요약은 다른데 봉 내용은 같음',
        'overlapping_order' => '주문이 겹침',
        'unmatched_fill' => '체결이 주문과 안 맞음',
        'unmatched_exit' => '청산이 체결과 안 맞음',
        'quantity_mismatch' => '수량이 다름',
        'account_reconciliation_failed' => '계좌 손익이 안 맞음',
        'account_realized_reconciliation_failed' => '실현손익 합계가 안 맞음',
        'semiconductors' => '반도체',
        'automotive' => '자동차',
        'financials' => '금융',
        'internet' => '인터넷',
        'materials' => '소재',
        'energy' => '에너지',
        'software' => '소프트웨어',
        'success' => '성공',
        'failed' => '실패',
        'running' => '실행 중',
        'halted' => '계좌 중단',
    ];
    if (isset($map[$code])) {
        return $map[$code];
    }
    $pos = strpos($code, ':');
    if ($pos !== false) {
        $head = substr($code, 0, $pos);
        $tail = substr($code, $pos + 1);
        if (isset($map[$head])) {
            return $map[$head] . ' · ' . ($map[$tail] ?? $tail);
        }
    }

    return $code;
}

/** @param array<int|string, mixed> $codes */
function paper_ko_join(array $codes, string $sep = ', '): string
{
    $out = [];
    foreach ($codes as $code) {
        $out[] = paper_ko((string) $code);
    }

    return $out === [] ? '—' : implode($sep, $out);
}

/** @param array<string, int|float> $counts */
function paper_ko_counts(array $counts): string
{
    if ($counts === []) {
        return '없음';
    }
    $parts = [];
    foreach ($counts as $code => $n) {
        $parts[] = paper_ko((string) $code) . ' ' . $n;
    }

    return implode(' · ', $parts);
}

function paper_field(string $field): string
{
    $map = [
        'open' => '시가',
        'high' => '고가',
        'low' => '저가',
        'close' => '종가',
        'volume' => '거래량',
        'available_at' => '기준 시각',
        'synthetic' => '임시 봉',
        'is_complete' => '완성 여부',
    ];

    return $map[$field] ?? paper_ko($field);
}

function paper_change_kind(string $kind): string
{
    return [
        'added' => '봉이 추가됨',
        'removed' => '봉이 빠짐',
        'changed' => '값이 바뀜',
        'key_order_only' => '값 순서만 다름',
    ][$kind] ?? paper_ko($kind);
}

function paper_account_field(string $id, string $label = '계좌', ?array $only = null): void
{
    $known = $only ?? ['paper-kr', 'paper-us', 'research-kr-v1', 'research-us-v1'];
    if (!in_array($id, $known, true)) {
        $known[] = $id;
    }
    echo '<label>' . paper_esc($label) . ' <select name="account">';
    foreach ($known as $x) {
        echo '<option value="' . paper_esc($x) . '"' . ($x === $id ? ' selected' : '') . '>' . paper_esc(paper_account_name($x)) . '</option>';
    }
    echo '</select></label>';
}

function paper_account_name(string $id): string
{
    return match ($id) {
        'paper-kr' => '한국 모의',
        'paper-us' => '미국 모의',
        'research-kr-v1' => '한국 연구 10종목',
        'research-us-v1' => '미국 연구 10종목',
        'us-identity' => '미국 · 같은 전략 확인',
        'kr-identity' => '한국 · 같은 전략 확인',
        'us-volume95-v1' => '미국 · 거래량 95%',
        'kr-volume95-v1' => '한국 · 거래량 95%',
        default => $id,
    };
}

function paper_experiment_field(string $id): void
{
    $known = ['us-identity', 'kr-identity', 'us-volume95-v1', 'kr-volume95-v1'];
    if (!in_array($id, $known, true)) {
        $known[] = $id;
    }
    echo '<label>실험 <select name="experiment">';
    foreach ($known as $x) {
        echo '<option value="' . paper_esc($x) . '"' . ($x === $id ? ' selected' : '') . '>' . paper_esc(paper_account_name($x)) . '</option>';
    }
    echo '</select></label>';
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
        'rr' => 'paper_rr.php',
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
      <a class="tabs__link<?= $on('rr') ?>" href="paper_rr.php?<?= paper_esc($q) ?>"<?= $now('rr') ?>>탈락 로그</a>
      <a class="tabs__link<?= $on('revisions') ?>" href="paper_revisions.php?<?= paper_esc($q) ?>"<?= $now('revisions') ?>>데이터 변경</a>
    </nav>
<?php
}

function paper_close(): void
{
    echo '</main></body></html>';
}
