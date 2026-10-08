<?php
declare(strict_types=1);
require_once __DIR__.'/Chrome.php';

/** Read existing evidence; do not replay today's rules against historical decisions. */
final class PaperPatternView
{
    public const COMMON=['context_wait','stale_data','blocked','risk_blocked','spike_dump','top_collapse','data_quality_blocked'];
    public const GATES=[
        'rising_structure'=>'상승 추세 구조','atr_valid'=>'가격 변동폭 자료','valid_zone'=>'관심 가격 구간',
        'near_ma20'=>'20일선 부근 눌림','volume_contracted'=>'눌림 거래량 감소','recovery_close'=>'직전봉 고점 회복',
        'bullish_candle'=>'양봉','volume_recovery'=>'거래량 회복','fresh_confirmation'=>'새 확인 신호',
    ];
    public static function describe(array $plan,?array $audits=null):array
    {
        $patterns=$plan['diagnostics']['patterns']??[];$rows=[];$blockers=[];
        $selected=$plan['pattern']??$plan['diagnostics']['selected_pattern']??null;
        if(in_array($plan['status']??'',self::COMMON,true))$blockers[]=$plan['status'];
        foreach($audits??[] as $audit)foreach($audit['exclusion_reasons']??[] as $code)if(in_array($code,self::COMMON,true))$blockers[]=$code;
        foreach(['breakout_retest','trend_pullback'] as $name){
            $raw=$patterns[$name]??null;
            $record=null;foreach($audits??[] as $audit)if(($audit['pattern']??'')===$name){$record=$audit;break;}
            if(!is_array($raw)&&$record){$raw=['status'=>$record['raw_status']??'unknown','reason'=>$record['raw_reason']??'',
                'gates'=>$record['gates']??[]]+($record['original']??[]);}
            $known=is_array($raw);$raw=$known?$raw:[];$gates=[];
            if($name==='trend_pullback')foreach(self::GATES as $key=>$label){
                $v=$raw['gates'][$key]??null;
                $gates[]=['label'=>$label,'state'=>$v===true?'통과':($v===false?'미충족':'미평가·미기록')];
            }
            $version=$raw['version']??($name.'_v1');
            // Missing selection evidence is unknown, never an inferred selection.
            $isSelected=$selected===null?null:($selected===$version||$selected===$name);
            $rows[]=['pattern'=>$name,'known'=>$known,'status'=>$raw['status']??'unknown','reason'=>$raw['reason']??'',
                'selected'=>$isSelected,'rr'=>$raw['reward_risk']??null,'gates'=>$gates];
        }
        return ['rows'=>$rows,'selected'=>$selected,'final'=>$plan['status']??'unknown','reason'=>$plan['reason']??'',
            'blockers'=>array_values(array_unique($blockers)),'complete_blocker_record'=>$audits!==null,'has_plan'=>$plan!==[],'at'=>$plan['data_asof']??null];
    }
}

function paper_pattern_panel(array $evidence,?array $audits=null):void
{
    $plan=$evidence['plan']??[];$v=PaperPatternView::describe($plan,$audits);
    $basis=$evidence['basis']??'unknown';$at=$v['at'];
    $date=is_numeric($at)?(new DateTimeImmutable('@'.(int)$at))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i'):'기준 시각 미기록';
    echo '<article class="info-card pattern-review"><h3>패턴별 판정과 공통 차단</h3>';
    echo '<p class="paper-note">두 패턴을 모두 만족할 필요는 없습니다. 아래는 각각의 원래 판정이며, 선택된 패턴에 공통 차단을 적용한 결과가 최종 판정입니다.</p>';
    echo '<p class="pattern-basis">'.paper_esc($date).' · '.paper_esc(match($basis){
        'completed_reference'=>'마지막 완료봉 참고 · 현재 장중 판정 아님',
        'completed_fallback'=>'장중 자료 실패 · 완료봉 참고',
        'completed'=>'완료 일봉',default=>'분석 기준 미확인',}).'</p>';
    if($basis==='completed_reference')echo '<p class="paper-alert">위 점수는 장중 자료를 포함하지만, 아래 패턴은 마지막 완료봉 기준입니다. 오늘 진입 확인으로 해석하지 마세요.</p>';
    echo '<div class="scan-table-wrap"><table class="scan-table pattern-table"><thead><tr><th>패턴</th><th>패턴 자체 판정</th><th>엔진 선택</th><th>손익비</th><th>필수 조건·근거</th></tr></thead><tbody>';
    foreach($v['rows'] as $r){
        echo '<tr><td>'.paper_esc(paper_ko($r['pattern'])).'</td><td>'.paper_esc($r['known']?paper_ko($r['status']):'당시 판정 기록 없음').'</td><td>'.($r['selected']===null?'미기록':($r['selected']?'선택됨':'선택 안 됨')).'</td><td>'.paper_esc(paper_number($r['rr'])).'</td><td>';
        echo paper_esc($r['reason']);
        if($r['gates']){
            echo '<details class="inline-details"><summary>통과·미충족·미평가 확인</summary><dl class="pattern-gates">';
            foreach($r['gates'] as $g)echo '<div><dt>'.paper_esc($g['label']).'</dt><dd>'.paper_esc($g['state']).'</dd></div>';
            echo '</dl></details>';
        }else echo '<p class="paper-note">세부 조건별 통과 여부는 개별 기록되지 않습니다. 패턴 상태와 근거를 확인하세요.</p>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div><div class="pattern-final"><p><strong>선택 이후 최종 판정</strong> '.paper_esc(paper_ko($v['final'])).'</p><p>'.paper_esc($v['reason']).'</p>';
    echo '<p><strong>'.($v['complete_blocker_record']?'감사 로그에 기록된 공통 차단':'최종 상태에 표시된 공통 차단').'</strong> '.paper_esc($v['blockers']?paper_ko_join($v['blockers']):($v['has_plan']?'표시된 차단 없음':'기록 부족 · 차단 여부 미확인')).'</p></div>';
    echo '<p class="paper-note">공통 차단은 선택된 계획에 대한 기록입니다. 선택되지 않은 패턴도 같은 결과였다고 추정하지 않습니다. —는 손익비 미평가·미기록이며 0이 아닙니다. 점수로 필수 조건을 대신 통과시키지 않습니다.</p></article>';
}

function paper_pattern_audit_panel(array $records):void
{
    echo '<section class="panel"><h2>종목별 패턴 선택·공통 차단</h2><p>당시 저장된 판정을 그대로 구분합니다. 과거 기록을 현재 규칙으로 다시 계산하지 않습니다.</p>';
    foreach($records as $r){
        $p=$r['analysis']['plan']??[];
        echo '<details class="paper-trade"><summary>'.paper_esc($r['name']??$r['symbol']??'종목 미기록').' · '.paper_esc(paper_ko($p['pattern']??'unknown')).' · '.paper_esc(paper_ko($p['status']??$r['reason']??'unknown')).'</summary>';
        paper_pattern_panel(['basis'=>'completed','plan'=>$p],is_array($r['patterns']??null)?$r['patterns']:null);
        echo '</details>';
    }
    if(!$records)echo '<p>조회할 판정 기록이 없습니다.</p>';
    echo '</section>';
}
