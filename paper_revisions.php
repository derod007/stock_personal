<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';require __DIR__.'/bin/paper/Revision.php';
use ChartEntryLab\PaperJournal;
function rh(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function rv(mixed $v):string{return PaperJournal::encode($v);}
$id=$_GET['account']??'paper-us';$mode=$_GET['mode']??'forward';
if(!is_string($id)||!preg_match('/^[a-z0-9_-]{1,64}$/',$id)||!in_array($mode,['forward','replay'],true)){http_response_code(400);exit('잘못된 요청');}
$dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__).'/stock-personal-paper';$report=null;$event=null;$error=null;
try {
 $d=(new PaperJournal($dir.'/'.$id.'-'.$mode.'.json'))->read();
 foreach($d['events']??[] as $e)if($e['type']==='data_revision')$event=$e;
 if(isset($event['payload']['report_hash']))$report=PaperRevision::read($dir,$event['payload']['report_hash']);
}catch(Throwable $e){$error='계좌 또는 변경 보고서를 읽을 수 없거나 무결성 검증에 실패했습니다.';}
if(($_GET['format']??'')==='json'){
 header('Content-Type: application/json; charset=utf-8');
 if($error){http_response_code(409);echo rv(['error'=>$error]);}else echo rv(['event'=>$event,'report'=>$report]);exit;
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>과거 데이터 변경 진단</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="app">
<h1>과거 데이터 변경 진단</h1><p><a href="paper_diagnostics.php?account=<?= rh($id) ?>&amp;mode=<?= rh($mode) ?>">실행·추천 진단</a></p>
<form><label>계좌 ID <input name="account" value="<?= rh($id) ?>"></label><select name="mode"><option value="forward" <?= $mode==='forward'?'selected':'' ?>>앞으로 기록</option><option value="replay" <?= $mode==='replay'?'selected':'' ?>>과거 재현</option></select><button>조회</button></form>
<?php if($error): ?><p><?= rh($error) ?></p><?php elseif(!$event): ?><p>이 계좌에는 과거 데이터 변경 중단 기록이 없습니다. 다른 ID나 백업 파일의 기록은 자동으로 합치지 않습니다.</p><?php elseif(!$report): ?><p>상세 진단 도입 이전 기록입니다. 당시 변경 전후 값이 기록되지 않아 원인을 복원할 수 없습니다. 백업과 입력 원본을 보존하세요.</p><pre><?= rh(rv($event['payload'])) ?></pre><?php else: ?>
<p>발견 시각 (UTC): <?= rh(gmdate('Y-m-d H:i:s',$report['detected_at'])) ?> · 변경 봉 <?= rh($report['change_count']) ?>개 · <?= rh($report['classification']) ?></p>
<p><strong>계좌 중단과 기존 기록 보존은 유지됩니다.</strong> 작은 변경·숫자 표현 차이도 자동 승인하지 않습니다. 공급자 정정/기업행동/수집 오류 중 실제 원인은 아직 확정되지 않았습니다.</p>
<p><a href="?account=<?= rh($id) ?>&amp;mode=<?= rh($mode) ?>&amp;format=json">전체 변경 보고서 JSON</a></p>
<h2>변경 필드 (최대 200개 봉 표시)</h2><table><thead><tr><th>종목 / 세션 UTC</th><th>유형 / 필드</th><th>이전 값</th><th>새 값</th><th>차이 / 변화율</th></tr></thead><tbody>
<?php foreach(array_slice($report['changes'],0,200) as $row): foreach($row['fields']?:[['field'=>'키 순서','old'=>null,'new'=>null,'old_present'=>false,'new_present'=>false,'delta'=>null,'change_pct'=>null,'representation_only'=>false]] as $f): ?>
<tr><td><?= rh($row['symbol'].' / '.gmdate('Y-m-d H:i',$row['session'])) ?></td><td><?= rh($row['kind'].' / '.$f['field'].($f['representation_only']?' (숫자 값 동일·표현 변경)':'')) ?></td><td><?= rh($f['old_present']?rv($f['old']):'필드 없음') ?></td><td><?= rh($f['new_present']?rv($f['new']):'필드 없음') ?></td><td><?= rh(rv($f['delta']).' / '.($f['change_pct']===null?'—':round($f['change_pct'],6).'%')) ?></td></tr>
<?php endforeach; endforeach ?></tbody></table>
<h2>잠재적 영향 범위</h2><p>변경 봉 이후 같은 종목의 입력을 사용한 추천 <?= rh(count($report['potentially_affected_snapshots'])) ?>건, 관련 주문·체결·청산 이벤트 <?= rh(count($report['related_trade_events'])) ?>건. 실제 추천이나 손익이 달라진다는 뜻은 아니며 재계산하지 않았습니다.</p>
<details><summary>추천 키와 거래 이벤트</summary><pre><?= rh(rv(['snapshots'=>$report['potentially_affected_snapshots'],'trade_events'=>$report['related_trade_events'],'active'=>$report['active_orders_or_positions']])) ?></pre></details>
<h2>입력 출처</h2><p>이전 출처는 마지막으로 보존한 추천의 출처 정보입니다. 변경 봉을 최초 수집한 출처라고 단정하지 않습니다.</p><pre><?= rh(rv($report['sources'])) ?></pre>
<?php endif ?></main></body></html>
