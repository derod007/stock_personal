<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';
require_once __DIR__.'/bin/paper/DailyChanges.php';
require_once __DIR__.'/bin/paper/Chrome.php';
$id=$_GET['account']??'paper-kr';$profile=$_GET['profile']??'account1';$day=$_GET['day']??PaperDailyChanges::day(time());
if(!is_string($id)||!preg_match('/^[a-z0-9_-]{1,64}$/',$id)||!in_array($profile,['account1','custom','isa'],true)||!is_string($day)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$day)||!checkdate((int)substr($day,5,2),(int)substr($day,8,2),(int)substr($day,0,4))){http_response_code(400);exit('잘못된 조회');}
function dcTime($t):string{return $t?(new DateTimeImmutable('@'.(int)$t))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('m-d H:i:s'):'—';}
function dcLabel($s):string{return ['new_ready'=>'새 진입 확인','lost_ready'=>'진입 확인 해제','first_seen_ready'=>'첫 관측 확인 · 이전 비교 없음','fill'=>'체결','exit'=>'청산','unfilled'=>'미체결 만료','cancelled_before_entry'=>'진입 전 취소','order_cancelled'=>'주문 취소'][$s]??paper_ko((string)$s);}
function dcNum($v):string{return is_numeric($v)?number_format((float)$v,3):'—';}
$r=PaperDailyChanges::read(getenv('PAPER_STATE_DIR')?:dirname(__DIR__).'/stock-personal-paper',$id,$profile,$day);
paper_open(['title'=>'오늘 달라진 종목','page'=>'changes','account'=>$id,'mode'=>'forward']);
?>
<form class="paper-filter"><?php paper_account_field($id); ?><label>날짜 (한국 시간) <input type="date" name="day" value="<?= paper_esc($day) ?>"></label><label>수동 프로필 <select name="profile"><?php foreach(['account1','custom','isa'] as $v): ?><option <?= $profile===$v?'selected':'' ?>><?= paper_esc($v) ?></option><?php endforeach ?></select></label><button>조회</button></form>
<p class="paper-lede">저장된 판정 변화와 체결·종료를 모아 봅니다. <a href="paper_journey.php?<?= paper_esc(http_build_query(['account'=>$id,'profile'=>$profile])) ?>">진입 확인 → 결과 상세</a></p>
<?php foreach($r['errors'] as $error): ?><p role="alert">일부 자료 확인 실패: <?= paper_esc($error) ?></p><?php endforeach ?>
<section class="panel"><h2>선택일 기록</h2>
<?php $s=$r['last_scan']; if($s): ?><p>최근 완료봉 감사 <?= paper_esc(dcTime($s['at'])) ?> · 종목 <?= (int)$s['count'] ?> · 확인 후 손익비 탈락 <?= isset($s['summary']['confirmed_rr_symbol_days'])?(int)$s['summary']['confirmed_rr_symbol_days']:'미기록' ?> · 연구 포함 <?= isset($s['summary']['added'])?(int)$s['summary']['added']:'미기록' ?> · 원본 요약 <a href="paper_rr.php?account=<?= paper_esc($id) ?>">탈락 로그</a></p><?php else: ?><p>선택일 완료봉 감사 기록이 없습니다. 0건 실행을 의미하지 않습니다.</p><?php endif ?>
<p>후속 자료 평가 기준 <?= paper_esc(dcTime($r['last_followup'])) ?> · 아래 각 이벤트의 날짜 기준을 확인하세요.</p></section>
<section class="panel"><h2>진입 확인 변화 · <?= count($r['changes']) ?>건</h2>
<p class="paper-note">같은 출처의 직전 저장 실행과 비교합니다. 수동은 같은 프로필·시장 범위의 완료봉 분석만 비교합니다. 장중 잠정·조회 실패·TOP100 이탈은 확인 해제로 추정하지 않습니다. 첫 관측 확인은 신규 확인과 구분합니다. 과거 전략 버전이 없어 관찰 비교이며, 같은 봉 재실행은 가격 움직임을 뜻하지 않습니다.</p>
<?php if(!$r['changes']): ?><p>확인 가능한 판정 변화가 없습니다. 비교 자료 부족일 수도 있습니다.</p><?php endif ?>
<div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>변화 / 종목</th><th>출처 · 비교 시각 → 관측 시각</th><th>이전 → 이후 판정</th><th>근거</th></tr></thead><tbody>
<?php foreach($r['changes'] as $e): ?><tr><td><?= paper_esc(dcLabel($e['kind']).' / '.$e['name'].' '.$e['symbol']) ?></td><td><?= paper_esc($e['source'].' · '.dcTime($e['before_at']).' → '.dcTime($e['at'])) ?></td><td><?= paper_esc(($e['before']===null?'미확인':paper_ko($e['before'])).' → '.paper_ko($e['after'])) ?></td><td><?= paper_esc(($e['same_bar']?'같은 봉 재실행 · ':'').$e['note']) ?><br><?= paper_esc($e['reference']) ?></td></tr><?php endforeach ?>
</tbody></table></div></section>
<section class="panel"><h2>개별 추천 후속 모의 결과 · <?= count($r['research']) ?>건</h2>
<p class="paper-note">원본이 일치하는 기존 추천(baseline)만 표시합니다. 지정가 연구 포함 건수와 다릅니다. 체결·청산은 모형 거래일, 만료·취소는 보존 기록에서 처음 확인된 날짜이며 실제 발생일은 미기록입니다. 후속 이력 오류가 있으면 최초 확인일 집계를 보류합니다.</p>
<div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>이벤트 / 종목</th><th>시각 / 기준</th><th>체결가 / 청산가</th><th>사유 / 비용 반영 순손익</th></tr></thead><tbody>
<?php foreach($r['research'] as $e): ?><tr><td><?= paper_esc(dcLabel($e['kind']).' / '.$e['name'].' '.$e['symbol']) ?></td><td><?= paper_esc(dcTime($e['at']).' / '.$e['basis']) ?></td><td><?= paper_esc(dcNum($e['entry']).' / '.dcNum($e['exit'])) ?></td><td><?= paper_esc(paper_ko($e['reason']??'—').' / '.($e['net_return_pct']===null?'—':dcNum($e['net_return_pct']).'%')) ?><?= $e['ambiguous']?' · 일봉 내 순서 불명':'' ?><br><?= paper_esc($e['reference']) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php if(!$r['research']): ?><p>선택일에 연결 가능한 이벤트가 없습니다.</p><?php endif ?></section>
<section class="panel"><h2>모의 계좌 이벤트 · <?= count($r['account_events']) ?>건</h2><p>계좌 장부에 저장된 거래일 기준입니다. 위 개별 추천 결과와 합산하지 않습니다. <a href="paper_trades.php?account=<?= paper_esc($id) ?>&amp;mode=forward">계좌 거래 상세</a></p>
<div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>이벤트 / 종목</th><th>거래일 / 기록일</th><th>가격 / 사유</th><th>기록 순손익 (계좌 통화)</th></tr></thead><tbody>
<?php foreach($r['account_events'] as $e): ?><tr><td><?= paper_esc('#'.$e['id'].' '.dcLabel($e['kind']).' / '.$e['symbol']) ?></td><td><?= paper_esc(dcTime($e['at']).' / '.dcTime($e['recorded_at'])) ?></td><td><?= paper_esc(dcNum($e['price']).' / '.paper_ko($e['reason']??'—')) ?></td><td><?= paper_esc(dcNum($e['net_pnl'])) ?></td></tr><?php endforeach ?>
</tbody></table></div><?php if(!$r['account_events']): ?><p>선택일에 기록된 계좌 이벤트가 없습니다. 주문 가능 여부를 뜻하지 않습니다.</p><?php endif ?></section>
<?php paper_close(); ?>
