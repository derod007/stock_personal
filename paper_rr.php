<?php

declare(strict_types=1);

require __DIR__ . '/bin/bootstrap.php';
require __DIR__ . '/bin/paper/RrView.php';
require_once __DIR__ . '/bin/paper/Chrome.php';
require_once __DIR__ . '/bin/paper/FollowupPanel.php';
require_once __DIR__ . '/bin/paper/RejectionReviewPanel.php';
require_once __DIR__ . '/bin/paper/RediagnosisPanel.php';
require_once __DIR__ . '/bin/paper/ConditionResearchPanel.php';
require_once __DIR__ . '/bin/paper/StopResearchPanel.php';
require_once __DIR__ . '/bin/paper/TargetResearchPanel.php';
require_once __DIR__ . '/bin/paper/TargetComparisonPanel.php';
require_once __DIR__ . '/bin/paper/TargetTrackingPanel.php';
require_once __DIR__ . '/bin/paper/EnvelopeResearchPanel.php';

use ChartEntryLab\ChartPlanEngine;
use ChartEntryLab\YahooChartClient;

function rh(mixed $v): string
{
    return paper_esc($v);
}

function rnum(mixed $v): string
{
    return is_numeric($v) ? number_format((float) $v, is_float($v + 0) && floor((float) $v) != (float) $v ? 3 : 0, '.', ',') : '—';
}

function rkst(?int $t): string
{
    return $t ? (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i') : '—';
}

$id = $_GET['account'] ?? 'paper-kr';
$mode = $_GET['mode'] ?? 'forward';
if (!is_string($id) || preg_match('/^[a-z0-9_-]{1,64}$/', $id) !== 1 || !in_array($mode, ['forward', 'replay'], true)) {
    http_response_code(400);
    exit('잘못된 계좌 요청');
}
$file = isset($_GET['file']) ? (string) $_GET['file'] : '';
if ($file !== '' && preg_match('/^\d{8}-\d{6}-[a-f0-9]{12}\.json$/', $file) !== 1) {
    http_response_code(400);
    exit('잘못된 로그 파일');
}
$wantOutcomes = isset($_GET['outcomes']) && (string) $_GET['outcomes'] === '1';
$preview = isset($_GET['preview']) && (string) $_GET['preview'] === '1';

$dir = (getenv('PAPER_STATE_DIR') ?: dirname(__DIR__) . '/stock-personal-paper') . '/rr-audit/' . $id;
if (($_GET['rediagnosis'] ?? '') === 'json') {
    try {
        $report = paper_rediagnosis_load(dirname($dir, 2), $id);
        if ($report === null) { http_response_code(404); exit('재진단 결과 없음'); }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$id.'-rediagnosis.json"');
        echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) { http_response_code(500); echo '재진단 파일 읽기 실패'; }
    exit;
}
$files = is_dir($dir) ? PaperRrView::files($dir) : [];
$error = null;
$bundle = null;
$note = '';
if ($file !== '') {
    $path = $dir . '/' . $file;
    if (!is_file($path)) {
        $error = '해당 감사 로그가 없습니다.';
    } else {
        try {
            $bundle = PaperRrView::load($path);
        } catch (Throwable $e) {
            $error = '감사 로그를 읽지 못했습니다.';
        }
    }
} elseif ($files !== []) {
    try {
        $bundle = PaperRrView::load($dir . '/' . $files[0]);
        $file = $files[0];
    } catch (Throwable $e) {
        $error = '최근 감사 로그를 읽지 못했습니다.';
    }
} elseif ($preview) {
    @set_time_limit(300);
    try {
        $bundle = rr_preview_from_scan(__DIR__);
        $note = '저장된 감사 로그가 없어 오늘 스캔 캐시 종목을 로컬 일봉으로 다시 계산했습니다. 20:20 당시 기록과 다를 수 있고, 이 화면은 파일을 만들지 않습니다.';
    } catch (Throwable $e) {
        $error = '스캔 캐시 미리보기에 실패했습니다.';
    }
}

$rows = is_array($bundle) ? PaperRrView::rows($bundle) : [];
$perf = null;
if ($wantOutcomes && is_array($bundle)) {
    $perf = PaperRrView::performance($bundle['records'], rr_needed_prices(__DIR__ . '/data/ohlcv', $bundle['records']), time());
}

