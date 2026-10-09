<?php
declare(strict_types=1);
require_once __DIR__.'/HistoryResearch.php';
require_once __DIR__.'/HigherLowResearch.php';
require_once __DIR__.'/StrategyVersion.php';
use ChartEntryLab\{CandleClock,PaperQuality,ChartPlanEngine,PrebreakHigherLow,TrendRecovery,TradeSimulator};

/** Fixed-universe retrospective research. No operational observations, portfolios or network. */
final class PaperPatternReplay
{
    public const VERSION='fixed_universe_pattern_replay_v1';
    public static function day(array $prefix,string $symbol,int $session,string $profile):array
    {
        // Defense in depth: callers may supply future rows, but no detector ever receives them.
        $past=array_values(array_filter($prefix,fn($b)=>CandleClock::closeTime($b,$symbol)<=$session));
        $hash=hash('sha256',PaperRrAudit::encode($past));
        $quality=PaperQuality::inspect($past,$symbol,$session,['sha256'=>$hash,'price_basis'=>PaperHistoryResearch::PRICE_BASIS]);
        if(!$quality['can_simulate'])return ['status'=>'quality_blocked','quality_reasons'=>$quality['reasons']];
        $a=(new ChartPlanEngine())->analyze($past,$symbol,$session,$profile,true);
        $r=['symbol'=>$symbol,'bars'=>$past,'session'=>$session,'input_hash'=>$hash,'analysis'=>$a];
        return ['status'=>'evaluated','input_hash'=>$hash,'completed_bars'=>$quality['completed_count'],
            'analysis'=>$a,'rr'=>PaperRrAudit::evaluate($a,$quality),'higher_low'=>PaperHigherLowResearch::inspect($r)];
    }
    /** Applied independently to each raw pattern; selection priority is recorded separately. */
    public static function blockers(array $a,array $raw):array
    {
        $b=[];$p=$a['plan'];$f=$a['features'];
        if($p['asof']-$p['data_asof']>144*3600)$b[]='stale_data';
        if($a['decision']['action']==='blocked')$b[]='blocked';
        if(($f['spike_dump_status']??'none')!=='none')$b[]='spike_dump';
        if(in_array($f['top_pattern_status']??'none',['warning','confirmed'],true)&&($f['top_pattern_phase']??'')!=='bounce_confirmed')$b[]='top_collapse';
        $c=$p['context'];$rr=$raw['reward_risk']??null;
        $ctx=($raw['version']??'')===TrendRecovery::VERSION?TrendRecovery::contextBlocked($c,$rr)
            :($c['daily']==='down'||($c['weekly']==='down'&&($c['daily']!=='up'||($rr??0)<2)));
        if($p['context_applied']&&$ctx)$b[]='context_wait';
        return $b;
    }
    public static function key(string $symbol,string $pattern,array $raw):string
    {
        $structure=match($pattern){
            'breakout_retest'=>[$raw['breakout_at'],$raw['retest_at']],
            'trend_recovery'=>[$raw['evidence']['breakdown']['at']],
            'higher_low'=>[$raw['setup_id'],$raw['stage']],
            // Pullback already requires a fresh confirmation; only that signal close identifies an event.
            default=>[$raw['signal_at']],
        };
        return hash('sha256',PaperRrAudit::encode([$symbol,$pattern,$structure]));
    }
    /** Retain invalid sessions: never compress the future by silently skipping an invalid bar. */
    public static function future(array $rows,string $symbol,int $session,int $cutoff):array
    {
        $byTime=[];
        foreach($rows as $b){
            $t=CandleClock::closeTime($b,$symbol);
            if($t<=$session||$t>$cutoff||!empty($b['synthetic'])||($b['is_complete']??true)===false)continue;
            $one=CandleClock::completed([$b],$symbol,$cutoff);
            $valid=$one!==[]&&is_numeric($b['volume']??null)&&is_finite((float)$b['volume'])&&$b['volume']>=0;
            if(array_key_exists($t,$byTime))$byTime[$t]=null;
            else $byTime[$t]=$valid?$one[0]:null;
        }
        ksort($byTime,SORT_NUMERIC);
        return array_slice(array_values($byTime),0,22); // 3 entry sessions + 20 holding sessions, inclusive.
    }
    public static function horizons(float $base,array $future):array
    {
        $out=[];
        foreach(PaperFollowup::HORIZONS as $n){
            $slice=array_slice($future,0,$n);
            if(in_array(null,$slice,true)){$out[$n]=['status'=>'quality_blocked'];continue;}
            if(count($slice)<$n){$out[$n]=['status'=>'pending'];continue;}
            $last=$slice[$n-1];$out[$n]=['status'=>'complete','return_pct'=>($last['close']/$base-1)*100,
                'max_up_pct'=>max(0,(max(array_column($slice,'high'))/$base-1)*100),
                'max_down_pct'=>min(0,(min(array_column($slice,'low'))/$base-1)*100)];
        }
        return $out;
    }
    public static function trade(array $plan,array $future):array
    {
        $valid=[];$bad=false;foreach($future as $bar){if($bar===null){$bad=true;break;}$valid[]=$bar;}
        $out=(new TradeSimulator())->simulate($plan,$valid,20);
        if($bad&&!$out['complete'])$out['status']='future_quality_blocked';
        $out['basis']='independent_trade_not_portfolio';
        return $out;
    }
    public static function symbol(array $rows,string $symbol,string $name,string $start,int $cutoff,string $profile):array
    {
        // Include invalid OHLC in the prefix so day-level quality guards remain effective.
        $timeline=[];
        foreach($rows as &$b)$b['available_at']=CandleClock::closeTime($b,$symbol);unset($b);
        foreach($rows as $bar){$t=CandleClock::closeTime($bar,$symbol);if($t>$cutoff)continue;$timeline[$t][]=$bar;}
        ksort($timeline,SORT_NUMERIC);$prefix=[];$seen=[];
        $out=['symbol'=>$symbol,'name'=>$name,'days'=>0,'day_statuses'=>[],'quality_reasons'=>[],
            'pattern_statuses'=>[],'final_statuses'=>[],'higher_low_states'=>[],'duplicates'=>0,'events'=>[]];
        foreach($timeline as $session=>$group){
            $prefix=array_merge($prefix,$group);
            if(PaperHistoryResearch::day($session)<$start)continue;
            $out['days']++;$d=self::day($prefix,$symbol,$session,$profile);self::count($out['day_statuses'],$d['status']);
            if($d['status']!=='evaluated'){foreach($d['quality_reasons'] as $reason)self::count($out['quality_reasons'],$reason);continue;}
            $a=$d['analysis'];$p=$a['plan'];self::count($out['final_statuses'],$p['status']);
            self::count($out['higher_low_states'],$d['higher_low']['status']);
            $candidates=[];
            foreach($p['diagnostics']['patterns'] as $pattern=>$raw){
                $out['pattern_statuses'][$pattern]??=[];self::count($out['pattern_statuses'][$pattern],$raw['status']);
                if(($raw['signal_at']??null)===$session)$candidates[$pattern]=$raw;
            }
            if(!empty($d['higher_low']['stage']))$candidates['higher_low']=$d['higher_low'];
            $future=null;
            foreach($candidates as $pattern=>$raw){
                $key=self::key($symbol,$pattern,$raw);
                if(isset($seen[$key])){$out['duplicates']++;continue;}$seen[$key]=true;
                $future??=self::future($rows,$symbol,$session,$cutoff);
                $completed=CandleClock::completed($prefix,$symbol,$session);$base=(float)end($completed)['close'];
                $selected=($raw['version']??null)===$p['pattern'];
                $blocks=$pattern==='higher_low'?null:self::blockers($a,$raw);
                $event=['id'=>$key,'symbol'=>$symbol,'name'=>$name,'session'=>$session,'date'=>PaperHistoryResearch::day($session),
                    'pattern'=>$pattern,'stage'=>$raw['stage']??'confirmation','raw_status'=>$raw['status'],
                    'raw_ready'=>$raw['ready']??false,'selected'=>$selected,'final_status'=>$p['status'],
                    'operational_ready'=>$selected&&!empty($p['ready']),'independent_blockers'=>$blocks,
                    'input_hash'=>$d['input_hash'],'completed_bars'=>$d['completed_bars'],'reference_close'=>$base,
                    'stage_confirmed_at'=>$pattern==='higher_low'?($raw['stage']==='watch'?$raw['evidence']['higher_low']['confirmed_at']:$raw['evidence']['breakout']['at']):$raw['signal_at'],
                    'raw'=>$raw,'horizons'=>self::horizons($base,$future),'trades'=>[]];
                if($pattern!=='higher_low'&&!empty($raw['ready'])&&$blocks===[])
                    $event['trades']['independent_ready']=self::trade($raw,$future);
                // Baseline uses the actual engine selection; RR experiment uses its existing eligibility rules.
                if($event['operational_ready'])$event['trades']['selected_baseline']=self::trade($p,$future);
                foreach($d['rr'] as $rr)if($rr['pattern']===$pattern&&$rr['status']==='added'){
                    $event['rr_candidate']=$rr['candidate'];$event['trades']['rr_limit']=self::trade($rr['candidate'],$future);
                }
                $out['events'][]=$event;
            }
        }
        return $out;
    }
    private static function count(array &$a,string $key):void{$a[$key]=($a[$key]??0)+1;}
    public static function summarize(array $symbols):array
    {
        $s=['symbols'=>count($symbols),'days'=>0,'day_statuses'=>[],'quality_reasons'=>[],'final_statuses'=>[],
            'pattern_statuses'=>[],'higher_low_states'=>[],'duplicates'=>0,'events'=>0,'groups'=>[],'trades'=>[]];
        foreach($symbols as $r){
            $s['days']+=$r['days'];$s['duplicates']+=$r['duplicates'];
            foreach(['day_statuses','quality_reasons','final_statuses','higher_low_states'] as $field)
                foreach($r[$field] as $k=>$n)$s[$field][$k]=($s[$field][$k]??0)+$n;
            foreach($r['pattern_statuses'] as $p=>$stats)foreach($stats as $k=>$n)$s['pattern_statuses'][$p][$k]=($s['pattern_statuses'][$p][$k]??0)+$n;
            foreach($r['events'] as $e){
                $s['events']++;$key=$e['pattern'].':'.$e['stage'].':'.$e['raw_status'];
                $g=&$s['groups'][$key];$g??=['events'=>0,'late_first_observations'=>0,'independent_pass'=>0,'selected_ready'=>0,'blockers'=>[],'horizons'=>[]];
                $g['events']++;$g['late_first_observations']+=(int)($e['stage_confirmed_at']<$e['session']);$g['independent_pass']+=(int)($e['raw_ready']&&$e['independent_blockers']===[]);$g['selected_ready']+=(int)$e['operational_ready'];
                foreach($e['independent_blockers']??[] as $b)self::count($g['blockers'],$b);
                foreach($e['horizons'] as $n=>$h){
                    $v=&$g['horizons'][$n];$v??=['n'=>0,'up'=>0,'pending'=>0,'quality_blocked'=>0,'mean_return_pct'=>null,'mean_max_up_pct'=>null,'mean_max_down_pct'=>null];
                    if($h['status']!=='complete'){$v[$h['status']]++;unset($v);continue;}
                    $v['n']++;$v['up']+=(int)($h['return_pct']>0);
                    foreach(['return_pct','max_up_pct','max_down_pct'] as $f)$v['mean_'.$f]=($v['mean_'.$f]??0)+($h[$f]-($v['mean_'.$f]??0))/$v['n'];unset($v);
                }unset($g);
                foreach($e['trades'] as $kind=>$t){
                    $key=$kind.':'.$e['pattern'];$v=&$s['trades'][$key];
                    $v??=['signals'=>0,'statuses'=>[],'closed'=>0,'wins'=>0,'mean_net_return_pct'=>null,'exits'=>[],'ambiguous'=>0];
                    $v['signals']++;self::count($v['statuses'],$t['status']);$v['ambiguous']+=(int)($t['ambiguous_bar']??false);
                    if($t['status']==='closed'){$v['closed']++;$v['wins']+=(int)($t['net_return_pct']>0);self::count($v['exits'],$t['first_exit']);
                        $v['mean_net_return_pct']=($v['mean_net_return_pct']??0)+($t['net_return_pct']-($v['mean_net_return_pct']??0))/$v['closed'];}unset($v);
                }
            }
        }
        return $s;
    }
}
