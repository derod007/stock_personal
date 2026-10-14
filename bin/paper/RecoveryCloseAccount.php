<?php
declare(strict_types=1);
require_once __DIR__.'/DatedTop100Replay.php';
require_once __DIR__.'/RecoveryClosePullback.php';
require_once __DIR__.'/RecoveryCloseEngine.php';
use ChartEntryLab\{PaperJournal,ChartPlanEngine};

/** One predeclared research intervention. Never modifies operational strategy files. */
final class PaperRecoveryCloseAccount
{
    public const VERSION='recovery_close_account_v1';
    public const FILES=['bin/paper/RecoveryCloseAccount.php','bin/paper/RecoveryClosePullback.php',
        'bin/paper/RecoveryCloseEngine.php','bin/paper_recovery_close_account.php',
        'bin/paper/AccountReplay.php',PaperDatedTop100Replay::RESEARCH_CONFIG_YEAR,
        'docs/recovery-close-account/protocol.md','docs/recovery-close-account/frozen-inputs.json'];
    public const REFERENCE='docs/historical-top100-replay-20251008-20261008/account';

    /** Enforce a static mirror with exactly the documented edits; no generated PHP is executed. */
    public static function assertMirror(string $root):void
    {
        $imports='namespace RecoveryCloseStudy;'."\n".'use ChartEntryLab\\{CandleClock,FeatureEngine,AccountPlaybook,BreakoutRetest,TrendRecovery,TrendContext,PriceCandidate};';
        $read=static fn(string $p):string=>str_replace("\r\n","\n",(string)file_get_contents($root.'/'.$p));
        $p=str_replace(['namespace ChartEntryLab;','array $f): array',"\$near && \$dry && \$last['close'] > \$prev['high']"],
            [$imports,'array $f, bool $removeRecoveryClose = true): array',"\$near && \$dry && (\$removeRecoveryClose || \$last['close'] > \$prev['high'])"],$read('src/TrendPullback.php'));
        $e=explode("\n    public function apply(",$read('src/ChartPlanEngine.php'))[0]."\n}\n";
        $e=str_replace(['namespace ChartEntryLab;','bool $useContext = true): array','->analyze($bars, $features);'],
            [$imports,'bool $useContext = true, bool $removeRecoveryClose = true): array','->analyze($bars, $features, $removeRecoveryClose);'],$e);
        if($p!==$read('bin/paper/RecoveryClosePullback.php')||$e!==$read('bin/paper/RecoveryCloseEngine.php'))
            throw new RuntimeException('Research mirror differs beyond the single recovery_close intervention');
    }

    public static function read(string $path):array
    {return json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}

    /** Numeric roundoff tolerance only; integers, strings, list order and field presence are not normalized away. */
    public static function differences(mixed $a,mixed $b,string $path='$'):array
    {
        if(is_array($a)&&is_array($b)){
            $out=[];
            foreach(array_unique(array_merge(array_keys($a),array_keys($b))) as $k){
                if(!array_key_exists($k,$a)||!array_key_exists($k,$b))$out[]=$path.'.'.$k.': missing field';
                else $out=array_merge($out,self::differences($a[$k],$b[$k],$path.'.'.$k));
            }
            return $out;
        }
        if((is_float($a)||is_int($a))&&(is_float($b)||is_int($b))){
            if(is_int($a)&&is_int($b))return $a===$b?[]:[$path.': integer mismatch'];
            return is_finite((float)$a)&&is_finite((float)$b)&&abs($a-$b)<=1e-7?[]:[$path.': numeric mismatch'];
        }
        return $a===$b?[]:[$path.': mismatch'];
    }

