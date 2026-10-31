<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;
function render(array $report): void
{
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $num = static fn($v, $d=0) => is_numeric($v) ? number_format((float)$v,$d) : '—';
    $summary = $report['summary'];
    $scope=($report['scope']??'all')==='breakout'?'breakout':'all';
    $saved=($report['source']??'live')==='saved';
    $dayQuery=$saved&&isset($report['saved_day'])?'&amp;day='.rawurlencode($report['saved_day']):'';
    $base=$saved?'type1_breakout.php':'type1_scan.php';
    $confirmed=array_values(array_filter($report['rows'],static fn($r)=>$r['breakout20']['confirmed']??false));
    $visible=$scope==='breakout'?$confirmed:$report['rows'];
    $near=$scope==='breakout' && ($report['sort']??'amount')==='near';
    $sortQuery=$near?'&amp;sort=near':'';
    $orders=[];
    if($scope==='breakout'){
        $sorted=$confirmed;
        $distance=static function(array $r): float {
            $v=$r['breakout20']['distance_pct']??null;
            return is_numeric($v)&&is_finite((float)$v)&&(float)$v>=0?(float)$v:INF;
        };
        usort($sorted,static fn($a,$b)=>($distance($a)<=>$distance($b))?:($a['rank']<=>$b['rank'])?:strcmp($a['symbol'],$b['symbol']));
        $orders=array_flip(array_column($sorted,'symbol'));
        if($near)$visible=$sorted;
    }
    $orderAttrs=static fn($r)=>isset($orders[$r['symbol']])?
        ' data-near="'.(int)$orders[$r['symbol']].'" data-amount="'.(int)$r['rank'].'"':'';
    $at = (new \DateTimeImmutable($report['as_of']))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i');
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>거래대금 스캔(유형1)</title><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/readability.css">
<style>
.type1{max-width:1280px;margin:40px auto;padding:0 20px;color:#e7edf8}.type1 nav{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:28px}.type1 nav a,.type1 .refresh{padding:10px 14px;border:1px solid #3b4b65;border-radius:8px;color:#cbd9f0;text-decoration:none}.type1 nav [aria-current]{background:#244473;color:white}.type1 h1{font-size:28px}.type1 p{line-height:1.7}.type1 .muted{color:#a6b5cb}.type1 .stats{display:flex;flex-wrap:wrap;gap:16px;margin:24px 0}.type1 .stats span{padding:16px;background:#15243a;border-radius:10px}.type1 .table-wrap{overflow-x:auto}.type1 table{width:100%;border-collapse:collapse;white-space:nowrap}.type1 th,.type1 td{padding:14px 12px;border-bottom:1px solid #293950;text-align:right}.type1 th:nth-child(2),.type1 td:nth-child(2){text-align:left}.type1 a{color:#95bfff}.type1 small{display:block;color:#a6b5cb;margin-top:4px}.type1 details{margin:24px 0;padding:16px;border:1px solid #293950;border-radius:10px}.type1 .notice{padding:18px;background:#15243a;border-radius:8px}body{background:#0b1220}
</style></head><body class="ui-mode-simple"><main class="type1">
<nav aria-label="화면"><a href="index.php">차트</a><a href="index.php?scan=kr_amount">거래대금 스캔</a><a href="index.php?scan=kr_amount_kospi">거래대금 스캔(코스피)</a><a href="type1_scan.php" aria-current="page">거래대금 스캔(유형1)</a><a href="paper.php">모의 계좌</a></nav>
<h1><?= $saved ? '마감 고정 · 유형1 + 20일 고가 돌파' : '거래대금 스캔(유형1)' ?></h1>
<p><a class="refresh" href="type1_tracking.php">마감 고정 후보 · 신규/관찰 중 · 결과 보기</a> · <a href="type1_intraday.php">장중 조정·반등 관찰</a></p>
<p><a href="type1_breakout.php">마감 고정 돌파 후보</a></p>
<p>코스피·코스닥 통합 TOP100 중 <strong>완료된 60일봉이 유형1에 해당하는 종목</strong>을 보여줍니다. 기본 정렬은 거래대금 순입니다.</p>
<?php if ($saved): ?><p class="notice">저장 기준일 <?= $h($report['saved_day']??'없음') ?> · 당시 스냅샷으로 계산합니다. 현재 가격이나 오늘 신규 주문이 아닙니다. TOP100 순위의 제공자 거래일은 미확정입니다.</p><?php else: ?>
<p class="muted">조회 시작 <?= $h($at) ?> KST · 스팩·ETF·ETN·단일종목 레버리지 제외<br>거래대금 순위는 조회 시점 기준입니다. 장중 미완료봉은 분류에 쓰지 않으며 분석 기준일은 종목별로 표시합니다.</p>
<p><a class="refresh" href="type1_scan.php?refresh=1&amp;scope=<?= $h($scope) ?><?= $sortQuery ?>" data-sort-refresh>새로 조회</a> <span class="muted">최대 100종목을 조회하므로 잠시 걸릴 수 있습니다.</span></p>
<?php endif ?>
<?php if($saved && !empty($report['saved_days'])): ?><form method="get"><label>판단일 <select name="day"><?php foreach(array_reverse($report['saved_days']) as $d): ?><option value="<?= $h($d) ?>"<?= $d===$report['saved_day']?' selected':'' ?>><?= $h($d) ?></option><?php endforeach ?></select></label><input type="hidden" name="scope" value="<?= $h($scope) ?>"><input type="hidden" name="sort" value="<?= $near?'near':'amount' ?>"><button>저장 기록 보기</button></form><?php endif ?>
<nav aria-label="후보 필터"><a href="<?= $base ?>?scope=all<?= $dayQuery ?>"<?= $scope==='all'?' aria-current="page"':'' ?>>유형1 전체 <?= count($report['rows']) ?></a><a href="<?= $base ?>?scope=breakout<?= $dayQuery ?>"<?= $scope==='breakout'?' aria-current="page"':'' ?>>종가 돌파 확인 <?= count($confirmed) ?></a></nav>
<?php if($scope==='breakout'): ?><nav aria-label="돌파 후보 정렬" id="breakout-sort">
<?php foreach(['amount'=>'거래대금 순','near'=>'돌파선 가까운 순'] as $key=>$label): ?><a href="<?= $base ?>?scope=breakout<?= $dayQuery ?>&amp;sort=<?= $key ?>" data-sort="<?= $key ?>"<?= ($near===($key==='near'))?' aria-current="true"':'' ?>><?= $label ?></a><?php endforeach ?></nav>
<p>돌파선 가까운 순은 판단일 종가가 직전 20봉 최고가를 넘은 비율이 작은 후보부터 보여줍니다. 동률이면 거래대금 순입니다. 후보 수와 대금 순위는 그대로이며 수익 확률 순위가 아닙니다.</p>
<script src="assets/type1-breakout-sort.js" defer></script><?php endif ?>
<p class="notice">같은 완료봉에서 유형1과 20일 고가 종가 돌파를 모두 확인한 후보입니다. 추가 점수·수익순 정렬·매수 권유가 아닙니다. 과거 연구의 재진입 간격은 이 목록에 적용하지 않습니다. 장중에는 전 거래일 결과일 수 있으므로 기준일을 확인하세요.</p>
<div class="stats"><span>조회 대상 <strong><?= $h($summary['total']) ?></strong></span><span>분류 완료 <strong><?= $h($summary['evaluated']) ?></strong></span><span>유형1 <strong><?= $h($summary['matches']) ?></strong></span><span>평가 불가 <strong><?= $h($summary['errors']) ?></strong></span></div>
<?php if (!$report['ok']): ?><p class="notice" role="alert"><?= $h($report['error']) ?></p>
<?php elseif (!$report['rows']): ?><p class="notice"><?= $summary['evaluated'] === 0 ? '분류 가능한 종목이 없습니다. 아래 평가 불가 사유를 확인해 주세요.' : '분류가 완료된 종목 중 유형1에 해당하는 종목이 없습니다.' ?></p><?php endif ?>
<?php if ($scope==='breakout' && !$visible && $report['ok']): ?><p class="notice">해당 기준일의 유형1 중 종가 돌파 확인 후보가 없습니다. 유형1 전체에서 나머지 상태를 볼 수 있습니다.</p><?php endif ?>
<?php if ($visible): ?><div class="table-wrap"><table><thead><tr><th scope="col">대금 순위</th><th scope="col">종목</th><th scope="col">시장</th><th scope="col">조회 가격</th><th scope="col">등락률</th><th scope="col">거래대금(억원)</th><th scope="col">분석 기준일</th><th scope="col">분석 종가</th><th scope="col">판단 종가 대비</th><th scope="col">20일 고가선</th><th scope="col">분석 종가의 기준선 대비</th><th scope="col">돌파 상태</th></tr></thead><tbody>
<?php foreach ($visible as $row): ?><tr<?= $orderAttrs($row) ?>>
<td><?= $h($row['rank']) ?></td><td><a href="index.php?symbol=<?= $h(rawurlencode($row['symbol'])) ?>"><?= $h($row['name']) ?></a><small><?= $h($row['symbol']) ?></small></td>
<td><?= str_ends_with($row['symbol'],'.KS') ? '코스피' : '코스닥' ?></td><td><?= $num($row['price'] ?? null) ?></td><td><?= isset($row['change_pct']) ? $num($row['change_pct'],2).'%' : '—' ?></td><td><?= $num(isset($row['amount_won']) ? $row['amount_won']/100000000 : null,1) ?></td><td><?= $h($row['analysis_date']) ?></td><td><?= $num($row['analysis_close']) ?></td><td><?= isset($row['price']) && $row['analysis_close'] > 0 ? $num(($row['price']/$row['analysis_close']-1)*100,2).'%' : '—' ?></td>
<td><?= $num($row['breakout20']['level']??null) ?></td><td><?= isset($row['breakout20']['distance_pct']) ? $num($row['breakout20']['distance_pct'],2).'%' : '—' ?></td><td><?= $h($row['breakout20']['status']??'근거 없음') ?><small><?= $h($row['breakout20']['day']??'') ?></small><small><?= $h($row['breakout20']['reason']??'') ?></small></td>
</tr><?php endforeach ?></tbody></table></div><?php endif ?>
<?php if($visible): ?><details open><summary>진입 판단표 · 참고 가격</summary>
<p>확정 저점은 판단일 포함 최근 20봉에서 좌우 2봉보다 저가가 낮은 가장 최근 봉입니다. 우측 2봉도 판단일까지 완료되어야 합니다. 없으면 미확정입니다. 저점까지 변화율은 (저점 ÷ 기준 가격 − 1)이며, 손절 지시나 최대 손실 보장이 아닙니다.</p>
<div class="table-wrap"><table><thead><tr><th>종목</th><th>판단 종가 / 고가선 대비</th><th>확정 저점 / 종가→저점</th><th>다음 시가 / 종가 대비 갭</th><th>시가의 고가선 대비 / 시가→저점</th><th>시가 상태</th></tr></thead><tbody>
<?php foreach($visible as $row): $e=$row['breakout20']??[];$n=$row['next_open']??[]; ?>
<tr<?= $orderAttrs($row) ?>><td><?= $h($row['name']) ?><small><?= $h($row['analysis_date']) ?></small></td>
<td><?= $num($row['analysis_close']) ?><small><?= isset($e['distance_pct'])?$num($e['distance_pct'],2).'%':'—' ?></small></td>
<td><?= isset($e['pivot_low'])?$num($e['pivot_low']):'미확정' ?><small><?= $h($e['pivot_day']??'') ?> · <?= isset($e['pivot_distance_pct'])?$num($e['pivot_distance_pct'],2).'%':'—' ?></small></td>
<td><?= $num($n['open']??null) ?><small><?= $h($n['day']??'') ?> · <?= isset($n['gap_pct'])?$num($n['gap_pct'],2).'%':'—' ?></small></td>
<td><?= isset($n['level_pct'])?$num($n['level_pct'],2).'%':'—' ?><small><?= isset($n['pivot_pct'])?$num($n['pivot_pct'],2).'%':'—' ?></small></td>
<td><?= $h($n['status']??'마감 고정 화면에서 확인') ?><small><?= isset($n['source_day'])?'자료 저장일 '.$h($n['source_day']):'' ?></small></td></tr>
<?php endforeach ?></tbody></table></div>
<p>다음 시가는 코스피·코스닥 지수 날짜가 일치한 저장 달력의 바로 다음 날짜만 비교합니다. 거래소 휴장 달력과의 독립 대조는 하지 않았습니다. 해당 봉이 없으면 이후 날짜로 대체하지 않습니다. 마감 후 저장된 일봉이므로 장 시작 실시간 안내가 아니며 공급자 수정이 반영될 수 있습니다. 당시 기준선·저점은 원래 판단일 스냅샷으로 고정합니다. 수수료·슬리피지는 이 가격 거리 표에 반영하지 않습니다.</p>
</details><?php endif ?>
<?php if ($report['errors']): ?><details><summary>평가 불가 <?= count($report['errors']) ?>종목 — 유형1 미해당과 구분</summary><ul><?php foreach ($report['errors'] as $error): ?><li><?= $h($error['name'].' ('.$error['symbol'].') · '.$error['reason']) ?></li><?php endforeach ?></ul></details><?php endif ?>
<details><summary>유형1은 어떻게 고르나요?</summary><p>완료된 60일봉의 종가와 거래량 모양을 정규화하고, 연구에서 고정한 6개 대표 모양 중 가장 가까운 유형을 찾습니다. 유형 번호는 성적 순위가 아닙니다.</p><p>이 목록은 오늘 조회한 차트 모양의 일치 여부입니다. 기존 추천 점수·보유 한도·재진입 간격을 적용한 주문 목록과는 다릅니다. 기본 정렬은 거래대금 순이며 수익 확률 순위가 아닙니다. 종목을 누르면 기존 차트 분석으로 이동합니다. 자동 주문은 실행하지 않습니다.</p></details>
</main></body></html>
<?php
}