paper_open(['title' => '탈락 로그·지정가 비교', 'page' => 'rr', 'account' => $id, 'mode' => $mode]);
$q = 'account=' . rawurlencode($id) . '&mode=' . rawurlencode($mode);
?>
<p class="paper-note">운영 매수에는 넣지 않습니다. 확인 후 손익비 1.5 미만인 종목만 연구 지정가 후보고, 위험·추세 차단이 있으면 제외합니다. 지정가 접촉은 체결 보장이 아니고, 아래 수익률은 계좌 한도를 적용하지 않은 개별 거래입니다.</p>
<?php if (!$preview): ?>
<nav class="paper-research-menu" aria-label="탈락 분석과 연구 선택"><h2 class="paper-section-title">어떤 내용을 확인할까요?</h2><div class="paper-research-links">
<?php foreach ([
    'review'=>'최근 5개 기록일 진단', 'target_tracking'=>'누적 목표가 비교',
    'target_comparison'=>'돌파 종목 목표 비교', 'target_research'=>'목표가 산정 근거',
    'stop_research'=>'손절 가격 비교', 'condition_research'=>'조건 하나를 바꾼 연구',
    'envelope_research'=>'엔벨로프 눌림 연구', 'rediagnosis'=>'과거 기록 재진단',
] as $key=>$label): if ($mode !== 'forward' && $key !== 'review') continue; ?>
<a href="?<?= rh($q) ?>&amp;<?= rh($key) ?>=1"<?= ($_GET[$key]??'')==='1'?' aria-current="page"':'' ?>><?= rh($label) ?></a>
<?php endforeach ?></div></nav>
<?php
if (($_GET['review']??'') === '1') {
    try { paper_rejection_review_panel(PaperRejectionReview::load($dir, PaperFollowup::load(dirname($dir, 2), $id))); }
    catch (Throwable $e) { echo '<p role="alert">최근 진단을 읽지 못했습니다: '.rh($e->getMessage()).'</p>'; }
}
if ($mode === 'forward') {
    foreach ([
        'envelope_research'=>'paper_envelope_research_panel', 'target_tracking'=>'paper_target_tracking_panel',
        'target_comparison'=>'paper_target_comparison_panel', 'target_research'=>'paper_target_research_panel',
        'stop_research'=>'paper_stop_research_panel', 'condition_research'=>'paper_condition_research_panel',
    ] as $key=>$render) {
        if (($_GET[$key]??'') !== '1') continue;
        try { $render(dirname($dir, 2), $id); }
        catch (Throwable $e) { echo '<p role="alert">연구 결과 읽기 실패: '.rh($e->getMessage()).'</p>'; }
    }
    if (($_GET['rediagnosis']??'') === '1') {
        try { paper_rediagnosis_panel(paper_rediagnosis_load(dirname($dir, 2), $id), $id); }
        catch (Throwable $e) { echo '<p role="alert">재진단 결과 읽기 실패: '.rh($e->getMessage()).'</p>'; }
    }
}
endif;
?>
<?php if ($files !== []): ?>
<form class="paper-filter" method="get">
  <input type="hidden" name="account" value="<?= rh($id) ?>">
  <input type="hidden" name="mode" value="<?= rh($mode) ?>">
  <label>로그 <select name="file"><?php foreach ($files as $name): ?><option value="<?= rh($name) ?>" <?= $name === $file ? 'selected' : '' ?>><?= rh($name) ?></option><?php endforeach ?></select></label>
  <label><input type="checkbox" name="outcomes" value="1" <?= $wantOutcomes ? 'checked' : '' ?>> 이후 봉 성과</label>
  <button>조회</button>