    public static function reference(string $root):array
    {
        $d=$root.'/'.self::REFERENCE;
        return ['summary'=>self::read($d.'/summary.json'),'trades'=>self::read($d.'/trades.json')['trades'],
            'daily'=>array_map(fn($s)=>json_decode($s,true,512,JSON_THROW_ON_ERROR),file($d.'/daily-log.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES))];
    }

    public static function baselineCheck(array $j,array $ref):array
    {
        $s=PaperAccountReplay::summary($j);$r=$ref['summary'];
        // Only the separate account identity differs. All research summary fields and all daily/order fields are compared.
        unset($s['account'],$r['account']);
        $diff=array_merge(self::differences($r,$s,'summary'),
            self::differences($ref['trades'],PaperAccountReplay::trades($j),'trades'),
            self::differences($ref['daily'],PaperAccountReplay::dailyLog($j),'daily'));
        return ['pass'=>$diff===[],'mismatches'=>$diff,'sessions'=>count(PaperAccountReplay::dailyLog($j))];
    }

    public static function requireBaseline(array $check,array $books):void
    {
        if(empty($check['pass'])||($check['sessions']??0)!==244||empty($books['pass']))
            throw new RuntimeException('Baseline gate failed; variant is forbidden');
    }

    public static function provider():callable
    {
        $e=new \RecoveryCloseStudy\ChartPlanEngine();
        return static fn(string $sym,array $bars,int $date):array=>count($bars)<40
            ? ['ready'=>false,'status'=>'data_quality','reason'=>'데이터 품질 확인 필요']
            : $e->analyze($bars,$sym,$date,'account1',true,true)['plan'];
    }

    /** Order-date membership and final engine plans, separately from cash/event reconciliation. */
    public static function orderAudit(array $j,array $data,array $cand,bool $variant):array
    {
        $diff=[];$n=0;$provider=$variant?self::provider():static fn($sym,$bars,$date)=>(new ChartPlanEngine())->analyze($bars,$sym,$date)['plan'];
        foreach($j['events'] as $event){
            if($event['type']!=='order')continue;$n++;$o=$event['payload'];$date=$o['session'];$sym=$o['symbol'];
            if(!in_array($sym,$cand['by_session'][$date]['symbols']??[],true)){$diff[]='not in TOP100 '.$sym.'@'.$date;continue;}
            $bars=array_values(array_filter($data['symbols'][$sym]['rows']??[],fn($b)=>$b['available_at']<=$date));
            $p=$provider($sym,$bars,$date);
            if(empty($p['ready']))$diff[]='not ready '.$sym.'@'.$date;
            foreach(['pattern','entry','stop','target','signal_at','order_valid_bars','reward_risk'] as $k)
                $diff=array_merge($diff,self::differences($p[$k]??null,$o[$k]??null,$sym.'@'.$date.'.'.$k));
        }
        return ['pass'=>$diff===[],'orders'=>$n,'mismatches'=>$diff,'independent_strategy_implementation'=>false];
    }

    public static function compare(array $baseline,array $variant):array
    {
        $index=static function(array $trades):array{$out=[];foreach($trades as $t){$k=$t['symbol'].'@'.$t['signal_date'];if(isset($out[$k]))throw new RuntimeException('Duplicate trade key');$out[$k]=$t;}return $out;};
        $b=$index(PaperAccountReplay::trades($baseline));$v=$index(PaperAccountReplay::trades($variant));
        $signals=[];foreach(PaperAccountReplay::dailyLog($variant) as $d)foreach($d['signals'] as $s)$signals[$s['symbol'].'@'.$d['date']]=$s;
        $changes=[];
        foreach(array_unique(array_merge(array_keys($b),array_keys($v))) as $k){
            $x=$b[$k]??null;$y=$v[$k]??null;
            $type=$x===null?'added':($y===null?'missing':($x['pattern']!==$y['pattern']?'pattern_replaced':(self::differences($x,$y)===[]?'unchanged':'changed')));
            $changes[]=['key'=>$k,'type'=>$type,'baseline'=>$x,'variant'=>$y,'variant_signal'=>$signals[$k]??null,
                'baseline_realized_winner'=>$x!==null&&$x['status']==='closed'&&$x['exit']['net_pnl']>0];
        }
        $bs=PaperAccountReplay::summary($baseline);$vs=PaperAccountReplay::summary($variant);
        $counts=array_count_values(array_column($changes,'type'));
        $period=[];foreach(['baseline'=>$baseline,'variant'=>$variant] as $name=>$j){
            $daily=PaperAccountReplay::dailyLog($j);$last=(float)$j['state']['config']['initial_cash'];
            foreach(array_chunk($daily,122) as $i=>$rows){$end=(float)end($rows)['equity'];$period[$name][]=['half'=>$i+1,'from'=>$rows[0]['date'],'through'=>end($rows)['date'],'starting_equity'=>$last,'ending_equity'=>$end,'return_pct'=>100*($end/$last-1)];$last=$end;}
        }
        $signalStats=[];
        foreach(['baseline'=>$baseline,'variant'=>$variant] as $arm=>$journal){
            $stats=['recommendations'=>0,'patterns'=>[],'outcomes'=>[]];
            foreach(PaperAccountReplay::dailyLog($journal) as $day)foreach($day['signals'] as $signal){
                $stats['recommendations']++;$pattern=$signal['pattern'];$outcome=$signal['outcome']??'none';
                $stats['patterns'][$pattern]=($stats['patterns'][$pattern]??0)+1;
                $stats['outcomes'][$outcome]=($stats['outcomes'][$outcome]??0)+1;
            }
            $signalStats[$arm]=$stats;
        }
        return ['kind'=>self::VERSION,'label'=>'KRX 일별 거래대금 TOP100 연구 · recovery_close 제외 탐색 비교',
            'baseline'=>$bs,'variant'=>$vs,'equity_delta'=>$vs['equity']-$bs['equity'],
            'signals'=>$signalStats,'change_counts'=>$counts,'order_changes'=>$changes,'halves'=>$period,
            'notes'=>['기존 자료를 본 뒤 선택한 탐색 실험이며 독립 검증이 아님','운영 다음 TOP100과 동일하지 않음',
                '주문 차이는 경로 의존적. 누락만으로 추가 주문이 직접 밀어냈다고 단정하지 않음',
                '현재 업종의 과거 적용, 수정주가 기준 미확인, 종료·보유·취소·대기 분리',
                '대안 저널의 injected_test는 기존 콜백 경로 이름이며 실제 판정은 고정된 연구 엔진을 사용함'],
            'operational_writes'=>false];
    }
}
