<?php
declare(strict_types=1);
require_once __DIR__.'/KrxContinuityReview.php';
use ChartEntryLab\{CandleClock,ChartPlanEngine,PaperJournal,PaperPortfolio};

final class PaperKrxAccountStudy
{
    public const OUTPUT='history-research/krx-continuity-account-v1';
    public const REPORT_SHA='81c38d188ecb0e04ccb83f4c7d6c3a6f0b47ad854a117a99486f06a2af052928';
    public const SECTOR='docs/historical-top100-20241008-20251002/sector-map-full-v2.json';
    public const SECTOR_SHA='ab86e9725b1b18ca62da74b37a76c15f0fd0e51ce764bf3bea750e8bb9c911b2';
    public const FILES=['bin/paper/KrxAccountStudy.php','bin/paper/KrxAccountReplay.php','bin/paper_krx_account_study.php',
        'bin/paper/AccountReplay.php','bin/paper/KrxContinuityReview.php','bin/paper/KrxSignalStudy.php',
        'bin/paper/RecoveryCloseAccount.php','bin/paper/RecoveryCloseEngine.php','bin/paper/RecoveryClosePullback.php',
        'docs/krx-continuity-account/protocol.md','config/paper-history-kr-dated-top100-20251008-20261008.json'];
    public const HEADER= <<<'HEAD'
<?php
// Static research adapter; exact declared changes are checked by PaperKrxAccountStudy::assertAdapter.
declare(strict_types=1);
use ChartEntryLab\{CandleClock,ChartPlanEngine,PaperJournal,PaperPortfolio,PaperQuality,PaperScanUniverse};
final class PaperKrxAccountReplay
{
    public const VERSION='krx_continuity_account_v1';
    public const LABEL='KRX TDD 원가격 · 동일 단절 보류 연구';
    public const STRATEGY_FINGERPRINT=PaperDatedTop100Replay::STRATEGY_FINGERPRINT;
    public const EVAL_START='2024-10-08';
    public const AS_OF='2025-10-02';
    public static function sessions(array $c):array{return PaperDatedTop100Replay::sessions($c);}
    public static function sectorMap(array $d,?array $m):array{return PaperDatedTop100Replay::sectorMap($d,$m);}
    public static function runtimeCodeHashes(array $e=[]):array{return PaperDatedTop100Replay::runtimeCodeHashes($e);}

HEAD;
    public static function adapterSource(string $root):string
    {
        $s=str_replace("\r\n","\n",(string)file_get_contents($root.'/bin/paper/DatedTop100Replay.php'));
        $start=strpos($s,'    public static function run(');$end=strpos($s,'    public static function initDataset(');
        if($start===false||$end===false)throw new RuntimeException('Adapter source boundary changed');
        $s=substr($s,$start,$end-$start);
        $s=str_replace("'price_basis'=>PaperHistoryResearch::PRICE_BASIS,'kind'=>'research_dataset'", "'price_basis'=>PaperKrxSignalStudy::BASIS,'kind'=>'krx_tdd_research_jsonl'",$s);
        $s=str_replace("'plan_source'=>\$provider===null?'engine':'injected_test'", "'plan_source'=>'krx_guarded_asof_engine'",$s);
        $s=str_replace('        $last=($journal->read()', "        if(!empty(\$existing['state']['halted']))return ['account'=>\$config['id'],'processed_sessions'=>0,'halted'=>true,'last_session'=>\$existing['state']['last_session'],'halt_at'=>\$existing['state']['halt_at']??null];\n        \$last=(\$journal->read()",$s);
        $s=str_replace('        ResearchCashFee::advance($s,$date,$barSet,$snapshots,$log);',
            "        if(!PaperKrxAccountStudy::beforeAdvance(\$s,\$date,\$barSet,\$snapshots,\$data['breaks']??[],\$emit))return false;\n        ResearchCashFee::advance(\$s,\$date,\$barSet,\$snapshots,\$log);",$s);
        return str_replace("\r\n","\n",self::HEADER).$s."}\n";
    }
    public static function assertAdapter(string $root):void
    {
        if(self::adapterSource($root)!==str_replace("\r\n","\n",(string)file_get_contents($root.'/bin/paper/KrxAccountReplay.php')))
            throw new RuntimeException('Account adapter differs beyond declared edits');
    }
    /** Atomic preflight: neither filled nor pending orders may cross an unpriced/break session. */
    public static function beforeAdvance(array &$s,int $date,array $bars,array $snap,array $breaks,callable $emit):bool
    {
        foreach($snap as $row)if(!empty($row['in_dated_top100'])&&!empty($row['quality']['can_simulate'])
            &&(!empty($row['plan']['ready'])||isset($row['plan']['continuity_review']))
            &&empty($row['plan']['continuity_source_eligible']))throw new RuntimeException('Eligible recommendation absent from frozen PR95 union');
        $issues=[];$day=PaperHistoryResearch::day($date);$previous=PaperHistoryResearch::day($s['last_session']);
        foreach($s['active'] as $symbol=>$order){
            $reasons=[];
            if(!isset($bars[$symbol])||empty($snap[$symbol]['quality']['can_simulate']))$reasons[]='active_invalid_or_missing_bar';
            $events=[];foreach($breaks[$symbol]??[] as $e)if($previous<$e['to']&&$e['to']<=$day)$events[]=$e;
            if($events!==[])$reasons[]='active_observed_price_break';
            if($reasons!==[])$issues[]=['symbol'=>$symbol,'filled'=>$order['filled'],'reasons'=>$reasons,
                'quality_reasons'=>$snap[$symbol]['quality']['reasons']??['missing_snapshot'],'breaks'=>$events];
        }
        if($issues!==[]){
            $s['halted']=true;$s['halt_reason']='krx_active_price_safety';$s['halt_at']=$date;$s['halt_issues']=$issues;
            $emit('account_halted',['session'=>$date,'reason'=>$s['halt_reason'],'issues'=>$issues,
                'action'=>'stop_entire_session_before_any_execution','last_valued_session'=>$s['last_session']]);return false;
        }
        foreach($snap as $symbol=>$row)if(!empty($row['plan']['continuity_review']['research_hold']))
            $emit('research_signal_held',['symbol'=>$symbol,'session'=>$date,'review'=>$row['plan']['continuity_review']]);
        return true;
    }
    public static function config(string $root,string $arm):array
    {
        if(!in_array($arm,['baseline','variant'],true))throw new RuntimeException('Unknown arm');
        $c=PaperDatedTop100Replay::configs($root,PaperDatedTop100Replay::RESEARCH_CONFIG_YEAR);
        $c['research']['id_prefix']='hist-krx-cont-v1-';
        return PaperAccountReplay::accountConfig($c['base'],'hist-krx-cont-v1-'.$arm,$c['research']);
    }
    public static function load(string $root,string $state,string $barsDir):array
    {
        self::assertAdapter($root);$p=PaperKrxSignalStudy::profile($root);
        $rp=$state.'/history-research/krx-continuity-v1/report.json';
        if(!is_file($rp)||hash_file('sha256',$rp)!==self::REPORT_SHA)throw new RuntimeException('PR95 full report missing/changed');
        $r=PaperKrxSignalStudy::json($rp);$in=PaperKrxSignalStudy::json($state.'/history-research/krx_signal_compare_v1/inputs.json');
        if(PaperKrxSignalStudy::digest($in)!==$r['source_inputs_sha256']||!$r['complete']||!$r['source_result_hash_verified'])throw new RuntimeException('Unverified source');
        foreach(array_merge($in['code_sha256_lf'],$r['code_sha256_lf']) as $f=>$sha)
            if(PaperStrategyVersion::fileHash($root.'/'.$f)!==$sha)throw new RuntimeException('Frozen source code changed '.$f);
        $symbols=[];$hashes=[];
        foreach($p['symbols'] as $symbol=>$code){
            $file=$barsDir.'/'.$symbol.'.jsonl';$sha=$in['verified_inputs']['jsonl_sha256'][$symbol]??null;
            if(!$sha||!is_file($file)||hash_file('sha256',$file)!==$sha)throw new RuntimeException('Missing/changed KRX JSONL '.$symbol);
            $rows=[];foreach(PaperKrxSignalStudy::rows($file) as $row)if(($b=PaperKrxSignalStudy::bar($row))!==null)$rows[]=$b;
            $symbols[$symbol]=['name'=>$symbol,'rows'=>$rows,'bars_sha256'=>$sha];$hashes[$symbol]=$sha;
        }
        $cand=PaperDatedTop100Replay::loadCandidates($root.'/'.PaperKrxSignalStudy::CAND,243,1248);
        $sector=PaperDatedTop100Replay::pinSectorMap($root.'/'.self::SECTOR,self::SECTOR_SHA);PaperSectorFreeze::assertCoversUnion($cand['union'],$sector);
        $data=['dataset'=>'krx-tdd-continuity-account-v1','files'=>['pr95_report'=>self::REPORT_SHA,'pr94_inputs'=>$r['source_inputs_sha256']],
            'symbols'=>$symbols,'missing'=>[],'hold'=>[],'start_day'=>'2024-10-08','as_of_day'=>'2025-10-02','breaks'=>$p['breaks']];
        $reference=[];foreach($r['cases'] as $c)$reference[$c['symbol'].'@'.$c['day']]=$c;
        return ['data'=>$data,'candidates'=>$cand,'sector'=>$sector,'reference'=>$reference,'days'=>$p['eval_days']];
    }
    public static function provider(bool $variant,array $breaks,array $reference):callable
    {
        return static function(string $symbol,array $bars,int $at)use($variant,$breaks,$reference):array{
            if(count($bars)<40)return ['ready'=>false,'status'=>'data_quality','reason'=>'완료 봉 부족'];
            $arm=$variant?'variant':'baseline';$day=PaperHistoryResearch::day($at);
            $full=$variant?(new \RecoveryCloseStudy\ChartPlanEngine())->analyze($bars,$symbol,$at,'account1',true,true)
                :(new ChartPlanEngine())->analyze($bars,$symbol,$at,'account1',true);
            $plan=$full['plan'];$ref=$reference[$symbol.'@'.$day]??null;
            // Source report includes only the quality-eligible union; raw-ready quality-blocked plans need no reference.
            if($ref!==null){
                if(PaperRecoveryCloseAccount::differences(PaperKrxSignalStudy::compact($plan),$ref['original'][$arm])!==[])
                    throw new RuntimeException('PR95 selected plan mismatch '.$symbol.'@'.$day);
            }
            if(!empty($plan['ready'])){
                $trace=PaperKrxContinuityReview::trace($bars,$full,$breaks[$symbol]??[],$day);
                if($ref!==null&&!empty($ref['original'][$arm.'_quality_eligible'])&&PaperRecoveryCloseAccount::differences($trace,$ref['arms'][$arm])!==[])
                    throw new RuntimeException('PR95 trace mismatch');
                $plan['continuity_review']=$trace;
                $plan['continuity_source_eligible']=!empty($ref['original'][$arm.'_quality_eligible']);
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
            if(!$ref||empty($ref['original'][$arm.'_quality_eligible'])||$ref['arms'][$arm]['status']!=='no_crossing_in_traced_scope')$bad[]='not frozen eligible';
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
require_once __DIR__.'/KrxAccountReplay.php';
