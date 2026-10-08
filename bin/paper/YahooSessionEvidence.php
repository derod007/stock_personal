<?php
declare(strict_types=1);
use ChartEntryLab\CandleClock;
use ChartEntryLab\PaperQuality;

/** No substitution or reconstruction: accept only the captured provider's full current-day bar. */
final class PaperYahooSessionEvidence
{
    public static function verify(array $source,array $bars,string $provider,int $now):int
    {
        $symbol=$source['symbol']??'';
        if(!preg_match('/^\d{6}\.(KS|KQ)$/',$symbol))throw new RuntimeException('Yahoo fallback requires a Korean symbol');
        if(!hash_equals($source['provider_sha256']??'',hash('sha256',$provider)))throw new RuntimeException('Yahoo provider hash mismatch');
        $at=strtotime($source['fetched_at']??'');
        if(!$at||$at>$now||$now-$at>3600)throw new RuntimeException('Yahoo capture is stale or future');
        $zone=new DateTimeZone('Asia/Seoul');$day=(new DateTimeImmutable('@'.$at))->setTimezone($zone)->format('Y-m-d');
        $session=(new DateTimeImmutable($day.' 15:30:00',$zone))->getTimestamp();
        if($at<$session)throw new RuntimeException('Yahoo capture is before market close');
        $data=json_decode($provider,true,512,JSON_THROW_ON_ERROR)['chart']['result'][0]??null;
        if(!is_array($data)||($data['meta']['symbol']??'')!==$symbol)throw new RuntimeException('Yahoo provider symbol mismatch');
        $quote=$data['indicators']['quote'][0]??[];$found=[];
        foreach($data['timestamp']??[] as $i=>$stamp){
            if(!is_int($stamp))continue;
            if((new DateTimeImmutable('@'.$stamp))->setTimezone($zone)->format('Y-m-d')===$day)$found[]=[$i,$stamp];
        }
        if(count($found)!==1)throw new RuntimeException('Yahoo current-day bar missing or duplicated');
        [$index,$stamp]=$found[0];$matches=array_values(array_filter($bars,fn($b)=>($b['time']??null)===$stamp));
        if(count($matches)!==1)throw new RuntimeException('Collected Yahoo current-day bar missing or duplicated');
        $bar=$matches[0];
        if(!empty($bar['synthetic'])||($bar['is_complete']??true)===false||CandleClock::closeTime($bar,$symbol)!==$session)throw new RuntimeException('Yahoo bar is not a completed regular session');
        foreach(['open','high','low','close','volume'] as $key){
            $value=$quote[$key][$index]??null;
            if(!is_int($value)&&!is_float($value))throw new RuntimeException('Yahoo provider OHLCV missing: '.$key);
            if(!is_finite((float)$value)||!is_numeric($bar[$key]??null)||(float)$bar[$key]!== (float)$value)throw new RuntimeException('Yahoo OHLCV mismatch: '.$key);
        }
        $quality=PaperQuality::inspect($bars,$symbol,$session,$source);
        if(empty($quality['can_simulate']))throw new RuntimeException('Yahoo daily quality blocked: '.implode(',',$quality['reasons']));
        return $session;
    }
}
