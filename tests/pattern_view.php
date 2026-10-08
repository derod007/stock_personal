<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/PatternPanel.php';
require __DIR__.'/../bin/paper/ScorePanel.php';
require __DIR__.'/../bin/paper/EntryJourney.php';
use ChartEntryLab\PatternEvidence;
function pv(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);echo "PASS $why\n";}
$raw=['version'=>'trend_pullback_v1','ready'=>true,'status'=>'ready','reason'=>'확인됨','reward_risk'=>2,
    'gates'=>['rising_structure'=>true,'volume_contracted'=>false]];
$plan=['pattern'=>'trend_pullback_v1','ready'=>false,'status'=>'context_wait','reason'=>'상위 추세 보류','data_asof'=>1791268200,
    'diagnostics'=>['patterns'=>['trend_pullback'=>$raw,'breakout_retest'=>['version'=>'breakout_retest_v1','status'=>'await_retest','ready'=>false]]]];
$before=json_encode($plan);$v=PaperPatternView::describe($plan);
pv($v['rows'][1]['status']==='ready'&&$v['final']==='context_wait'&&$v['rows'][1]['selected'],'raw confirmation and common rejection kept separate');
pv(!$v['rows'][0]['selected']&&$v['rows'][0]['status']==='await_retest','unselected pattern not overwritten by selected outcome');
$g=array_column($v['rows'][1]['gates'],'state','label');
pv($g['상승 추세 구조']==='통과'&&$g['눌림 거래량 감소']==='미충족'&&$g['양봉']==='미평가·미기록','false and unevaluated are distinct');
pv(json_encode($plan)===$before,'view never mutates plan');
$missing=PaperPatternView::describe([]);pv(!$missing['rows'][0]['known']&&$missing['rows'][0]['selected']===null&&$missing['rows'][1]['rr']===null,'old missing records never become passes or zero RR');
$audit=[['pattern'=>'trend_pullback','raw_status'=>'rejected_rr','exclusion_reasons'=>['context_wait','spike_dump','not_selected_pattern','rr_below_threshold']]];
$hidden=PaperPatternView::describe(['status'=>'risk_blocked'],$audit);
pv($hidden['blockers']===['risk_blocked','context_wait','spike_dump'],'independent saved common blockers preserved without mixing research exclusions');
$live=['analysis_mode'=>'intraday','trade_plan'=>['ready'=>false,'status'=>'intraday_preview','data_asof'=>999], 'completed_trade_plan'=>$plan];
$e=PatternEvidence::capture($live);pv($e['basis']==='completed_reference'&&$e['plan']['data_asof']===$plan['data_asof'],'intraday uses explicitly labelled completed reference');
$bundle=PaperEntryJourney::manualBundle(['rows'=>[['pattern_evidence'=>$e,'order_plan'=>$live['trade_plan']]]]);
pv($bundle['rows'][0]['pattern_evidence']===$e&&$bundle['rows'][0]['order_plan']===$live['trade_plan'],'manual log retains evidence without converting provisional orders');
pv(!array_key_exists('pattern_evidence',PaperEntryJourney::manualBundle(['rows'=>[['score'=>24]]])['rows'][0]),'legacy manual rows remain structurally unchanged');
ob_start();paper_pattern_panel($e);$html=ob_get_clean();pv(str_contains($html,'현재 장중 판정 아님')&&str_contains($html,'마지막 완료봉 기준'),'intraday warning and basis are visible');
$score=['base_score'=>25,'final_score'=>9,'base_max'=>100,'lesson_bonus'=>0,'level_bonus'=>0,'top_pattern_adjustment'=>0,'spike_dump_adjustment'=>-16,'cap_adjustment'=>0,'items'=>[
    ['key'=>'higher_low','label'=>'저점 상승','earned'=>25,'max'=>25,'detail'=>'저점 확인','status'=>'pass'],
    ['key'=>'spike_dump','label'=>'급등 후 급락','earned'=>-16,'max'=>0,'min'=>-16,'detail'=>'<script>unsafe</script>','status'=>'penalty'],
]];
ob_start();paper_score_panel($score,'장중 잠정');$s=ob_get_clean();
pv(str_contains($s,'25 / 25점')&&str_contains($s,'-16점')&&str_contains($s,'9 / 100'),'recorded positive/negative scores and final total displayed');
pv(!str_contains($s,'<script>unsafe')&&str_contains($s,'&lt;script&gt;unsafe'),'score explanations are escaped');
ob_start();paper_score_panel(null,'완료 일봉');$empty=ob_get_clean();pv(str_contains($empty,'없는 항목을 0점으로 추정하지 않습니다'),'missing scores explicitly identified');
echo "PATTERN_VIEW_PASS\n";
