<?php
declare(strict_types=1);

/** Offline SVG charts from stored OHLCV. Indicators use the full prefix; the axis uses only the drawn window. */
final class PaperReviewCharts
{
    public static function completedThrough(array $rows, int $asOf): array
    {
        $out=[];
        foreach($rows as $bar){
            $t=(int)($bar['available_at']??0);
            if($t<=0||$t>$asOf||!empty($bar['synthetic'])||($bar['is_complete']??true)===false)continue;
            $bad=false;
            foreach(['open','high','low','close'] as $k){
                if(!isset($bar[$k])||!is_numeric($bar[$k])||!is_finite((float)$bar[$k])||$bar[$k]<=0)$bad=true;
            }
            if($bad)continue;
            $rel=$bar['low']>min($bar['open'],$bar['close'])||$bar['high']<max($bar['open'],$bar['close'])||$bar['high']<$bar['low'];
            $bar['ohlc_invalid']=$rel;$out[$t]=$bar;
        }
        ksort($out,SORT_NUMERIC);
        return array_values($out);
    }

    /** @return array{bars:list<array>,ma20:list<?float>,ma60:list<?float>,last_at:int} */
    public static function view(array $completed, int $asOf, int $bars): array
    {
        $prefix=array_values(array_filter($completed,fn($b)=>(int)$b['available_at']<=$asOf));
        $ma20=self::alignedSma($prefix,20);$ma60=self::alignedSma($prefix,60);
        $slice=array_slice($prefix,-$bars);$from=count($prefix)-count($slice);
        return ['bars'=>$slice,'ma20'=>array_slice($ma20,$from),'ma60'=>array_slice($ma60,$from),
            'last_at'=>$slice? (int)end($slice)['available_at']:0,'prefix_bars'=>count($prefix)];
    }

    /** SMA of valid closes only, placed back on the displayed bars. Invalid candles stay visible and do not enter the average. */
    public static function alignedSma(array $bars, int $n): array
    {
        $closes=[];$idx=[];
        foreach($bars as $i=>$b){if(!empty($b['ohlc_invalid']))continue;$closes[]=(float)$b['close'];$idx[]=$i;}
        $sma=self::sma($closes,$n);$out=array_fill(0,count($bars),null);
        foreach($idx as $j=>$i)$out[$i]=$sma[$j];
        return $out;
    }

    /** @param list<float|int> $values */
    public static function sma(array $values, int $n): array
    {
        $out=[];$sum=0;
        foreach($values as $i=>$v){$sum+=(float)$v;if($i>=$n)$sum-=(float)$values[$i-$n];$out[]=$i+1>=$n?$sum/$n:null;}
        return $out;
    }

    /**
     * Weeks match TrendContext: the as-of week is omitted unless its last daily bar is Friday or later.
     * @return list<array{available_at:int,open:float,high:float,low:float,close:float,volume:float,ohlc_invalid:bool}>
     */
    public static function weekly(array $completed, int $asOf, string $symbol): array
    {
        $tz=new DateTimeZone(str_ends_with($symbol,'.KS')||str_ends_with($symbol,'.KQ')?'Asia/Seoul':'America/New_York');
        $now=(new DateTimeImmutable('@'.$asOf))->setTimezone($tz);
        $weeks=[];
        foreach($completed as $b){
            if((int)$b['available_at']>$asOf)continue;
            $d=(new DateTimeImmutable('@'.$b['available_at']))->setTimezone($tz);
            $weeks[$d->format('o-W')][]=$b;
        }
        if((int)$now->format('N')<=5){
            $key=$now->format('o-W');$last=isset($weeks[$key])?end($weeks[$key]):null;
            if($last!==null){
                $d=(new DateTimeImmutable('@'.$last['available_at']))->setTimezone($tz);
                if((int)$d->format('N')<5)unset($weeks[$key]);
            }
        }
        $out=[];
        foreach($weeks as $rows){
            $valid=array_values(array_filter($rows,fn($b)=>empty($b['ohlc_invalid'])));
            $src=$valid?:$rows;$first=$src[0];$last=end($src);
            $out[]=['available_at'=>(int)$last['available_at'],'open'=>(float)$first['open'],
                'high'=>max(array_column($src,'high')),'low'=>min(array_column($src,'low')),
                'close'=>(float)$last['close'],'volume'=>array_sum(array_column($src,'volume')),
                'ohlc_invalid'=>$valid===[]];
        }
        return $out;
    }

