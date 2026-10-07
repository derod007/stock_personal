<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';
require_once __DIR__.'/bin/paper/EntryJourney.php';
require_once __DIR__.'/bin/paper/Chrome.php';
use ChartEntryLab\ScanEntryView;
$id=$_GET['account']??'paper-kr';$profile=$_GET['profile']??'account1';$source=$_GET['source']??'all';
if(!is_string($id)||!preg_match('/^[a-z0-9_-]{1,64}$/',$id)||!in_array($profile,['account1','custom','isa'],true)||!in_array($source,['all','manual_scan','completed_audit'],true)){http_response_code(400);exit('잘못된 조회');}
function jt($v):string{return is_numeric($v)&&$v>0?(new DateTimeImmutable('@'.(int)$v))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i:s'):'—';}
function jn($v):string{return is_numeric($v)?number_format((float)$v,3):'—';}
function jl($s):string{return ['linked'=>'후속 결과 연결됨','missing_followup'=>'후속 기록 없음','source_mismatch'=>'원본 불일치 · 연결 보류','policy_mismatch'=>'추적 규칙 불일치',
    'manual_observation_only'=>'수동 관찰 기록 · 체결 판정 없음','missing_outcome'=>'체결 결과 미기록'][$s]??paper_ko($s);}
$error=null;$r=['rows'=>[],'errors'=>[]];
try{$r=PaperEntryJourney::read(getenv('PAPER_STATE_DIR')?:dirname(__DIR__).'/stock-personal-paper',$id,$profile);}catch(Throwable $e){$error='기록을 읽지 못했습니다. 후속 기록 파일을 확인하세요.';}
$rows=array_values(array_filter($r['rows'],fn($x)=>$source==='all'||$x['source']===$source));
$page=max(1,(int)($_GET['p']??1));$pages=max(1,(int)ceil(count($rows)/50));$page=min($page,$pages);
paper_open(['title'=>'진입 확인 → 결과','page'=>'journey','account'=>$id,'mode'=>'forward']);
?>
<form class="paper-filter"><?php paper_account_field($id); ?><label>수동 프로필 <select name="profile"><?php foreach(['account1','custom','isa'] as $v): ?><option <?= $v===$profile?'selected':'' ?>><?= paper_esc($v) ?></option><?php endforeach ?></select></label><label>기록 <select name="source"><?php foreach(['all'=>'전체','manual_scan'=>'수동 스캔','completed_audit'=>'완료봉 감사 기록'] as $v=>$label): ?><option value="<?= paper_esc($v) ?>" <?= $v===$source?'selected':'' ?>><?= paper_esc($label) ?></option><?php endforeach ?></select></label><button>조회</button></form>
<p class="paper-lede">진입 확인 당시 계획과 기존 후속 모의 체결을 연결합니다. 실제 주문·계좌 체결 내역은 <a href="paper_trades.php?account=<?= paper_esc($id) ?>&amp;mode=forward">모의 계좌 거래</a>에서 확인하세요.</p>
<p class="paper-note">완료봉 결과는 원본 해시·파일·종목·판정봉·추적 규칙이 일치하는 기존 추천 결과만 연결합니다. 개별 거래 모의 결과이며 계좌 수익률이 아닙니다. 수동 스캔은 선택 프로필의 공통 관찰 기록이며 위 모의 계좌에 주문을 생성하지 않습니다. 장중 기록에 당일 전체 고저가로 체결을 소급 적용하지 않습니다.</p>
<p class="paper-note">수동 기록은 이 기능 적용 후 실행부터 보존됩니다. 장중에는 진입 확정 기능이 없으므로 관심 후보를 잠정 관찰로 표시하고 지정가 계획은 비워 둡니다. 장중→마감 비교는 같은 종목·같은 날의 이후 완료봉 판정을 보여주며, 프로필·전략 동일성이나 동일 주문을 뜻하지 않습니다. 과거 원본 전략 버전은 미확인입니다.</p>
<?php if($error): ?><p role="alert"><?= paper_esc($error) ?></p><?php endif ?>
<?php foreach($r['errors'] as $e): ?><p role="alert">기록 오류: <?= paper_esc($e) ?></p><?php endforeach ?>
<section class="panel"><p>진입 확인·장중 관찰 기록 <?= count($rows) ?>건 · <?= $page ?> / <?= $pages ?>페이지 · 후속 평가 기준 <?= paper_esc(jt($r['followup_as_of']??null)) ?></p>
<?php if(!$rows): ?><p>아직 진입 확인 기록이 없습니다. 수동 스캔 또는 완료봉 스캔 실행 후 확인하세요.</p><?php endif ?>
<?php foreach(array_slice($rows,($page-1)*50,50) as $row): $p=$row['plan'];$o=$row['outcome']??[]; ?>
<details class="paper-trade"><summary><?= paper_esc($row['name'].' · '.jt($row['recorded_at']).' · '.($row['source']==='manual_scan'?'수동 스캔':'완료봉 기록').' · '.jl($o['status']??$row['link'])) ?></summary>
<div class="scan-table-wrap"><table class="scan-table paper-kv"><tbody>
<tr><th>판정 근거</th><td><?= paper_esc($row['reason']) ?></td></tr>
<tr><th>분석 기준 / 신호봉</th><td><?= paper_esc(ScanEntryView::modeLabel($row['mode']).' / '.jt($row['session'])) ?><br><?= paper_esc($row['note']) ?></td></tr>
<?php if(!empty($row['candidate'])&&$row['mode']==='intraday'): $c=$row['candidate']; ?><tr><th>장중 관심 구간 (주문 계획 아님)</th><td><?= paper_esc(jn($c['low']??null).' ~ '.jn($c['high']??null)) ?></td></tr><?php endif ?>
<tr><th>고정 지정가 / 손절 / 목표 / 손익비</th><td><?= paper_esc(jn($p['entry']??null).' / '.jn($p['stop']??null).' / '.jn($p['target']??null).' / '.jn($p['reward_risk']??null)) ?></td></tr>
<tr><th>유효 기간</th><td><?= isset($p['order_valid_bars'])?'신호 다음 '.paper_esc($p['order_valid_bars']).'거래봉':'미기록' ?></td></tr>
<tr><th>추적 연결 / 마지막 평가</th><td><?= paper_esc(jl($row['link']).' / '.jt($row['evaluated_at'])) ?></td></tr>
<tr><th>체결일 / 체결가</th><td><?= paper_esc(jt($o['entry_at']??null).' / '.jn($o['entry_fill']??null)) ?></td></tr>
<tr><th>청산일 / 청산가 / 사유</th><td><?= paper_esc(jt($o['exit_at']??null).' / '.jn($o['exit_fill']??null).' / '.paper_ko($o['first_exit']??'—')) ?></td></tr>
<tr><th>비용 반영 순손익</th><td><?= isset($o['net_return_pct'])?paper_esc(jn($o['net_return_pct'])).'%':'—' ?></td></tr>
<tr><th>체결·취소 해석</th><td><?= ($o['status']??'')==='unfilled'?'유효 기간 내 지정가 미체결':(($o['status']??'')==='cancelled_before_entry'?'기존 모형의 진입 전 가격 조건으로 취소 (세부 취소 사유는 저장되지 않음)':($o?'저장된 기존 모형 결과':'결과 없음 · 체결 여부 미확인')) ?><?= !empty($o['ambiguous_bar'])?' · 같은 봉 내 도달 순서 불명, 기존 모형 우선순위 적용':'' ?></td></tr>
<?php if($row['source']==='manual_scan'&&$row['mode']==='intraday'): $c=$row['close_comparison']; ?><tr><th>당일 마감 관찰</th><td><?= $c?paper_esc(jt($c['session']).' · '.paper_ko($c['status']).' · '.($c['ready']?'마감 판정도 진입 확인':'마감 판정은 진입 미확인')):'연결할 이후 완료봉 판정 없음' ?></td></tr><?php endif ?>
<tr><th>원본 기록</th><td><?= paper_esc($row['symbol'].' / '.$row['source_file']) ?></td></tr>
</tbody></table></div></details>
<?php endforeach ?>
<?php foreach(['이전'=>$page-1,'다음'=>$page+1] as $label=>$n): if($n<1||$n>$pages)continue; ?><a href="?<?= paper_esc(http_build_query(['account'=>$id,'profile'=>$profile,'source'=>$source,'p'=>$n])) ?>"><?= paper_esc($label) ?></a> <?php endforeach ?>
<p class="paper-note">완료봉 후속 갱신: <code>php bin/paper_followup.php --account=<?= paper_esc($id) ?></code>. 기존 예약 실행에서도 갱신합니다. 연결이 없거나 가격 품질 검사를 통과하지 못하면 손익을 추정하지 않습니다.</p></section>
<?php paper_close(); ?>
