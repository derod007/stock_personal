<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';
require_once __DIR__.'/bin/paper/Chrome.php';
require_once __DIR__.'/bin/paper/DatedTop100Trades.php';
function t100h(mixed $v): string {return paper_esc($v);}
function t100won(mixed $v): string {
    if($v===null || $v==='')return '—';
    return number_format((int)round((float)$v),0,'.',',');
}
function t100pct(mixed $v): string {
    if($v===null || $v==='')return '—';
    return number_format((float)$v,1,'.',',');
}
function t100fill(mixed $price,mixed $qty,mixed $amount): string {
    return '<span class="t100-lines"><span>'.t100h(t100won($price)).'원 · '.t100h($qty).'주</span><span>'.t100h(t100won($amount)).'원</span></span>';
}
function t100pnl(?float $v): string {
    if($v===null)return '';
    return $v>0?'pnl-plus':($v<0?'pnl-minus':'');
}
$id=PaperDatedTop100Trades::ACCOUNT;
$error=null;$r=null;
try {$r=PaperDatedTop100Trades::load(__DIR__);}
catch(Throwable $e){$error='저장 재현 결과를 읽지 못했습니다. 파일을 추정하지 않습니다.';}
paper_open(['title'=>'KRX 일별 TOP100 연구 거래','page'=>'top100_trades','account'=>$id,'mode'=>'replay']);
$patterns=['trend_pullback_v1'=>'상승 눌림','trend_recovery_v1'=>'추세 이탈 후 회복','breakout_retest_v1'=>'돌파 후 재지지'];
$reasons=['target'=>'목표','stop'=>'손절','time'=>'기간'];
$periodFrom='2025-10-08';$periodTo='2026-10-08';
if(isset($r['period']) && preg_match('/^(\d{4}-\d{2}-\d{2})~(\d{4}-\d{2}-\d{2})$/',(string)$r['period'],$pm)){
    $periodFrom=$pm[1];$periodTo=$pm[2];
}
$settle=$r?PaperDatedTop100Trades::settle($r['fills'],$periodFrom,$periodTo,'sell_date'):null;
?>
<p class="paper-alert">과거 재현 연구 · 실제 거래 아님. KRX 일별 TOP100이며 운영 다음 실시간 TOP100과 다름.</p>
<p class="paper-lede"><?= t100h($r['label']??'KRX 일별 거래대금 TOP100 연구') ?> · 계좌 <?= t100h($id) ?> · 기간 <?= t100h($r['period']??'2025-10-08~2026-10-08') ?>. 고정 종목군 연구·opportunity 독립 관찰과 섞지 않습니다. 계획 진입가·목표가를 체결가로 쓰지 않습니다.</p>
<p><a href="paper.php?account=paper-kr-recovery-v1&amp;mode=replay">운영 모의 계좌</a> · <a href="paper_trades.php?account=paper-kr-recovery-v1&amp;mode=replay">운영 계좌 거래</a> · <a href="paper_universe.php">고정 종목군 연구</a></p>
<?php if($error): ?>
<section class="panel panel--error"><p><?= t100h($error) ?></p></section>
<?php else: ?>
<section class="panel panel--result">
  <div class="metric-grid">
    <div class="metric metric--accent"><span class="metric__label">체결</span><strong class="metric__value"><?= t100h($r['fill_count']) ?>건</strong></div>
    <div class="metric"><span class="metric__label">청산</span><strong class="metric__value"><?= t100h($r['closed_count']) ?>건</strong></div>
    <div class="metric"><span class="metric__label">보유</span><strong class="metric__value"><?= t100h($r['open_count']) ?>건</strong></div>
    <div class="metric"><span class="metric__label">취소(체결 목록 제외)</span><strong class="metric__value"><?= t100h($r['cancel_count']) ?>건</strong></div>
  </div>
  <p class="paper-note">전체 청산 <?= t100h($r['closed_count']) ?>건의 비용 포함 실현손익 합계 <?= t100h(t100won($r['closed_net_pnl'])) ?>원<?= $r['summary_realized_pnl']!==null?' · 저장 summary 실현손익 '.t100h(t100won($r['summary_realized_pnl'])).'원':'' ?><?= $r['closed_return_pct']!==null?' · 전체 수익률 정산 '.t100h(t100pct($r['closed_return_pct'])).'%':'' ?>. 거래 수익률을 더하거나 평균한 값은 계좌 수익률이 아닙니다. 보유 3건의 미실현손익은 이 합계에 넣지 않습니다. 화면의 원 금액은 반올림한 정수이며 저장 값은 그대로입니다. 아래 조회 기간을 바꾸면 그 구간의 실현손익 합계와 수익률 정산이 다시 계산됩니다.</p>
  <?php if($r['issues']): ?><p class="paper-alert">저장 건수 또는 실현손익 합계를 확인하세요: <?= t100h(implode(', ',$r['issues'])) ?></p><?php endif ?>
