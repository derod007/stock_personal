<?php
declare(strict_types=1);
use ChartEntryLab\PaperPortfolio;
use ChartEntryLab\PaperJournal;
use ChartEntryLab\CandleClock;
final class PaperExperiment
{
    public static function update(?array $pair,array $source,array $definition,int $now,callable $emit): array
    {
        $mode=$definition['mode'];$candidate=$definition['candidate'];
        if(!in_array($mode,['forward','replay'],true))throw new InvalidArgumentException('Invalid mode');
        if(array_diff(array_keys($candidate),['order_valid_bars']))throw new InvalidArgumentException('Unsupported candidate change');
        if(isset($candidate['order_valid_bars']) && (!is_int($candidate['order_valid_bars']) || $candidate['order_valid_bars']<1 || $candidate['order_valid_bars']>5))throw new InvalidArgumentException('TTL must be integer 1..5');
        $s=$source['state'];
        if($s['mode']!==$mode)throw new RuntimeException('Source mode mismatch');
        if(!empty($s['halted']))throw new RuntimeException('Source halted; comparison paused');
        $signature=hash('sha256',PaperJournal::encode([$definition,$s['config'],$s['version'],hash_file('sha256',__FILE__)]));
        if($pair!==null && $pair['signature']!==$signature)throw new RuntimeException('Pinned experiment changed; use a new experiment ID');
        if($pair===null) {
            $pair=['signature'=>$signature,'definition'=>$definition,'created_at'=>$now,'source_version'=>$s['version'],
                'source_cursor'=>0,'source_hash'=>'','last_session'=>0,'first_session'=>null,'sessions'=>0,'blocked_snapshots'=>0,
                'baseline'=>PaperPortfolio::start($s['config'],$s['version'],$mode),
                'candidate'=>PaperPortfolio::start($s['config'],$s['version'],$mode)];
            $emit('experiment_started',['definition'=>$definition,'created_at'=>$now,'source_version'=>$s['version']]);
        }
        if(!empty($pair['baseline']['halted']) || !empty($pair['candidate']['halted']))throw new RuntimeException('Experiment arm halted; review and start a new ID');
        $cursor=$pair['source_cursor'];$events=$source['events'];
        if($cursor>count($events) || ($cursor && $events[$cursor-1]['hash']!==$pair['source_hash']))throw new RuntimeException('Source history changed or rolled back');
        $sessions=[];
        foreach(array_slice($events,$cursor) as $event)if($event['type']==='snapshot') {
            $p=$event['payload'];$sessions[$p['session']][$p['symbol']]=['snapshot'=>$p,'event_hash'=>$event['hash']];
        }
        ksort($sessions,SORT_NUMERIC);$latest=$sessions===[]?0:max(array_keys($sessions));
        $history=[];
        foreach($s['history']??[] as $symbol=>$bars)foreach(CandleClock::completed($bars,$symbol,$s['last_session']) as $bar)$history[$symbol][$bar['available_at']]=$bar;
        foreach($sessions as $at=>$entries) {
            if($at<=$pair['last_session'])continue;
            if($mode==='forward' && $pair['first_session']===null && $at!==$latest)continue;
            if(count($entries)!==count($s['config']['symbols']))throw new RuntimeException('Incomplete shared session');
            $snap=[];$bars=[];$refs=[];
            foreach($s['config']['symbols'] as $symbol=>$sector) {
                if(!isset($entries[$symbol]))throw new RuntimeException('Missing source symbol');
                $p=$entries[$symbol]['snapshot'];$refs[$symbol]=$entries[$symbol]['event_hash'];
                // A newly created experimental order cannot execute before this comparison actually ran.
                $p['recorded_at']=$now;
                $p['origin']=$mode==='replay'?'replay':($at===$latest && $entries[$symbol]['snapshot']['origin']==='forward'?'forward':'catchup');
                if($mode==='forward' && $now-$at>4*86400){$p['quality']['can_simulate']=false;$p['quality']['status']='blocked';$p['quality']['reasons'][]='stale_comparison_session';}
                if(empty($p['quality']['can_simulate']))$pair['blocked_snapshots']++;
                $snap[$symbol]=$p;
                if(isset($history[$symbol][$at]))$bars[$symbol]=$history[$symbol][$at];
            }
            $emit('shared_input',['session'=>$at,'source_event_hashes'=>$refs,'snapshots'=>$snap,'bars'=>$bars]);
            foreach(['baseline','candidate'] as $arm) {
                $armSnapshots=$snap;
                if($arm==='candidate' && isset($candidate['order_valid_bars']))foreach($armSnapshots as &$p)$p['plan']['order_valid_bars']=$candidate['order_valid_bars'];
                unset($p);
                PaperPortfolio::advance($pair[$arm],$at,$bars,$armSnapshots,function($type,$payload)use($emit,$arm){$emit('arm_event',['arm'=>$arm,'type'=>$type,'payload'=>$payload]);});
            }
            $pair['first_session']??=$at;$pair['last_session']=$at;$pair['sessions']++;
        }
        $pair['source_cursor']=count($events);$pair['source_hash']=$events===[]?'':$events[count($events)-1]['hash'];
        return $pair;
    }
    public static function report(array $p): array
    {
        $out=['definition'=>$p['definition'],'created_at'=>$p['created_at'],'first_session'=>$p['first_session'],'last_session'=>$p['last_session'],'sessions'=>$p['sessions'],'reasons'=>[],'arms'=>[]];
        foreach(['baseline','candidate'] as $arm) {
            $s=$p[$arm];$equity=PaperPortfolio::equity($s);$used=[];
            foreach($s['equity'] as $e)if($e['equity']>0)$used[]=($e['equity']-$e['cash']+$e['reserved_cash'])/$e['equity']*100;
            $out['arms'][$arm]=['currency'=>$s['config']['currency'],'equity'=>$equity,'net_pnl'=>$equity-$s['config']['initial_cash'],
                'return_pct'=>100*($equity/$s['config']['initial_cash']-1),'realized'=>$s['realized'],'closed_trades'=>$s['closed_trades'],
                'max_drawdown_pct'=>100*$s['max_drawdown'],'mean_capital_use_pct'=>$used?array_sum($used)/count($used):null,'cash'=>$s['cash'],'reserved_cash'=>PaperPortfolio::reserved($s)];
            if(!empty($s['halted']))$out['reasons'][]=$arm.'_halted';
        }
        if($p['baseline']['last_session']!==$p['candidate']['last_session'])$out['reasons'][]='period_mismatch';
        if($p['blocked_snapshots'])$out['reasons'][]='data_quality_blocks';
        if(min($p['baseline']['closed_trades'],$p['candidate']['closed_trades'])<30)$out['reasons'][]='fewer_than_30_closed_trades';
        $identity=$p['definition']['candidate']===[];
        $out['identity_check']=$identity?($p['baseline']===$p['candidate']?'matched':'mismatch'):'not_identity';
        if($out['identity_check']==='mismatch')$out['reasons'][]='identity_mismatch';
        $out['decision']=$identity?'동일 전략 검증 · 수익성 판단 대상 아님':($out['reasons']?'판단 보류':'검토 가능 · 자동 채택 없음');
        return $out;
    }
}
