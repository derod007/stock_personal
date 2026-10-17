<?php
declare(strict_types=1);
require_once __DIR__.'/NoPullbackStudy.php';
use ChartEntryLab\{ChartPlanEngine,PaperJournal};

/** Recent Yahoo-backed year. Same exclusion intervention, original recent replay safety rules. */
final class PaperRecentNoPullbackStudy
{
    public const OUTPUT='history-research/recent-no-pullback-account-v1';
    public const FIXED='docs/recovery-close-account/frozen-inputs.json';
    public const FIXED_SHA='e5db66635c05e68f70747a9e9cb52ec8af21a54d1c1670d2b6104435fc08a507';
    public const SECTOR_SHA=PaperDatedTop100Replay::YEAR_SECTOR_MAP_SHA256;
    public const FILES=[...PaperNoPullbackStudy::FILES,'bin/paper/RecentNoPullbackStudy.php',
        'bin/paper_recent_no_pullback_study.php','docs/recent-no-pullback/protocol.md',self::FIXED];
    public static function config(string $root,string $arm):array
    {
        if(!in_array($arm,['baseline','variant'],true))throw new RuntimeException('Unknown arm');
        $c=PaperDatedTop100Replay::configs($root,PaperDatedTop100Replay::RESEARCH_CONFIG_YEAR);
        return PaperAccountReplay::accountConfig($c['base'],'hist-kr-dated-top100-v1-no-pullback-'.$arm,$c['research']);
    }
    public static function load(string $root,string $state):array
    {
        PaperNoPullbackStudy::assertMirror($root);
        if(!PaperDatedTop100Replay::supportsRuntime(PaperStrategyVersion::current()))throw new RuntimeException('Strategy fingerprint changed');
        if(PaperStrategyVersion::fileHash($root.'/'.self::FIXED)!==self::FIXED_SHA)throw new RuntimeException('Frozen input definition changed');
        $fixed=PaperRecoveryCloseAccount::read($root.'/'.self::FIXED);
        foreach($fixed['repository_lf_sha256'] as $file=>$sha)
            if(PaperStrategyVersion::fileHash($root.'/'.$file)!==$sha)throw new RuntimeException('Frozen repository input changed '.$file);
        $profile=PaperDatedTop100Replay::profile(PaperDatedTop100Replay::PROFILE_YEAR);
        $dir=PaperHistoryResearch::datasetDir($state,PaperDatedTop100Replay::DATASET_YEAR);
        foreach($fixed['dataset_sha256'] as $file=>$sha)
            if(!is_file($dir.'/'.$file)||hash_file('sha256',$dir.'/'.$file)!==$sha)throw new RuntimeException('Frozen dataset missing/changed '.$file);
        $cand=PaperDatedTop100Replay::loadCandidates($root.'/'.$profile['candidates'],244,933);
        $data=PaperDatedTop100Replay::loadDataset($dir,$cand,$profile['dataset'],$profile['eval_start'],$profile['as_of']);
        $sector=PaperDatedTop100Replay::pinSectorMap($root.'/'.PaperDatedTop100Replay::YEAR_SECTOR_MAP,self::SECTOR_SHA);
        PaperSectorFreeze::assertCoversUnion($cand['union'],$sector);
        $hashes=[];foreach($data['symbols'] as $sym=>$info)$hashes[$sym]=$info['bars_sha256'];ksort($hashes);
        if(hash('sha256',PaperJournal::encode($hashes))!==$fixed['bars_hash'])throw new RuntimeException('Frozen bar hashes changed');
        $days=array_map(fn($t)=>$cand['by_session'][$t]['day'],PaperDatedTop100Replay::sessions($cand));
        if(count($days)!==244||$days[0]!=='2025-10-10'||end($days)!=='2026-10-08')throw new RuntimeException('Recent period mismatch');
        return ['data'=>$data,'candidates'=>$cand,'sector'=>$sector,'days'=>$days,
            'reference'=>PaperRecoveryCloseAccount::reference($root)];
    }
    public static function provider(bool $variant):?callable
    {
        if(!$variant)return null; // Preserve original engine path and provenance label for PR76 replay.
        return static fn(string $symbol,array $bars,int $at):array=>count($bars)<40
            ? ['ready'=>false,'status'=>'data_quality','reason'=>'데이터 품질 확인 필요']
            : (new \NoPullbackStudy\ChartPlanEngine())->analyze($bars,$symbol,$at,'account1',true,true)['plan'];
    }
    /** Prefix compares every daily field; full horizon additionally compares all summary/trade fields. */
    public static function baselineMatch(array $j,array $reference):array
    {
        $daily=PaperAccountReplay::dailyLog($j);$n=count($daily);
        if($n===244)return PaperRecoveryCloseAccount::baselineCheck($j,$reference);
        $diff=PaperRecoveryCloseAccount::differences(array_slice($reference['daily'],0,$n),$daily);
        if($n===0||$n>244)$diff[]='Invalid baseline prefix length';
        return ['pass'=>$diff===[],'sessions'=>$n,'mismatches'=>$diff,'scope'=>'all_daily_fields_prefix'];
    }
    public static function verify(array $j,array $loaded,bool $variant):array
    {
        $books=PaperAccountReplay::reconcile($j,$loaded['data']);$bad=[];$n=0;
        $provider=self::provider($variant)??static fn($s,$b,$t)=>(new ChartPlanEngine())->analyze($b,$s,$t,'account1',true)['plan'];
        foreach($j['events'] as $e){
            if($e['type']!=='order')continue;$n++;$o=$e['payload'];$sym=$o['symbol'];$at=$o['session'];
            if(!in_array($sym,$loaded['candidates']['by_session'][$at]['symbols']??[],true))$bad[]='not TOP100';
            $bars=array_values(array_filter($loaded['data']['symbols'][$sym]['rows']??[],fn($b)=>$b['available_at']<=$at));
            $plan=$provider($sym,$bars,$at);
            if(empty($plan['ready']))$bad[]='not ready';
            if($variant&&!in_array($o['pattern']??'',['breakout_retest_v1','trend_recovery_v1'],true))$bad[]='excluded pattern order';
            foreach(['pattern','entry','stop','target','signal_at','order_valid_bars','reward_risk'] as $k)
                $bad=array_merge($bad,PaperRecoveryCloseAccount::differences($o[$k]??null,$plan[$k]??null,$sym.'.'.$k));
        }
        return ['pass'=>$books['pass']&&$bad===[],'books'=>$books,'orders'=>$n,'mismatches'=>$bad,'independent_strategy_implementation'=>false];
    }
}
