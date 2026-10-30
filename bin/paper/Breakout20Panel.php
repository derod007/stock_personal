<?php
declare(strict_types=1);
function paper_breakout20_panel(array $r): void
{
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $num=static fn($v)=>is_numeric($v)?number_format((float)$v,2):'—';
    echo '<section class="panel"><h3>20일 고가 돌파</h3><p><strong>'.$h($r['status']).'</strong> · 기준봉 '.$h($r['day']??'없음').' · '.($r['complete']?'완료봉':'진행 봉·미확정').'</p>';
    echo '<p>직전 20봉 최고가 <b>'.$num($r['level']).'</b> · 해당 봉 종가/진행 가격의 기준선 대비 거리 <b>'.($r['distance_pct']!==null?sprintf('%+.2f%%',$r['distance_pct']):'—').'</b></p><p>'.$h($r['reason']).'</p>';
    if($r['previous_breakout'])echo '<p>이전 종가 돌파: '.$h($r['previous_breakout']['day']).' · 당시 고정 기준선 '.$num($r['previous_breakout']['level']).'</p>';
    echo '<p><small>일봉 기준이며 현재 봉은 기준선 계산에서 제외합니다. 점수·유형1·주문과 독립된 표시입니다. 조회 자료의 마지막 봉 기준이며 실시간 현재가가 아닐 수 있습니다.</small></p>';
    $points=$r['points'];$values=[];
    foreach($points as $p)if($p['valid']){array_push($values,$p['low'],$p['high']);if($p['level']!==null)$values[]=$p['level'];}
    if(count($values)>1){
        $min=min($values);$max=max($values);$range=max($max-$min,$max*.01);$min-=$range*.08;$max+=$range*.08;
        $y=static fn($p)=>20+($max-$p)/($max-$min)*230;$step=740/max(1,count($points));$width=min(12,$step*.6);
        echo '<div style="overflow:auto"><svg viewBox="0 0 860 300" role="img" aria-label="최근 일봉과 직전 20봉 최고가선" style="width:100%;min-width:640px;background:#101c2c">';
        for($i=0;$i<4;$i++){$v=$min+($max-$min)*$i/3;$yy=$y($v);echo '<text x="770" y="'.$yy.'" fill="#cad8ec" font-size="11">'.$num($v).'</text>';}
        $previous=null;
        foreach($points as $i=>$p){$x=20+$step*($i+.5);
            if($p['valid']){$color=$p['close']>=$p['open']?'#fb7185':'#60a5fa';$top=$y(max($p['open'],$p['close']));$height=max(1,abs($y($p['open'])-$y($p['close'])));
                echo '<g><title>'.$h($p['day'].' O '.$p['open'].' H '.$p['high'].' L '.$p['low'].' C '.$p['close']).'</title><line x1="'.$x.'" x2="'.$x.'" y1="'.$y($p['high']).'" y2="'.$y($p['low']).'" stroke="'.$color.'"/><rect x="'.($x-$width/2).'" y="'.$top.'" width="'.$width.'" height="'.$height.'" fill="'.$color.'"/></g>';}
            if($p['level']!==null){$yy=$y($p['level']);if($previous)echo '<line x1="'.$previous[0].'" y1="'.$previous[1].'" x2="'.$x.'" y2="'.$yy.'" stroke="#fbbf24" stroke-width="2"/>';$previous=[$x,$yy];}else $previous=null;
            if($i===0||$i===count($points)-1)echo '<text x="'.$x.'" y="275" text-anchor="'.($i===0?'start':'end').'" fill="#cad8ec" font-size="11">'.$h($p['day']).'</text>';
        }
        echo '</svg></div><p><small>노란선: 각 날짜의 직전 20봉 최고가. 빨간 봉: 상승 · 파란 봉: 하락. 가격 단위는 해당 종목 호가 통화입니다.</small></p>';
    }
    echo '</section>';
}
