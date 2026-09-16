<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';
use ChartEntryLab\PaperJournal;
use ChartEntryLab\PaperPortfolio;
function ph(mixed $v): string {return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function pt(int $v): string {return $v?(new DateTimeImmutable('@'.$v))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i'):'—';}
$id=$_GET['account']??'paper-us';$mode=$_GET['mode']??'forward';
if(!is_string($id) || !preg_match('/^[a-z0-9_-]{1,64}$/',$id) || !in_array($mode,['forward','replay'],true)) {http_response_code(400);exit('잘못된 계좌 요청');}
$directory=getenv('PAPER_STATE_DIR')?:dirname(__DIR__).'/stock-personal-paper';
$d=null;$error=null;
try {$d=(new PaperJournal($directory.'/'.$id.'-'.$mode.'.json'))->read();}
catch(Throwable $e) {$error='기록 무결성 또는 읽기 오류: CLI에서 원본 기록을 확인하세요.';}
$s=$d['state']??null;
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>모의 계좌 기록</title><link rel="stylesheet" href="assets/app.css"></head>
<body><main class="app"><h1>모의 계좌 기록</h1>
<p><a href="index.php">종목 분석</a> · <a href="?account=paper-us">미국 모의 계좌</a> · <a href="?account=paper-kr">한국 모의 계좌</a></p>
<form method="get"><label>계좌 ID <input name="account" value="<?= ph($id) ?>"></label>
<label>기록 종류 <select name="mode"><option value="forward" <?= $mode==='forward'?'selected':'' ?>>앞으로 기록한 추천</option><option value="replay" <?= $mode==='replay'?'selected':'' ?>>과거 재현</option></select></label><button>조회</button></form>
<p><a href="paper_diagnostics.php?account=<?= ph($id) ?>&amp;mode=<?= ph($mode) ?>">실행·추천 진단</a> · <a href="paper_trades.php?account=<?= ph($id) ?>&amp;mode=<?= ph($mode) ?>">거래별 성과 분석</a></p>
<p>실제 주문을 보내지 않는 모의 기록입니다. 과거 재현 결과와 앞으로 기록한 추천은 별도 계좌로 관리합니다.</p>
<?php if($error): ?><p><?= ph($error) ?></p><?php elseif(!$s): ?>
<p>아직 기록이 없습니다. 프로젝트 폴더에서 아래 명령으로 수집·기록한 뒤 다시 조회하세요.</p>
<pre>python3 bin/paper_daily.py --config=config/paper-us.json</pre>
<p>한국 계좌는 config/paper-kr.json을 사용하세요. 화면 조회만으로 주문이나 수집이 실행되지는 않습니다.</p>
<?php else:
$equity=PaperPortfolio::equity($s);$reserve=PaperPortfolio::reserved($s);$last=$s['equity']===[]?null:end($s['equity']);
?>
<h2><?= ph($id) ?> · <?= ph($s['config']['currency']) ?> · <?= $mode==='forward'?'앞으로 기록한 추천':'과거 재현' ?></h2>
<p>마지막 평가: <?= ph(pt($s['last_session'])) ?> KST · 전략 버전 <?= ph(substr($s['version'],0,12)) ?></p>
<?php if(!empty($s['halted'])): ?><p><strong><?= ($s['halt_reason']??'historical_revision')==='unpriced_position'?'보유 종목의 가격 경로를 확인할 수 없어 계좌 처리를 중단했습니다. 누락 기간의 체결을 추측하지 않습니다.':'과거 가격 변경이 발견되어 계좌 처리를 중단했습니다. 이전 추천은 보존됐습니다.' ?></strong></p><?php endif ?>
<?php if(!empty($last['stale_positions'])): ?><p><strong>최신 가격을 확인하지 못한 보유 종목: <?= ph(implode(', ',$last['stale_positions'])) ?>. 자산은 마지막 유효 가격 기준 추정치입니다.</strong></p><?php endif ?>
<table><tbody>
<tr><th>현금 / 주문 예약금</th><td><?= ph(number_format($s['cash'],2)) ?> / <?= ph(number_format($reserve,2)) ?></td></tr>
<tr><th>주문 가능 현금</th><td><?= ph(number_format($s['cash']-$reserve,2)) ?></td></tr>
<tr><th>평가 자산</th><td><?= ph(number_format($equity,2)) ?></td></tr>
<tr><th>실현 / 미실현 손익</th><td><?= ph(number_format($s['realized'],2)) ?> / <?= ph(number_format($equity-$s['config']['initial_cash']-$s['realized'],2)) ?></td></tr>
<tr><th>유효 종가 기준 최대 낙폭</th><td><?= ph(round($s['max_drawdown']*100,2)) ?>%</td></tr>
<tr><th>완료 거래 / 이익 / 손실</th><td><?= ph($s['closed_trades']) ?> / <?= ph($s['wins']) ?> / <?= ph($s['losses']) ?></td></tr>
</tbody></table>
<p><a href="paper_compare.php">기준·후보 전략 비교</a></p>
<h2>보유 종목과 대기 주문</h2>
<table><thead><tr><th>종목</th><th>상태</th><th>수량</th><th>계획 진입 / 손절 / 목표</th><th>계획 위험</th></tr></thead><tbody>
<?php foreach($s['active'] as $symbol=>$o): ?><tr><td><?= ph($symbol) ?></td><td><?= $o['filled']?'보유':'지정가 대기' ?></td><td><?= ph($o['quantity']) ?></td><td><?= ph($o['plan']['entry'].' / '.$o['plan']['stop'].' / '.$o['plan']['target']) ?></td><td><?= ph(round($o['planned_risk'],2)) ?></td></tr><?php endforeach ?>
</tbody></table>
<h2>최근 추천과 데이터 품질</h2>
<p>warning은 보정 방식 등이 미확인인 연구용 데이터, blocked는 이번 모의 주문에 사용할 수 없는 데이터입니다.</p>
<table><thead><tr><th>종목</th><th>판단 시점 / 실제 기록 시점 (KST)</th><th>기록 종류</th><th>신호</th><th>품질·출처</th></tr></thead><tbody>
<?php foreach($s['last_snapshots'] as $symbol=>$x): ?><tr><td><?= ph($symbol) ?></td><td><?= ph(pt($x['session']).' / '.pt($x['recorded_at'])) ?></td><td><?= ph($x['origin']) ?></td><td><?= ph($x['plan']['reason']??$x['plan']['status']) ?></td><td><?= ph($x['quality']['status'].' · '.implode(', ',array_merge($x['quality']['reasons'],$x['quality']['warnings']))) ?><br><?= ph($x['quality']['source']['source']??'출처 없음') ?></td></tr><?php endforeach ?>
</tbody></table>
<h2>최근 기록 80건</h2><p>snapshot은 당시 판단, decision은 주문 허용/제외 결과입니다. 전체 기록은 계좌 파일에 보존됩니다.</p>
<table><thead><tr><th>번호 / 실제 기록 시각 (KST)</th><th>종류</th><th>내용</th></tr></thead><tbody>
<?php foreach(array_reverse(array_slice($d['events'],-80)) as $e): $p=$e['payload']; ?>
<tr><td><?= ph($e['id'].' / '.pt($e['recorded_at'])) ?></td><td><?= ph($e['type']) ?></td><td><?php
if($e['type']==='snapshot') echo ph(($p['symbol']??'').' · '.($p['plan']['status']??'').' · '.$p['origin']);
else echo ph(PaperJournal::encode($p));
?></td></tr><?php endforeach ?></tbody></table>
<?php endif ?></main></body></html>
