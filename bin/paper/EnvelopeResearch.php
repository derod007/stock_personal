<?php
declare(strict_types=1);
require_once __DIR__.'/Followup.php';
use ChartEntryLab\CandleClock;
use ChartEntryLab\PaperQuality;

/** Descriptive research only. No signals, order levels, scores or portfolio writes. */
final class PaperEnvelopeResearch
{
    public const KIND='envelope_observation_v1';
    public static function executionVersion():string
    {
        $hashes=[];
        foreach([__FILE__, __DIR__.'/Followup.php',__DIR__.'/TrackingInput.php',__DIR__.'/RrAudit.php',
            dirname(__DIR__,2).'/src/CandleClock.php',dirname(__DIR__,2).'/src/PaperQuality.php'] as $file)
            $hashes[basename($file)]=hash('sha256',str_replace("\r\n","\n",file_get_contents($file)));
        return hash('sha256',PaperRrAudit::encode($hashes));
    }
    public static function inspect(array $r):array
    {
        $raw=$r['bars'];$symbol=$r['symbol'];$session=(int)$r['session'];
        if(!hash_equals($r['input_hash'],hash('sha256',PaperRrAudit::encode($raw))))throw new RuntimeException('input_hash_mismatch');
        if(PaperTrackingInput::identity($r)['status']==='analysis_symbol_mismatch')throw new RuntimeException('analysis_symbol_mismatch');
        $bars=CandleClock::completed($raw,$symbol,$session);$n=count($bars);
        $base=['status'=>'insufficient_history','completed_bars'=>$n,'required_bars'=>240];
        if($n<240)return $base;
        $q=PaperQuality::inspect($raw,$symbol,$session,['sha256'=>$r['input_hash']]);
        // An excluded invalid bar within MA240's span must not quietly shorten that average.
        if(!$q['can_simulate']||array_filter($q['invalid_bars'],fn($t)=>$t>=$bars[$n-240]['available_at']))
            return array_replace($base,['status'=>'quality_blocked','quality'=>$q]);
        $closes=array_column($bars,'close');
        $ma=fn(int $length,int $end)=>array_sum(array_slice($closes,$end-$length+1,$length))/$length;
        $m20=$ma(20,$n-1);$m60=$ma(60,$n-1);$m240=$ma(240,$n-1);
        $last=$bars[$n-1];$lower=$m20*.91;$upper=$m20*1.09;
        $events=[];$inEpisode=false;$episodeStart=null;$previousStart=null;$previousEnd=null;
        // Include the pre-240-bar band history, but explicitly call the first event 'first in saved range'.
        for($i=19;$i<$n;$i++){
            $b=$bars[$i];$band=$ma(20,$i)*.91;$inLower=$b['low']<=$band;
            if($inLower&&!$inEpisode){
                $previousStart=$episodeStart;$episodeStart=$i;$events[]=$i;
            }
            if(!$inLower&&$inEpisode)$previousEnd=$i-1;
            $inEpisode=$inLower;
        }
        $touch=$last['low']<=$lower&&$last['high']>=$lower;
        $zone=$last['high']<$lower?'below_lower':($touch?'lower_touch':
            ($last['low']<=$m20&&$last['high']>=$m20?'center_touch':'other'));
        $episode=$inEpisode?($episodeStart===$n-1?'new_episode':'continuing_episode'):'outside_lower';
        $distance=$inEpisode&&$previousStart!==null?$episodeStart-$previousStart:null;
        $counts=[];foreach([5,10,20] as $w)$counts[$w]=count(array_filter($events,fn($i)=>$i>=$n-$w));
        // Strict 2-left/2-right pivots: last two bars can never confirm a pivot.
        $lows=[];
        for($i=2;$i<=$n-3;$i++)if($bars[$i]['low']<min($bars[$i-2]['low'],$bars[$i-1]['low'],$bars[$i+1]['low'],$bars[$i+2]['low']))
            $lows[]=['session'=>$bars[$i]['available_at'],'price'=>$bars[$i]['low'],'confirmed_at'=>$bars[$i+2]['available_at']];
        $pivots=array_slice($lows,-2);
        $higher=count($pivots)===2?$pivots[1]['price']>$pivots[0]['price']:null;
        // Explicit proxy, NOT the author's undefined 'first box': last 20-bar high breakout in prior 20 bars.
        $breakout=null;
        for($i=max(20,$n-21);$i<$n-1;$i++){
            $level=max(array_column(array_slice($bars,$i-20,20),'high'));
            if($bars[$i]['close']>$level)$breakout=['session'=>$bars[$i]['available_at'],'level'=>$level];
        }
        $retest=$breakout!==null&&$last['low']<=$breakout['level']&&$last['close']>=$breakout['level'];
        return ['status'=>'evaluated','completed_bars'=>$n,'required_bars'=>240,'reference_close'=>$last['close'],
            'ma20'=>$m20,'ma60'=>$m60,'ma240'=>$m240,'lower'=>$lower,'upper'=>$upper,
            'ordered_ma20_60_240'=>$m20>$m60&&$m60>$m240,'gap60_240_pct'=>($m60/$m240-1)*100,
            'zone'=>$zone,'lower_touch'=>$touch,'close_reclaimed_lower'=>$touch&&$last['close']>$lower,
            'center_close_held'=>$zone==='center_touch'&&$last['close']>$m20,
            'episode'=>$episode,'episode_start'=>$inEpisode?$bars[$episodeStart]['available_at']:null,
            'first_in_saved_range'=>$inEpisode&&$previousStart===null,
            'bars_between_episode_starts'=>$distance,'bars_since_previous_episode_end'=>$inEpisode&&$previousEnd!==null?$episodeStart-$previousEnd-1:null,
            'episodes_in_last_bars'=>$counts,'confirmed_low_pivots'=>$pivots,'higher_confirmed_low'=>$higher,
            'prior20_breakout'=>$breakout,'prior20_breakout_retest_proxy'=>$retest,'author_first_box'=>null,
            'band_formula'=>'SMA20 +/- 9% of same completed close series','quality'=>$q];
    }
    public static function load(string $state,string $id):?array
    {
        if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account');
        $path=$state.'/envelope-research/'.$id.'/latest.json';if(!is_file($path))return null;
        $r=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(($r['kind']??null)!==self::KIND||($r['account']??null)!==$id||!is_array($r['rows']??null))throw new RuntimeException('Invalid envelope ledger');
        foreach($r['rows'] as $key=>$row){
            if(!hash_equals($row['frozen_hash']??'',hash('sha256',PaperRrAudit::encode($row['frozen'])))
                ||$key!==$row['frozen']['record']['symbol'].'@'.$row['frozen']['record']['session'])throw new RuntimeException('Frozen envelope integrity mismatch');
        }return $r;
    }
    public static function register(array $report,array $obs,int $now,string $execution):array
    {
        $report['errors']=array_merge($report['errors'],$obs['errors']);
        $report['duplicates']=$obs['duplicates']??0;$report['unavailable_observations']=$obs['unavailable']??0;
        foreach($obs['records'] as $key=>$r){
            if($r['session']>$now||$r['captured_at']>$now)continue;
            if(isset($report['rows'][$key])){
                if($report['rows'][$key]['frozen']['record']['observation_hash']!==$r['observation_hash'])
                    $report['errors'][]=['symbol'=>$r['symbol'],'error'=>'changed_observation_preserved_original'];
                continue;
            }
            try{
                $features=self::inspect($r);
                $selected=[];foreach($r['patterns']??[] as $p)if(!in_array('not_selected_pattern',$p['exclusion_reasons']??[],true))$selected[]=$p['pattern']??'unknown';
                $f=['policy'=>self::KIND,'execution_version'=>$execution,'registered_at'=>$now,'record'=>$r,
                    'features'=>$features,'stored_strategy_version'=>$r['strategy_version']??null,'selected_patterns'=>$selected,
                    'final_status'=>$r['analysis']['plan']['status']??'unknown','original_ready'=>!empty($r['analysis']['plan']['ready'])];
                $report['rows'][$key]=['frozen'=>$f,'frozen_hash'=>hash('sha256',PaperRrAudit::encode($f)),
                    'status'=>$features['status']==='evaluated'?'registered':$features['status'],'horizons'=>[],'last_success_at'=>null];
                $report['added']++;
            }catch(Throwable $e){$report['errors'][]=['symbol'=>$r['symbol'],'session'=>$r['session'],'error'=>$e->getMessage()];}
        }return $report;
    }
    public static function refresh(array $row,callable $provider,string $execution,int $now):array
    {
        try{
            $f=$row['frozen'];
            if(!hash_equals($row['frozen_hash'],hash('sha256',PaperRrAudit::encode($f))))throw new RuntimeException('Frozen checksum mismatch');
            if($f['policy']!==self::KIND||$f['execution_version']!==$execution)throw new RuntimeException('Research version changed');
            if($f['features']['status']!=='evaluated'||$row['status']==='complete')return $row;
            $e=$provider($f['record'],$row);$at=(int)$e['as_of'];
            if($at>$now||$at<(int)($row['last_success_at']??0))throw new RuntimeException('Price cutoff regression or future cutoff');
            if(!hash_equals($e['price_hash'],hash('sha256',PaperRrAudit::encode($e['raw']))))throw new RuntimeException('Price hash mismatch');
            // Reuse strict identity/OHLC/quality checks and return math, without any simulated orders.
            $record=$f['record'];$record['analysis']['plan']['ready']=false;$record['patterns']=[];
            $result=PaperFollowup::evaluate($record,$e['raw'],$at);
            if(!in_array($result['status'],['pending','complete','no_future_bars'],true))throw new RuntimeException($result['status']);
            $row['horizons']=$result['horizons'];$row['latest_session']=$result['latest_session'];
            $row['observed_bars']=$result['observed_bars'];$row['last_success_at']=$at;$row['price_hash']=$e['price_hash'];
            $row['status']=$result['status'];unset($row['error']);
        }catch(Throwable $e){$row['status']='update_error';$row['error']=$e->getMessage();}
        return $row;
    }
    public static function summarize(array $rows):array
    {
        $out=['retained'=>count($rows),'statuses'=>[],'groups'=>[]];
        foreach($rows as $row){
            $out['statuses'][$row['status']]=($out['statuses'][$row['status']]??0)+1;
            $f=$row['frozen'];$v=$f['features'];if($v['status']!=='evaluated')continue;
            // Separate versions and original decisions; overlapping comparisons are never added together.
            $stratum=['policy'=>$f['policy'],'execution_version'=>$f['execution_version'],
                'strategy_version'=>$f['stored_strategy_version'],'patterns'=>$f['selected_patterns'],'final_status'=>$f['final_status']];
            $cohorts=['zone:'.$v['zone']];
            if($v['zone']==='lower_touch'&&$v['episode']==='new_episode'){
                $cohorts[]=$v['first_in_saved_range']?'first_in_saved_range':'repeat_episode';
                $cohorts[]='ordered:'.($v['ordered_ma20_60_240']?'yes':'no');
                $cohorts[]='higher_low:'.($v['higher_confirmed_low']===null?'unknown':($v['higher_confirmed_low']?'yes':'no'));
                $cohorts[]='breakout_retest_proxy:'.($v['prior20_breakout_retest_proxy']?'yes':'no');
                $cohorts[]='ordered_higher_retest:'.($v['higher_confirmed_low']===null?'unknown':
                    ($v['ordered_ma20_60_240']&&$v['higher_confirmed_low']&&$v['prior20_breakout_retest_proxy']?'yes':'no'));
                foreach([5,10,20] as $w)$cohorts[]='repeat_within_'.$w.':'.($v['episodes_in_last_bars'][$w]>=2?'yes':'no');
            }
            foreach($cohorts as $cohort){
                $key=hash('sha256',PaperRrAudit::encode([$stratum,$cohort]));
                $g=&$out['groups'][$key];$g??=['stratum'=>$stratum,'cohort'=>$cohort,'signals'=>0,'horizons'=>[]];$g['signals']++;
                foreach(PaperFollowup::HORIZONS as $n){
                    $g['horizons'][$n]??=['n'=>0,'up'=>0,'mean_return_pct'=>null,'mean_max_up_pct'=>null,'mean_max_down_pct'=>null];
                    $h=$row['horizons'][$n]??[];if(($h['status']??null)!=='complete')continue;
                    $s=&$g['horizons'][$n];$count=++$s['n'];$s['up']+=(int)($h['return_pct']>0);
                    foreach(['return_pct','max_up_pct','max_down_pct'] as $k)$s['mean_'.$k]=(($s['mean_'.$k]??0)*($count-1)+$h[$k])/$count;
                    unset($s);
                }unset($g);
            }
        }$out['groups']=array_values($out['groups']);return $out;
    }
}
