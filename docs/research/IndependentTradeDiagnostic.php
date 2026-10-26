<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bin/bootstrap.php';
use ChartEntryLab\{TradeSimulator,PaperQuality,CandleClock};
final class IndependentTradeDiagnostic
{
    public static function stamp(string $d):int{return (new DateTimeImmutable($d.' 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();}
    public static function run(array $sig,array $days,array $raw,array $eventDays,string $sha):array
    {
        $plan=['ready'=>true,'signal_at'=>self::stamp($sig['anchor']),'entry'=>$sig['entry'],'stop'=>$sig['stop'],'target'=>$sig['target'],'order_valid_bars'=>1,'exit_mode'=>'fixed','protection_mode'=>'none'];
        $history=[];$last=['filled'=>false];$sim=new TradeSimulator();
        foreach($days as $day){
            if($day<=$sig['anchor'])continue;
            if($day>$sig['end'])break;
            $reason=in_array($day,$eventDays,true)?'reference_discontinuity':null;
            $ts=self::stamp($day);$past=array_values(array_filter($raw,fn($b)=>CandleClock::closeTime($b,$sig['symbol'])<=$ts));
            $q=PaperQuality::inspect($past,$sig['symbol'],$ts,['sha256'=>$sha,'price_basis'=>'krx_tdd_ohlcv_adjustment_unverified']);
            if($reason===null&&!$q['can_simulate'])$reason=implode('|',$q['reasons']);
            if($reason!==null)return ['status'=>'unresolved','reason'=>$reason,'at'=>$day,'known_filled'=>$last['filled'],'net_return_pct'=>null];
            $bars=CandleClock::completed($past,$sig['symbol'],$ts);$b=end($bars);$history[]=$b;
            $last=$sim->simulate($plan,$history,20);
            if($last['complete'])return $last;
        }
        return ['status'=>'unresolved','reason'=>'window_exhausted','known_filled'=>$last['filled'],'net_return_pct'=>null];
    }
}
