<?php
declare(strict_types=1);
require_once __DIR__.'/Followup.php';
require_once __DIR__.'/SingleConditionReview.php';

/** Saved evidence only: no strategy replay, orders, or threshold changes. */
final class PaperRejectionReview
{
    public static function load(string $dir, ?array $followup, int $days=5): array
    {
        $bundles=[];$errors=[];
        foreach(PaperRrView::files($dir) as $file){
            try{
                $b=PaperRrView::load($dir.'/'.$file);
                if(($b['membership']??'')!=='observed_scan_only')continue;
                $b['source_file']=$file;
                // Drop OHLC payload immediately; preserve the exact followup observation identity.
                foreach($b['records'] as &$r){
                    $copy=$r;$copy['source_file']=$file;
                    $copy['captured_at']=(int)($b['recorded_at']??$r['session']??0);
                    $r['observation_hash']=hash('sha256',PaperRrAudit::encode($copy));
                    unset($r['bars']);
                }unset($r);
                $bundles[]=$b;
            }catch(Throwable $e){$errors[]=['file'=>$file,'error'=>$e->getMessage()];}
        }
        $out=self::build($bundles,$followup,$days);$out['errors']=$errors;return $out;
    }

    public static function build(array $bundles, ?array $followup, int $days=5): array
    {
        $tz=new DateTimeZone('Asia/Seoul');$byDay=[];
        usort($bundles,fn($a,$b)=>strcmp($a['source_file'],$b['source_file']));
        foreach($bundles as $b){
            if(($b['membership']??'')!=='observed_scan_only'||empty($b['recorded_at']))continue;
            $date=(new DateTimeImmutable('@'.$b['recorded_at']))->setTimezone($tz)->format('Y-m-d');
            $byDay[$date][]=$b;
        }
        krsort($byDay);$byDay=array_slice($byDay,0,max(1,$days),true);ksort($byDay);
        $tracked=[];
        foreach($followup['rows']??[] as $f){
            if(!empty($f['observation_hash']))$tracked[$f['observation_hash']]=$f;
        }
        $out=['dates'=>array_keys($byDay),'rows'=>[],'final'=>[],'raw'=>[],'cross'=>[],
            'blockers'=>[],'quality'=>[],'rr'=>[],'volume_only'=>[],'duplicates'=>0,
            'followup_as_of'=>$followup['as_of']??null,'errors'=>[]];
        foreach($byDay as $date=>$runs){
            $seen=[];
            foreach($runs as $b)foreach($b['records'] as $r){
                $symbol=(string)($r['symbol']??'');
                if(isset($seen[$symbol])){$out['duplicates']++;continue;}$seen[$symbol]=true;
                $plan=$r['analysis']['plan']??[];$selected=null;
                foreach($r['patterns']??[] as $p){
                    if(!in_array('not_selected_pattern',$p['exclusion_reasons']??[],true)){
                        if($selected!==null){$selected=null;break;}$selected=$p;
                    }
                }
                $available=($r['status']??'')==='evaluated';
                $final=$available?($plan['status']??'unknown'):'unavailable';
                $raw=$selected['raw_status']??'unknown';
                $blockers=array_values(array_intersect($selected['exclusion_reasons']??[],
                    ['data_quality_blocked','stale_data','blocked','spike_dump','top_collapse','risk_blocked','context_wait']));
                $f=$tracked[$r['observation_hash']??'']??null;
                // Never attach another scan's outcome, even for the same symbol and date.
                if($f && (($f['source_file']??null)!==$b['source_file']||($f['session']??null)!==($r['session']??null)||($f['symbol']??null)!==$symbol))$f=null;
                $row=['date'=>$date,'symbol'=>$symbol,'name'=>$r['name']??$symbol,'source_file'=>$b['source_file'],
                    'session'=>$r['session']??null,'final'=>$final,'raw'=>$raw,'blockers'=>$blockers,
                    'pattern'=>$selected['pattern']??null,'missing'=>$selected['missing_conditions']??[],
                    'not_evaluated'=>$selected['not_evaluated']??[],
                    'measurements'=>$r['measurements']??[],
                    'strategy_version'=>$r['analysis']['strategy_version']??null,
                    'context_applied'=>$plan['context_applied']??null,
                    'single_condition'=>PaperSingleConditionReview::assess($r,$selected),
                    'followup'=>$f,'followup_status'=>$f['status']??'unmatched',
                    'limit_status'=>$selected['status']??null];
                $out['rows'][]=$row;self::inc($out['final'],$final);
                if(!$available){
                    $detail=(string)($r['detail']??'');
                    $kind=preg_match('/\b404\b/',$detail)?'HTTP 404':
                        ((str_contains($detail,'40')&&(str_contains($detail,'봉')||str_contains($detail,'candle')))?'일봉 40개 미만':($r['reason']??'기타 오류'));
                    self::inc($out['quality'],$kind);continue;
                }
                self::inc($out['raw'],$raw);self::inc($out['cross'],$raw.' → '.$final);
                foreach(array_unique($blockers) as $code)self::inc($out['blockers'],$code);
                if($final==='rejected_rr')$out['rr'][]=$row;
                if(($selected['pattern']??'')==='trend_pullback'
                    &&array_keys($row['missing'])===['volume_contracted'])$out['volume_only'][]=$row;
            }
        }
        $out['rr_summary']=PaperFollowup::summarize(array_values(array_filter(array_column($out['rr'],'followup'))));
        $out['single_condition_review']=PaperSingleConditionReview::summarize($out['rows']);
        return $out;
    }
    private static function inc(array &$counts,string $key):void{$counts[$key]=($counts[$key]??0)+1;}
}
