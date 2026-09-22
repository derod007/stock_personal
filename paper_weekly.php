<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';require_once __DIR__.'/bin/paper/Experiment.php';require_once __DIR__.'/bin/paper/Weekly.php';require_once __DIR__.'/bin/paper/Diagnostics.php';
require_once __DIR__.'/bin/paper/Chrome.php';
require_once __DIR__.'/bin/paper/FollowupPanel.php';
use ChartEntryLab\PaperJournal;
function wh(mixed $x):string{return paper_esc($x);}
function wt(?int $t):string{return $t?(new DateTimeImmutable('@'.$t))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i'):'—';}
function wn(mixed $x):string{return $x===null?'—':number_format((float)$x,2);}
$id=$_GET['account']??'paper-us';$mode=$_GET['mode']??'forward';$week=$_GET['week']??null;$r=null;$error=null;
try{
 if(!is_string($id)||!is_string($mode)||($week!==null&&!is_string($week)))throw new InvalidArgumentException('Invalid request');
 $dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__).'/stock-personal-paper';$r=PaperWeekly::load($dir,$id,$mode,$week);
}catch(InvalidArgumentException $e){http_response_code(400);exit('계좌/기록 종류/주차를 확인하세요.');}
catch(Throwable $e){$error='계좌 기록 읽기 또는 무결성 확인에 실패했습니다. 정상 0건으로 집계하지 않습니다.';}
if(($_GET['format']??'')==='json'){header('Content-Type: application/json; charset=utf-8');if($error)http_response_code(409);echo PaperJournal::encode($error?['error'=>$error]:$r);exit;}
$week=$r['window']['week']??$week;
paper_open(['title'=>'주간 모의 계좌 요약','page'=>'weekly','account'=>$id,'mode'=>$mode,'week'=>(string)$week]);
?>
<form class="paper-filter"><?php paper_account_field($id); ?><label>주차 <input type="week" name="week" value="<?= wh($week) ?>"></label><label>기록 종류 <select name="mode"><option value="forward" <?= $mode==='forward'?'selected':'' ?>>앞으로 기록</option><option value="replay" <?= $mode==='replay'?'selected':'' ?>>과거 재현</option></select></label><button>조회</button></form>
<?php if($error): ?><section class="panel panel--error"><p><?= wh($error) ?></p></section>
<?php else: $s=$r['summary']; ?>
<p class="paper-lede"><?= wh(wt($r['window']['start']).' ~ '.wt($r['window']['end'])) ?> KST (끝 시각 제외). 조회할 때 기존 기록으로 자동 집계하며 원본을 수정하지 않습니다.</p>
<p class="paper-links"><a class="btn-scan btn-scan--ghost" href="?account=<?= wh($id) ?>&amp;mode=<?= wh($mode) ?>&amp;week=<?= wh($week) ?>&amp;format=json">전체 JSON</a></p>
<section class="panel">
<h2 class="paper-section-title">실행 상태</h2>
<?php if($r['operations']===null): ?><p class="paper-note">과거 재현에는 실제 주간 예약 실행 횟수를 적용하지 않습니다.</p><?php else: $o=$r['operations']; ?>
<p class="paper-note">선택 주 실행 <?= wh($o['total']) ?>건 · <?= wh(paper_ko_counts($o['counts'])) ?><br>현재까지 마지막 실행 시작 <?= wh(wt($o['latest_start'])) ?> · 읽기 불가 로그 <?= wh($o['invalid_files']) ?>개 (전체 로그 기준)</p><p class="paper-note">로그 0건은 추천 0건과 다릅니다. 예약 시각·휴일 정보를 모르므로 미실행 횟수를 추정하지 않습니다.</p>
<?php endif ?>
<?php if(!$s): ?><p class="paper-empty">계좌 기록이 없어 거래 성과를 집계할 수 없습니다.</p><?php else: ?>
<p class="paper-lede"><?= $s['week_complete']?'달력상 종료된 주':'진행 중인 주 · 부분 집계' ?> · 통화 <?= wh(paper_ko((string) $s['currency'])) ?> · 평가 세션 <?= wh($s['evaluation_sessions']) ?>개</p>
<p class="paper-note">현재 계좌 <?= $s['current_account']['halted']?'중단: '.wh(paper_ko((string) ($s['current_account']['halt_reason']??''))):'중단 표시 없음' ?> · 현재 마지막 평가 <?= wh(wt($s['current_account']['last_session'])) ?></p>
<?php if($s['account_alerts']): ?><p class="paper-alert">선택 주 실제 발견 중단: <?php $bits=[]; foreach($s['account_alerts'] as $a){$bits[]=paper_ko((string)($a['type']??'')).' · '.paper_ko((string)($a['reason']??''));} echo wh(implode(' / ', $bits)); ?></p><?php endif ?>
</section>
<section class="panel">
<h2 class="paper-section-title">추천에서 주문까지</h2>
<div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>기록 종류</th><th>평가</th><th>조건 충족</th><th>주문</th><th>제외</th></tr></thead><tbody>
<?php foreach($s['groups'] as $origin=>$g): ?><tr><td><?= wh(PaperDiagnostics::label($origin)) ?></td><td class="mono"><?= wh($g['evaluated']) ?></td><td class="mono"><?= wh($g['confirmed']) ?></td><td class="mono"><?= wh($g['orders']) ?></td><td class="mono"><?= wh($g['excluded']) ?></td></tr><?php endforeach ?></tbody></table></div>
<p class="paper-note">조건 충족은 plan.ready 기준입니다. 품질·자금 한도로 주문이 제외될 수 있습니다. 아래 체결·청산에는 이전 주에 생성한 주문도 포함되므로 위 건수로 체결률을 계산하지 않습니다.</p>
<p class="paper-note">체결 <?= wh($s['execution']['fill']) ?> / 청산 <?= wh($s['execution']['exit']) ?> / 취소·만료 <?= wh($s['execution']['order_cancelled']) ?>건</p>
<?php foreach($s['groups'] as $origin=>$g): if(!$g['reasons'])continue; ?><h3 class="paper-section-title"><?= wh(PaperDiagnostics::label($origin)) ?> 제외 사유</h3><ul><?php foreach($g['reasons'] as $reason=>$count): ?><li><?= wh(PaperDiagnostics::label('decision:'.$reason).' · '.$count.'건') ?></li><?php endforeach ?></ul><?php endforeach ?>
</section>
<section class="panel">
<h2 class="paper-section-title">선택 주 성과</h2>
<p class="paper-lede">청산 순손익 <?= wh(wn($s['realized_pnl'])) ?> · 평가자산 변화 <?= wh(wn($s['equity_change'])) ?> <?= wh(paper_ko((string) $s['currency'])) ?></p>
<p class="paper-note">비교 시작 평가 <?= wh(wt($s['opening_valuation']['session']??null)) ?> / 마지막 평가 <?= wh(wt($s['closing_valuation']['session']??null)) ?> KST. 첫 평가 주의 시작값은 초기자금입니다. 직전 평가가 오래됐으면 그 이후 변동까지 포함되므로 정확한 주간 수익으로 단정하지 않습니다.</p>
<?php if($s['valuation']): ?><p class="paper-note">선택 주 마지막 평가 기준 보유 미실현손익 <?= wh(wn($s['valuation']['unrealized'])) ?> · 현금 <?= wh(wn($s['valuation']['cash'])) ?> · 예약금 <?= wh(wn($s['valuation']['reserved_cash'])) ?>.<br><?= !empty($s['valuation']['stale_positions'])?'가격 누락으로 평가 추정치입니다. 자산 변화 계산을 보류했습니다.':'' ?></p><?php else: ?><p class="paper-note">선택 주 평가 기록 없음. 보유 평가손익은 0이 아닌 미집계입니다.</p><?php endif ?>
<div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>청산 사유</th><th>건수</th><th>순손익</th></tr></thead><tbody><?php foreach($s['exit_reasons'] as $reason=>$v): ?><tr><td><?= wh(['stop'=>'손절','target'=>'목표가','time'=>'보유기간 만료'][$reason]??$reason) ?></td><td class="mono"><?= wh($v['count']) ?></td><td class="mono"><?= wh(wn($v['net_pnl'])) ?></td></tr><?php endforeach ?></tbody></table></div>
<?php endif ?>
</section>
<section class="panel">
<h2 class="paper-section-title">비교 계좌 현재 상태 (선택 주의 과거 상태 아님)</h2>
<?php if(!$r['comparisons_current']): ?><p class="paper-empty">이 계좌와 연결된 읽을 수 있는 비교 기록이 없습니다.</p><?php endif ?>
<?php foreach($r['comparisons_current'] as $c): ?><p class="paper-lede"><?= wh(paper_account_name((string) $c['definition']['id'])) ?> · <?= wh(paper_ko((string) $c['identity_check'])) ?> · <?= $c['source_sync']?'원본과 갱신 일치':'원본과 갱신 불일치' ?><br><?= wh($c['decision'].' / '.paper_ko_join($c['reasons'])) ?> · 마지막 평가 <?= wh(wt($c['last_session'])) ?></p><?php if($c['operations']): ?><p class="paper-note">선택 주 비교 실행: <?= wh(paper_ko_counts($c['operations']['counts'])) ?> · 마지막 시작 <?= wh(wt($c['operations']['latest_start'])) ?></p><?php endif; endforeach ?>
<?php if($r['comparison_read_errors']): ?><p class="paper-alert">비교 파일 읽기 오류 <?= wh($r['comparison_read_errors']) ?>개 (동일 기록 종류의 전체 비교 파일). 동일 전략 정상으로 간주하지 않습니다.</p><?php endif ?>
<p class="paper-note">주간 완료 거래 수가 적으면 손익은 관측값일 뿐입니다. 자동 조건 변경이나 전략 승격은 없습니다. 거래 집계는 세션 시각, 실행/중단 발견은 실제 기록 시각 기준입니다.</p>
<?php
if($mode==='forward'){
    if($r['followup_error'])echo '<p class="paper-alert">'.wh($r['followup_error']).'</p>';
    else paper_followup_panel($r['followup'], $r['window']);
}
endif;
paper_close();

