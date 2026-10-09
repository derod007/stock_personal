<?php
declare(strict_types=1);
require_once __DIR__.'/EnvelopeStore.php';
use ChartEntryLab\{PrebreakHigherLow,CandleClock,PaperQuality};

final class PaperHigherLowResearch
{
    public const KIND='prebreak_higher_low_observation_v1';
    public static function executionVersion():string
    {
        $files=[__FILE__,dirname(__DIR__,2).'/src/PrebreakHigherLow.php',__DIR__.'/Followup.php',__DIR__.'/TrackingInput.php',
            __DIR__.'/RrAudit.php',dirname(__DIR__,2).'/src/CandleClock.php',dirname(__DIR__,2).'/src/PaperQuality.php'];
        $hashes=[];foreach($files as $file)$hashes[basename($file)]=hash('sha256',str_replace("\r\n","\n",file_get_contents($file)));
        return self::hash($hashes);
    }
    private static function hash(array $v):string{return hash('sha256',PaperRrAudit::encode($v));}
    public static function inspect(array $r):array
    {
        if(!hash_equals($r['input_hash'],self::hash($r['bars'])))throw new RuntimeException('input_hash_mismatch');
        if(PaperTrackingInput::identity($r)['status']==='analysis_symbol_mismatch')throw new RuntimeException('analysis_symbol_mismatch');
        if(!preg_match('/^\d{6}\.(KS|KQ)$/D',$r['symbol']))return ['status'=>'unsupported_market','stage'=>null];
        $session=(int)$r['session'];$bars=CandleClock::completed($r['bars'],$r['symbol'],$session);
        $q=PaperQuality::inspect($r['bars'],$r['symbol'],$session,['sha256'=>$r['input_hash']]);
        if(!$q['can_simulate'])return ['status'=>'quality_blocked','stage'=>null,'quality_reasons'=>$q['reasons']];
        // Pattern lifetime is 60 bars plus its causal support history; reject holes in the inspected series.
        $bars=array_slice($bars,-180);$start=$bars[0]['available_at']??$session;
        if(array_filter($q['invalid_bars'],fn($at)=>$at>=$start))return ['status'=>'quality_blocked','stage'=>null,'quality_reasons'=>['invalid_pattern_history']];
        return (new PrebreakHigherLow())->analyze($bars);
    }
    public static function load(string $state,string $id):?array
    {
        if(!preg_match('/^[a-z0-9_-]{1,64}$/D',$id))throw new InvalidArgumentException('Invalid account');
        $folder=$state.'/higher-low-research/'.$id;$path=$folder.'/latest.json';if(!is_file($path))return null;
        $rows=[];$stream=PaperJsonRows::read($path);
        foreach($stream as $key=>$row){
            $row=PaperEnvelopeStore::compact($folder,$row,false);
            if($key!==$row['frozen']['record']['symbol'].'@'.$row['frozen']['record']['session'])throw new RuntimeException('Observation key mismatch');
            $rows[$key]=$row;
        }
        $report=$stream->getReturn();
        if(($report['kind']??null)!==self::KIND||($report['account']??null)!==$id)throw new RuntimeException('Invalid higher-low ledger');
        $report['rows']=$rows;return $report;
    }
    public static function register(array $report,string $folder,string $source,int $now,string $execution):array
    {
        $events=[];
        foreach($report['rows'] as $row)if(!empty($row['frozen']['event_key']))$events[$row['frozen']['event_key']]=true;
        $stream=PaperFollowup::observationStream($source);
        foreach($stream as $key=>$r){
            if($r['session']>$now||$r['captured_at']>$now)continue;
            try{
                if($r['captured_at']<$r['session'])throw new RuntimeException('observation_before_close');
                if(isset($report['rows'][$key])){
                    if($report['rows'][$key]['frozen']['record']['observation_hash']!==$r['observation_hash'])throw new RuntimeException('changed_observation_preserved_original');
                    continue;
                }
                $v=self::inspect($r);$event=null;
                if(!empty($v['stage'])){
                    $candidate=self::hash([$execution,$r['symbol'],$v['setup_id'],$v['stage']]);
                    if(!isset($events[$candidate]))$event=$candidate;
                }
                $f=['policy'=>self::KIND,'execution_version'=>$execution,'registered_at'=>$now,'record'=>$r,'features'=>$v,
                    'event_key'=>$event,'final_status'=>$r['analysis']['plan']['status']??'unknown','original_ready'=>!empty($r['analysis']['plan']['ready']),
                    'stored_strategy_version'=>$r['strategy_version']??null];
                $row=['frozen'=>$f,'frozen_hash'=>self::hash($f),'status'=>$event?'registered':'not_tracked','horizons'=>[],'last_success_at'=>null];
                $report['rows'][$key]=PaperEnvelopeStore::compact($folder,$row);$report['added']++;
                if($event!==null)$events[$event]=true;
            }catch(Throwable $e){$report['errors'][]=['symbol'=>$r['symbol'],'session'=>$r['session'],'error'=>$e->getMessage()];}
        }
        $meta=$stream->getReturn();$report['errors']=array_merge($report['errors'],$meta['errors']);
        $report['duplicates']=$meta['duplicates'];$report['unavailable_observations']=$meta['unavailable'];return $report;
    }
    public static function refresh(string $folder,array $row,callable $provider,string $execution,int $now):array
    {
        $previous=$row;
        try{
            $row=PaperEnvelopeStore::compact($folder,$row,false);
            if(empty($row['frozen']['event_key'])||$row['status']==='complete')return $row;
            $row=PaperEnvelopeStore::hydrate($folder,$row);$f=$row['frozen'];
            if($f['policy']!==self::KIND||$f['execution_version']!==$execution)throw new RuntimeException('Research version changed');
            $e=$provider($f['record'],$row);$at=(int)$e['as_of'];
            if($at>$now||$at<(int)($row['last_success_at']??0))throw new RuntimeException('Price cutoff regression or future cutoff');
            if(!hash_equals($e['price_hash'],self::hash($e['raw'])))throw new RuntimeException('Price hash mismatch');
            $r=$f['record'];$r['analysis']['plan']['ready']=false;$r['patterns']=[];
            $result=PaperFollowup::evaluate($r,$e['raw'],$at);
            if(!in_array($result['status'],['no_future_bars','pending','complete'],true))throw new RuntimeException($result['status']);
            foreach(['horizons','latest_session','observed_bars','status'] as $key)$row[$key]=$result[$key];
            $row['last_success_at']=$at;$row['price_hash']=$e['price_hash'];unset($row['error']);
            return PaperEnvelopeStore::compact($folder,$row);
        }catch(Throwable $e){
            $previous['status']='update_error';$previous['error']=$e->getMessage();unset($previous['view_hash']);
            $previous['view_hash']=self::hash($previous);return $previous;
        }
    }
    public static function summarize(array $rows):array
    {
        $out=['observations'=>count($rows),'states'=>[],'events'=>0,'statuses'=>[],'groups'=>[]];
        foreach($rows as $row){
            $f=$row['frozen'];$v=$f['features'];$out['states'][$v['status']]=($out['states'][$v['status']]??0)+1;
            $out['statuses'][$row['status']]=($out['statuses'][$row['status']]??0)+1;
            if(empty($f['event_key']))continue;$out['events']++;
            $key=self::hash([$f['execution_version'],$v['stage'],$f['final_status'],$f['stored_strategy_version']]);
            $g=&$out['groups'][$key];$g??=['execution_version'=>$f['execution_version'],'stage'=>$v['stage'],'final_status'=>$f['final_status'],
                'stored_strategy_version'=>$f['stored_strategy_version'],'observations'=>0,'horizons'=>[]];$g['observations']++;
            foreach(PaperFollowup::HORIZONS as $n){
                $g['horizons'][$n]??=['n'=>0,'up'=>0,'mean_return_pct'=>null,'mean_max_up_pct'=>null,'mean_max_down_pct'=>null];
                // Failed refresh results are retained for inspection, but not mixed into current statistics.
                if($row['status']==='update_error'||($row['horizons'][$n]['status']??'')!=='complete')continue;
                $h=&$g['horizons'][$n];$m=$row['horizons'][$n];$h['n']++;$h['up']+=(int)($m['return_pct']>0);
                foreach(['return_pct','max_up_pct','max_down_pct'] as $k)$h['mean_'.$k]=(($h['mean_'.$k]??0)*($h['n']-1)+$m[$k])/$h['n'];
                unset($h);
            }unset($g);
        }return $out;
    }
}
