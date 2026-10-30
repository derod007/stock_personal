<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;
function render(array $report): void
{
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $num = static fn($v, $d=0) => is_numeric($v) ? number_format((float)$v,$d) : '—';
    $summary = $report['summary'];
    $at = (new \DateTimeImmutable($report['as_of']))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i');
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>거래대금 스캔(유형1)</title><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/readability.css">
<style>
.type1{max-width:1280px;margin:40px auto;padding:0 20px;color:#e7edf8}.type1 nav{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:28px}.type1 nav a,.type1 .refresh{padding:10px 14px;border:1px solid #3b4b65;border-radius:8px;color:#cbd9f0;text-decoration:none}.type1 nav [aria-current]{background:#244473;color:white}.type1 h1{font-size:28px}.type1 p{line-height:1.7}.type1 .muted{color:#a6b5cb}.type1 .stats{display:flex;flex-wrap:wrap;gap:16px;margin:24px 0}.type1 .stats span{padding:16px;background:#15243a;border-radius:10px}.type1 .table-wrap{overflow-x:auto}.type1 table{width:100%;border-collapse:collapse;white-space:nowrap}.type1 th,.type1 td{padding:14px 12px;border-bottom:1px solid #293950;text-align:right}.type1 th:nth-child(2),.type1 td:nth-child(2){text-align:left}.type1 a{color:#95bfff}.type1 small{display:block;color:#a6b5cb;margin-top:4px}.type1 details{margin:24px 0;padding:16px;border:1px solid #293950;border-radius:10px}.type1 .notice{padding:18px;background:#15243a;border-radius:8px}body{background:#0b1220}
</style></head><body class="ui-mode-simple"><main class="type1">
<nav aria-label="화면"><a href="index.php">차트</a><a href="index.php?scan=kr_amount">거래대금 스캔</a><a href="index.php?scan=kr_amount_kospi">거래대금 스캔(코스피)</a><a href="type1_scan.php" aria-current="page">거래대금 스캔(유형1)</a><a href="paper.php">모의 계좌</a></nav>
<h1>거래대금 스캔(유형1)</h1>
<p><a class="refresh" href="type1_tracking.php">마감 고정 후보 · 신규/관찰 중 · 결과 보기</a> · <a href="type1_intraday.php">장중 조정·반등 관찰</a></p>
<p>코스피·코스닥 통합 TOP100 중 <strong>완료된 60일봉이 유형1에 해당하는 종목</strong>을 거래대금 순으로 보여줍니다.</p>
<p class="muted">조회 시작 <?= $h($at) ?> KST · 스팩·ETF·ETN·단일종목 레버리지 제외<br>거래대금 순위는 조회 시점 기준입니다. 장중 미완료봉은 분류에 쓰지 않으며 분석 기준일은 종목별로 표시합니다.</p>
<p><a class="refresh" href="type1_scan.php?refresh=1">새로 조회</a> <span class="muted">최대 100종목을 조회하므로 잠시 걸릴 수 있습니다.</span></p>
<div class="stats"><span>조회 대상 <strong><?= $h($summary['total']) ?></strong></span><span>분류 완료 <strong><?= $h($summary['evaluated']) ?></strong></span><span>유형1 <strong><?= $h($summary['matches']) ?></strong></span><span>평가 불가 <strong><?= $h($summary['errors']) ?></strong></span></div>
<?php if (!$report['ok']): ?><p class="notice" role="alert"><?= $h($report['error']) ?></p>
<?php elseif (!$report['rows']): ?><p class="notice"><?= $summary['evaluated'] === 0 ? '분류 가능한 종목이 없습니다. 아래 평가 불가 사유를 확인해 주세요.' : '분류가 완료된 종목 중 유형1에 해당하는 종목이 없습니다.' ?></p><?php endif ?>
<?php if ($report['rows']): ?><div class="table-wrap"><table><thead><tr><th scope="col">대금 순위</th><th scope="col">종목</th><th scope="col">시장</th><th scope="col">조회 가격</th><th scope="col">등락률</th><th scope="col">거래대금(억원)</th><th scope="col">분석 기준일</th><th scope="col">분석 종가</th><th scope="col">판단 종가 대비</th></tr></thead><tbody>
<?php foreach ($report['rows'] as $row): ?><tr>
<td><?= $h($row['rank']) ?></td><td><a href="index.php?symbol=<?= $h(rawurlencode($row['symbol'])) ?>"><?= $h($row['name']) ?></a><small><?= $h($row['symbol']) ?></small></td>
<td><?= str_ends_with($row['symbol'],'.KS') ? '코스피' : '코스닥' ?></td><td><?= $num($row['price'] ?? null) ?></td><td><?= isset($row['change_pct']) ? $num($row['change_pct'],2).'%' : '—' ?></td><td><?= $num(isset($row['amount_won']) ? $row['amount_won']/100000000 : null,1) ?></td><td><?= $h($row['analysis_date']) ?></td><td><?= $num($row['analysis_close']) ?></td><td><?= isset($row['price']) && $row['analysis_close'] > 0 ? $num(($row['price']/$row['analysis_close']-1)*100,2).'%' : '—' ?></td>
</tr><?php endforeach ?></tbody></table></div><?php endif ?>
<?php if ($report['errors']): ?><details><summary>평가 불가 <?= count($report['errors']) ?>종목 — 유형1 미해당과 구분</summary><ul><?php foreach ($report['errors'] as $error): ?><li><?= $h($error['name'].' ('.$error['symbol'].') · '.$error['reason']) ?></li><?php endforeach ?></ul></details><?php endif ?>
<details><summary>유형1은 어떻게 고르나요?</summary><p>완료된 60일봉의 종가와 거래량 모양을 정규화하고, 연구에서 고정한 6개 대표 모양 중 가장 가까운 유형을 찾습니다. 유형 번호는 성적 순위가 아닙니다.</p><p>이 목록은 오늘 조회한 차트 모양의 일치 여부입니다. 기존 추천 점수·보유 한도·재진입 간격을 적용한 주문 목록과는 다릅니다. 정렬은 거래대금 순이며 수익 확률 순위가 아닙니다. 종목을 누르면 기존 차트 분석으로 이동합니다. 자동 주문은 실행하지 않습니다.</p></details>
</main></body></html>
<?php
}
