<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';
require __DIR__.'/bin/paper/Experiment.php';
require __DIR__.'/bin/paper/EntryGates.php';
require __DIR__.'/bin/paper/Chrome.php';
use ChartEntryLab\PaperJournal;
$id=$_GET['account']??'research-us-v1';$mode=$_GET['mode']??'forward';$origin=$_GET['origin']??($mode==='replay'?'replay':'forward');$exp=$_GET['experiment']??'us-volume95-v1';
foreach([$id,$exp] as $v)if(!is_string($v)||!preg_match('/^[a-z0-9_-]{1,64}$/',$v)){http_response_code(400);exit('잘못된 ID');}
if(!in_array($mode,['forward','replay'],true)||!in_array($origin,['forward','catchup','replay'],true)){http_response_code(400);exit('잘못된 기록 종류');}
$dir=getenv('PAPER_STATE_DIR')?:dirname(__DIR__).'/stock-personal-paper';$r=null;$pair=null;$error=null;$health='';$s=null;
try {
    $s=(new PaperJournal($dir.'/'.$id.'-'.$mode.'.json'))->read();
    if($s)$r=PaperEntryGates::summarize($s['events'],$origin);
    $d=(new PaperJournal($dir.'/entry-experiments/'.$exp.'-'.$mode.'.json'))->read();
    if($d){
        if($d['state']['definition']['source']!==$id){$health='선택한 실험의 원본 계좌가 다릅니다. 원본 진단만 표시합니다.';$d=null;}
    }
    if($d){
        $pair=PaperExperiment::report($d['state']);$arms=[];
        foreach(['baseline','candidate'] as $arm){$ev=[];foreach($d['events'] as $e)if($e['type']==='arm_event' && $e['payload']['arm']===$arm)$ev[]=$e['payload'];$arms[$arm]=PaperEntryGates::summarize($ev,$origin);}
        if(!$s || !empty($s['state']['halted']))$health='원본 없음/중단: 마지막 비교 기록입니다.';
        elseif(count($s['events'])!==$d['state']['source_cursor'])$health='원본과 실험의 갱신 시점이 다릅니다. 실행 로그를 확인하세요.';
    }
}catch(Throwable $e){$error='기록 읽기/무결성 또는 계좌·실험 연결 오류';}
paper_open(['title'=>'진입 조건 진단·완화 실험','page'=>'entry','account'=>$id,'mode'=>$mode]);
?>
<form class="paper-filter"><label>원본 계좌 <input name="account" value="<?= paper_esc($id) ?>"></label><label>실험 <input name="experiment" value="<?= paper_esc($exp) ?>"></label><label>모드 <select name="mode"><?php foreach(['forward','replay'] as $x): ?><option <?= $mode===$x?'selected':'' ?>><?= $x ?></option><?php endforeach ?></select></label><label>관측 종류 <select name="origin"><?php foreach(['forward','catchup','replay'] as $x): ?><option <?= $origin===$x?'selected':'' ?>><?= $x ?></option><?php endforeach ?></select></label><button>조회</button></form>
<p><a href="paper_entry.php?account=research-us-v1&amp;experiment=us-volume95-v1">미국 연구</a> · <a href="paper_entry.php?account=research-kr-v1&amp;experiment=kr-volume95-v1">한국 연구</a></p>
<?php if($error): ?><p><?= paper_esc($error) ?></p><?php else: ?>
<section class="panel"><h2>원본 계좌 탈락 조건</h2>
<?php if(!$r): ?><p>원본 계좌 기록이 없습니다. 일일 실행 후 확인하세요.</p><?php else: ?>
<p>마지막 평가 <?= paper_esc(gmdate('Y-m-d H:i',$s['state']['last_session'])) ?> UTC · 중단 <?= !empty($s['state']['halted'])?'예':'아니오' ?></p>
<p>관측 <?= $r['snapshots'] ?>건 · 진입 확인 <?= $r['ready'] ?>건 · 품질 차단 <?= $r['quality_blocked'] ?>건 · 주문 <?= $r['orders'] ?>건 · 체결 <?= $r['fills'] ?>건</p>
<p>아래는 상승 추세 눌림 패턴의 조건입니다. 미평가는 앞 단계에서 종료되었거나 진단 정보가 없는 기록으로, 탈락으로 세지 않습니다. 여러 조건에서 동시에 탈락할 수 있습니다. ‘단독 탈락’도 최종 추세·손익비·품질 통과를 보장하지 않습니다.</p>
<table><thead><tr><th>조건</th><th>통과</th><th>탈락</th><th>미평가</th><th>단독 탈락</th></tr></thead><tbody>
<?php $labels=['rising_structure'=>'상승 구조','atr_valid'=>'ATR 유효','valid_zone'=>'유효 가격 구간','near_ma20'=>'20일선 눌림','volume_contracted'=>'눌림 거래량 감소','recovery_close'=>'직전 고가 위 종가','bullish_candle'=>'양봉','volume_recovery'=>'거래량 회복','fresh_confirmation'=>'새 확인 신호'];foreach($r['gates'] as $k=>$row): ?><tr><th><?= paper_esc($labels[$k]??$k) ?></th><?php foreach($row as $n): ?><td><?= $n ?></td><?php endforeach ?></tr><?php endforeach ?></tbody></table>
<?php foreach(['statuses'=>'최종 신호 상태','pattern_statuses'=>'패턴별 상태','decisions'=>'주문 차단 사유','cancellations'=>'미체결·취소 사유'] as $key=>$label): ?><h3><?= $label ?></h3><ul><?php foreach($r[$key] as $reason=>$n): ?><li><?= paper_esc($reason) ?>: <?= $n ?></li><?php endforeach ?></ul><?php endforeach ?>
<p>건수는 종목×평가일이며 독립 거래 수가 아닙니다. 체결·취소는 해당 관측 종류의 평가일에 발생한 건수입니다. 동적 스캔에서 애초에 계좌로 넘어오지 않은 종목은 이 집계에 없습니다.</p>
<?php endif ?></section>
<section class="panel"><h2>거래량 85% → 95% 비교 실험</h2>
<p>사전 지정한 가설입니다. 최다 탈락 원인으로 확인됐다는 뜻이 아닙니다. 손절·손익비·상위 추세·품질·위험 한도는 유지합니다.</p>
<?php if(!$pair): ?><p><?= paper_esc($health) ?></p><p>이 계좌에 연결된 비교 기록이 없습니다. 연구용 일일 래퍼를 실행하세요.</p><pre>python bin/paper_entry_daily.py --config=config/paper-research-us-v1.json --experiment=us-volume95-v1</pre>
<?php else: ?>
<p><?= paper_esc($health) ?></p><p><?= paper_esc($pair['decision']) ?> · <?= paper_esc(implode(', ',$pair['reasons'])) ?></p>
<p>공통 <?= $pair['sessions'] ?>세션 · 시작/마지막 <?= paper_esc($pair['first_session']?gmdate('Y-m-d H:i',$pair['first_session']):'—') ?> / <?= paper_esc($pair['last_session']?gmdate('Y-m-d H:i',$pair['last_session']):'—') ?> UTC</p>
<table><thead><tr><th>항목</th><th>기준</th><th>완화 후보</th></tr></thead><tbody>
<?php foreach(['ready'=>'진입 확인','orders'=>'주문','fills'=>'체결'] as $k=>$label): ?><tr><th><?= $label ?> (<?= paper_esc($origin) ?>)</th><?php foreach(['baseline','candidate'] as $arm): ?><td><?= $arms[$arm][$k] ?></td><?php endforeach ?></tr><?php endforeach ?>
<?php foreach(['net_pnl'=>'비용·평가손익 포함 순손익','closed_trades'=>'완료 거래','max_drawdown_pct'=>'최대 종가 낙폭 %','return_pct'=>'계좌 수익률 %'] as $k=>$label): ?><tr><th><?= $label ?> (전체 기간)</th><?php foreach(['baseline','candidate'] as $arm): ?><td><?= paper_esc(round($pair['arms'][$arm][$k],3)) ?></td><?php endforeach ?></tr><?php endforeach ?></tbody></table>
<p>통화 <?= paper_esc($pair['arms']['baseline']['currency']) ?>. 양쪽은 같은 날 빈 계좌로 시작합니다. 기존 원본 계좌 누적 성과와 직접 비교하지 않습니다. 30건은 탐색 기준이며 수익성 입증이나 자동 채택 기준이 아닙니다.</p>
<?php endif ?></section><?php endif;paper_close(); ?>
