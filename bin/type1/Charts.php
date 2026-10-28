<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;

/** Read-only presentation of the original snapshot and frozen observation path. */
final class Charts
{
    public static function snapshot(string $folder, array $state, string $day): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) || !isset($state['captures'][$day])) {
            throw new \InvalidArgumentException('저장된 판단일이 아닙니다.');
        }
        $path=$folder.'/snapshot-'.$day.'.json';
        if (!is_file($path)) throw new \RuntimeException('당시 스냅샷이 없어 차트를 표시할 수 없습니다.');
        $snapshot=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        $expected=$state['captures'][$day]['snapshot_sha256']??null;
        if (!$expected || !hash_equals($expected,hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR))) || $snapshot['day']!==$day) {
            throw new \RuntimeException('당시 스냅샷 검증에 실패했습니다.');
        }
        return $snapshot;
    }

    public static function detail(array $state, array $snapshot, string $symbol): array
    {
        if (!preg_match('/^[0-9A-Z]{6}\.(KS|KQ)$/D',$symbol)) throw new \InvalidArgumentException('지원하지 않는 종목입니다.');
        $day=$snapshot['day'];$row=null;
        foreach ($state['captures'][$day]['rows'] as $r) if ($r['symbol']===$symbol) {$row=$r;break;}
        if (!$row) throw new \InvalidArgumentException('그날 저장된 종목이 아닙니다.');
        // Never reconstruct the pre-decision chart from a later provider response.
        $pre=Tracker::byDay($snapshot['prices'][$symbol]??[],$symbol,$snapshot['as_of']);
        $pre=array_filter($pre,static fn($d)=>$d<=$day,ARRAY_FILTER_USE_KEY);
        $pre=array_slice($pre,-60,null,true);
        $preError=null;
        if (count($pre)!==60 || array_key_last($pre)!==$day || count(array_filter($pre,[Tracker::class,'valid']))!==60) {
            $preError='당일을 포함한 유효한 60봉이 없습니다. 다른 날짜의 봉으로 대체하지 않았습니다.';$pre=[];
        }
        $trade=$state['trades'][$day.'|'.$symbol]??null;$post=[];
        if ($trade) {
            $i=array_search($day,$state['days'],true);
            foreach (array_slice($state['days'],$i+1,20) as $d) {
                if ($trade['exit'] && $d>$trade['exit']['day']) break;
                $b=$trade['path'][$d]??null;
                $post[$d]=$b && Tracker::valid($b)?$b:null;
            }
        }
        return ['day'=>$day,'symbol'=>$symbol,'row'=>$row,'pre'=>$pre,'pre_error'=>$preError,'post'=>$post,'trade'=>$trade];
    }

    public static function latestQuotes(array $state,array $snapshot): array
    {
        $out=[];
        foreach ($snapshot['prices'] as $symbol=>$bars) {
            try {$by=Tracker::byDay($bars,$symbol,$snapshot['as_of']);$b=$by[$snapshot['day']]??null;
                if ($b && Tracker::valid($b)) $out[$symbol]=['day'=>$snapshot['day'],'close'=>$b['close']];
            } catch (\Throwable $e) { /* unavailable remains absent */ }
        }
        return $out;
    }

    public static function svg(array $bars,string $title,?array $trade=null): string
    {
        $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $valid=array_filter($bars,static fn($b)=>is_array($b)&&Tracker::valid($b));
        if (!$valid) return '<p class="notice">'.$h($title).' · 아직 표시할 유효 봉이 없습니다.</p>';
        $lo=min(array_column($valid,'low'));$hi=max(array_column($valid,'high'));$levels=[];
        if ($trade && $trade['entry']) {
            $levels=['진입'=>$trade['entry']['price'],'손절'=>$trade['entry']['stop'],'목표'=>$trade['entry']['target']];
            $lo=min($lo,min($levels));$hi=max($hi,max($levels));
        }
        $pad=max(($hi-$lo)*.08,$hi*.005);$lo-=$pad;$hi+=$pad;
        $y=static fn($p)=>28+200*($hi-$p)/($hi-$lo);
        $step=700/max(1,count($bars));$vol=max(1,max(array_column($valid,'volume')));
        $num=static fn($v)=>number_format((float)$v,2,'.','');
        $out='<svg class="candles" viewBox="0 0 900 345" role="img" aria-label="'.$h($title).'"><title>'.$h($title).'</title>';
        foreach ([0,.5,1] as $fraction) {
            $price=$hi-($hi-$lo)*$fraction;$yy=$y($price);
            $out.='<line x1="80" x2="780" y1="'.$num($yy).'" y2="'.$num($yy).'" stroke="#33435a"/><text x="74" y="'.$num($yy+4).'" text-anchor="end">'.$h(number_format($price)).'</text>';
        }
        $out.='<text x="80" y="16">가격(원)</text><text x="80" y="253">거래량(주)</text>';
        foreach ($levels as $label=>$p) {$yy=$num($y($p));$out.='<line x1="80" x2="780" y1="'.$yy.'" y2="'.$yy.'" stroke="#d8bc6c" stroke-dasharray="5 4"/><text x="786" y="'.$yy.'">'.$h($label.' '.number_format($p)).'</text>';}
        $i=0;$days=array_keys($bars);
        foreach ($bars as $day=>$b) {
            $x=80+($i+.5)*$step;$width=min(10,$step*.65);
            if (!$b || !Tracker::valid($b)) {
                $out.='<rect x="'.$num($x-$step*.4).'" y="28" width="'.$num($step*.8).'" height="275" fill="#64748b" opacity=".18"><title>'.$h($day.' · 가격 미확정').'</title></rect>';
            } else {
                $color=$b['close']>=$b['open']?'#ff808c':'#76aaff';$top=min($y($b['open']),$y($b['close']));$height=max(1,abs($y($b['open'])-$y($b['close'])));
                $out.='<g><title>'.$h($day.' 시가 '.$b['open'].' 고가 '.$b['high'].' 저가 '.$b['low'].' 종가 '.$b['close'].' 거래량 '.$b['volume']).'</title>';
                $out.='<line x1="'.$num($x).'" x2="'.$num($x).'" y1="'.$num($y($b['high'])).'" y2="'.$num($y($b['low'])).'" stroke="'.$color.'"/>';
                $out.='<rect x="'.$num($x-$width/2).'" y="'.$num($top).'" width="'.$num($width).'" height="'.$num($height).'" fill="'.$color.'"/>';
                $vh=45*$b['volume']/$vol;$out.='<rect x="'.$num($x-$width/2).'" y="'.$num(305-$vh).'" width="'.$num($width).'" height="'.$num(max(.5,$vh)).'" fill="'.$color.'"/></g>';
                foreach (['entry'=>'진입','exit'=>'청산'] as $key=>$label) if (($trade[$key]['day']??null)===$day) {
                    $yy=$num($y($trade[$key]['price']));$out.='<circle cx="'.$num($x).'" cy="'.$yy.'" r="5" fill="none" stroke="#fff" stroke-width="2"><title>'.$h($label.' '.$day.' '.$trade[$key]['price']).'</title></circle><text x="'.$num($x).'" y="'.($key==='entry'?'242':'266').'" text-anchor="middle">'.$label.'</text>';
                }
            }
            if ($i===0 || $i===count($bars)-1 || (count($bars)>10 && $i===(int)floor(count($bars)/2))) {
                $out.='<text x="'.$num($x).'" y="330" text-anchor="middle">'.$h($day).'</text>';
            }
            $i++;
        }
        return $out.'</svg>';
    }

    public static function render(array $d): void
    {
        $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$t=$d['trade'];
        echo '<article class="chart-detail"><h2>'.$h($d['row']['name'].' · '.$d['symbol']).'</h2><p>판단일 <strong>'.$h($d['day']).'</strong> · <a href="index.php?symbol='.$h(rawurlencode($d['symbol'])).'">현재 차트 분석 열기</a></p>';
        echo '<h3>① 판단 당시: 완료된 60일봉과 거래량</h3><p>그날 저장한 스냅샷만 사용합니다. 판단일 뒤의 가격이나 매매 결과는 이 차트에 넣지 않습니다.</p>';
        echo $d['pre_error']?'<p class="notice">'.$h($d['pre_error']).'</p>':self::svg($d['pre'],'판단일까지 60일봉');
        echo '<details class="after"><summary>② 판단 이후 모의 경로 펼치기</summary><p>이 영역은 사후 결과입니다. 위 차트와 가격 축 범위가 다를 수 있습니다. 빈 구간은 유효 봉 미확정이며 보간하지 않습니다.</p>';
        if (!$t) echo '<p>이 판단일에는 신규 진입 관찰이 생성되지 않았습니다.</p>';
        else {
            $labels=['waiting'=>'다음 시가 대기','open'=>'관찰 중','closed'=>'종료','unknown'=>'가격 미확정'];
            echo '<p>상태: '.$h($labels[$t['status']]).' · '.$h($t['error']??'').'</p>';
            if ($t['net_pct']!==null) echo '<p>비용 반영 모형 손익률 '.number_format($t['net_pct'],2).'%</p>';
            if ($t['same_bar_order_unknown']) echo '<p class="notice">같은 봉 손절·목표 접촉: 장중 순서 미확정, 모형은 손절 우선.</p>';
            echo self::svg($d['post'],'판단 이후 고정 모의 경로',$t);
            echo '<p>모형 기준: 다음 시가 진입, −5% 손절, +15% 목표, T+20 종가 종료. 매수·매도 각각 0.15% 비용. 실제 주문·체결 기록이 아닙니다.</p>';
        }
        echo '</details></article>';
    }
}