</section>
<section class="panel">
  <h2 class="paper-section-title">체결된 거래</h2>
  <p class="paper-note">매수금액 = 체결수량 × 매수 체결가. 체결가에는 기존 모형 슬리피지가 이미 들어 있어 다시 빼지 않습니다. 비용 포함 실현손익은 저장 청산 기록의 순손익입니다(매수·매도 수수료 반영). 거래 수익률(%) = 실현손익 ÷ (매수금액 + 매수수수료) × 100. 보유 중은 매도를 미청산으로 두고, 기간 말 평가일·평가가격·미실현손익을 따로 표시합니다.</p>
  <form class="paper-filter" id="t100-filter" onsubmit="return false">
    <label class="t100-dates">조회 기간 <span><input id="t100-from" type="date" value="<?= t100h($periodFrom) ?>" min="<?= t100h($periodFrom) ?>" max="<?= t100h($periodTo) ?>"> <span>~</span> <input id="t100-to" type="date" value="<?= t100h($periodTo) ?>" min="<?= t100h($periodFrom) ?>" max="<?= t100h($periodTo) ?>"></span></label>
    <label>기간 기준 <select id="t100-date-field"><option value="sell_date">매도 체결일(실현)</option><option value="buy_date">매수 체결일</option></select></label>
    <label>종목 검색 <input id="t100-q" type="search" placeholder="이름 또는 코드"></label>
    <label>패턴 <select id="t100-pattern"><option value="">전체</option><?php foreach($patterns as $k=>$lab): ?><option value="<?= t100h($k) ?>"><?= t100h($lab) ?></option><?php endforeach ?></select></label>
    <label>청산 사유 <select id="t100-reason"><option value="">전체</option><?php foreach($reasons as $k=>$lab): ?><option value="<?= t100h($k) ?>"><?= t100h($lab) ?></option><?php endforeach ?></select></label>
    <label>보유 여부 <select id="t100-status"><option value="">전체</option><option value="closed">청산 완료</option><option value="open">보유 중</option></select></label>
    <button type="button" id="t100-csv">현재 표시 목록 CSV</button>
  </form>
  <?php if($settle): ?>
  <div class="metric-grid t100-settle" id="t100-settle">
    <div class="metric"><span class="metric__label">조회 표시</span><strong class="metric__value" id="t100-settle-shown"><?= t100h($settle['shown']) ?>건</strong></div>
    <div class="metric"><span class="metric__label">조회 청산</span><strong class="metric__value" id="t100-settle-closed"><?= t100h($settle['closed']) ?>건</strong></div>
    <div class="metric"><span class="metric__label">실현손익 합계</span><strong class="metric__value mono <?= t100pnl($settle['net_pnl']) ?>" id="t100-settle-pnl"><?= t100h(t100won($settle['net_pnl'])) ?>원</strong></div>
    <div class="metric"><span class="metric__label">수익률 정산</span><strong class="metric__value mono <?= t100pnl($settle['return_pct']) ?>" id="t100-settle-ret"><?= $settle['return_pct']===null?'—':t100h(t100pct($settle['return_pct'])).'%' ?></strong></div>
  </div>
  <?php endif ?>
  <p id="t100-shown" class="paper-note" role="status">지금 표시 <?= t100h($r['fill_count']) ?>건 · 조회 청산 <?= t100h($settle['closed']??0) ?>건 · 기본 정렬 매수일순</p>
  <p class="paper-note">조회 수익률 정산은 지금 표시된 청산 거래의 실현손익 합 ÷ 그 거래들의 (매수금액+매수수수료) 합 × 100입니다. 거래별 수익률을 평균하지 않았고, 계좌 평가자산 수익률도 아닙니다. 보유 중은 실현 합계에 넣지 않습니다. 기본 기간 기준은 매도 체결일(실현일)입니다.</p>
  <div class="scan-table-wrap"><table class="scan-table" id="t100-table">
    <thead><tr>
      <th>종목</th>
      <th class="t100-col-pattern">패턴</th>
      <th>추천일</th>
      <th><button type="button" class="t100-sort" data-sort="buy_date">매수 체결일</button></th>
      <th>매수 체결가 · 수량 · 금액</th>
      <th>매도 체결일</th>
      <th>매도 체결가 · 수량 · 금액</th>
      <th class="t100-col-pnl"><button type="button" class="t100-sort" data-sort="net_pnl">실현손익</button></th>
      <th><button type="button" class="t100-sort" data-sort="net_return_pct">수익률</button></th>
      <th>청산</th>
      <th>상태</th>
      <th class="t100-col-mark" title="기간 말 평가(보유)">보유 평가</th>
    </tr></thead>
    <tbody>
