<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';
require_once __DIR__.'/bin/paper/Chrome.php';
use ChartEntryLab\PaperJournal;
use ChartEntryLab\PaperPortfolio;
function ph(mixed $v): string {return paper_esc($v);}
function pt(int $v): string {return $v?(new DateTimeImmutable('@'.$v))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i'):'—';}
$id=$_GET['account']??'paper-us';$mode=$_GET['mode']??'forward';
if(!is_string($id) || !preg_match('/^[a-z0-9_-]{1,64}$/',$id) || !in_array($mode,['forward','replay'],true)) {http_response_code(400);exit('잘못된 계좌 요청');}
$directory=getenv('PAPER_STATE_DIR')?:dirname(__DIR__).'/stock-personal-paper';
$d=null;$error=null;
try {$d=(new PaperJournal($directory.'/'.$id.'-'.$mode.'.json'))->read();}
catch(Throwable $e) {$error='기록 무결성 또는 읽기 오류: CLI에서 원본 기록을 확인하세요.';}
$s=$d['state']??null;
paper_open(['title'=>'모의 계좌 기록','page'=>'account','account'=>$id,'mode'=>$mode]);
?>
<form class="paper-filter" method="get">
  <label>계좌 ID <input name="account" value="<?= ph($id) ?>"></label>
  <label>기록 종류 <select name="mode"><option value="forward" <?= $mode==='forward'?'selected':'' ?>>앞으로 기록한 추천</option><option value="replay" <?= $mode==='replay'?'selected':'' ?>>과거 재현</option></select></label>
  <button>조회</button>
</form>
<p class="paper-lede">실제 주문을 보내지 않는 모의 기록입니다. 과거 재현 결과와 앞으로 기록한 추천은 별도 계좌로 관리합니다.</p>
<?php if($error): ?>
<section class="panel panel--error"><h2>읽을 수 없음</h2><p><?= ph($error) ?></p></section>
<?php elseif(!$s): ?>
<section class="panel panel--idle">
  <h2>아직 기록이 없습니다</h2>
  <p>프로젝트 폴더에서 아래 명령으로 수집·기록한 뒤 다시 조회하세요.</p>
  <pre class="paper-pre">python3 bin/paper_daily.py --config=config/paper-us.json</pre>
  <p class="paper-note">한국 계좌는 config/paper-kr.json을 사용하세요. 화면 조회만으로 주문이나 수집이 실행되지는 않습니다.</p>
</section>
<?php else:
$equity=PaperPortfolio::equity($s);$reserve=PaperPortfolio::reserved($s);$last=$s['equity']===[]?null:end($s['equity']);
$unreal=$equity-$s['config']['initial_cash']-$s['realized'];
?>
<section class="panel panel--result">
  <div class="result__head">
    <div class="result__identity">
      <h2><?= ph($id) ?></h2>
      <p class="result__meta"><?= ph($s['config']['currency']) ?> · <?= $mode==='forward'?'앞으로 기록한 추천':'과거 재현' ?> · 마지막 평가 <?= ph(pt($s['last_session'])) ?> KST · 전략 <?= ph(substr($s['version'],0,12)) ?></p>
    </div>
  </div>
  <?php if(!empty($s['halted'])): ?><p class="paper-alert"><?= ($s['halt_reason']??'historical_revision')==='unpriced_position'?'보유 종목의 가격 경로를 확인할 수 없어 계좌 처리를 중단했습니다. 누락 기간의 체결을 추측하지 않습니다.':'과거 가격 변경이 발견되어 계좌 처리를 중단했습니다. 이전 추천은 보존됐습니다.' ?></p><?php endif ?>
  <?php if(!empty($last['stale_positions'])): ?><p class="paper-alert">최신 가격을 확인하지 못한 보유 종목: <?= ph(implode(', ',$last['stale_positions'])) ?>. 자산은 마지막 유효 가격 기준 추정치입니다.</p><?php endif ?>
  <div class="metric-grid">
    <div class="metric"><span class="metric__label">현금 / 예약금</span><strong class="metric__value mono"><?= ph(number_format($s['cash'],0)) ?></strong><small><?= ph(number_format($reserve,0)) ?></small></div>
    <div class="metric metric--accent"><span class="metric__label">주문 가능 현금</span><strong class="metric__value mono"><?= ph(number_format($s['cash']-$reserve,0)) ?></strong></div>
    <div class="metric metric--success"><span class="metric__label">평가 자산</span><strong class="metric__value mono"><?= ph(number_format($equity,0)) ?></strong></div>
    <div class="metric"><span class="metric__label">실현 / 미실현</span><strong class="metric__value mono"><?= ph(number_format($s['realized'],0)) ?></strong><small><?= ph(number_format($unreal,0)) ?></small></div>
    <div class="metric metric--danger"><span class="metric__label">최대 낙폭</span><strong class="metric__value mono"><?= ph(round($s['max_drawdown']*100,2)) ?>%</strong></div>
    <div class="metric"><span class="metric__label">완료 거래</span><strong class="metric__value"><?= ph($s['closed_trades']) ?></strong><small>이익 <?= ph($s['wins']) ?> · 손실 <?= ph($s['losses']) ?></small></div>
  </div>
</section>
<section class="panel">
  <h2 class="paper-section-title">보유 종목과 대기 주문</h2>
  <?php if(!$s['active']): ?><p class="paper-empty">보유·대기 주문 없음</p>
  <?php else: ?><div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>종목</th><th>상태</th><th>수량</th><th>계획 진입 / 손절 / 목표</th><th>계획 위험</th></tr></thead><tbody>
  <?php foreach($s['active'] as $symbol=>$o): ?><tr><td class="mono"><?= ph($symbol) ?></td><td><?= $o['filled']?'보유':'지정가 대기' ?></td><td class="mono"><?= ph($o['quantity']) ?></td><td class="mono"><?= ph($o['plan']['entry'].' / '.$o['plan']['stop'].' / '.$o['plan']['target']) ?></td><td class="mono"><?= ph(round($o['planned_risk'],2)) ?></td></tr><?php endforeach ?>
  </tbody></table></div><?php endif ?>
</section>
<section class="panel">
  <h2 class="paper-section-title">최근 추천과 데이터 품질</h2>
  <p class="paper-note">warning은 보정 방식 등이 미확인인 연구용 데이터, blocked는 이번 모의 주문에 사용할 수 없는 데이터입니다.</p>
  <div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>종목</th><th>판단 시점 / 실제 기록 시점 (KST)</th><th>기록 종류</th><th>신호</th><th>품질·출처</th></tr></thead><tbody>
  <?php foreach($s['last_snapshots'] as $symbol=>$x):
    $planStatus=(string)($x['plan']['status']??'');
    $rowClass=$planStatus==='risk_blocked'?'is-top-risk':($x['plan']['ready']??false?'is-recommend':'');
  ?>
  <tr class="<?= ph($rowClass) ?>"><td class="mono"><?= ph($symbol) ?></td><td class="mono"><?= ph(pt($x['session']).' / '.pt($x['recorded_at'])) ?></td><td><?= ph($x['origin']) ?></td><td><?= ph($x['plan']['reason']??$x['plan']['status']) ?></td><td><?= ph($x['quality']['status'].' · '.implode(', ',array_merge($x['quality']['reasons'],$x['quality']['warnings']))) ?><br><span class="scan-code"><?= ph($x['quality']['source']['price_basis']??$x['quality']['source']['source']??'출처 없음') ?></span></td></tr>
  <?php endforeach ?>
  </tbody></table></div>
</section>
<section class="panel">
  <h2 class="paper-section-title">최근 기록 80건</h2>
  <p class="paper-note">snapshot은 당시 판단, decision은 주문 허용/제외 결과입니다. 전체 기록은 계좌 파일에 보존됩니다.</p>
  <div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>번호 / 실제 기록 시각 (KST)</th><th>종류</th><th>내용</th></tr></thead><tbody>
  <?php foreach(array_reverse(array_slice($d['events'],-80)) as $e): $p=$e['payload']; ?>
  <tr><td class="mono"><?= ph($e['id'].' / '.pt($e['recorded_at'])) ?></td><td><span class="badge"><?= ph($e['type']) ?></span></td><td><?php
  if($e['type']==='snapshot') echo ph(($p['symbol']??'').' · '.($p['plan']['status']??'').' · '.$p['origin']);
  else echo ph(PaperJournal::encode($p));
  ?></td></tr><?php endforeach ?>
  </tbody></table></div>
</section>
<?php endif;
paper_close();
