<?php
declare(strict_types=1);
use ChartEntryLab\PaperJournal;
final class PaperWeekly
{
    public static function window(?string $week=null):array
    {
        $tz=new DateTimeZone('Asia/Seoul');$week??=(new DateTimeImmutable('now',$tz))->format('o-\WW');
        if(!preg_match('/^(\d{4})-W(\d{2})$/',$week,$m))throw new InvalidArgumentException('ISO week required');
        $start=(new DateTimeImmutable('now',$tz))->setISODate((int)$m[1],(int)$m[2],1)->setTime(0,0);
        if($start->format('o-\WW')!==$week)throw new InvalidArgumentException('Invalid ISO week');
        return ['week'=>$week,'start'=>$start->getTimestamp(),'end'=>$start->modify('+7 days')->getTimestamp(),'timezone'=>'Asia/Seoul'];
    }
    public static function summarize(array $d,array $window,int $now):array
    {
        $inside=fn($at)=>$at>=$window['start'] && $at<$window['end'];
        $r=['window'=>$window,'generated_at'=>$now,'week_complete'=>$now>=$window['end'],'groups'=>[],
            'execution'=>['fill'=>0,'exit'=>0,'order_cancelled'=>0],'exit_reasons'=>[],'realized_pnl'=>0.0,'account_alerts'=>[],
            'valuation'=>null,'equity_change'=>null,'opening_valuation'=>null,'closing_valuation'=>null,'evaluation_sessions'=>0];
        $snapshots=[];$points=[];$first=null;
        foreach($d['events'] as $e){
            $p=$e['payload'];$t=$e['type'];$at=(int)($p['session']??0);$key=($p['symbol']??'').':'.$at;
            if($t==='snapshot')$snapshots[$key]=$p;
            if($t==='equity'){$points[$at]=$p;$first=$first===null?$at:min($first,$at);}
            if(in_array($t,['data_revision','account_halted'],true) && $inside((int)$e['recorded_at']))$r['account_alerts'][]=['type'=>$t,'recorded_at'=>$e['recorded_at'],'reason'=>$p['reason']??'historical_revision'];
            if(!$inside($at))continue;
            if(in_array($t,['snapshot','decision','order'],true)){
                $origin=$snapshots[$key]['origin']??'unknown';
                if(!isset($r['groups'][$origin]))$r['groups'][$origin]=['evaluated'=>0,'confirmed'=>0,'orders'=>0,'excluded'=>0,'reasons'=>[]];
                $g=&$r['groups'][$origin];
                if($t==='snapshot'){$g['evaluated']++;$g['confirmed']+=(int)!empty($p['plan']['ready']);}
                elseif($t==='order')$g['orders']++;
                else{$g['excluded']++;$reason=$p['reason']??'unknown';$g['reasons'][$reason]=($g['reasons'][$reason]??0)+1;}
                unset($g);
            }
            if(isset($r['execution'][$t]))$r['execution'][$t]++;
            if($t==='exit'){
                $r['realized_pnl']+=(float)$p['net_pnl'];$reason=$p['reason']??'unknown';
                if(!isset($r['exit_reasons'][$reason]))$r['exit_reasons'][$reason]=['count'=>0,'net_pnl'=>0.0];
                $r['exit_reasons'][$reason]['count']++;$r['exit_reasons'][$reason]['net_pnl']+=$p['net_pnl'];
            }
        }
        ksort($points,SORT_NUMERIC);$prior=null;$last=null;
        foreach($points as $at=>$p){if($at<$window['start'])$prior=$p;if($inside($at)){$last=$p;$r['evaluation_sessions']++;}}
        if($last){
            $r['valuation']=$last;$r['closing_valuation']=['session'=>$last['session'],'equity'=>$last['equity'],'estimated'=>!empty($last['stale_positions'])];
            if($prior)$r['opening_valuation']=['session'=>$prior['session'],'equity'=>$prior['equity'],'estimated'=>!empty($prior['stale_positions']),'basis'=>'last_observed_before_week'];
            elseif($first!==null && $inside($first))$r['opening_valuation']=['session'=>null,'equity'=>$d['state']['config']['initial_cash'],'estimated'=>false,'basis'=>'initial_cash_before_first_evaluation'];
            $open=$r['opening_valuation'];
            if($open && !$open['estimated'] && !$r['closing_valuation']['estimated'])$r['equity_change']=$last['equity']-$open['equity'];
        }
        foreach($r['groups'] as &$g)arsort($g['reasons']);unset($g);
        $r['currency']=$d['state']['config']['currency'];$r['mode']=$d['state']['mode'];
        $r['current_account']=['halted'=>!empty($d['state']['halted']),'halt_reason'=>$d['state']['halt_reason']??null,'last_session'=>$d['state']['last_session']];
        return $r;
    }
    public static function runs(string $folder,array $window,int $now,int $unfinishedAfter=3700):array
    {
        $out=['counts'=>[],'latest_start'=>null,'invalid_files'=>0,'total'=>0];
        foreach(glob($folder.'/*.json')?:[] as $path){
            try{$r=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
                if(!is_array($r)||!is_int($r['started_at']??null)||!in_array($r['status']??'', ['success','failed','halted','running'],true))throw new RuntimeException('Invalid run');
                $out['latest_start']=max($out['latest_start']??0,$r['started_at']);
                if($r['started_at']<$window['start']||$r['started_at']>=$window['end'])continue;
                $status=$r['status'];if($status==='running' && $now-$r['started_at']>$unfinishedAfter)$status='unfinished';
                $out['counts'][$status]=($out['counts'][$status]??0)+1;$out['total']++;
            }catch(Throwable $e){$out['invalid_files']++;}
        }
        return $out;
    }
    public static function load(string $dir,string $id,string $mode,?string $week=null,?int $now=null):array
    {
        if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id)||!in_array($mode,['forward','replay'],true))throw new InvalidArgumentException('Invalid account/mode');
        $now??=time();$window=self::window($week);
        $d=(new PaperJournal($dir.'/'.$id.'-'.$mode.'.json'))->read();
        $out=['account'=>$id,'mode'=>$mode,'window'=>$window,'summary'=>null,'operations'=>null,'comparisons_current'=>[],'comparison_read_errors'=>0];
        if($mode==='forward')$out['operations']=self::runs($dir.'/runs/'.$id.'-forward',$window,$now);
        if(!$d || !$d['state'])return $out;
        $out['summary']=self::summarize($d,$window,$now);
        foreach(glob($dir.'/experiments/*-'.$mode.'.json')?:[] as $file){
            try{
                $experiment=(new PaperJournal($file))->read();$p=$experiment['state']??null;
                if(!$p || $p['definition']['source']!==$id || $p['definition']['mode']!==$mode)continue;
                $report=PaperExperiment::report($p);$cursor=$p['source_cursor'];
                $report['source_sync']=($cursor===count($d['events']) && ($cursor===0 || $p['source_hash']===$d['events'][$cursor-1]['hash']));
                $report['operations']=$mode==='forward'?self::runs($dir.'/experiment-runs/'.$p['definition']['id'],$window,$now,5600):null;
                $out['comparisons_current'][]=$report;
            }catch(Throwable $e){$out['comparison_read_errors']++;}
        }
        return $out;
    }
}