<?php foreach($r['fills'] as $t):
    $pat=$patterns[$t['pattern']]??paper_ko($t['pattern']);
    $reason=$t['exit_reason']===null?'—':($reasons[$t['exit_reason']]??paper_ko($t['exit_reason']));
    $statusLab=$t['status']==='open'?'보유 중':'청산 완료';
?>
      <tr data-name="<?= t100h($t['name']) ?>" data-symbol="<?= t100h($t['symbol']) ?>" data-code="<?= t100h($t['code']) ?>" data-pattern="<?= t100h($t['pattern']) ?>" data-reason="<?= t100h($t['exit_reason']??'') ?>" data-status="<?= t100h($t['status']) ?>" data-buy_date="<?= t100h($t['buy_date']) ?>" data-sell_date="<?= t100h($t['sell_date']??'') ?>" data-net_pnl="<?= t100h($t['net_pnl']??'') ?>" data-entry_cost="<?= t100h($t['entry_cost']??'') ?>" data-net_return_pct="<?= t100h($t['net_return_pct']??'') ?>" data-buy-price="<?= t100h(t100won($t['buy_price'])) ?>" data-buy-qty="<?= t100h($t['quantity']) ?>" data-buy-amount="<?= t100h(t100won($t['buy_amount'])) ?>" data-sell-price="<?= t100h($t['status']==='open'?'':t100won($t['sell_price'])) ?>" data-sell-qty="<?= t100h($t['status']==='open'?'':$t['sell_quantity']) ?>" data-sell-amount="<?= t100h($t['status']==='open'?'':t100won($t['sell_amount'])) ?>" data-mark="<?= t100h($t['status']==='open'?('평가일 '.($t['mark_date']??'—').' · '.t100won($t['mark_price']).'원 · 미실현 '.t100won($t['unrealized_pnl']).'원'):'') ?>">
        <td><?= t100h($t['name']) ?><span class="scan-code"><?= t100h($t['symbol']) ?></span></td>
        <td class="t100-col-pattern"><?= t100h($pat) ?></td>
        <td class="mono"><?= t100h($t['signal_date']) ?></td>
        <td class="mono"><?= t100h($t['buy_date']) ?></td>
        <td class="mono"><?= t100fill($t['buy_price'],$t['quantity'],$t['buy_amount']) ?></td>
        <td class="mono"><?= $t['status']==='open'?'미청산':t100h($t['sell_date']) ?></td>
        <td class="mono"><?php if($t['status']==='open'): ?>미청산<?php else: ?><?= t100fill($t['sell_price'],$t['sell_quantity'],$t['sell_amount']) ?><?php endif ?></td>
        <td class="mono t100-col-pnl <?= t100pnl($t['net_pnl']) ?>"><?= $t['status']==='open'?'—':t100h(t100won($t['net_pnl'])).'원' ?></td>
        <td class="mono <?= t100pnl($t['net_return_pct']) ?>"><?= $t['status']==='open'?'—':t100h(t100pct($t['net_return_pct'])).'%' ?></td>
        <td><?= $t['status']==='open'?'미청산':t100h($reason) ?></td>
        <td><?= t100h($statusLab) ?></td>
        <td class="mono t100-col-mark"><?php if($t['status']==='open'): ?><span class="t100-lines"><span><?= t100h($t['mark_date']??'—') ?></span><span><?= t100h(t100won($t['mark_price'])) ?>원</span><span>미실현 <?= t100h(t100won($t['unrealized_pnl'])) ?>원</span></span><?php else: ?>—<?php endif ?></td>
      </tr>
