<?php
declare(strict_types=1);
require_once __DIR__.'/KrxAccountStudy.php';
require_once __DIR__.'/NoPullbackEngine.php';
use ChartEntryLab\{CandleClock,PaperJournal};

final class PaperNoPullbackStudy
{
    public const OUTPUT='history-research/krx-no-pullback-account-v1';
    public const REPORT_SHA=PaperKrxAccountStudy::REPORT_SHA;
    public const SECTOR_SHA=PaperKrxAccountStudy::SECTOR_SHA;
    public const SOURCE_BASELINE_SHA='620b53a7d97587bd016597b60ffa62751c093d4e22be495aa7ae397a84180101';
    public const FILES=[...PaperKrxAccountStudy::FILES,'bin/paper/NoPullbackStudy.php','bin/paper/NoPullbackEngine.php',
        'bin/paper_no_pullback_study.php','docs/krx-no-pullback/protocol.md'];
    public static function assertMirror(string $root):void
    {
        $read=static fn(string $p):string=>str_replace("\r\n","\n",(string)file_get_contents($root.'/'.$p));
        $e=explode("\n    public function apply(",$read('src/ChartPlanEngine.php'))[0]."\n}\n";
        $imports='namespace NoPullbackStudy;'."\n".'use ChartEntryLab\\{CandleClock,FeatureEngine,AccountPlaybook,BreakoutRetest,TrendPullback,TrendRecovery,TrendContext,PriceCandidate};';
        $selector="        \$plan = !empty(\$retest['ready']) ? \$retest\n            : (!empty(\$pullback['ready']) || \$pullback['candidate'] !== null ? \$pullback : \$retest);";
        $e=str_replace(['namespace ChartEntryLab;','bool $useContext = true): array',$selector],
            [$imports,'bool $useContext = true, bool $excludePullback = true): array',
             $selector."\n        if (\$excludePullback) \$plan = \$retest; // Before context/recovery selection, never delete trades afterwards."],$e);
        if($e!==$read('bin/paper/NoPullbackEngine.php'))throw new RuntimeException('No-pullback mirror changed beyond declared selection edit');
    }
    public static function config(string $root,string $arm):array
    {
        $c=PaperKrxAccountStudy::config($root,$arm);$c['id']='hist-krx-no-pullback-v1-'.$arm;return $c;
    }
    public static function load(string $root,string $state,string $barsDir):array
    {
        self::assertMirror($root);
        $p=$state.'/'.PaperKrxAccountStudy::OUTPUT.'/baseline/account-replay.json';
        if(!is_file($p)||hash_file('sha256',$p)!==self::SOURCE_BASELINE_SHA)throw new RuntimeException('PR96 baseline journal missing/changed; preserve source');
        $loaded=PaperKrxAccountStudy::load($root,$state,$barsDir);
        $loaded['source_baseline']=(new PaperJournal($p))->read()??throw new RuntimeException('Source journal absent');
        return $loaded;
    }
    /** Compare every dated event through the requested horizon, including cash/equity and rejected signals. */
    public static function baselineMatch(array $j,array $source):array
    {
        $through=$j['state']['last_session'];
        $slice=static function(array $journal)use($through):array{
            $out=[];foreach($journal['events'] as $e)if($e['type']!=='account_started'&&($e['payload']['session']??PHP_INT_MAX)<=$through)
                $out[]=['type'=>$e['type'],'payload'=>$e['payload']];return $out;
        };
        $diff=PaperRecoveryCloseAccount::differences($slice($j),$slice($source));
        return ['pass'=>$diff===[],'mismatches'=>$diff,'source_sha256'=>self::SOURCE_BASELINE_SHA];
    }
    public static function provider(bool $variant,array $breaks,array $reference):callable
    {
        if(!$variant)return PaperKrxAccountStudy::provider(false,$breaks,$reference);
        return static function(string $symbol,array $bars,int $at)use($breaks):array{
            if(count($bars)<40)return ['ready'=>false,'status'=>'data_quality','reason'=>'완료 봉 부족'];
            $full=(new \NoPullbackStudy\ChartPlanEngine())->analyze($bars,$symbol,$at,'account1',true,true);
            $plan=$full['plan'];
            if(!empty($plan['ready'])){
                if(!in_array($plan['pattern'],['breakout_retest_v1','trend_recovery_v1'],true))throw new RuntimeException('Excluded pattern selected');
                $trace=PaperKrxContinuityReview::trace($bars,$full,$breaks[$symbol]??[],PaperHistoryResearch::day($at));
                $plan['continuity_review']=$trace;
                // Existing replay preflight flag: for this arm it means freshly traced as-of, NOT PR95 membership.
                $plan['continuity_source_eligible']=true;
                $plan['research_eligibility_source']='no_pullback_asof_recomputed';
                if($trace['research_hold']){$plan['ready']=false;$plan['status']=$trace['status'];$plan['reason']='연구 가격 근거 보류';}
            }
            return $plan;
        };
    }
    public static function verify(array $j,array $loaded,bool $variant):array
    {
        $data=$loaded['data'];$books=PaperAccountReplay::reconcile($j,$data);$bad=[];$n=0;
        $provider=self::provider($variant,$data['breaks'],$loaded['reference']);
        foreach($j['events'] as $e){if($e['type']!=='order')continue;$n++;$o=$e['payload'];$symbol=$o['symbol'];$at=$o['session'];
            if(!in_array($symbol,$loaded['candidates']['by_session'][$at]['symbols']??[],true))$bad[]='not TOP100';
            $bars=CandleClock::completed($data['symbols'][$symbol]['rows'],$symbol,$at);$plan=$provider($symbol,$bars,$at);
            $ref=$loaded['reference'][$symbol.'@'.PaperHistoryResearch::day($at)]??null;$arm=$variant?'variant':'baseline';
            if(!$variant && (!$ref||empty($ref['original']['baseline_quality_eligible'])||$ref['arms']['baseline']['status']!=='no_crossing_in_traced_scope'))$bad[]='not frozen baseline eligible';
            if($variant && !in_array($plan['pattern']??'', ['breakout_retest_v1','trend_recovery_v1'],true))$bad[]='excluded pattern ordered';
            if(empty($plan['ready']))$bad[]='not ready';
            foreach(['pattern','entry','stop','target','signal_at','order_valid_bars','reward_risk'] as $k)
                $bad=array_merge($bad,PaperRecoveryCloseAccount::differences($o[$k]??null,$plan[$k]??null,$symbol.'.'.$k));
        }
        // No event may execute on or after the stopping session; it has no fabricated equity point.
        if(!empty($j['state']['halted']))foreach($j['events'] as $e)
            if(in_array($e['type'],['order','fill','exit','order_cancelled','equity','day_log'],true)&&($e['payload']['session']??0)>=$j['state']['halt_at'])$bad[]='event after halt';
        $active=[];$byDay=[];$previous=0;
        foreach($j['events'] as $e)if(isset($e['payload']['session']))$byDay[$e['payload']['session']][]=$e;
        ksort($byDay);
        foreach($byDay as $date=>$events){
            $unsafe=false;
            foreach($active as $symbol=>$_){
                $past=array_values(array_filter($data['symbols'][$symbol]['rows'],fn($b)=>$b['available_at']<=$date));
                $q=\ChartEntryLab\PaperQuality::inspect($past,$symbol,$date,['sha256'=>$data['symbols'][$symbol]['bars_sha256'],'price_basis'=>PaperKrxSignalStudy::BASIS],[]);
                if(empty($q['can_simulate']))$unsafe=true;
                foreach($data['breaks'][$symbol]??[] as $e)if(PaperHistoryResearch::day($previous)<$e['to']&&$e['to']<=PaperHistoryResearch::day($date))$unsafe=true;
            }
            $stopped=in_array('account_halted',array_column($events,'type'),true);
            if($unsafe!==$stopped)$bad[]='active safety history mismatch @'.$date;
            foreach($events as $e){$symbol=$e['payload']['symbol']??'';
                if($e['type']==='order')$active[$symbol]=true;
                if(in_array($e['type'],['exit','order_cancelled'],true))unset($active[$symbol]);
            }
            $previous=$date;
        }
        $pass=$books['pass']&&$bad===[];
        return ['pass'=>$pass,'books'=>$books,'orders'=>$n,'mismatches'=>$bad,'independent_strategy_implementation'=>false];
    }
}
