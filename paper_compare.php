<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';require __DIR__.'/bin/paper/Experiment.php';
require_once __DIR__.'/bin/paper/Chrome.php';
use ChartEntryLab\PaperJournal;
function eh(mixed $x):string{return paper_esc($x);}
$id=$_GET['experiment']??'us-identity';$mode=$_GET['mode']??'forward';
if(!is_string($id)||!preg_match('/^[a-z0-9_-]{1,64}$/',$id)||!in_array($mode,['forward','replay'],true)){http_response_code(400);exit('잘못된 요청');}
$dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__).'/stock-personal-paper';$r=null;$error=null;$health='';
try {
 $d=(new PaperJournal($dir.'/experiments/'.$id.'-'.$mode.'.json'))->read();
 if($d && $d['state']){
  $r=PaperExperiment::report($d['state']);$def=$d['state']['definition'];
  $s=(new PaperJournal($dir.'/'.$def['source'].'-'.$mode.'.json'))->read();
  if(!$s || !empty($s['state']['halted']))$health='원본 계좌 없음/중단: 비교 결과는 마지막 기록 기준입니다.';
  elseif(count($s['events'])!==$d['state']['source_cursor'])$health='원본과 비교의 갱신 시점이 다릅니다. 비교 작업 실행 이력을 확인하세요.';
 }
}catch(Throwable $e){$error='비교 기록 읽기/무결성 오류';}
$account=str_starts_with($id,'kr-')?'paper-kr':'paper-us';
paper_open(['title'=>'전략 비교','page'=>'compare','account'=>$account,'mode'=>$mode,'experiment'=>$id]);
?>
<form class="paper-filter"><label>실험 ID <input name="experiment" value="<?= eh($id) ?>"></label><select name="mode"><option value="forward" <?= $mode==='forward'?'selected':'' ?>>앞으로 기록</option><option value="replay" <?= $mode==='replay'?'selected':'' ?>>과거 재현</option></select><button>조회</button></form>
<?php if($error): ?><section class="panel panel--error"><p><?= eh($error) ?></p></section>
<?php elseif(!$r): ?>
<section class="panel panel--idle">
<p>비교 기록이 없습니다. 비교 일일 실행 명령을 실행하면 별도 기준·후보 계좌가 시작됩니다.</p>
<pre class="paper-pre">python bin/paper_compare_daily.py --config=config/paper-us.json --experiment=us-identity</pre>
</section>
<?php else: ?>
<section class="panel panel--result">
<?php if($health): ?><p class="paper-alert"><?= eh($health) ?></p><?php endif ?>
<p class="action"><?= eh($r['decision']) ?></p>
<p class="paper-note">동일 전략 확인: <?= eh($r['identity_check']) ?> · 판단 보류 사유: <?= eh(implode(', ',$r['reasons'])) ?></p>
<p class="paper-note">공통 평가 <?= eh($r['sessions']) ?>세션 · 시작/마지막 평가 (UTC): <?= eh($r['first_session']?gmdate('Y-m-d H:i',$r['first_session']):'—') ?> / <?= eh($r['last_session']?gmdate('Y-m-d H:i',$r['last_session']):'—') ?></p>
<p class="paper-note">변경 조건: <?= eh($r['definition']['candidate']?PaperJournal::encode($r['definition']['candidate']):'없음 · 동일 전략 독립 실행') ?></p>
<div class="scan-table-wrap"><table class="scan-table"><thead><tr><th>항목</th><th>기준</th><th>후보</th></tr></thead><tbody>
<?php foreach(['equity'=>'평가자산','net_pnl'=>'평가손익 포함 순손익','return_pct'=>'계좌 수익률 (%)','realized'=>'실현손익','closed_trades'=>'완료 거래 수','max_drawdown_pct'=>'최대 종가 낙폭 (%)','mean_capital_use_pct'=>'평균 자금 사용률 (%)','cash'=>'현금','reserved_cash'=>'예약금'] as $key=>$label): ?><tr><th><?= eh($label) ?></th><?php foreach(['baseline','candidate'] as $arm): ?><td class="mono"><?= $r['arms'][$arm][$key]===null?'—':eh(number_format($r['arms'][$arm][$key],2)) ?></td><?php endforeach ?></tr><?php endforeach ?></tbody></table></div>
<p class="paper-note">통화 <?= eh($r['arms']['baseline']['currency']) ?>. 자금 사용률은 종가 보유평가액과 미체결 예약금의 합을 평가자산으로 나눈 세션별 단순 평균입니다. 낙폭은 유효 종가 기준이며 장중 낙폭이 아닙니다.</p>
<p class="paper-note">30건 미만은 탐색 표본 기준입니다. 30건 이상이어도 수익성을 입증하지 않으며 자동 전략 교체는 없습니다. 과거 재현과 앞으로 기록한 성과는 별도 파일입니다. 기존 계좌 성과와 시작일이 다른 이 비교 계좌 성과를 직접 비교하지 마세요.</p>
</section>
<?php endif;
paper_close();