<?php endforeach ?>
    </tbody>
  </table></div>
</section>
<section class="panel">
  <h2 class="paper-section-title">취소 <?= t100h($r['cancel_count']) ?>건</h2>
  <p class="paper-note">체결되지 않은 주문입니다. 위 체결 목록·실현손익에 넣지 않습니다.</p>
  <div class="scan-table-wrap"><table class="scan-table" id="t100-cancel">
    <thead><tr><th>종목</th><th>패턴</th><th>추천일</th><th>취소일</th><th>사유</th></tr></thead>
    <tbody>
<?php foreach($r['cancelled'] as $c): ?>
      <tr>
        <td><?= t100h($c['name']) ?><span class="scan-code"><?= t100h($c['symbol']) ?></span></td>
        <td><?= t100h($patterns[$c['pattern']]??paper_ko($c['pattern'])) ?></td>
        <td class="mono"><?= t100h($c['signal_date']) ?></td>
        <td class="mono"><?= t100h($c['cancel_date']) ?></td>
        <td><?= t100h(paper_ko($c['cancel_reason'])) ?></td>
      </tr>
<?php endforeach ?>
    </tbody>
  </table></div>
</section>
<script>
(function(){
  const table=document.getElementById('t100-table');
  if(!table)return;
  const body=table.tBodies[0];
  const rows=[].slice.call(body.rows);
  const q=document.getElementById('t100-q');
  const pattern=document.getElementById('t100-pattern');
  const reason=document.getElementById('t100-reason');
  const status=document.getElementById('t100-status');
  const fromEl=document.getElementById('t100-from');
  const toEl=document.getElementById('t100-to');
  const dateField=document.getElementById('t100-date-field');
  const shown=document.getElementById('t100-shown');
  const settleShown=document.getElementById('t100-settle-shown');
  const settleClosed=document.getElementById('t100-settle-closed');
  const settlePnl=document.getElementById('t100-settle-pnl');
  const settleRet=document.getElementById('t100-settle-ret');
  let sortKey='buy_date', sortDir=1;
  function visible(){return rows.filter(function(r){return r.style.display!=='none';});}
  function won(v){return Math.round(v).toLocaleString('en-US');}
  function pct(v){return (Math.round(v*10)/10).toLocaleString('en-US',{minimumFractionDigits:1,maximumFractionDigits:1});}
  function paint(el,v){
    if(!el)return;
    el.classList.remove('pnl-plus','pnl-minus');
    if(v>0)el.classList.add('pnl-plus');
    else if(v<0)el.classList.add('pnl-minus');
  }
  function inDate(r){
    const from=fromEl.value||'';
    const to=toEl.value||'';
    const field=dateField.value||'sell_date';
    if(!from&&!to)return true;
    if(field==='sell_date' && r.dataset.status==='open')return true;
    const d=r.dataset[field]||'';
    if(!d)return false;
    if(from && d<from)return false;
    if(to && d>to)return false;
    return true;
  }
  function apply(){
    const needle=(q.value||'').trim().toLowerCase();
    rows.forEach(function(r){
      const hay=(r.dataset.name+' '+r.dataset.symbol+' '+r.dataset.code).toLowerCase();
      const okQ=!needle||hay.indexOf(needle)!==-1;
      const okP=!pattern.value||r.dataset.pattern===pattern.value;
      const okR=!reason.value||r.dataset.reason===reason.value;
      const okS=!status.value||r.dataset.status===status.value;
      r.style.display=okQ&&okP&&okR&&okS&&inDate(r)?'':'none';
    });
    let closed=0,pnl=0,cost=0;
    const vis=visible();
    vis.forEach(function(r){
      if(r.dataset.status!=='closed')return;
      closed++;
      pnl+=Number(r.dataset.net_pnl||0);
      cost+=Number(r.dataset.entry_cost||0);
    });
    const ret=cost>0?100*pnl/cost:null;
    shown.textContent='지금 표시 '+vis.length+'건 · 조회 청산 '+closed+'건 · 정렬 '+({buy_date:'매수일',net_pnl:'손익',net_return_pct:'수익률'}[sortKey]||sortKey)+(sortDir>0?'순':' 역순');
    if(settleShown)settleShown.textContent=vis.length+'건';
    if(settleClosed)settleClosed.textContent=closed+'건';
    if(settlePnl){settlePnl.textContent=won(pnl)+'원';paint(settlePnl,pnl);}
    if(settleRet){
      settleRet.textContent=ret===null?'—':pct(ret)+'%';
      paint(settleRet,ret===null?0:ret);
    }
  }
  function cmp(a,b){
    const va=a.dataset[sortKey], vb=b.dataset[sortKey];
    if(sortKey==='buy_date'){return sortDir*((va||'').localeCompare(vb||'')||(a.dataset.symbol||'').localeCompare(b.dataset.symbol||''));}
    const na=va===''?null:Number(va), nb=vb===''?null:Number(vb);
    if(na===null&&nb===null)return 0;
    if(na===null)return 1;
    if(nb===null)return -1;
    return sortDir*(na-nb);
  }
  function sortRows(){rows.sort(cmp).forEach(function(r){body.appendChild(r);});apply();}
  [q,pattern,reason,status,fromEl,toEl,dateField].forEach(function(el){el.addEventListener('input',apply);el.addEventListener('change',apply);});
  table.querySelectorAll('.t100-sort').forEach(function(btn){
    btn.addEventListener('click',function(){
      const key=btn.getAttribute('data-sort');
      if(sortKey===key)sortDir=-sortDir; else {sortKey=key;sortDir=key==='buy_date'?1:-1;}
      sortRows();
    });
  });
  document.getElementById('t100-csv').addEventListener('click',function(){
    const cols=['종목명','코드','패턴','추천일','매수일','매수체결가','수량','매수금액','매도일','매도체결가','매도수량','매도금액','실현손익','거래수익률','청산사유','상태','평가일','평가가격','미실현손익'];
    const lines=[cols.join(',')];
    visible().forEach(function(r){
      const c=r.cells;
      const name=r.dataset.name, code=r.dataset.symbol, open=r.dataset.status==='open';
      const row=[name,code,c[1].innerText,c[2].innerText,c[3].innerText,r.dataset.buyPrice||'',r.dataset.buyQty||'',r.dataset.buyAmount||'',open?'미청산':c[5].innerText,open?'미청산':(r.dataset.sellPrice||''),open?'':(r.dataset.sellQty||''),open?'':(r.dataset.sellAmount||''),open?'':c[7].innerText,open?'':c[8].innerText,c[9].innerText,c[10].innerText,open?(r.dataset.mark||''):'','',''];
      lines.push(row.map(function(v){return '"'+String(v).replace(/"/g,'""')+'"';}).join(','));
    });
    const blob=new Blob(['\ufeff'+lines.join('\n')],{type:'text/csv;charset=utf-8'});
    const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='krx-ilbyeol-top100-trades.csv';a.click();
    URL.revokeObjectURL(a.href);
  });
  apply();
})();
</script>
<?php endif;
paper_close();
