<?php
declare(strict_types=1);
use ChartEntryLab\PaperPortfolio;
use ChartEntryLab\PaperJournal;
use ChartEntryLab\CandleClock;
final class PaperEntryExperiment
{
    public static function update(?array $pair,array $source,array $definition,int $now,callable $emit): array
    {
        $mode=$definition['mode'];$candidate=$definition['candidate'];
        if(!in_array($mode,['forward','replay'],true))throw new InvalidArgumentException('Invalid mode');
        if($candidate!==[] && $candidate!==['pullback_volume_ratio'=>0.95])throw new InvalidArgumentException('Only the fixed .95 volume hypothesis is supported');
        $s=$source['state'];
        if($s['mode']!==$mode)throw new RuntimeException('Source mode mismatch');
        if(!empty($s['halted']))throw new RuntimeException('Source halted; comparison paused');
        $signature=hash('sha256',PaperJournal::encode([$definition,$s['config'],$s['version'],hash_file('sha256',__FILE__),hash_file('sha256',__DIR__.'/EntryRelaxation.php'),hash_file('sha256',__DIR__.'/Experiment.php')]));
        if($pair!==null && $pair['signature']!==$signature)throw new RuntimeException('Pinned experiment changed; use a new experiment ID');
        if($pair===null) {
            $pair=['signature'=>$signature,'definition'=>$definition,'created_at'=>$now,'source_version'=>$s['version'],
                'source_cursor'=>0,'source_hash'=>'','last_session'=>0,'first_session'=>null,'sessions'=>0,'blocked_snapshots'=>0,
                'baseline'=>PaperPortfolio::start($s['config'],$s['version'],$mode),
                'candidate'=>PaperPortfolio::start($s['config'],$s['version'],$mode)];
            $pair['baseline']['sectors']=$s['sectors']??[];
            $pair['candidate']['sectors']=$s['sectors']??[];
            $emit('experiment_started',['definition'=>$definition,'created_at'=>$now,'source_version'=>$s['version']]);
        }
        $pair['baseline']['sectors']=$s['sectors']??($pair['baseline']['sectors']??[]);
        $pair['candidate']['sectors']=$s['sectors']??($pair['candidate']['sectors']??[]);
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
            $wanted=$s['config']['symbols']??[];
            if($wanted===[]) $wanted=array_fill_keys(array_keys($entries),'unclassified');
            elseif(count($entries)!==count($wanted))throw new RuntimeException('Incomplete shared session');
            $snap=[];$bars=[];$refs=[];
            foreach($wanted as $symbol=>$sector) {
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
                if($arm==='candidate' && $candidate!==[])foreach($armSnapshots as $symbol=>&$p) {
                    $p=PaperEntryRelaxation::apply($p,$s['history'][$symbol]??[]);
                }
                unset($p);
                PaperPortfolio::advance($pair[$arm],$at,$bars,$armSnapshots,function($type,$payload)use($emit,$arm){$emit('arm_event',['arm'=>$arm,'type'=>$type,'payload'=>$payload]);});
            }
            $pair['first_session']??=$at;$pair['last_session']=$at;$pair['sessions']++;
        }
        $pair['source_cursor']=count($events);$pair['source_hash']=$events===[]?'':$events[count($events)-1]['hash'];
        return $pair;
    }
    public static function report(array $p): array { return PaperExperiment::report($p); }
}
