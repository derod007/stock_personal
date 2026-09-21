<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';require_once __DIR__.'/bin/paper/Universe.php';
use ChartEntryLab\PaperJournal;
function uh(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function ut(?int $v):string{return $v?(new DateTimeImmutable('@'.$v))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i:s'):'—';}
$id=$_GET['account']??'research-us-v1';$mode=$_GET['mode']??'forward';
if(!is_string($id)||!preg_match('/^research-[a-z0-9_-]{1,55}$/',$id)||!in_array($mode,['forward','replay'],true)){http_response_code(400);exit('잘못된 연구 계좌 요청');}
$dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__).'/stock-personal-paper';$r=null;$config=null;$error=null;
try{$d=(new PaperJournal($dir.'/'.$id.'-'.$mode.'.json'))->read();if($d && $d['state']){$r=PaperUniverse::summarize($d);$config=$r['config'];}
elseif(in_array($id,['research-us-v1','research-kr-v1'],true)){$market=$id==='research-us-v1'?'us':'kr';$config=json_decode(file_get_contents(__DIR__.'/config/paper-research-'.$market.'-v1.json'),true,512,JSON_THROW_ON_ERROR);}}
catch(Throwable $e){$error='계좌 읽기/무결성 오류. 성과를 0으로 대체하지 않습니다.';}
$telemetry=$mode==='forward'?PaperUniverse::telemetry($dir.'/research-runs/'.$id):null;
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>고정 종목군 연구</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="app"><h1>고정 종목군 연구</h1>
<p><a href="?account=research-us-v1">미국 연구</a> · <a href="?account=research-kr-v1">한국 연구</a> · <a href="paper.php?account=<?= uh($id) ?>&amp;mode=<?= uh($mode) ?>">계좌</a> · <a href="paper_weekly.php?account=<?= uh($id) ?>&amp;mode=<?= uh($mode) ?>">주간 요약</a> · <a href="paper_trades.php?account=<?= uh($id) ?>&amp;mode=<?= uh($mode) ?>">거래 분석</a></p>
<form><label>연구 계좌 <input name="account" value="<?= uh($id) ?>"></label><select name="mode"><option value="forward" <?= $mode==='forward'?'selected':'' ?>>앞으로 기록</option><option value="replay" <?= $mode==='replay'?'selected':'' ?>>과거 재현</option></select><button>조회</button></form>
<p>업종을 나누어 고정한 수동 검증 표본입니다. 매수 추천·시장 대표 표본·수익률 순위가 아닙니다. 기존 계좌와 시작일이 달라 성과를 직접 비교하지 않습니다.</p>
<?php if($error): ?><p><?= uh($error) ?></p><?php elseif($config): ?>
<p>종목군 정의일 <?= uh($config['universe']['defined_on']??'—') ?> · 버전 <?= uh($config['universe']['version']??'—') ?> · 통화 <?= uh($config['currency']) ?> · 실제 기록 시작 <?= uh(ut($r['started_at']??null)) ?> KST</p>
<details open><summary>고정 종목 목록</summary><table><thead><tr><th>종목 코드</th><th>연구 업종</th></tr></thead><tbody><?php foreach($config['symbols'] as $sym=>$sector): ?><tr><td><?= uh($sym) ?></td><td><?= uh($sector) ?></td></tr><?php endforeach ?></tbody></table></details>
<?php if(!$r): ?><p>아직 연구 계좌 기록이 없습니다. 기존 예약 작업을 유지하고 별도 연구 실행을 추가하세요.</p><pre>python bin/paper_research_daily.py --config=config/paper-research-<?= $id==='research-kr-v1'?'kr':'us' ?>-v1.json</pre><?php endif ?>
<?php endif ?>
<h2>최근 수집·실행 상태 (앞으로 기록 계좌)</h2>
<?php if(!$telemetry): ?><p>과거 재현과 실시간 수집 로그는 분리합니다.</p><?php elseif(!$telemetry['latest']): ?><p>연구 실행 로그 없음. 수집 성공으로 간주하지 않습니다.</p><?php else: $t=$telemetry['latest']; ?>
<p>시작 <?= uh(ut($t['started_at'])) ?> KST · 결과 <?= uh($t['status']) ?> · 단계 <?= uh($t['stage']??'—') ?> · 오류 <?= uh($t['error_type']??'없음') ?></p>
<p>총 <?= uh($t['elapsed_seconds']??'—') ?>초 / 수집 <?= uh($t['collect_seconds']??'—') ?>초 / 한국 정규장 보정 <?= uh($t['naver_session_seconds']??'—') ?>초 / 계좌 계산 <?= uh($t['account_seconds']??'—') ?>초</p>
<?php if(isset($t['coverage'])): ?><p>입력 확보 <?= uh($t['coverage']['available'].' / '.$t['coverage']['requested']) ?>종목 · 누락/차단 <?= uh(implode(', ',$t['coverage']['missing'])?:'없음') ?></p>
<table><thead><tr><th>종목</th><th>수집 상태</th><th>유효 봉</th><th>사유</th></tr></thead><tbody><?php foreach($t['coverage']['symbols'] as $symbol=>$v): ?><tr><td><?= uh($symbol) ?></td><td><?= uh($v['status']) ?></td><td><?= uh($v['valid_bars']??0) ?></td><td><?= uh($v['reason']??'—') ?></td></tr><?php endforeach ?></tbody></table>
<?php else: ?><p>수집 검사가 완료되지 않았습니다.</p><?php endif ?>
<p>입력 확보는 완전한 가격 보정·최신성 검증이 아닙니다. 완료 일봉·최신성·과거 변경 검사는 기존 계좌 처리에서 추가로 수행합니다. 일부 종목 입력이 빠지면 전체 연구 계좌 갱신을 보류합니다.</p>
<?php endif ?>
<?php if($telemetry && $telemetry['invalid_files']): ?><p>읽기 불가 로그 <?= uh($telemetry['invalid_files']) ?>개</p><?php endif ?>
<?php if($r): ?>
<h2>전체 기록 성과</h2><p>마지막 평가 <?= uh(ut($r['last_session'])) ?> KST · <?= $r['halted']?'계좌 중단':'계좌 중단 표시 없음' ?> · 완료 <?= uh($r['closed']) ?>건 · 실현손익 <?= uh(number_format($r['realized'],2)) ?> <?= uh($r['config']['currency']) ?></p>
<?php if($r['issues']): ?><p>계좌 합계 확인 필요: <?= uh(implode(', ',$r['issues'])) ?></p><?php endif ?>
<p>업종 손익은 이 계좌에 기여한 실현손익입니다. 업종별 독립 전략 수익률이 아닙니다. 자금 한도와 종목 코드 순서 때문에 먼저 처리된 주문이 다른 주문을 제한할 수 있습니다. 체결·청산은 이전 평가의 주문을 포함하므로 단순 전환율을 계산하지 않습니다.</p>
<?php foreach(['sectors'=>'업종별','symbols'=>'종목별'] as $key=>$title): ?><h3><?= uh($title) ?></h3><table><thead><tr><th>구분</th><th>평가 / 조건 충족</th><th>주문 / 체결</th><th>청산 / 이익</th><th>실현손익</th></tr></thead><tbody><?php foreach($r[$key] as $v): ?><tr><td><?= uh($v['symbol']??$v['sector']) ?></td><td><?= uh($v['evaluated'].' / '.$v['ready']) ?></td><td><?= uh($v['orders'].' / '.$v['fills']) ?></td><td><?= uh($v['closed'].' / '.$v['wins']) ?></td><td><?= uh(number_format($v['net_pnl'],2)) ?></td></tr><?php endforeach ?></tbody></table><?php endforeach ?>
<h3>종목별 관측·제외 사유</h3><?php foreach($r['symbols'] as $v): ?><details><summary><?= uh($v['symbol']) ?> · 최신 품질 <?= uh($v['last_snapshot']['quality']??'기록 없음') ?></summary><pre><?= uh(PaperJournal::encode(['origins'=>$v['origins'],'excluded'=>$v['excluded'],'latest'=>$v['last_snapshot']])) ?></pre></details><?php endforeach ?>
<p>표본 수가 작으면 수익성 판단을 보류합니다. 현재 종목을 선택한 표본에는 생존 편향이 있을 수 있고, 업종 분류는 연구용 수동 분류입니다. 종목 변경 시 새 계좌 ID와 종목군 버전을 사용하세요.</p>
<?php endif ?></main></body></html>