</form>
<?php endif ?>
<?php if ($error): ?><p class="paper-alert"><?= rh($error) ?></p><?php endif ?>
<?php if ($note !== ''): ?><p class="paper-note"><?= rh($note) ?></p><?php endif ?>
<?php if (!$bundle && !$error): ?>
<p class="paper-note">아직 이 계좌의 탈락 로그가 없습니다. 한국 모의는 다음 20:20 실행부터 이 화면에 쌓입니다.</p>
<p><a class="btn-scan" href="paper_rr.php?<?= rh($q) ?>&amp;preview=1">오늘 스캔 캐시로 미리보기</a></p>
<?php elseif ($bundle): ?>
<p class="paper-lede"><?= rh((string) ($bundle['fetched_at'] ?? rkst(isset($bundle['recorded_at']) ? (int) $bundle['recorded_at'] : null))) ?> · 종목 <?= rh((string) ($bundle['summary']['symbols'] ?? count($rows))) ?> · 확인 후 손익비 탈락 <?= rh((string) ($bundle['summary']['confirmed_rr_symbol_days'] ?? 0)) ?> · 연구 포함 <?= rh((string) ($bundle['summary']['added'] ?? 0)) ?></p>
<section class="panel">
<h2 class="paper-section-title">종목별 탈락</h2>
<div class="scan-table-wrap"><table class="scan-table">
<thead><tr><th>종목·패턴</th><th>최종 판정</th><th>탈락 이유·상세 근거</th><th>당시 확인 가격</th><th>연구 지정가</th></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr>
  <td><strong><?= rh($row['name']) ?></strong><span class="scan-code"><?= rh(paper_ko($row['pattern'])) ?></span></td>
  <td><?= rh(paper_ko($row['final_status'])) ?></td>
  <td><?php
    $why = trim((string) ($row['why'] ?? ''));
    $finalReason = trim((string) ($row['final_reason'] ?? ''));
    if ($finalReason !== '' && ($why === '' || str_contains($finalReason, $why))) {
        $why = $finalReason;
    } elseif ($finalReason !== '' && !str_contains($why, $finalReason)) {
        $why .= ' · ' . $finalReason;
    }
    echo rh($why !== '' ? $why : '—');
  ?><details class="inline-details"><summary>미충족 조건·추가 차단 보기</summary>
  <p>패턴 판정: <?= rh(paper_ko($row['raw_status'])) ?></p>
  <p>미충족: <?= rh($row['missing'] !== '' ? $row['missing'] : '—') ?></p>
  <p>추가 차단: <?= rh($row['blockers'] !== '' ? $row['blockers'] : ($row['included'] ? '없음' : '—')) ?></p>
  </details></td>
  <td class="mono"><?= rh(rnum($row['entry'])) ?><span class="scan-code">손익비 <?= rh(paper_number($row['rr'])) ?></span></td>
  <td class="mono"><?= rh(rnum($row['limit'])) ?><span class="scan-code">손익비 <?= rh(paper_number($row['limit_rr'])) ?> · 기준 1.5</span></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
</section>
<?php if (!$wantOutcomes): ?>
<p><a class="btn-scan" href="paper_rr.php?<?= rh($q) ?><?= $file !== '' ? '&amp;file=' . rawurlencode($file) : ($preview ? '&amp;preview=1' : '') ?>&amp;outcomes=1">이후 일봉으로 체결·손익 비교</a></p>
<?php elseif ($perf): ?>
<section class="panel">
<h2 class="paper-section-title">지정가 후보 vs 기존 추천</h2>
<p class="paper-note">청산된 거래만 평균에 넣습니다. 수수료 편도 10bp, 슬리피지 5bp. 같은 봉에서 지정가와 손절이 같이 닿으면 기존 시뮬레이터의 보수적 취소를 따릅니다.</p>
<div class="scan-table-wrap"><table class="scan-table">
<thead><tr><th>방식</th><th>신호</th><th>체결</th><th>청산</th><th>손절</th><th>목표</th><th>미체결</th><th>청산 평균 순수익률</th></tr></thead>
<tbody>
<?php foreach (['baseline' => '기존 추천', 'limit' => '지정가 후보'] as $key => $label): $s = $perf[$key]; ?>
<tr>
  <td><?= rh($label) ?></td>
  <td class="mono"><?= rh((string) $s['signals']) ?></td>
  <td class="mono"><?= rh((string) $s['filled']) ?></td>
  <td class="mono"><?= rh((string) $s['closed']) ?></td>
  <td class="mono"><?= rh((string) $s['stops']) ?></td>
  <td class="mono"><?= rh((string) $s['targets']) ?></td>
  <td class="mono"><?= rh((string) $s['unfilled']) ?></td>
  <td class="mono"><?= rh($s['mean_net_pct'] === null ? '—' : sprintf('%+.3f%%', $s['mean_net_pct'])) ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php if ($perf['rows'] !== []): ?>
