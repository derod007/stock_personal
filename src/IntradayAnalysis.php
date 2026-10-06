<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** UI-only preview. Never changes CandleClock or the replay/confirmed engine. */
final class IntradayAnalysis
{
    public static function isRegularSession(string $symbol,int $asOf):bool
    {
        $now=(new \DateTimeImmutable('@'.$asOf))->setTimezone(self::zone($symbol));
        if((int)$now->format('N')>5)return false;
        $clock=$now->format('H:i:s');
        return $clock>=(self::kr($symbol)?'09:00:00':'09:30:00') && $clock<(self::kr($symbol)?'15:30:00':'16:00:00');
    }
    public static function validBar(array $b,string $symbol,int $asOf):bool
    {
        foreach(['open','high','low','close','volume','time','observed_at'] as $k){
            if(!isset($b[$k])||!is_numeric($b[$k])||!is_finite((float)$b[$k]))return false;
            if($k==='volume' ? $b[$k]<0 : $b[$k]<=0)return false;
        }
        if(!empty($b['synthetic'])||$b['low']>min($b['open'],$b['close'])||$b['high']<max($b['open'],$b['close']))return false;
        $tz=self::zone($symbol);$now=(new \DateTimeImmutable('@'.$asOf))->setTimezone($tz);
        $observed=(new \DateTimeImmutable('@'.(int)$b['observed_at']))->setTimezone($tz);
        $barDay=(new \DateTimeImmutable('@'.(int)$b['time']))->setTimezone($tz)->format('Y-m-d');
        if($barDay!==$now->format('Y-m-d')||$observed->format('Y-m-d')!==$barDay||(int)$b['observed_at']>$asOf)return false;
        $open=new \DateTimeImmutable($barDay.(self::kr($symbol)?' 09:00:00':' 09:30:00'),$tz);
        $close=CandleClock::closeTime(['time'=>(int)$b['time']],$symbol);
        if($asOf<$open->getTimestamp()||(int)$b['observed_at']<$open->getTimestamp())return false;
        // Intraday provider delay must be explicit and bounded. A closed session requires a close-time quote.
        return $asOf<$close ? $asOf-(int)$b['observed_at']<=1200 : (int)$b['observed_at']>=$close;
    }
    public function analyze(array $raw,string $symbol,int $asOf,string $profile,?array $daily):array
    {
        $engine=new ChartPlanEngine();$closed=CandleClock::completed($raw,$symbol,$asOf);
        $base=$engine->analyze($raw,$symbol,$asOf,$profile);
        if(!self::isRegularSession($symbol,$asOf) && $daily===null){
            $base['mode']='completed';
            $base['live_note']='장외 분석 · 수집된 완료 일봉 기준 (거래일은 분석 기준 시각 확인)';
            return $base;
        }
        $base['mode']='completed_fallback';$base['live_note']='당일 OHLCV 확인 불가 · 완료 일봉 점수';
        if($daily===null||!self::validBar($daily,$symbol,$asOf))return $base;
        $date=(new \DateTimeImmutable('@'.(int)$daily['time']))->setTimezone(self::zone($symbol))->format('Y-m-d');
        $closed=array_values(array_filter($closed,static fn($b)=>(new \DateTimeImmutable('@'.(int)$b['time']))->setTimezone(self::zone($symbol))->format('Y-m-d')!==$date));
        foreach(['open','high','low','close','volume'] as $k)$daily[$k]=(float)$daily[$k];
        $daily['available_at']=CandleClock::closeTime(['time'=>(int)$daily['time']],$symbol);
        $daily['time_kst']=(new \DateTimeImmutable('@'.(int)$daily['observed_at']))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i:s');
        $daily['is_complete']=$asOf>=$daily['available_at'];
        $preview=[...$closed,$daily];
        if($daily['is_complete']){
            $result=$engine->analyze($preview,$symbol,$asOf,$profile);
            $result['mode']='completed';$result['live_note']='당일 마감 확인 · 완료 일봉 점수';return $result;
        }
        $features=(new FeatureEngine())->extract($preview);
        $decision=AccountPlaybook::forProfile($profile)->decide($features,$symbol);
        $candidate=(new PriceCandidate())->fromStructure($features);
        $plan=$base['plan'];
        $plan=array_replace($plan,['ready'=>false,'status'=>'intraday_preview','confirmation_status'=>'provisional',
            'candidate'=>$candidate,'candidate_available'=>$candidate!==null,'data_asof'=>(int)$daily['observed_at'],
            'entry'=>null,'stop'=>null,'target'=>null,'reward_risk'=>null,
            'reason'=>'장중 잠정 평가 · 진행 중인 봉 포함, 종가 확인 전']);
        if($decision['action']==='blocked'){$plan['status']='blocked';$plan['candidate']=null;$plan['candidate_available']=false;$plan['reason']=$decision['reason'];}
        $plan['diagnostics']=['order_ready'=>false,'candidate_available'=>$plan['candidate_available'],'final_status'=>$plan['status']];
        return ['features'=>$features,'decision'=>$decision,'plan'=>$plan,'mode'=>'intraday',
            'completed_plan'=>$base['plan'],'daily'=>$daily,
            'live_note'=>'장중 잠정 점수 · 거래량은 현재까지 누적값(하루 예상치 아님)'];
    }
    private static function kr(string $symbol):bool{return preg_match('/\.(KS|KQ)$/i',$symbol)===1;}
    private static function zone(string $symbol):\DateTimeZone{return new \DateTimeZone(self::kr($symbol)?'Asia/Seoul':'America/New_York');}
}
