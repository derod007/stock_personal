<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';
require __DIR__.'/bin/paper/TradeReview.php';
use ChartEntryLab\PaperJournal;
function th(mixed $x): string {return htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');}
function tn(mixed $x,int $dec=2): string {return $x===null?'—':number_format((float)$x,$dec);}
function tt(int $t): string {return $t?(new DateTimeImmutable('@'.$t))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i'):'—';}
function tl(string $s): string {return ['forward'=>'당시 기록','catchup'=>'사후 기록','replay'=>'과거 재현','unknown'=>'기록 없음','stop'=>'손절','target'=>'목표가','time'=>'보유기간 만료','closed'=>'청산 완료','open'=>'보유 중','complete'=>'관측 완료','awaiting_bars'=>'거래봉 누적 대기','missing_or_blocked_data'=>'누락/품질 차단','available'=>'계산 가능'][$s]??$s;}
$id=$_GET['account']??'paper-us';$mode=$_GET['mode']??'forward';
if(!is_string($id)||!preg_match('/^[a-z0-9_-]{1,64}$/',$id)||!in_array($mode,['forward','replay'],true)){http_response_code(400);exit('잘못된 계좌 요청');}
$dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__).'/stock-personal-paper';$r=null;$error=null;
try {$d=(new PaperJournal($dir.'/'.$id.'-'.$mode.'.json'))->read();if($d && $d['state'])$r=PaperTradeReview::build($d);}
catch(Throwable $e){$error='기록 읽기 또는 분석 오류입니다. 원본 계좌 기록을 확인하세요.';}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>거래별 성과 분석</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="app">
<h1>거래별 성과 분석</h1><p><a href="paper.php?account=<?= th($id) ?>&amp;mode=<?= th($mode) ?>">모의 계좌</a> · <a href="paper_diagnostics.php?account=<?= th($id) ?>&amp;mode=<?= th($mode) ?>">실행·추천 진단</a></p>
<form><label>계좌 ID <input name="account" value="<?= th($id) ?>"></label><select name="mode"><option value="forward" <?= $mode==='forward'?'selected':'' ?>>앞으로 기록</option><option value="replay" <?= $mode==='replay'?'selected':'' ?>>과거 재현</option></select><button>조회</button></form>
<p>모의 계좌에 저장된 체결·청산과 당시 진입 조건을 분석합니다. 현재 전략으로 과거 매매를 다시 계산하지 않습니다.</p>
<?php if($error): ?><p><?= th($error) ?></p><?php elseif(!$r): ?><p>아직 계좌 기록이 없습니다. 일일 수집·모의 계좌 갱신 이후 확인하세요.</p><?php else: ?>
<p><?= th($id) ?> · <?= th($r['currency']) ?> · 마지막 평가 <?= th(tt($r['last_session'])) ?> KST</p>
<?php if($r['halted']): ?><p><strong>중단된 계좌입니다. 아래 결과는 마지막으로 보존된 기록까지만 분석합니다.</strong></p><?php endif ?>
<?php if($r['issues']): ?><p><strong>거래 연결 또는 계좌 손익 대사 오류: <?= th(implode(', ',$r['issues'])) ?>. 집계로 전략을 판단하기 전에 원본을 확인하세요.</strong></p><?php endif ?>
<p>완료 거래 <?= th($r['closed']) ?>건 · 기록된 순손익 <?= th(tn($r['net_pnl'])) ?> <?= th($r['currency']) ?>. 거래 수가 적은 그룹은 탐색용입니다. 그룹 평균 수익률은 거래별 단순 평균이며 계좌 수익률이 아닙니다.</p>
<h2>청산 사유·진입 패턴별 성과</h2>
<table><thead><tr><th>기록 종류</th><th>구분 / 조건</th><th>완료 / 이익 건수</th><th>순손익</th><th>평균 순수익률</th><th>평균 R</th></tr></thead><tbody>
<?php foreach($r['groups'] as $g): ?><tr><td><?= th(tl($g['origin'])) ?></td><td><?= $g['kind']==='pattern'?'진입 패턴':'청산 사유' ?> / <?= th(tl($g['label'])) ?></td><td><?= th($g['count'].' / '.$g['wins']) ?></td><td><?= th(tn($g['net_pnl'])) ?></td><td><?= th(tn($g['mean_return_pct'])) ?>%</td><td><?= th(tn($g['mean_r'])) ?></td></tr><?php endforeach ?></tbody></table>
<p>R = 기록된 순손익 ÷ 주문 당시 계획 위험금액. 청산 사유와 패턴 집계에는 같은 거래가 각각 포함되므로 두 표본을 합산하지 않습니다.</p>
<h2>거래별 내역</h2>
<?php if(!$r['trades']): ?><p>체결된 거래가 없습니다. 주문 제외·만료는 실행·추천 진단에서 확인하세요.</p><?php endif ?>
<p>확인 구간 상승·하락은 체결 가격, 청산 가격, 온전히 보유한 중간 일봉, 보유가 이어진 날의 종가를 사용합니다. 경계일 포함 범위는 진입·청산 당일 전체 고저가까지 포함합니다. 일봉의 체결 전후 순서를 알 수 없어 실제 최대 상승·하락은 확정하지 않습니다. 두 수치는 비용 차감 전 가격 변동이며, 청산 가격에는 기존 모형의 슬리피지가 반영돼 있습니다.</p>
<?php foreach(array_reverse($r['trades']) as $t): $f=$t['fill'];$x=$t['exit']; ?>
<details><summary>#<?= th($t['id']) ?> <?= th($t['symbol']) ?> · <?= th(tl($t['origin'])) ?> · <?= th(tl($t['status'])) ?> · 순손익 <?= th(tn($t['net_pnl'])) ?> <?= th($r['currency']) ?></summary>
<p>추천 <?= th(tt($t['signal_session'])) ?> / 실제 기록 <?= th(tt($t['signal_recorded_at']??0)) ?> KST<br>진입 패턴 <?= th($t['pattern']) ?> · <?= th($t['signal_reason']) ?></p>
<table><tbody>
<tr><th>진입 / 청산 거래일 (KST)</th><td><?= th(tt($f['session']).' / '.tt($x['session']??0)) ?></td></tr>
<tr><th>수량 / 진입가 / 청산가</th><td><?= th($f['quantity'].' / '.tn($f['price'],4).' / '.tn($x['price']??null,4)) ?></td></tr>
<tr><th>청산 사유 / 일봉 내 동시 도달</th><td><?= th(tl($x['reason']??'보유 중')) ?> / <?= $t['ambiguous_bar']?'있음 · 기존 모형의 우선순위 적용':'기록된 충돌 없음 (장중 순서 확정 아님)' ?></td></tr>
<tr><th>가격 차이 손익 / 모형 수수료 / 순손익</th><td><?= th(tn($t['gross_pnl']).' / '.tn($t['fees']).' / '.tn($t['net_pnl'])) ?></td></tr>
<tr><th>순수익률 / R</th><td><?= th(tn($t['net_return_pct'])) ?>% / <?= th(tn($t['net_r'])) ?></td></tr>
<tr><th>보유 구간 관측 봉 / 품질</th><td><?= th($t['observed_holding_bars'].' / '.tl($t['excursion_status'])) ?></td></tr>
<tr><th>확인 구간 상승 / 하락</th><td><?= th(tn($t['known_mfe_pct']).'% / '.tn($t['known_mae_pct'])) ?>%</td></tr>
<tr><th>경계일 포함 상승 / 하락 범위</th><td><?= th(tn($t['envelope_mfe_pct']).'% / '.tn($t['envelope_mae_pct'])) ?>%</td></tr>
</tbody></table>
<?php if($t['post_stop']): ?><h3>손절 이후 흐름</h3><p>손절 다음 거래봉부터 계산합니다. 손절 당일 반등은 제외합니다. 진입가 회복은 해당 N번째 봉의 종가 기준, 비용 제외입니다. 손절하지 않고 보유했을 때의 수익률을 뜻하지 않습니다.</p>
<table><thead><tr><th>기간</th><th>상태</th><th>N봉째 종가 / 손절 체결가</th><th>N봉째 종가 진입가 회복</th><th>기간 고점 / 저점 (손절 체결가 대비)</th></tr></thead><tbody>
<?php foreach($t['post_stop'] as $n=>$p): ?><tr><td><?= th($n) ?>거래봉</td><td><?= th(tl($p['status']).' ('.$p['observed'].'봉)') ?></td><td><?= th(tn($p['close_vs_exit_pct'])) ?>%</td><td><?= $p['recovered_entry_at_close']===null?'—':($p['recovered_entry_at_close']?'예':'아니오') ?></td><td><?= th(tn($p['max_high_vs_exit_pct']).'% / '.tn($p['min_low_vs_exit_pct'])) ?>%</td></tr><?php endforeach ?></tbody></table><?php endif ?>
</details>
<?php endforeach ?>
<p>거래일은 계좌에 관측된 평가 세션 기준입니다. 거래소 휴일·거래정지 전체 달력을 검증하는 기능은 아니며, 관측된 품질 차단/누락을 건너뛰어 계산하지 않습니다. 보유 중 거래는 완료 거래 집계에서 제외합니다.</p>
<?php endif ?></main></body></html>