<div class="scan-table-wrap"><table class="scan-table">
<thead><tr><th>방식</th><th>종목</th><th>가격</th><th>결과</th><th>손절</th><th>목표</th><th>순수익률</th></tr></thead>
<tbody>
<?php foreach ($perf['rows'] as $row): ?>
<tr>
  <td><?= rh(paper_ko($row['kind'])) ?></td>
  <td><?= rh($row['name']) ?></td>
  <td class="mono"><?= rh(rnum($row['entry'])) ?></td>
  <td><?= rh(paper_ko($row['status'])) ?><?= !empty($row['entry_bar_stop_touch']) ? ' · 체결봉 손절 접촉' : '' ?></td>
  <td><?= !empty($row['hit_stop']) ? '예' : '—' ?></td>
  <td><?= !empty($row['hit_target']) ? '예' : '—' ?></td>
  <td class="mono"><?= rh(is_numeric($row['net_return_pct']) ? sprintf('%+.3f%%', $row['net_return_pct']) : '—') ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</section>
<?php endif ?>
<?php endif ?>
<?php
if ($mode === 'forward') {
    try { $state = dirname($dir, 2); paper_followup_panel(PaperFollowup::load($state, $id), null, $state . '/runs/' . $id . '-forward'); }
    catch (Throwable $e) { echo '<p class="paper-alert">후속 추적 기록 읽기 실패</p>'; }
}
paper_close();

/** @param list<array<string,mixed>> $records
 *  @return array<string,list<array<string,mixed>>>
 */
function rr_needed_prices(string $dir, array $records): array
{
    $need = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $plan = $record['analysis']['plan'] ?? null;
        $added = false;
        foreach ($record['patterns'] ?? [] as $p) {
            if (is_array($p) && ($p['status'] ?? '') === 'added') {
                $added = true;
            }
        }
        if ($added || (is_array($plan) && !empty($plan['ready']))) {
            $need[(string) $record['symbol']] = true;
        }
    }
    $out = [];
    foreach (array_keys($need) as $symbol) {
        $path = $dir . '/' . $symbol . '_2y_1d_closed_v2.json';
        if (!is_file($path)) {
            continue;
        }
        try {
            $bars = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            continue;
        }
        if (is_array($bars)) {
            $out[$symbol] = $bars;
        }
    }

    return $out;
}

function rr_preview_from_scan(string $root): array
{
    $cache = $root . '/data/raw/cache/kr_amount_scan_v19_all_account1_100.json';
    if (!is_file($cache)) {
        throw new RuntimeException('scan cache missing');
    }
    $report = json_decode((string) file_get_contents($cache), true, 512, JSON_THROW_ON_ERROR);
    $yahoo = new YahooChartClient($root . '/data/ohlcv');
    $engine = new ChartPlanEngine();
    $records = [];
    foreach (is_array($report['rows'] ?? null) ? $report['rows'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $symbol = (string) ($row['yahoo'] ?? '');
        $leader = ['yahoo' => $symbol, 'name' => $row['name'] ?? $symbol, 'rank' => $row['amount_rank'] ?? null];
        if ($symbol === '' || empty($row['ok'])) {
            $records[] = PaperRrAudit::capture($leader, ['ok' => false, 'error' => $row['error'] ?? 'chart_query_failed']);
            continue;
        }
        $bars = $yahoo->fetch($symbol, '2y', '1d', useCache: true, maxAgeSeconds: 86400);
        $asOf = time();
        $analysis = $engine->analyze($bars, $symbol, $asOf);
        $records[] = PaperRrAudit::capture($leader, [
            'ok' => true,
            'evidence_origin' => 'preview_local_cache',
            'research_input' => ['bars' => $bars, 'analysis' => $analysis],
        ]);
    }

    return [
        'version' => PaperRrAudit::VERSION,
        'fetched_at' => (string) ($report['fetched_at'] ?? ''),
        'membership' => 'preview_from_scan_cache_not_saved_audit',
        'summary' => PaperRrAudit::summary($records),
        'records' => $records,
    ];
}

