<?php
declare(strict_types=1);
require_once __DIR__.'/RrView.php';
use ChartEntryLab\CandleClock;

/** Observational returns, never a portfolio or a causal estimate of a filter's value. */
final class PaperFollowup
{
    public const HORIZONS=[1,3,5,10,20];
    public static function observations(string $dir):array
    {
        $out=[];$errors=[];$duplicates=0;$unavailable=0;
        $files=PaperRrView::files($dir);sort($files,SORT_STRING);
        foreach($files as $file){
            try{
                $b=PaperRrView::load($dir.'/'.$file);
                if(($b['membership']??'')!=='observed_scan_only')continue;
                foreach($b['records'] as $r){
                    if(($r['status']??'')!=='evaluated'){$unavailable++;continue;}
                    if(!preg_match('/^[A-Za-z0-9.^=_-]{1,40}$/',$r['symbol']??'') || !isset($r['session'],$r['bars'],$r['input_hash']))throw new RuntimeException('Invalid observation');
                    $key=$r['symbol'].'@'.$r['session'];
                    // Earliest captured signal per symbol/session. Reruns must not multiply samples.
                    if(isset($out[$key])){$duplicates++;continue;}
                    $r['source_file']=$file;$r['captured_at']=(int)($b['recorded_at']??$r['session']);
                    $r['observation_hash']=hash('sha256',PaperRrAudit::encode($r));$out[$key]=$r;
                }
            }catch(Throwable $e){$errors[]=['file'=>$file,'error'=>$e->getMessage()];}
        }
        return ['records'=>$out,'errors'=>$errors,'duplicates'=>$duplicates,'unavailable'=>$unavailable];
    }
    public static function reasons(array $r):array
    {
        $reasons=[];$plan=$r['analysis']['plan'];
        if(!empty($plan['ready']))return [];
        foreach($r['patterns']??[] as $p){
            $codes=$p['exclusion_reasons']??[];
            if(!empty($p['confirmed_rr_rejection']))$reasons['rr']='손익비';
            if(in_array('context_wait',$codes,true))$reasons['trend']='추세';
            if(array_intersect(['risk_blocked','blocked','spike_dump','top_collapse'],$codes))$reasons['risk']='위험 차단';
            if(!in_array('not_selected_pattern',$codes,true)){
                $missing=$p['missing_conditions']??[];
                if(isset($missing['volume_contracted'])||isset($missing['volume_recovery']))$reasons['volume']='거래량';
            }
        }
        if(!$reasons)$reasons['other']='그 외 패턴·확인·품질';
        return $reasons;
    }
    public static function evaluate(array $r,array $raw,int $asOf):array
    {
        $plan=$r['analysis']['plan'];$symbol=$r['symbol'];$session=(int)$r['session'];
        $row=['symbol'=>$symbol,'name'=>$r['name']??$symbol,'session'=>$session,'captured_at'=>$r['captured_at'],
            'source_file'=>$r['source_file'],'observation_hash'=>$r['observation_hash'],
            'rejected'=>empty($plan['ready']),'reasons'=>self::reasons($r),'final_status'=>$plan['status']??'unknown',
            'as_of'=>$asOf,'status'=>'pending','complete'=>false,'horizons'=>[],'trades'=>[]];
        if($session>$asOf || $r['captured_at']>$asOf){$row['status']='not_yet_observed';return $row;}
        // Reuse the original audit's hash, historical revision and future quality guards.
        $probe=PaperRrAudit::outcome($r,['ready'=>false],$raw,$asOf);
        if(in_array($probe['status']??'', ['input_hash_mismatch','historical_revision_or_missing','future_quality_blocked'],true)){
            $row['status']=$probe['status'];return $row;
        }
        $old=CandleClock::completed($r['bars'],$symbol,$session);
        $base=end($old)['close']??0;
        if($base<=0){$row['status']='invalid_reference_price';return $row;}
        $future=array_values(array_filter(CandleClock::completed($raw,$symbol,$asOf),fn($b)=>$b['available_at']>$session));
        if($future && $future[0]['available_at']<=$r['captured_at']){$row['status']='late_observation';return $row;}
        $row['reference_close']=$base;$row['observed_bars']=count($future);
        $row['latest_session']=$future?end($future)['available_at']:$session;
        foreach(self::HORIZONS as $n){
            $m=['status'=>'pending','return_pct'=>null,'max_up_pct'=>null,'max_down_pct'=>null];
            if(count($future)>=$n){$slice=array_slice($future,0,$n);$last=$slice[$n-1];
                $m=['status'=>'complete','session'=>$last['available_at'],'return_pct'=>($last['close']/$base-1)*100,
                    'max_up_pct'=>max(0,(max(array_column($slice,'high'))/$base-1)*100),
                    'max_down_pct'=>min(0,(min(array_column($slice,'low'))/$base-1)*100)];}
            $row['horizons'][$n]=$m;
        }
        if(!empty($plan['ready']))$row['trades'][]=['kind'=>'baseline','outcome'=>PaperRrAudit::outcome($r,$plan,$raw,$asOf)];
        foreach($r['patterns']??[] as $p)if(($p['status']??'')==='added' && is_array($p['candidate']??null))
            $row['trades'][]=['kind'=>'limit','outcome'=>PaperRrAudit::outcome($r,$p['candidate'],$raw,$asOf)];
        $terminal=true;
        foreach($row['trades'] as $t)if(!in_array($t['outcome']['status']??'', ['closed','unfilled','cancelled_before_entry'],true))$terminal=false;
        $row['complete']=count($future)>=20 && $terminal;
        $row['status']=$row['complete']?'complete':(count($future)?'pending':'no_future_bars');
        return $row;
    }
    public static function summarize(array $rows,?array $window=null):array
    {
        $out=['observations'=>0,'rejected'=>0,'statuses'=>[],'groups'=>[],'trades'=>[]];
        foreach($rows as $r){
            if($window && ($r['session']<$window['start']||$r['session']>=$window['end']))continue;
            $out['observations']++;$out['rejected']+=(int)$r['rejected'];
            $out['statuses'][$r['status']]=($out['statuses'][$r['status']]??0)+1;
            foreach($r['reasons'] as $code=>$label){
                if(!isset($out['groups'][$code]))$out['groups'][$code]=['label'=>$label,'signals'=>0,'horizons'=>[]];
                $g=&$out['groups'][$code];$g['signals']++;
                foreach(self::HORIZONS as $n){
                    if(!isset($g['horizons'][$n]))$g['horizons'][$n]=['n'=>0,'up'=>0,'down'=>0,'flat'=>0,'mean_return_pct'=>null,'mean_max_up_pct'=>null,'mean_max_down_pct'=>null];
                    $h=$r['horizons'][$n]??[];if(($h['status']??'')!=='complete')continue;
                    $s=&$g['horizons'][$n];$count=++$s['n'];$s[$h['return_pct']>0?'up':($h['return_pct']<0?'down':'flat')]++;
                    foreach(['return_pct','max_up_pct','max_down_pct'] as $k)$s['mean_'.$k]=(($s['mean_'.$k]??0)*($count-1)+$h[$k])/$count;
                    unset($s);
                }unset($g);
            }
            foreach($r['trades'] as $t){
                $k=$t['kind'];if(!isset($out['trades'][$k]))$out['trades'][$k]=['signals'=>0,'filled'=>0,'closed'=>0,'stops'=>0,'targets'=>0,'statuses'=>[],'mean_net_pct'=>null];
                $s=&$out['trades'][$k];$v=$t['outcome'];$status=$v['status']??'unknown';$s['signals']++;
                $s['statuses'][$status]=($s['statuses'][$status]??0)+1;$s['filled']+=(int)!empty($v['filled']);
                $s['stops']+=(int)!empty($v['hit_stop']);$s['targets']+=(int)!empty($v['hit_target']);
                if($status==='closed' && is_numeric($v['net_return_pct']??null)){$n=++$s['closed'];$s['mean_net_pct']=(($s['mean_net_pct']??0)*($n-1)+$v['net_return_pct'])/$n;}unset($s);
            }
        }
        return $out;
    }
    public static function load(string $dir,string $id,?array $window=null):?array
    {
        if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account');
        $p=$dir.'/followup/'.$id.'/latest.json';if(!is_file($p))return null;
        $r=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
        if(($r['schema']??0)!==1||!is_array($r['rows']??null))throw new RuntimeException('Invalid followup report');
        $r['summary']=self::summarize($r['rows'],$window);return $r;
    }
    public static function save(string $folder,array $report):void
    {
        if(!is_dir($folder)&&!mkdir($folder,0770,true)&&!is_dir($folder))throw new RuntimeException('Cannot create followup directory');
        $json=PaperRrAudit::encode($report)."\n";$tmp=tempnam($folder,'write-');
        try{if(file_put_contents($tmp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('Cannot write report');
            $history=$folder.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
            if(!copy($tmp,$history)||!rename($tmp,$folder.'/latest.json'))throw new RuntimeException('Cannot publish report');
        }finally{if(is_file($tmp))unlink($tmp);}
    }
}
