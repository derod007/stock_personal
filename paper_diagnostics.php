<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';
require __DIR__.'/bin/paper/Diagnostics.php';
require_once __DIR__.'/bin/paper/Chrome.php';
use ChartEntryLab\PaperJournal;
function dh(mixed $v): string {return paper_esc($v);}
function dt(int $t): string {return $t?(new DateTimeImmutable('@'.$t))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i:s'):'—';}
$id=$_GET['account']??'paper-us';$mode=$_GET['mode']??'forward';
if(!is_string($id)||!preg_match('/^[a-z0-9_-]{1,64}$/',$id)||!in_array($mode,['forward','replay'],true)) {http_response_code(400);exit('잘못된 계좌 요청');}
$dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__).'/stock-personal-paper';
$error=null;$d=null;$runs=null;
try {$d=(new PaperJournal($dir.'/'.$id.'-'.$mode.'.json'))->read();}
catch(Throwable $e) {$error='계좌 기록 읽기/무결성 오류. 원본 기록을 확인하세요.';}
if($mode==='forward') $runs=PaperDiagnostics::runs($dir.'/runs/'.$id.'-forward',time());
$report=PaperDiagnostics::summarize($d['events']??[]);
paper_open(['title'=>'실행·추천 진단','page'=>'diagnostics','account'=>$id,'mode'=>$mode]);
?>
<form class="paper-filter"><?php paper_account_field($id); ?><label>기록 종류 <select name="mode"><option value="forward" <?= $mode==='forward'?'selected':'' ?>>앞으로 기록</option><option value="replay" <?= $mode==='replay'?'selected':'' ?>>과거 재현</option></select></label><button>조회</button></form>
<section class="panel">
<h2 class="paper-section-title">실행 상태</h2>
<?php if($runs===null): ?><p class="paper-note">과거 재현에는 일일 스케줄러 실행 상태를 적용하지 않습니다.</p>
<?php elseif(!$runs['latest']): ?><p class="paper-note">실행 로그가 없습니다. 이 버전의 paper_daily.py가 실행된 이후부터 확인할 수 있습니다. 미실행 여부는 아직 판정할 수 없습니다.</p>
<?php else: $r=$runs['latest']; ?>
<p class="paper-lede">최근 시작 <?= dh(dt($r['started_at'])) ?> · <?= dh(PaperDiagnostics::label($r['outcome'])) ?> · <?= dh(paper_ko((string) $r['stage'])) ?></p>
<p class="paper-note">마지막 시작 후 <?= dh(round($runs['hours_since_start'],1)) ?>시간 경과. 스케줄러의 예정 시각·휴일 설정은 이 화면에서 알 수 없으므로 경과 시간만으로 미실행을 확정하지 않습니다.</p>
<div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>시작 / 완료 (KST)</th><th>결과</th><th>단계 / 오류 유형</th><th>계좌 마지막 거래일 (KST)</th></tr></thead><tbody>
<?php foreach($runs['recent'] as $r): ?><tr><td class="mono"><?= dh(dt($r['started_at']).' / '.dt($r['finished_at']??0)) ?></td><td><?= dh(PaperDiagnostics::label($r['outcome'])) ?></td><td><?= dh(paper_ko((string) $r['stage']).' / '.paper_ko((string) ($r['error_type']??'—'))) ?></td><td class="mono"><?= dh(dt($r['summary']['last_session']??0)) ?></td></tr><?php endforeach ?></tbody></table></div>
<?php endif ?>
<?php if($runs && $runs['invalid_files']): ?><p class="paper-alert">읽을 수 없는 실행 로그 <?= dh($runs['invalid_files']) ?>개가 있습니다.</p><?php endif ?>
</section>
<section class="panel">
<h2 class="paper-section-title">추천·주문 진단</h2>
<?php if($error): ?><p class="paper-alert"><?= dh($error) ?></p><?php elseif(!$d['state']): ?><p class="paper-note">아직 계좌 기록이 없습니다. 실행 상태부터 확인하세요.</p><?php else: ?>
<?php if(!empty($d['state']['halted'])): ?><p class="paper-alert">계좌 중단: <?= dh(paper_ko((string) ($d['state']['halt_reason']??'unknown'))) ?>. 정상적인 추천 없음과 다릅니다.</p><?php endif ?>
<p class="paper-note">마지막 기록 거래일 <?= dh(dt($report['latest_session'])) ?> KST를 기준으로 최근 30일을 집계합니다. 오늘까지 최신 데이터라는 뜻은 아닙니다. 종목·거래일별 평가 건수이며 같은 종목이 여러 번 포함됩니다.</p>
<p class="paper-note">주문 실행 결과는 이전에 만든 주문을 포함하므로 같은 기간의 추천 건수와 직접 나눠 체결률을 계산하지 않습니다. 제외 사유는 처음 걸린 조건 하나이며, 품질 사유는 한 평가에 여러 개일 수 있습니다.</p>
<?php foreach($report['origins'] as $origin=>$g): ?>
<h3 class="paper-section-title"><?= dh(PaperDiagnostics::label($origin)) ?></h3>
<?php foreach(['counts'=>'건수','reasons'=>'주문 제외·취소·청산 사유','plan_reasons'=>'진입 조건 미충족 상세','quality_reasons'=>'데이터 차단 상세'] as $key=>$title): if(!$g[$key])continue; ?>
<h4 class="info-card__title"><?= dh($title) ?></h4>
<div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>항목</th><th>건수</th></tr></thead><tbody><?php foreach($g[$key] as $reason=>$count): ?><tr><td><?= dh(PaperDiagnostics::label((string)$reason)) ?></td><td class="mono"><?= dh($count) ?></td></tr><?php endforeach ?></tbody></table></div>
<?php endforeach; endforeach ?>
<?php endif ?>
</section>
<?php paper_close();