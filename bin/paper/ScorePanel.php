<?php
declare(strict_types=1);
require_once __DIR__.'/Chrome.php';
/** Render the exact recorded breakdown in both simple and analysis mode. */
function paper_score_panel(?array $breakdown,string $basis):void
{
    echo '<article class="info-card score-detail-card" id="score-breakdown"><div class="score-detail__head"><h3>차트 구조 점수 상세</h3><strong>'.paper_esc(paper_number($breakdown['final_score']??null)).' / 100</strong></div>';
    echo '<p class="paper-note">'.paper_esc($basis).' · 점수는 차트 특징의 합계이며 승률이나 매수 확정을 뜻하지 않습니다.</p>';
    if(!$breakdown||!is_array($breakdown['items']??null)||!$breakdown['items']){
        echo '<p>이 결과에는 점수 산정 내역이 없습니다. 종목을 다시 조회해 주세요. 없는 항목을 0점으로 추정하지 않습니다.</p></article>';return;
    }
    echo '<div class="score-detail__list">';
    foreach($breakdown['items'] as $item){
        $adjust=array_key_exists('min',$item)||in_array($item['key']??'', ['lesson1','horizontal_support','top_pattern','spike_dump'],true);
        $earned=$item['earned']??null;
        $text=is_numeric($earned)?($adjust?sprintf('%+d점',(int)$earned):paper_number($earned).' / '.paper_number($item['max']??null).'점'):'미기록';
        $state=in_array($item['status']??'',['pass','bonus','partial','fail','penalty','neutral'],true)?$item['status']:'neutral';
        echo '<div class="score-detail__item" data-status="'.paper_esc($state).'"><div class="score-detail__item-head"><strong>'.paper_esc($item['label']??$item['key']??'항목').'</strong><span>'.paper_esc($text).'</span></div><p>'.paper_esc($item['detail']??'산정 근거 미기록').'</p></div>';
    }
    echo '</div><p class="score-detail__formula">';
    foreach(['base_score'=>'기본 점수','lesson_bonus'=>'불법과외 가감','level_bonus'=>'가로 지지 가감','top_pattern_adjustment'=>'고점·저점 가감','spike_dump_adjustment'=>'급등 후 급락 가감','cap_adjustment'=>'0~100 범위 조정'] as $key=>$label){
        echo '<span>'.paper_esc($label).' '.paper_esc(paper_number($breakdown[$key]??null)).'</span> ';
    }
    echo '</p></article>';
}
