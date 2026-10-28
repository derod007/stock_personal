<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;
use ChartEntryLab\CandleClock;
/** Independent observations only. No cash, sizing, accounts or orders. */
final class Tracker
{
    public const VERSION='type1-observation-v1';
    public static function empty(): array {return ['version'=>self::VERSION,'days'=>[],'last_day'=>null,'episodes'=>[],'trades'=>[],'captures'=>[]];}
    public static function valid(array $b): bool
    {
        foreach(['open','high','low','close','volume'] as $k) if(!isset($b[$k])||!is_numeric($b[$k])||!is_finite((float)$b[$k])||($k==='volume'?$b[$k]<0:$b[$k]<=0))return false;
        return empty($b['synthetic'])&&($b['is_complete']??true)!==false&&$b['low']<=min($b['open'],$b['close'])&&$b['high']>=max($b['open'],$b['close']);
    }
    public static function byDay(array $bars,string $symbol,int $asOf): array
    {
        $out=[];
        foreach($bars as $b){$stamp=CandleClock::closeTime($b,$symbol);if($stamp>$asOf||($b['is_complete']??true)===false)continue;
            $day=(new \DateTimeImmutable('@'.$stamp))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d');
            if(array_key_exists($day,$out))throw new \RuntimeException('Duplicate price session');$out[$day]=$b;
        }ksort($out);return $out;
    }
    public static function advance(array $state,array $snapshot,Classifier $classifier): array
    {
        if($state['version']!==self::VERSION)throw new \RuntimeException('Tracker version mismatch');
        $day=$snapshot['day'];$asOf=$snapshot['as_of'];$days=$snapshot['days'];$sorted=$days;sort($sorted);
        if(!$days||$sorted!==$days||count(array_unique($days))!==count($days)||end($days)!==$day)throw new \RuntimeException('Invalid calendar');
        foreach($days as $d){$v=\DateTimeImmutable::createFromFormat('!Y-m-d',$d);if(!$v||$v->format('Y-m-d')!==$d)throw new \RuntimeException('Invalid date');}
        $local=(new \DateTimeImmutable('@'.$asOf))->setTimezone(new \DateTimeZone('Asia/Seoul'));
        if($local->format('Y-m-d')!==$day||$local->format('H:i')<'16:00')throw new \RuntimeException('Not an after-close capture');
        if($state['last_day']!==null&&$day<=$state['last_day'])throw new \RuntimeException('Out of order snapshot');
        if($state['days']&&array_slice($days,0,count($state['days']))!==$state['days'])throw new \RuntimeException('Calendar changed');
        $state['days']=$days;$index=array_flip($days);$i=$index[$day];$rows=[];$seen=[];
        foreach($snapshot['leaders'] as $leader){
            $symbol=$leader['yahoo'];if(!preg_match('/^[0-9A-Z]{6}\.(KS|KQ)$/D',$symbol)||isset($seen[$symbol]))throw new \RuntimeException('Invalid/duplicate leader');$seen[$symbol]=true;
            $episode=$state['episodes'][$symbol]??null;$fresh=$episode===null||$i-$index[$episode]>20;
            if($fresh)$state['episodes'][$symbol]=$day; // Nonmatches/unavailable also consume T..T+20.
            $row=['symbol'=>$symbol,'name'=>$leader['name']??$symbol,'rank'=>$leader['rank'],'amount_won'=>$leader['amount_won']??null,
                'episode_anchor'=>$state['episodes'][$symbol],'episode_state'=>$fresh?'new':'observing','classification'=>null,'error'=>null];
            try{$r=$classifier->classify($snapshot['prices'][$symbol]??[],$symbol,$asOf);if($r['analysis_date']!==$day)throw new \RuntimeException('당일 완료봉 없음');$row['classification']=$r;
                if($fresh&&$r['matches'])$state['trades'][$day.'|'.$symbol]=['symbol'=>$symbol,'name'=>$row['name'],'anchor'=>$day,'rank'=>$row['rank'],
                    'analysis_close'=>$r['analysis_close'],'status'=>'waiting','path'=>[],'entry'=>null,'exit'=>null,'net_pct'=>null,'error'=>null,'same_bar_order_unknown'=>false];
            }catch(\Throwable $e){$row['error']=$e->getMessage();}$rows[]=$row;
        }
        foreach($state['trades'] as &$t){
            if($t['status']==='closed')continue;$start=$index[$t['anchor']];$end=min($start+20,$i);
            try{$quotes=self::byDay($snapshot['prices'][$t['symbol']]??[],$t['symbol'],$asOf);}catch(\Throwable $e){$t['status']='unknown';$t['error']='중복 또는 잘못된 봉 날짜';continue;}
            for($j=$start+1;$j<=$end;$j++){$d=$days[$j];if(!isset($t['path'][$d])&&isset($quotes[$d])&&self::valid($quotes[$d]))$t['path'][$d]=$quotes[$d];}
            $t['error']=null;$t['status']='waiting';
            for($j=$start+1;$j<=$end;$j++){
                $d=$days[$j];$b=$t['path'][$d]??null;if(!$b){$t['status']='unknown';$t['error']=$d.' 유효 봉 없음';break;}
                if($j===$start+1)$t['entry']=['day'=>$d,'price'=>$b['open'],'stop'=>$b['open']*.95,'target'=>$b['open']*1.15];
                $t['status']='open';$p=$t['entry']['price'];$stop=$b['low']<=$p*.95;$target=$b['high']>=$p*1.15;
                $reason=$stop?'stop':($target?'target':($j===$start+20?'time':null));
                if($reason!==null){$price=$stop?min($p*.95,$b['open']):($target?$p*1.15:$b['close']);$t['exit']=['day'=>$d,'price'=>$price,'reason'=>$reason];$t['status']='closed';
                    $t['same_bar_order_unknown']=$stop&&$target;$t['net_pct']=($price*.9985/($p*1.0015)-1)*100;
                    $t['path']=array_filter($t['path'],static fn($k)=>$k<=$d,ARRAY_FILTER_USE_KEY);break;}
            }
            $t['elapsed_sessions']=$i-$start;$t['end_day']=$days[$start+20]??null;
        }unset($t);
        $state['captures'][$day]=['as_of'=>$asOf,'rows'=>$rows,'snapshot_sha256'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),
            'ranking_session_verified'=>$snapshot['ranking_session_verified']??false,'coverage_gap'=>$state['last_day']!==null&&$i-$index[$state['last_day']]>1];
        $state['last_day']=$day;return $state;
    }
}
