<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';
foreach(['Tracker','Store','Intraday'] as $f)require __DIR__.'/bin/type1/'.$f.'.php';
use ChartEntryLab\Type1Scan\{Store,Intraday};
header('Cache-Control: no-store');
$rows=[];$day=null;$error=null;
try{$state=Store::load(Store::folder());$day=$state['last_day'];$rows=Intraday::candidates($state);}
catch(Throwable $e){$error='마감 후보 기록을 읽지 못했습니다.';}
if(isset($_GET['symbol'])){
    header('Content-Type: application/json; charset=utf-8');
    try{
        $symbol=$_GET['symbol'];
        if(!is_string($symbol)||!preg_match('/^[0-9A-Z]{6}\.(KS|KQ)$/D',$symbol)||!in_array($symbol,array_column($rows,'symbol'),true))throw new RuntimeException('저장된 최신 신규 후보가 아닙니다.');
        if(!is_string($_GET['day']??null)||$_GET['day']!==$day)throw new RuntimeException('마감 후보가 갱신됐습니다. 페이지를 새로 여세요.');
        $now=time();$tz=new DateTimeZone('Asia/Seoul');$today=(new DateTimeImmutable('@'.$now))->setTimezone($tz)->format('Y-m-d');
        if($day>=$today)throw new RuntimeException('이 후보는 다음 거래일부터 관찰합니다.');
        $client=new ChartEntryLab\YahooChartClient(__DIR__.'/data/type1-cache/intraday');
        // Calendar identity must agree in both indices; never infer next session from weekdays.
        foreach(['^KS11','^KQ11'] as $index){
            $dates=[];
            foreach($client->fetch($index,range:'1mo',interval:'1d',maxAgeSeconds:60) as $b){
                $d=(new DateTimeImmutable('@'.$b['time']))->setTimezone($tz)->format('Y-m-d');
                if($b['time']<=$now && $d>$day)$dates[$d]=true;
            }
            ksort($dates);
            if(array_key_first($dates)!==$today)throw new RuntimeException('후보 다음 거래일이 오늘인지 지수 자료로 확인하지 못했습니다. 지난 후보를 뒤늦게 진입 대상으로 표시하지 않습니다.');
        }
        $result=Intraday::evaluate($client->fetch($symbol,range:'5d',interval:'5m',maxAgeSeconds:60),$now);
        $result['candidate_day']=$day;$result['symbol']=$symbol;$result['checked_at']=date(DATE_ATOM,$now);
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }catch(Throwable $e){echo json_encode(['status'=>'판단 불가','reason'=>$e instanceof RuntimeException?$e->getMessage():'장중 자료 조회 실패'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}
    exit;
}
$h=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?><!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>유형1 장중 관찰</title>
<style>body{font:16px/1.6 system-ui,sans-serif;background:#0b1220;color:#e7edf8;max-width:1300px;margin:30px auto;padding:0 20px}a{color:#95bfff}button{padding:10px;background:#244473;color:white;border:1px solid #8297b8;border-radius:6px;cursor:pointer}button:disabled{opacity:.5}table{border-collapse:collapse;width:100%;white-space:nowrap}td,th{padding:12px;text-align:left;border-bottom:1px solid #35435a}.scroll{overflow:auto}small{display:block;color:#a6b5cb}.reason{white-space:normal;min-width:220px}.notice{padding:16px;background:#192b45}#progress{min-height:2em}</style></head><body>
<nav><a href="type1_scan.php">유형1 스캔</a> · <a href="type1_tracking.php">마감 고정 후보</a></nav>
<h1>유형1 장중 관찰</h1>
<p class="notice">마감 신규 후보에서 첫 30분을 지켜본 뒤 조정과 반등을 구분합니다. 자동 주문·매수 추천이 아닙니다. 기존 독립 모의 경로와 별도이며, 조회 결과는 장부에 저장하지 않습니다.</p>
<p>마감 후보 기준일: <strong><?= $h($day??'기록 없음') ?></strong> · 신규 <?=count($rows)?>종목 · 대상은 후보 다음 거래일만</p>
<p>완료된 5분봉 · 09:00~09:30 관찰 · 조정 후 직전 고점 위 종가로 반등 확인. 종목별 확인 시각은 다를 수 있습니다. 가격 제공 지연으로 실제 가격과 다를 수 있습니다.</p>
<details><summary>판단 규칙과 가격 읽는 법</summary><p>첫 30분 고점 이후, 이전 봉보다 종가가 낮아지면 조정으로 봅니다. 조정 시작 전 고점을 돌파 기준으로 고정하고, 조정 저점은 반등 확인 전까지 갱신합니다. 이후 완료봉 종가가 돌파 기준을 넘으면 반등 확인입니다. 같은 봉에서 새 조정 저점까지 만들면 확인하지 않습니다.</p><p>반등 전 첫 30분 저점 접촉·이탈, 또는 반등 후 고정 조정 저점 접촉·이탈이면 당일 조건 무효입니다. 당일 재진입은 표시하지 않습니다. 반등 확인 가격으로 실제 체결됐다는 뜻이 아니며 목표가·부분 청산·추적 손절은 이번 화면에 포함하지 않았습니다.</p><p>봉 누락·가격 모순·합성 봉·15분 초과 지연은 판단 불가입니다. 하루 전체가 아닌 조회 시점까지의 자료로 다시 계산하며, 공급자의 과거 봉 수정으로 상태가 달라질 수 있습니다. 지수 거래일 확인 실패도 판단 불가로 남깁니다.</p></details>
<?php if($error): ?><p role="alert"><?= $h($error) ?></p><?php endif ?>
<button id="all" <?=!$rows?'disabled':''?>>전체 후보 조회</button><p id="progress" role="status" aria-live="polite">아직 장중 자료를 조회하지 않았습니다.</p>
<div class="scroll"><table><thead><tr><th>대금 순위 / 종목</th><th>상태 / 사유</th><th>돌파 기준</th><th>무효화 기준</th><th>최근 완료봉 종가</th><th>봉 마감 / 반등 확인 시각(KST)</th><th>조회</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr data-symbol="<?= $h($r['symbol']) ?>"><td><?= $h($r['rank']) ?> · <?= $h($r['name']) ?><small><?= $h($r['symbol']) ?></small></td><td class="reason"><strong data-field="status">미조회</strong><small data-field="reason">버튼을 눌러 확인하세요.</small></td><td data-field="trigger">—</td><td data-field="invalidation">—</td><td data-field="close">—</td><td><span data-field="as_of">—</span><small data-field="confirmed_at">—</small></td><td><button class="one">조회</button></td></tr><?php endforeach ?>
</tbody></table></div>
<script>
const day=<?=json_encode($day,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const rows=[...document.querySelectorAll('tr[data-symbol]')];
const all=document.querySelector('#all'),progress=document.querySelector('#progress');
const clock=v=>v?new Date(v).toLocaleString('ko-KR',{timeZone:'Asia/Seoul'}):'—';
async function refresh(row){
 const button=row.querySelector('button');button.disabled=true;
 row.querySelector('[data-field=status]').textContent='조회 중';
 try{
  const response=await fetch('type1_intraday.php?symbol='+encodeURIComponent(row.dataset.symbol)+'&day='+encodeURIComponent(day),{cache:'no-store',signal:AbortSignal.timeout(120000)});
  if(!response.ok)throw Error('HTTP '+response.status);
  const data=await response.json();
  row.querySelectorAll('[data-field]').forEach(el=>{const k=el.dataset.field,v=data[k];el.textContent=['as_of','confirmed_at'].includes(k)?clock(v):typeof v==='number'?v.toLocaleString('ko-KR',{maximumFractionDigits:2})+'원':v??'—';});
 }catch(e){row.querySelector('[data-field=status]').textContent='판단 불가';row.querySelector('[data-field=reason]').textContent='조회 실패 · 다시 조회하세요.';row.querySelectorAll('[data-field]').forEach(el=>{if(!['status','reason'].includes(el.dataset.field))el.textContent='—';});}
 finally{button.disabled=false;}
}
rows.forEach(row=>row.querySelector('button').addEventListener('click',()=>refresh(row)));
all.addEventListener('click',async()=>{all.disabled=true;let done=0;for(const row of rows){await refresh(row);progress.textContent=(++done)+' / '+rows.length+' 조회 완료';}all.disabled=false;});
</script></body></html>
