<?php
declare(strict_types=1);
namespace ChartEntryLab;

final class PaperQuality
{
    public static function inspect(array $raw,string $symbol,int $session,array $source,array $conflicts=[]): array
    {
        $completed=CandleClock::completed($raw,$symbol,$session);
        $recentInvalid=[];$invalid=[];$seen=[];$duplicate=false;
        foreach($raw as $b) {
            $t=CandleClock::closeTime($b,$symbol);
            if($t>$session || !empty($b['synthetic']) || ($b['is_complete']??true)===false) continue;
            if(isset($seen[$t])) $duplicate=true;
            $seen[$t]=true;
            $valid=CandleClock::completed([$b],$symbol,$session);
            if($valid===[] || !is_numeric($b['volume']??null) || !is_finite((float)$b['volume']) || $b['volume']<0) {
                $invalid[]=$t;if($session-$t<=90*86400) $recentInvalid[]=$t;
            }
        }
        $last=$completed===[]?null:end($completed)['available_at'];
        $reasons=[];$warnings=[];
        if(empty($source['sha256'])) $reasons[]='missing_source';
        if(count($completed)<60) $reasons[]='insufficient_history';
        if($last!==$session) $reasons[]='missing_session';
        if($recentInvalid!==[]) $reasons[]='invalid_recent_ohlcv';
        if($duplicate) $reasons[]='duplicate_session';
        if($invalid!==[]) $warnings[]='excluded_invalid_history';
        foreach($conflicts as $c) {
            if(($c['symbol']??null)!==$symbol || ($c['status']??'')==='two_providers_agree_on_valid_ohlc') continue;
            $tz=new \DateTimeZone(str_ends_with($symbol,'.KS')?'Asia/Seoul':'America/New_York');
            $t=(new \DateTimeImmutable($c['date'].' 16:00:00',$tz))->getTimestamp();
            if($t>$session) continue;
            $warnings[]='provider_disagreement';
            if($t<=$session && $session-$t<=90*86400) $reasons[]='recent_provider_disagreement';
        }
        if(empty($source['price_basis'])) $warnings[]='price_basis_not_recorded';
        $warnings[]='corporate_action_adjustment_unverified';
        return ['status'=>$reasons===[]?'warning':'blocked','can_simulate'=>$reasons===[],
            'reasons'=>array_values(array_unique($reasons)),'warnings'=>array_values(array_unique($warnings)),
            'source'=>$source,'data_asof'=>$last,'invalid_bars'=>$invalid,'completed_count'=>count($completed)];
    }
}
