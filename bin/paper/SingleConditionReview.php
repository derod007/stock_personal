<?php
declare(strict_types=1);

/** Read-only classification of saved evidence; never a counterfactual recommendation. */
final class PaperSingleConditionReview
{
    public const GATES = [
        'rising_structure'=>'상승 구조','atr_valid'=>'ATR 유효','valid_zone'=>'유효 관심 구간',
        'near_ma20'=>'MA20 눌림','volume_contracted'=>'눌림 거래량','recovery_close'=>'직전 고점 회복',
        'bullish_candle'=>'양봉','volume_recovery'=>'거래량 회복','fresh_confirmation'=>'새 확인 신호',
    ];
    public const LABELS = [
        'rr'=>'손익비','context_wait'=>'상위 추세·추가 손익비','blocked'=>'매수 금지',
        'spike_dump'=>'급등 후 급락','top_collapse'=>'고점 붕괴','risk_blocked'=>'위험 차단 세부 미기록',
        'data_quality'=>'일봉 품질','freshness'=>'일봉 최신성','pattern'=>'패턴 확인',
    ];

    public static function assess(array $record, ?array $selected): array
    {
        $failed=[];$unknown=[];
        $check=static function(string $key,$value)use(&$failed,&$unknown):void{
            if($value===false)$failed[]=$key;
            elseif($value!==true)$unknown[]=$key;
        };
        $plan=$record['analysis']['plan']??[];
        $features=$record['analysis']['features']??[];
        $codes=$selected['exclusion_reasons']??[];
        if(in_array($plan['status']??'', ['context_wait','risk_blocked','blocked','stale_data'],true))$codes[]=$plan['status'];
        $check('data_quality',$record['quality']['can_simulate']??null);
        $check('freshness',isset($plan['asof'],$plan['data_asof'])
            ? $plan['asof']-$plan['data_asof']<=144*3600 : null);
        $action=$record['analysis']['decision']['action']??null;
        $check('blocked',is_string($action)?$action!=='blocked':null);
        $spike=$features['spike_dump_status']??null;
        $check('spike_dump',is_string($spike)?$spike==='none':null);
        $top=$features['top_pattern_status']??null;
        $topPass=null;
        if(is_string($top)){
            $topPass=!in_array($top,['warning','confirmed'],true);
            if(!$topPass && isset($features['top_pattern_phase']))$topPass=$features['top_pattern_phase']==='bounce_confirmed';
            elseif(!$topPass)$topPass=null;
        }
        $check('top_collapse',$topPass);
        // Stored independent blockers override any inconsistent pass evidence.
        foreach(['blocked','spike_dump','top_collapse'] as $key)
            if(in_array($key,$codes,true)){$failed[]=$key;$unknown=array_values(array_diff($unknown,[$key]));}
        if(in_array('risk_blocked',$codes,true) && !array_intersect($failed,['spike_dump','top_collapse']))$unknown[]='risk_blocked';
        if(in_array('stale_data',$codes,true))$failed[]='freshness';
        if(in_array('data_quality_blocked',$codes,true))$failed[]='data_quality';
        $contextPass=null;
        if(($plan['context_applied']??null)===false)$contextPass=true;
        elseif(($plan['context_applied']??null)===true){
            $d=$plan['context']['daily']??null;$w=$plan['context']['weekly']??null;
            if(is_string($d)&&is_string($w)){
                if($d==='down')$contextPass=false;
                elseif($w!=='down')$contextPass=true;
                elseif($d!=='up')$contextPass=false;
                elseif(is_numeric($plan['reward_risk']??null))$contextPass=(float)$plan['reward_risk']>=2;
            }
        }
        if(in_array('context_wait',$codes,true))$contextPass=false;
        $check('context_wait',$contextPass);
        $raw=$selected['raw_status']??null;
        if($selected===null)$unknown[]='selected_pattern';
        elseif(($selected['pattern']??'')==='trend_pullback'){
            foreach(self::GATES as $key=>$label)$check($key,$selected['gates'][$key]??null);
        }else{
            // Retest has no per-gate diagnostics. Only terminal confirmation is usable.
            $check('pattern',in_array($raw,['ready','rejected_rr'],true)?true:null);
        }
        if($raw==='rejected_rr')$check('rr',false);
        elseif($raw==='ready')$check('rr',true);
        else $check('rr',null); // Candidate RR cannot prove confirmation-time RR.
        if(!in_array($raw,['ready','rejected_rr'],true)
            && empty($selected['missing_conditions']))$unknown[]='pattern';
        foreach($selected['missing_conditions']??[] as $key=>$label)$failed[]=$key;
        foreach($selected['not_evaluated']??[] as $key=>$label){
            if($key==='detailed_gates'&&in_array($raw,['ready','rejected_rr'],true))continue;
            $unknown[]=$key;
        }
        $failed=array_values(array_unique($failed));$unknown=array_values(array_unique(array_diff($unknown,$failed)));
        $status='multiple_or_unresolved';
        if(count($failed)===1)$status=$unknown?'single_unverified':'single_verified';
        elseif(!$failed&&!$unknown&&($plan['ready']??false)===true)$status='ready_reference';
        return ['status'=>$status,'failed'=>$failed,'unknown'=>$unknown,
            'condition'=>count($failed)===1?$failed[0]:null];
    }

