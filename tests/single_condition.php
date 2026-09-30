<?php
declare(strict_types=1);
require_once __DIR__.'/../bin/paper/RejectionReviewPanel.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function fixture():array{
    $p=['pattern'=>'trend_pullback','raw_status'=>'ready','original'=>['reward_risk'=>1.8],
        'gates'=>array_fill_keys(array_keys(PaperSingleConditionReview::GATES),true),
        'missing_conditions'=>[],'not_evaluated'=>[],'exclusion_reasons'=>[]];
    return ['symbol'=>'A','name'=>'A','session'=>100,'status'=>'evaluated','observation_hash'=>'hash',
        'quality'=>['can_simulate'=>true],'patterns'=>[$p],
        'analysis'=>['strategy_version'=>'test-v1','decision'=>['action'=>'wait'],
            'features'=>['spike_dump_status'=>'none','top_pattern_status'=>'none'],
            'plan'=>['ready'=>true,'status'=>'ready','asof'=>110,'data_asof'=>100,
                'context_applied'=>true,'context'=>['daily'=>'up','weekly'=>'up'],'reward_risk'=>1.8]]];
}
function assess(array $r):array{return PaperSingleConditionReview::assess($r,$r['patterns'][0]);}
$r=fixture();
check(assess($r)['status']==='ready_reference','fully evidenced ready reference');
$r['patterns'][0]['raw_status']='rejected_rr';$r['analysis']['plan']['ready']=false;$r['analysis']['plan']['status']='rejected_rr';
check(assess($r)['status']==='single_verified'&&assess($r)['condition']==='rr','confirmed RR only');
$rr=$r;
$r['patterns'][0]['exclusion_reasons']=['context_wait'];
check(assess($r)['status']==='multiple_or_unresolved','RR plus context is not sole failure');
$r=fixture();$r['analysis']['plan']['ready']=false;$r['analysis']['plan']['status']='await_confirmation';
$r['patterns'][0]['raw_status']='await_confirmation';
$r['patterns'][0]['gates']['volume_contracted']=false;
$r['patterns'][0]['missing_conditions']=['volume_contracted'=>'거래량'];
check(assess($r)['status']==='single_unverified'&&in_array('rr',assess($r)['unknown'],true),'volume only with untested RR remains unverified');
$volume=$r;
unset($r['patterns'][0]['gates']['bullish_candle']);
check(in_array('bullish_candle',assess($r)['unknown'],true),'absent gate not pass');
$r=$volume;$r['patterns'][0]['gates']['bullish_candle']=null;
check(in_array('bullish_candle',assess($r)['unknown'],true),'null gate not pass');
$r=$volume;$r['patterns'][0]['gates']['volume_recovery']=false;
check(assess($r)['status']==='multiple_or_unresolved','two volume conditions not counted as one');
$r=fixture();$r['analysis']['plan']['ready']=false;$r['analysis']['plan']['status']='risk_blocked';
$r['analysis']['features']['spike_dump_status']='warning';$r['patterns'][0]['exclusion_reasons']=['spike_dump','risk_blocked'];
check(assess($r)['status']==='single_verified'&&assess($r)['failed']===['spike_dump'],'risk umbrella not double counted');
$r['analysis']['features']['top_pattern_status']='confirmed';$r['analysis']['features']['top_pattern_phase']='failed';
check(assess($r)['status']==='multiple_or_unresolved','two risk types remain two conditions');
$r=$rr;unset($r['analysis']['features']['top_pattern_status']);
check(assess($r)['status']==='single_unverified','missing safety evidence prevents verification');
$r=$rr;unset($r['quality']);
check(in_array('data_quality',assess($r)['unknown'],true),'missing quality is unknown');
$r=$rr;$r['quality']['can_simulate']=false;
check(assess($r)['status']==='multiple_or_unresolved','quality failure not ignored');
$r=$rr;$r['analysis']['plan']['asof']=100+145*3600;
check(assess($r)['status']==='multiple_or_unresolved','stale evidence not eligible');
$r=$rr;unset($r['analysis']['plan']['context_applied']);
check(in_array('context_wait',assess($r)['unknown'],true),'missing context configuration is unknown');
$r=fixture();$r['analysis']['plan']['ready']=false;$r['analysis']['plan']['status']='context_wait';
$r['analysis']['plan']['context']['daily']='down';
check(assess($r)['status']==='single_verified'&&assess($r)['condition']==='context_wait','ready pattern blocked only by context');
$r=$rr;$r['patterns'][0]['pattern']='breakout_retest';$r['patterns'][0]['gates']=[];
$r['patterns'][0]['not_evaluated']=['detailed_gates'=>'not provided'];
check(assess($r)['status']==='single_verified','confirmed retest status supports RR-only classification');
$r['patterns'][0]['raw_status']='await_retest';
check(assess($r)['status']==='multiple_or_unresolved','unconfirmed retest never treated as passed');

$b=['source_file'=>'a.json','membership'=>'observed_scan_only','recorded_at'=>strtotime('2026-09-30 12:00 UTC'),'records'=>[$rr]];
$f=['symbol'=>'A','session'=>100,'source_file'=>'a.json','observation_hash'=>'hash','status'=>'pending','rejected'=>true,'reasons'=>['rr'=>'손익비'],
    'horizons'=>[1=>['status'=>'complete','return_pct'=>2.0,'max_up_pct'=>4.0,'max_down_pct'=>-1.0],5=>['status'=>'pending']],
    'trades'=>[]];
$report=PaperRejectionReview::build([$b],['rows'=>[$f]]);
$g=$report['single_condition_review']['groups'][0];
check($g['horizons'][1]['n']===1&&$g['horizons'][1]['mean_return_pct']===2.0&&$g['horizons'][5]['n']===0,'mature horizons only and null missing mean');
check($g['horizons'][5]['mean_return_pct']===null,'no sample is null not zero');
$f['observation_hash']='wrong';$report=PaperRejectionReview::build([$b],['rows'=>[$f]]);
check($report['single_condition_review']['groups'][0]['linked']===0,'exact identity required');
$f['observation_hash']='hash';$f['status']='future_quality_blocked';
$report=PaperRejectionReview::build([$b],['rows'=>[$f]]);
check($report['single_condition_review']['groups'][0]['horizons'][1]['n']===0,'blocked rows never contribute even with stale horizon payload');
$f['status']='pending';
$b2=$b;$b2['source_file']='b.json';$b2['recorded_at']+=86400;
$report=PaperRejectionReview::build([$b,$b2],['rows'=>[$f]]);
check($report['single_condition_review']['duplicates']===1&&$report['single_condition_review']['groups'][0]['signals']===1,'same candle across recorded days counted once');
$b2['records'][0]['symbol']='B';$b2['records'][0]['analysis']['strategy_version']='test-v2';
$report=PaperRejectionReview::build([$b,$b2],['rows'=>[$f]]);
check(count($report['single_condition_review']['groups'])===2,'versions kept separate');
unset($b2['records'][0]['analysis']['strategy_version']);
$report=PaperRejectionReview::build([$b,$b2],['rows'=>[$f]]);
check($report['single_condition_review']['groups'][1]['version_known']===false,'unknown version explicitly marked');
$report['single_condition_review']['groups'][0]['rows'][0]['name']='<script>bad</script>';
ob_start();paper_single_condition_panel($report['single_condition_review']);$html=ob_get_clean();
check(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'escape row names');
check(str_contains($html,'--saved-evidence')&&str_contains($html,'5個')===false&&str_contains($html,'5개 기록일과 5봉 완료는 다릅니다'),'explain collection versus recomputation');