    /** @param list<array{label:string,price:?float,at:?int,kind:string}> $marks */
    public static function svg(array $view, array $marks, string $title, bool $pricesOnlyInView): string
    {
        $bars=$view['bars'];
        if($bars===[]){
            $font='Malgun Gothic, Apple SD Gothic Neo, Noto Sans CJK KR, sans-serif';
            $esc=htmlspecialchars($title,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            return '<svg xmlns="http://www.w3.org/2000/svg" width="960" height="460" viewBox="0 0 960 460"><!-- last_available_at=0 bars=0 -->'
                .'<rect width="960" height="460" fill="#fff"/>'
                .'<text x="64" y="18" font-size="13" font-family="'.$font.'">'.$esc.'</text>'
                .'<text x="64" y="180" font-size="14" font-family="'.$font.'">이 창에 그릴 완료 봉이 없다</text></svg>';
        }
        $n=count($bars);
        $w=960;$h=460;$ml=64;$mr=16;$pt=28;$pv=300;$gap=16;$vh=90;
        $highs=[];$lows=[];
        foreach($bars as $b){if(!empty($b['ohlc_invalid']))continue;$highs[]=(float)$b['high'];$lows[]=(float)$b['low'];}
        foreach($view['ma20'] as $v)if($v!==null){$highs[]=$v;$lows[]=$v;}
        foreach($view['ma60'] as $v)if($v!==null){$highs[]=$v;$lows[]=$v;}
        if(!$pricesOnlyInView)foreach($marks as $m)if($m['price']!==null){$highs[]=(float)$m['price'];$lows[]=(float)$m['price'];}
        if($highs===[]){$highs[]=1;$lows[]=1;}
        $hi=max($highs);$lo=min($lows);if($hi<=$lo){$hi+=1;$lo-=1;}
        $pad=($hi-$lo)*0.04;$hi+=$pad;$lo-=$pad;
        $y=fn(float $p)=>$pt+($hi-$p)/($hi-$lo)*$pv;
        $x=fn(int $i)=>$ml+($i+0.5)*(($w-$ml-$mr)/$n);
        $esc=fn(string $s)=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $font='Malgun Gothic, Apple SD Gothic Neo, Noto Sans CJK KR, sans-serif';
        $lastAt=(int)($view['last_at']??0);
        $svg=['<svg xmlns="http://www.w3.org/2000/svg" width="960" height="460" viewBox="0 0 960 460">',
            '<!-- last_available_at='.$lastAt.' bars='.count($bars).' -->',
            '<rect width="960" height="460" fill="#fff"/>','<text x="64" y="18" font-size="13" font-family="'.$font.'">'.$esc($title).'</text>'];
        $svg[]=sprintf('<text x="8" y="%d" font-size="11" font-family="%s">%s</text>',(int)$y($hi),$font,$esc(self::num($hi)));
        $svg[]=sprintf('<text x="8" y="%d" font-size="11" font-family="%s">%s</text>',(int)$y($lo),$font,$esc(self::num($lo)));
        $volMax=max(1,max(array_map(fn($b)=> (float)($b['volume']??0),$bars)));
        foreach($bars as $i=>$b){
            $cx=$x($i);$up=$b['close']>=$b['open'];$color=$up?'#c0392b':'#2471a3';
            if(!empty($b['ohlc_invalid'])){
                $svg[]=sprintf('<text x="%.1f" y="%d" text-anchor="middle" font-size="12" fill="#7b241c">X</text>',$cx,$pt+$pv-8);
                continue;
            }
            $y1=$y((float)$b['high']);$y2=$y((float)$b['low']);$yo=$y((float)$b['open']);$yc=$y((float)$b['close']);
            $svg[]=sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s"/>',$cx,$y1,$cx,$y2,$color);
            $top=min($yo,$yc);$bh=max(1,abs($yc-$yo));
            $svg[]=sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>',$cx-2.2,$top,4.4,$bh,$color);
            $vhgt=max(1,((float)$b['volume']/$volMax)*$vh);
            $svg[]=sprintf('<rect x="%.1f" y="%.1f" width="4.4" height="%.1f" fill="%s"/>',$cx-2.2,$pt+$pv+$gap+$vh-$vhgt,4.4,$color);
        }
        $svg[]=self::line($view['ma20'],$x,$y,'#e67e22');
        $svg[]=self::line($view['ma60'],$x,$y,'#8e44ad');
        $lastX=$x(count($bars)-1);
        $svg[]=sprintf('<line x1="%.1f" y1="%d" x2="%.1f" y2="%d" stroke="#222" stroke-dasharray="3 3"/>',$lastX,$pt,$lastX,$pt+$pv);
        foreach($marks as $m){
            if(($m['at']??null)===null)continue;
            foreach($bars as $i=>$b){
                if((int)$b['available_at']!==(int)$m['at'])continue;
                $xx=$x($i);
                $svg[]=sprintf('<line x1="%.1f" y1="%d" x2="%.1f" y2="%d" stroke="#7d6608" stroke-dasharray="2 2"/>',$xx,$pt,$xx,$pt+$pv);
                $svg[]=sprintf('<text x="%.1f" y="%d" font-size="10" font-family="%s" fill="#7d6608">%s</text>',$xx+2,$pt+12,$font,$esc($m['label']));
            }
        }
        foreach($marks as $m){
            if($m['price']===null)continue;
            $yy=$y((float)$m['price']);
            if($pricesOnlyInView&&($yy<$pt-1||$yy>$pt+$pv+1))continue;
            $color=match($m['kind']){'entry'=>'#1a1a1a','stop'=>'#6c3483','target'=>'#1a5276',default=>'#b9770e'};
            $svg[]=sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="%s" stroke-dasharray="5 3"/>',$ml,$yy,$w-$mr,$yy,$color);
            $svg[]=sprintf('<text x="%d" y="%.1f" font-size="11" font-family="%s" fill="%s">%s</text>',$ml+4,max(12,$yy-3),$font,$color,$esc($m['label'].' '.self::num((float)$m['price'])));
        }
        $svg[]='<text x="64" y="452" font-size="11" font-family="'.$font.'" fill="#333">빨강 상승 · 파랑 하락 · 주황 MA20 · 보라 MA60(60개 이상이 있을 때) · 점선은 판정일. 거래량은 아래 칸.</text>';
        $svg[]='</svg>';
        return implode('',$svg);
    }

    private static function line(array $values, Closure $x, Closure $y, string $color): string
    {
        $pts=[];
        foreach($values as $i=>$v){if($v===null)continue;$pts[]=sprintf('%.1f,%.1f',$x($i),$y((float)$v));}
        if(count($pts)<2)return '';
        return '<polyline fill="none" stroke="'.$color.'" stroke-width="1.4" points="'.implode(' ',$pts).'"/>';
    }

    public static function num(float $v): string
    {
        return number_format($v,$v>=1000?0:2,'.',',');
    }
}