    public static function label(string $key):string{return self::GATES[$key]??self::LABELS[$key]??$key;}

    public static function summarize(array $rows):array
    {
        $out=['statuses'=>[],'groups'=>[],'duplicates'=>0,'unavailable'=>0,'missing_session'=>0];$seen=[];
        foreach($rows as $row){
            if(($row['final']??'')==='unavailable'){$out['unavailable']++;continue;}
            if(!is_numeric($row['session']??null)||(int)$row['session']<=0){$out['missing_session']++;continue;}
            // One symbol/decision candle across recorded days, matching the tracker.
            $key=$row['symbol'].'@'.($row['session']??'');
            if(isset($seen[$key])){$out['duplicates']++;continue;}$seen[$key]=true;
            $a=$row['single_condition'];$status=$a['status'];
            $out['statuses'][$status]=($out['statuses'][$status]??0)+1;
            if(!in_array($status,['single_verified','single_unverified','ready_reference'],true))continue;
            $version=$row['strategy_version']??null;
            $scope=['strategy_version'=>$version,'pattern'=>$row['pattern'],'context_applied'=>$row['context_applied']??null];
            $groupKey=json_encode([$scope,$status,$a['condition']],JSON_THROW_ON_ERROR);
            if(!isset($out['groups'][$groupKey]))$out['groups'][$groupKey]=[
                'scope'=>$scope,'version_known'=>is_string($version)&&$version!=='',
                'status'=>$status,'condition'=>$a['condition'],'signals'=>0,'linked'=>0,'pattern_stages'=>[],
                'tracking_statuses'=>[],'horizons'=>[],'rows'=>[]];
            $g=&$out['groups'][$groupKey];$g['signals']++;$g['rows'][]=$row;
            $stage=$row['raw']??'unknown';$g['pattern_stages'][$stage]=($g['pattern_stages'][$stage]??0)+1;
            $f=$row['followup'];$tracking=$row['followup_status'];
            $g['tracking_statuses'][$tracking]=($g['tracking_statuses'][$tracking]??0)+1;
            if($f!==null)$g['linked']++;
            foreach(PaperFollowup::HORIZONS as $n){
                $g['horizons'][$n]??=['n'=>0,'up'=>0,'mean_return_pct'=>null,'mean_max_up_pct'=>null,'mean_max_down_pct'=>null];
                $h=$f['horizons'][$n]??[];
                if(!in_array($tracking,['pending','complete','no_future_bars'],true)||($h['status']??'')!=='complete')continue;
                if(!is_numeric($h['return_pct']??null)||!is_numeric($h['max_up_pct']??null)||!is_numeric($h['max_down_pct']??null))continue;
                $s=&$g['horizons'][$n];$count=++$s['n'];$s['up']+=(int)($h['return_pct']>0);
                foreach(['return_pct','max_up_pct','max_down_pct'] as $field)
                    $s['mean_'.$field]=(($s['mean_'.$field]??0)*($count-1)+$h[$field])/$count;
                unset($s);
            }unset($g);
        }
        $out['groups']=array_values($out['groups']);return $out;
    }
}
