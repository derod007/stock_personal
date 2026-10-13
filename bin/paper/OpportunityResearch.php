<?php
declare(strict_types=1);
require_once __DIR__.'/DatedTop100Replay.php';
require_once __DIR__.'/PatternReplay.php';
use ChartEntryLab\{CandleClock,ChartPlanEngine,PaperQuality,TradeSimulator,PriceCandidate};
/** Independent observations, never portfolio decisions or account writes. */
final class PaperOpportunityResearch
{
    public const VERSION='dated_opportunity_v1';
    public static function demand(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
    public static function encode(array $v):string {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
    public static function read(string $p):array {return json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR);}
    public static function hash(array $v):string {return hash('sha256',self::encode($v));}
    public static function num(mixed $a,mixed $b,string $why):void {self::demand(is_numeric($a)&&is_numeric($b)&&abs((float)$a-(float)$b)<1e-6,$why);}
    public static function writeNew(string $path,array $v):void {
        self::demand(!file_exists($path),'Refusing overwrite: '.$path);
        $bytes=self::encode($v)."\n";$tmp=$path.'.tmp-'.bin2hex(random_bytes(6));
        self::demand(file_put_contents($tmp,$bytes,LOCK_EX)===strlen($bytes),'Output write failed');
        self::demand(rename($tmp,$path),'Output rename failed');
    }
    public static function baseline(string $path,array $candidates):array {
        $out=[];$count=0;$orders=0;$capacity=[];
        foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
            $d=json_decode($line,true,512,JSON_THROW_ON_ERROR);$day=$d['date'];$ts=PaperDatedTop100Replay::sessionClose($day);
            self::demand(!isset($out[$day])&&isset($candidates['by_session'][$ts]),'Duplicate/unknown baseline day');$signals=[];
            foreach($d['signals'] as $s){
                self::demand(!isset($signals[$s['symbol']])&&in_array($s['symbol'],$candidates['by_session'][$ts]['symbols'],true),'Bad baseline candidate');
                $signals[$s['symbol']]=$s;$count++;$orders+=(int)($s['outcome']==='order');
                if($s['outcome']==='position_limit')$capacity[]=['date'=>$day]+$s;
            }
            ksort($signals);$out[$day]=$signals;
        }
        ksort($out);self::demand(count($out)===244&&$count===103&&$orders===68&&count($capacity)===28,'Baseline counts changed');
        return ['days'=>$out,'capacity'=>$capacity,'counts'=>['sessions'=>244,'signals'=>$count,'orders'=>$orders,'capacity'=>28]];
    }
    /** Primitive conditions: a composite rising-structure failure can be 1, 2 or 3 failures. */
    public static function pullback(array $b,array $f):array {
        if(count($b)<45)return ['eligible'=>false,'reason'=>'insufficient_bars'];
        $atr=(float)($f['atr14']??0);if($atr<=0)return ['eligible'=>false,'reason'=>'invalid_atr'];
        $n=count($b);$last=$b[$n-1];$prev=$b[$n-2];$cl=array_column($b,'close');
        $ma=array_sum(array_slice($cl,-20))/20;$old=array_sum(array_slice($cl,-25,20))/20;
        $lo=static fn(array $xs):float=>(float)min(array_column($xs,'low'));$hi=static fn(array $xs):float=>(float)max(array_column($xs,'high'));
        $rawStop=$lo(array_slice($b,-10))-.2*$atr;$rawTarget=$hi(array_slice($b,-21,20));
        $zone=PriceCandidate::build($ma-.4*$atr,$ma+.4*$atr,$rawStop,$rawTarget,'research');
        $entry=PriceCandidate::trunc((float)$last['close']);$stop=PriceCandidate::trunc($rawStop);$target=PriceCandidate::trunc($rawTarget);
        if($zone===null||!($stop>0&&$stop<$entry&&$entry<$target))return ['eligible'=>false,'reason'=>'invalid_zone_or_levels'];
        $base=array_sum(array_column(array_slice($b,-24,20),'volume'))/20;$vol=array_sum(array_column(array_slice($b,-4,3),'volume'))/3;
        $rr=($target-$entry)/($entry-$stop);$nearLow=$lo(array_slice($b,-6,5));
        $g=['ma20_rising'=>$ma>$old,'window_low_rising'=>$lo(array_slice($b,-20))>$lo(array_slice($b,-40,20)),
            'window_high_rising'=>$hi(array_slice($b,-20))>$hi(array_slice($b,-40,20)),
            'near_ma20'=>$nearLow<=$ma+.75*$atr,'volume_contracted'=>$base>0&&$vol<$base*.85,
            'recovery_close'=>$last['close']>$prev['high'],'bullish_candle'=>$last['close']>$last['open'],
            'volume_recovery'=>$last['volume']>$prev['volume'],'fresh_confirmation'=>$prev['close']<=$b[$n-3]['high'],'minimum_rr'=>$rr>=1.5];
        return ['eligible'=>true,'gates'=>$g,'failed'=>array_keys(array_filter($g,static fn($v)=>!$v)),
            'values'=>['ma20'=>$ma,'old_ma20'=>$old,'atr14'=>$atr,'recent_low'=>$lo(array_slice($b,-20)),'prior_low'=>$lo(array_slice($b,-40,20)),
                'recent_high'=>$hi(array_slice($b,-20)),'prior_high'=>$hi(array_slice($b,-40,20)),'near_low'=>$nearLow,'near_limit'=>$ma+.75*$atr,
                'base_volume'=>$base,'pull_volume'=>$vol,'previous_high'=>$prev['high'],'signal_open'=>$last['open'],'signal_close'=>$last['close'],
                'signal_volume'=>$last['volume'],'previous_volume'=>$prev['volume'],'previous_close'=>$prev['close'],'two_bars_back_high'=>$b[$n-3]['high'],'rr_exact'=>$rr],
            'plan'=>['version'=>'trend_pullback_v1','ready'=>true,'entry'=>$entry,'stop'=>$stop,'target'=>$target,'reward_risk'=>round($rr,3),'signal_at'=>$last['available_at'],'order_valid_bars'=>3]];
    }
    public static function checkPullback(array $pr,array $raw):void {
        if(empty($pr['eligible'])){self::demand(empty($raw['ready']),'Ready pattern excluded by research');return;}
        $g=$pr['gates'];$map=$g+['rising_structure'=>$g['ma20_rising']&&$g['window_low_rising']&&$g['window_high_rising'],'atr_valid'=>true,'valid_zone'=>true];
        foreach($raw['gates']??[] as $k=>$v)self::demand(isset($map[$k])&&$map[$k]===$v,'Pullback gate mismatch: '.$k);
        self::demand(($pr['failed']===[])===!empty($raw['ready']),'Pullback ready mismatch');
        if(in_array($raw['status'],['ready','rejected_rr'],true))foreach(['entry','stop','target','reward_risk'] as $k)self::num($pr['plan'][$k],$raw[$k],'Pullback level '.$k);
    }
    public static function single(array $p):bool {return !empty($p['eligible'])&&count($p['failed'])===1;}
    public static function rrOnly(array $raw,int $date):?array {
        if(($raw['status']??'')!=='rejected_rr'||($raw['signal_at']??null)!==$date)return null;
        if(!($raw['stop']>0&&$raw['stop']<$raw['entry']&&$raw['entry']<$raw['target']))return null;
        $rr=($raw['target']-$raw['entry'])/($raw['entry']-$raw['stop']);
        self::demand($rr<1.5,'Rejected RR is not below 1.5');
        return ['plan'=>array_replace($raw,['ready'=>true]),'failed'=>['minimum_rr'],'values'=>['rr_exact'=>$rr]];
    }
    /** As-of defense: future rows cannot reach quality checks or detectors. */
    public static function inspect(array $info,string $symbol,int $date,string $dataset):array {
        $past=array_values(array_filter($info['rows'],static fn($b)=>CandleClock::closeTime($b,$symbol)<=$date));
        $source=['sha256'=>$info['bars_sha256'],'price_basis'=>PaperHistoryResearch::PRICE_BASIS,'kind'=>'research_dataset','dataset'=>$dataset];
        $q=PaperQuality::inspect($past,$symbol,$date,$source,[]);
        if(!$q['can_simulate'])return ['quality'=>$q,'analysis'=>null,'probe'=>null];
        $valid=CandleClock::completed($past,$symbol,$date);$a=(new ChartPlanEngine())->analyze($valid,$symbol,$date,'account1',true);
        $pr=self::pullback($valid,$a['features']);self::checkPullback($pr,$a['plan']['diagnostics']['patterns']['trend_pullback']);
        return ['quality'=>$q,'analysis'=>$a,'probe'=>$pr];
    }
    /** Preserve missing exchange sessions, invalid/duplicate/synthetic/incomplete bars. */
    public static function future(array $rows,string $symbol,int $date,array $sessions):array {
        $needed=array_slice(array_values(array_filter($sessions,static fn($t)=>$t>$date)),0,22);$by=[];
        foreach($rows as $b){$t=CandleClock::closeTime($b,$symbol);if(!in_array($t,$needed,true))continue;
            $valid=CandleClock::completed([$b],$symbol,$t);$ok=$valid!==[]&&is_numeric($b['volume']??null)&&is_finite((float)$b['volume'])&&$b['volume']>=0;
            if(array_key_exists($t,$by))$by[$t]=null;else $by[$t]=$ok?$valid[0]:null;
        }
        return array_map(static fn($t)=>$by[$t]??null,$needed);
    }
    public static function shadow(array $plan,array $future):array {
        $prefix=[];$bad=false;foreach($future as $b){if($b===null){$bad=true;break;}$prefix[]=$b;}
        $sim=new TradeSimulator(10,5);$r=$sim->simulate($plan,$prefix,20);
        if($bad&&!$r['complete']){$r['status']='future_quality_blocked';$r['net_return_pct']=null;}
        $r['observation_end_at']=$r['exit_at'];
        if($r['complete']&&$r['observation_end_at']===null){
            for($i=1;$i<=count($prefix);$i++){if($sim->simulate($plan,array_slice($prefix,0,$i),20)['complete']){$r['observation_end_at']=$prefix[$i-1]['available_at'];break;}}
        }
        $r['basis']='independent_unit_trade_not_portfolio';return $r;
    }
    public static function checkBaseline(array $got,array $want):void {
        ksort($got);ksort($want);self::demand(array_keys($got)===array_keys($want),'Final recommendation membership mismatch');
        foreach($want as $symbol=>$s){self::demand($got[$symbol]['pattern']===$s['pattern'],'Final pattern mismatch');
            foreach(['entry','stop','target','reward_risk','signal_at','order_valid_bars'] as $k)self::num($got[$symbol][$k],$s[$k],'Final '.$symbol.' '.$k);
        }
    }
    public static function day(array $data,array $c,int $ts,array $expected):array {
        $day=PaperHistoryResearch::day($ts);$got=[];$cap=[];$near=[];$counts=['top100'=>0,'quality_blocked'=>0,'evaluated'=>0];$states=[];$elig=[];$sessions=PaperDatedTop100Replay::sessions($c);
        foreach($c['by_session'][$ts]['symbols'] as $symbol){
            $counts['top100']++;$info=$data['symbols'][$symbol];$r=self::inspect($info,$symbol,$ts,$data['dataset']);
            if($r['analysis']===null){$counts['quality_blocked']++;continue;}
            $counts['evaluated']++;$a=$r['analysis'];$p=$a['plan'];$pr=$r['probe'];$raws=$p['diagnostics']['patterns'];
            if($p['ready'])$got[$symbol]=$p;
            foreach($raws as $k=>$v){$key=$k.':'.$v['status'];$states[$key]=($states[$key]??0)+1;}
            $key=empty($pr['eligible'])?$pr['reason']:'failed_'.count($pr['failed']);$elig[$key]=($elig[$key]??0)+1;
            $base=['date'=>$day,'session'=>$ts,'symbol'=>$symbol,'name'=>$info['name'],'final_ready'=>(bool)$p['ready'],'final_pattern'=>$p['pattern'],'final_status'=>$p['status']];
            if(($expected[$symbol]['outcome']??null)==='position_limit'){
                self::demand($p['ready'],'Capacity signal no longer ready');$pl=$expected[$symbol]+['ready'=>true,'version'=>$p['pattern']];
                $cap[]=$base+['pattern'=>$p['pattern'],'plan'=>$pl,'shadow'=>self::shadow($pl,self::future($info['rows'],$symbol,$ts,$sessions))];
            }
            $probes=self::single($pr)?[$pr]:[];
            foreach(['breakout_retest','trend_recovery'] as $k){$rr=self::rrOnly($raws[$k],$ts);if($rr!==null)$probes[]=$rr;}
            foreach($probes as $probe){$pl=$probe['plan'];$blocks=PaperPatternReplay::blockers($a,$pl);$potential=$blocks===[]&&!$p['ready'];
                $near[]=$base+['pattern'=>$pl['version'],'missing_gate'=>$probe['failed'][0],'gates'=>$probe['gates']??null,'values'=>$probe['values'],
                    'plan'=>$pl,'common_blockers'=>$blocks,'already_final_ready'=>(bool)$p['ready'],'potential_new'=>$potential,
                    'shadow'=>$potential?self::shadow($pl,self::future($info['rows'],$symbol,$ts,$sessions)):null];
            }
        }
        self::checkBaseline($got,$expected);
        return ['date'=>$day,'counts'=>$counts,'baseline_signals_verified'=>count($got),'pattern_states'=>$states,'pullback_eligibility'=>$elig,'capacity'=>$cap,'near_misses'=>$near];
    }
    public static function label(array $rows,array $allDays):array {
        $index=array_flip($allDays);$last=[];$seen=[];
        usort($rows,static fn($a,$b)=>[$a['date'],$a['symbol'],$a['pattern'],$a['missing_gate']??'']<=>[$b['date'],$b['symbol'],$b['pattern'],$b['missing_gate']??'']);
        foreach($rows as &$r){$di=$index[$r['date']];$sig=self::hash([$r['symbol'],$r['pattern'],$r['missing_gate']??null,$r['potential_new']??null,$r['common_blockers']??[],
                $r['plan']['entry'],$r['plan']['stop'],$r['plan']['target']]);$old=$last[$sig]??null;$first=$old===null||$old['index']!==$di-1;
            $r['episode_first']=$first;$r['exact_plan_episode']=$first?self::hash([$r['date'],$sig]):$old['id'];$last[$sig]=['index'=>$di,'id'=>$r['exact_plan_episode']];
            $r['overlaps_prior_same_symbol']=false;
            if($r['shadow']!==null){$end=$r['shadow']['observation_end_at']??PaperDatedTop100Replay::sessionClose($allDays[min($di+22,count($allDays)-1)]);
                foreach($seen[$r['symbol']]??[] as $e)if($e>=$r['session']){$r['overlaps_prior_same_symbol']=true;break;}
                $seen[$r['symbol']][]=$end;
            }
        }unset($r);return $rows;
    }
    public static function stats(array $rows):array {
        $s=['observations'=>count($rows),'states'=>[],'closed'=>0,'wins'=>0,'losses'=>0,'mean_net_return_pct'=>null];$sum=0;
        foreach($rows as $r){$t=$r['shadow'];$key=$t['status']??'not_simulated';$s['states'][$key]=($s['states'][$key]??0)+1;
            if($key==='closed'){$v=$t['net_return_pct'];$s['closed']++;$sum+=$v;$s['wins']+=(int)($v>0);$s['losses']+=(int)($v<0);}
        }if($s['closed'])$s['mean_net_return_pct']=$sum/$s['closed'];ksort($s['states']);return $s;
    }
    public static function checkpoint(string $dir,array $identity,array $allDays):array {
        $path=$dir.'/manifest.json';
        if(!is_file($path)){self::demand(array_diff(scandir($dir),['.','..','.lock'])===[],'Nonempty output without manifest');self::writeNew($path,$identity);}
        self::demand(self::read($path)===$identity,'Research input/code changed; use a new run-id');$done=[];
        foreach(glob($dir.'/day-*.json') as $p){$e=self::read($p);self::demand(isset($e['payload'],$e['sha256'])&&self::hash($e['payload'])===$e['sha256'],'Corrupt checkpoint: '.$p);
            $r=$e['payload'];self::demand(basename($p)==='day-'.$r['date'].'.json','Checkpoint date mismatch');$done[$r['date']]=$r;
        }ksort($done);self::demand(array_keys($done)===array_slice($allDays,0,count($done)),'Noncontiguous checkpoint days');return $done;
    }
    public static function summarize(array $days,array $allDays):array {
        ksort($days);$cap=[];$near=[];$counts=[];$states=[];$elig=[];$verified=0;
        foreach($days as $d){$cap=array_merge($cap,$d['capacity']);$near=array_merge($near,$d['near_misses']);$verified+=$d['baseline_signals_verified'];
            foreach($d['counts'] as $k=>$v)$counts[$k]=($counts[$k]??0)+$v;
            foreach($d['pattern_states'] as $k=>$v)$states[$k]=($states[$k]??0)+$v;
            foreach($d['pullback_eligibility'] as $k=>$v)$elig[$k]=($elig[$k]??0)+$v;
        }
        $cap=self::label($cap,$allDays);$near=self::label($near,$allDays);$groups=[];
        foreach($near as $r){$k=$r['pattern'].':'.$r['missing_gate'];$groups[$k]??=['independent_single_failure'=>0,'common_blocked'=>0,'already_final_ready'=>0,'potential_new'=>[]];
            $groups[$k]['independent_single_failure']++;$groups[$k]['common_blocked']+=(int)($r['common_blockers']!==[]);$groups[$k]['already_final_ready']+=(int)$r['already_final_ready'];
            if($r['potential_new'])$groups[$k]['potential_new'][]=$r;
        }
        foreach($groups as &$g){$rs=$g['potential_new'];$g['potential_new']=self::stats($rs);$g['first_exact_plan_episode']=self::stats(array_values(array_filter($rs,static fn($r)=>$r['episode_first'])));}unset($g);
        $complete=count($days)===count($allDays);if($complete)self::demand($verified===103&&count($cap)===28,'Full baseline totals mismatch');
        return ['version'=>self::VERSION,'full_period_complete'=>$complete,'sessions'=>count($days),'expected_sessions'=>count($allDays),'baseline_signals_verified'=>$verified,
            'counts'=>$counts,'pattern_states'=>$states,'pullback_eligibility'=>$elig,'capacity_summary'=>self::stats($cap),
            'capacity_first_exact_plan_episode'=>self::stats(array_values(array_filter($cap,static fn($r)=>$r['episode_first']))),
            'potential_new_symbol_days'=>count(array_unique(array_map(static fn($r)=>$r['date'].'|'.$r['symbol'],array_filter($near,static fn($r)=>$r['potential_new'])))),'near_miss_groups'=>$groups,'capacity'=>$cap,'near_misses'=>$near,'warning'=>'Independent unit trades, not portfolio returns or a validated filter. Partial runs are not full results.'];
    }
}
