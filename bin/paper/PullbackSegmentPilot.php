<?php
declare(strict_types=1);
require_once __DIR__.'/ReviewCharts.php';

/**
 * 24-case pilot for marking the current pullback segment. Review material only: it reads the PR #61 package and
 * never touches a strategy file, an account, a cache or a source dataset.
 */
final class PaperPullbackSegmentPilot
{
    public const VERSION='pullback_segment_pilot_v1';
    public const SEED='pullback-segment-pilot-v1';
    public const PIVOT_SIDE=3;
    /** Cases whose engine page was already shown to the agent in earlier work. They are not candidates. */
    public const EXPOSED=['f0445c951f5182c2'];
    public const WAIT=['wait_pullback','await_confirmation'];
    public const DROP=['rejected_rr'];
    public const PLAN=[
        ['category'=>'selected','count'=>6],
        ['category'=>'waiting','count'=>3,'status_order'=>['wait_pullback','await_confirmation','wait_pullback']],
        ['category'=>'dropped','count'=>3,'status_order'=>['rejected_rr','rejected_rr','rejected_rr']],
    ];

    public static function loadJson(string $path):array
    {
        $data=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('JSON is not an object: '.$path);
        return $data;
    }

    public static function write(string $path,array $data):void
    {
        $dir=dirname($path);if(!is_dir($dir))mkdir($dir,0775,true);
        file_put_contents($path,str_replace("\r\n","\n",(string)json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT))."\n");
    }

    public static function orderKey(string $dataset,string $category,string $caseId):string
    {
        return hash('sha256',self::SEED.'|'.$dataset.'|'.$category.'|'.$caseId);
    }

    /**
     * Greedy fixed-order pick. Pass 1 wants the slot's status, a new symbol and a new month. Pass 2 drops the month rule.
     * Pass 3 drops the status rule. A shortfall is recorded, never filled from another pattern.
     * @param list<array{case_id:string,symbol:string,month:string,status:?string}> $pool
     * @return array{picked:list<array>,shortfall:int,notes:list<string>}
     */
    public static function pick(array $pool,int $count,?array $statusOrder,array &$usedSymbols,array &$usedMonths,string $dataset,string $category):array
    {
        foreach($pool as &$row)$row['order_key']=self::orderKey($dataset,$category,$row['case_id']);unset($row);
        usort($pool,fn($a,$b)=>$a['order_key']<=>$b['order_key']);
        $picked=[];$notes=[];$taken=[];
        for($slot=0;$slot<$count;$slot++){
            $want=$statusOrder[$slot]??null;$found=null;$pass=null;
            foreach([1,2,3] as $try){
                foreach($pool as $i=>$row){
                    if(isset($taken[$i])||isset($usedSymbols[$row['symbol']]))continue;
                    if($try<=2&&$want!==null&&$row['status']!==$want)continue;
                    if($try===1&&isset($usedMonths[$row['month']]))continue;
                    $found=$i;$pass=$try;break 2;
                }
            }
            if($found===null){$notes[]='slot '.($slot+1).': no candidate left';continue;}
            $row=$pool[$found];$taken[$found]=true;$usedSymbols[$row['symbol']]=true;$usedMonths[$row['month']]=true;
            if($pass===2)$notes[]='slot '.($slot+1).' '.$row['case_id'].': month already used, kept status and symbol rule';
            if($pass===3)$notes[]='slot '.($slot+1).' '.$row['case_id'].': wanted status '.$want.' unavailable, took '.($row['status']??'selected');
            $picked[]=$row+['slot'=>$slot+1,'pass'=>$pass];
        }
        return ['picked'=>$picked,'shortfall'=>$count-count($picked),'notes'=>$notes];
    }

    /** @return list<array<string,mixed>> A-only bars with indicators, as stored in the package price file */
    public static function bars(string $pack,string $caseId):array
    {
        return self::loadJson($pack.'/json/a/'.$caseId.'-input.json')['bars'];
    }

    /**
     * Confirmed pivot test for bar $i: strictly beyond the SIDE bars on each side. Confirmed at the SIDE-th right bar.
     * @return array{confirmed:bool,confirmed_at:?int,reason:?string}
     */
    public static function pivot(array $bars,int $i,string $field):array
    {
        $s=self::PIVOT_SIDE;$n=count($bars);
        if($i-$s<0)return ['confirmed'=>false,'confirmed_at'=>null,'reason'=>'left bars are missing in the price file'];
        if($i+$s>$n-1)return ['confirmed'=>false,'confirmed_at'=>null,'reason'=>'fewer than '.$s.' completed bars after it before the decision day'];
        $v=(float)$bars[$i][$field];
        for($k=$i-$s;$k<=$i+$s;$k++){
            if($k===$i)continue;
            if(!empty($bars[$k]['ohlc_invalid']))return ['confirmed'=>false,'confirmed_at'=>null,'reason'=>'an invalid candle is among the compared bars'];
            $w=(float)$bars[$k][$field];
            if($field==='high'?$w>=$v:$w<=$v)return ['confirmed'=>false,'confirmed_at'=>null,'reason'=>'a neighbouring bar within '.$s.' bars is as '.($field==='high'?'high':'low').' or more extreme'];
        }
        return ['confirmed'=>true,'confirmed_at'=>(int)$bars[$i+$s]['available_at'],'reason'=>null];
    }

    /** Confirmed pivots of both kinds in the last $span bars, plus unconfirmed extremes of the last $tail bars. */
    public static function helper(array $bars,int $span=150):array
    {
        $n=count($bars);$from=max(0,$n-$span);$piv=[];
        for($i=$from;$i<$n;$i++)foreach(['high'=>'H','low'=>'L'] as $f=>$tag){
            if(!empty($bars[$i]['ohlc_invalid']))continue;
            $p=self::pivot($bars,$i,$f);if($p['confirmed'])$piv[]=['i'=>$i,'tag'=>$tag,'date'=>$bars[$i]['date'],'price'=>$bars[$i][$f]];
        }
        return ['bars'=>$n,'first_date'=>$bars[0]['date'],'last_date'=>$bars[$n-1]['date'],'pivots'=>$piv];
    }

    public const COLORS=['S'=>'#1e8449','H'=>'#b03a2e','L'=>'#1f618d','P'=>'#7d3c98'];
    public const FIELD=['S'=>'low','H'=>'high','L'=>'low','P'=>'low'];

    /** @param list<array<string,mixed>> $bars */
    private static function index(array $bars):array
    {
        $by=[];foreach($bars as $i=>$b)$by[$b['date']]=$i;
        return $by;
    }

    /** Resolve one dated point against the A price file. Price, observation time and confirmation time come from the file. */
    private static function resolve(array $bars,array $by,string $code,string $date,?string $why,string $caseId):array
    {
        if(!isset($by[$date]))throw new RuntimeException($caseId.' '.$code.': '.$date.' is not a completed bar in the A price file');
        $i=$by[$date];$b=$bars[$i];$field=self::FIELD[$code];$n=count($bars);
        if(!empty($b['ohlc_invalid']))throw new RuntimeException($caseId.' '.$code.': '.$date.' is an invalid candle');
        $p=self::pivot($bars,$i,$field);
        return [
            'date'=>$date,'index'=>$i,'field'=>$field,'price'=>$b[$field],
            'observable_at'=>(int)$b['available_at'],
            'pivot'=>['confirmed'=>$p['confirmed'],'confirmed_at'=>$p['confirmed_at'],'confirmed_date'=>$p['confirmed']?$bars[$i+self::PIVOT_SIDE]['date']:null,'reason'=>$p['reason']],
            'bars_after'=>$n-1-$i,'in_view_120'=>$i>=$n-120,'in_view_40'=>$i>=$n-40,'why'=>$why,
        ];
    }

    /** @return array{cases:list<array>} */
    public static function annotate(string $pack,string $srcPath,string $manifestPath,string $srcSha):array
    {
        $src=self::loadJson($srcPath);$manifest=self::loadJson($manifestPath);
        $meta=[];foreach($manifest['cases'] as $c)$meta[$c['case_id']]=$c;
        $out=[];$seen=[];
        foreach($src['cases'] as $c){
            $id=$c['case_id'];
            if(!isset($meta[$id]))throw new RuntimeException($id.' is not in the sample manifest');
            if(isset($seen[$id]))throw new RuntimeException($id.' is annotated twice');$seen[$id]=true;
            $a=self::loadJson($pack.'/json/a/'.$id.'.json');$bars=self::bars($pack,$id);$by=self::index($bars);$n=count($bars);
            $session=$a['session_date'];
            if($bars[$n-1]['date']!==$session)throw new RuntimeException($id.': last bar '.$bars[$n-1]['date'].' is not the decision day '.$session);
            $pts=[];
            foreach(['S','H'] as $code){
                $p=['main'=>self::resolve($bars,$by,$code,$c[$code]['main']['date'],$c[$code]['why']??null,$id),'alternatives'=>[]];
                foreach($c[$code]['alternatives'] as $alt)$p['alternatives'][]=self::resolve($bars,$by,$code,$alt['date'],$alt['why'],$id);
                $pts[$code]=$p;
            }
            if($pts['S']['main']['index']>=$pts['H']['main']['index'])throw new RuntimeException($id.': S is not before H');
            $hi=$pts['H']['main']['index'];
            if($hi>=$n-1)throw new RuntimeException($id.': no bar after H to derive L');
            $li=null;
            for($i=$hi+1;$i<$n;$i++){
                if(!empty($bars[$i]['ohlc_invalid']))continue;
                if($li===null||(float)$bars[$i]['low']<(float)$bars[$li]['low'])$li=$i;
            }
            $pts['L']=['main'=>self::resolve($bars,$by,'L',$bars[$li]['date'],'H 다음 봉부터 판정일까지의 가장 낮은 저가. 도구가 계산한 값이다',$id),'alternatives'=>[]];
            $pm=$c['P']['main']['date']==='=L'?$bars[$li]['date']:$c['P']['main']['date'];
            $pts['P']=['main'=>self::resolve($bars,$by,'P',$pm,$c['P']['why']??null,$id),'alternatives'=>[],'same_as_L'=>$pm===$bars[$li]['date']];
            foreach($c['P']['alternatives'] as $alt)$pts['P']['alternatives'][]=self::resolve($bars,$by,'P',$alt['date'],$alt['why'],$id);
            $R=['state'=>$c['R']['status'],'why'=>$c['R']['why'],'evidence'=>[]];
            if(!in_array($R['state'],['confirmed','partial','not_confirmed','unknown'],true))throw new RuntimeException($id.': bad R state');
            foreach($c['R']['evidence'] as $e){
                if(!isset($by[$e['date']]))throw new RuntimeException($id.' R: '.$e['date'].' is not a completed bar');
                $R['evidence'][]=['date'=>$e['date'],'observable_at'=>(int)$bars[$by[$e['date']]]['available_at'],'note'=>$e['note']];
            }
            // Facts computed from the file, written next to the reading so a reader can see what the file shows.
            $hh=(float)$bars[$hi]['high'];$above=null;$closeAbove=null;
            for($i=$hi+1;$i<$n;$i++){
                if($above===null&&empty($bars[$i]['ohlc_invalid'])&&(float)$bars[$i]['high']>$hh)$above=$bars[$i]['date'];
                if($closeAbove===null&&empty($bars[$i]['ohlc_invalid'])&&(float)$bars[$i]['close']>$hh)$closeAbove=$bars[$i]['date'];
            }
            $inv=[];$zero=[];
            for($i=max(0,$n-120);$i<$n;$i++){
                if(!empty($bars[$i]['ohlc_invalid']))$inv[]=$bars[$i]['date'];
                elseif((float)$bars[$i]['volume']<=0)$zero[]=$bars[$i]['date'];
            }
            $dataNotes=[];
            if($inv)$dataNotes[]='120일 창에 비정상 봉 '.count($inv).'개: '.implode(', ',array_slice($inv,0,5));
            if($zero)$dataNotes[]='120일 창에 거래량 0인 봉 '.count($zero).'개: '.implode(', ',array_slice($zero,0,5));
            if(!empty($c['note']))$dataNotes[]=$c['note'];
            $out[]=[
                'case_id'=>$id,'symbol'=>$a['symbol'],'name'=>$a['name'],'session_date'=>$session,
                'bars_in_price_file'=>$n,'first_bar'=>$bars[0]['date'],'last_bar'=>$bars[$n-1]['date'],
                'view_120_first'=>$bars[max(0,$n-120)]['date'],'view_40_first'=>$bars[max(0,$n-40)]['date'],
                'points'=>$pts,'rebound'=>$R,'correction_state'=>$c['correction_state'],
                'ambiguity'=>['level'=>$c['ambiguity'],'why'=>$c['ambiguity_why']],
                'file_facts'=>['bars_from_H_to_decision_day'=>$n-1-$hi,'high_above_H_first_date'=>$above,'close_above_H_first_date'=>$closeAbove,
                    'L_is_decision_day'=>$li===$n-1,'decision_day_close'=>$bars[$n-1]['close'],'H_high'=>$hh],
                'data_notes'=>$dataNotes,
            ];
        }
        if(count($out)!==count($meta))throw new RuntimeException('annotated '.count($out).' cases, sample has '.count($meta));
        usort($out,fn($a,$b)=>strcmp($a['case_id'],$b['case_id']));
        return ['version'=>self::VERSION,'stage'=>'A','pivot_rule'=>'k='.self::PIVOT_SIDE.', strict, confirmed at the '.self::PIVOT_SIDE.'rd right bar (this study only)',
            'inputs'=>['annotations_src_sha256'=>$srcSha,'price_files'=>'json/a/{case_id}-input.json (bars through the decision day)','not_read'=>'B files, C files, engine code, PR #62 labels, returns'],
            'cases'=>$out];
    }

    public const COMPARE_CATEGORIES=['consistent','different_segments','chart_ambiguous','structure_ok_excluded_by_target_rr','common_risk_block','data_limit'];

    /** Extreme of a bar window by index range. Ties keep the earliest bar. */
    private static function extreme(array $bars,int $from,int $to,string $field,bool $max):array
    {
        $best=null;
        for($i=max(0,$from);$i<=$to&&$i<count($bars);$i++){
            if(!empty($bars[$i]['ohlc_invalid']))continue;
            $v=(float)$bars[$i][$field];
            if($best===null||($max?$v>$bars[$best][$field]:$v<$bars[$best][$field]))$best=$i;
        }
        return ['index'=>$best,'date'=>$bars[$best]['date'],'price'=>$bars[$best][$field]];
    }

    private static function win(array $bars,int $from,int $to):array
    {
        return ['from'=>$bars[max(0,$from)]['date'],'to'=>$bars[min($to,count($bars)-1)]['date'],'bars'=>$to-max(0,$from)+1];
    }

    /**
     * B stage. The windows are those written in src/TrendPullback.php as read after the A freeze. The ATR is not in the price
     * file, so it is recovered from the stored zone (mid-0.4ATR .. mid+0.4ATR) and every reproduction is checked against the stored values.
     */
    public static function compare(string $pack,array $ann,array $manifest,string $freezeSha):array
    {
        $meta=[];foreach($manifest['cases'] as $c)$meta[$c['case_id']]=$c;
        $out=[];$repro=['rising_structure'=>[0,0],'near_ma20'=>[0,0],'volume_contracted'=>[0,0],'target'=>[0,0],'stop'=>[0,0],'zone_mid'=>[0,0]];
        foreach($ann['cases'] as $c){
            $id=$c['case_id'];$b=self::loadJson($pack.'/json/b/'.$id.'.json');$bars=self::bars($pack,$id);$n=count($bars);
            $pts=$c['points'];
            $recent=[$n-20,$n-1];$prior=[$n-40,$n-21];
            $rMin=self::extreme($bars,$recent[0],$recent[1],'low',false);$rMax=self::extreme($bars,$recent[0],$recent[1],'high',true);
            $pMin=self::extreme($bars,$prior[0],$prior[1],'low',false);$pMax=self::extreme($bars,$prior[0],$prior[1],'high',true);
            $closes=array_map(fn($x)=>(float)$x['close'],$bars);
            $ma=array_sum(array_slice($closes,-20))/20;$oldMa=array_sum(array_slice($closes,-25,20))/20;
            $up=$ma>$oldMa&&(float)$rMin['price']>(float)$pMin['price']&&(float)$rMax['price']>(float)$pMax['price'];
            $nearWin=[$n-6,$n-2];$nearLow=self::extreme($bars,$nearWin[0],$nearWin[1],'low',false);
            $stopWin=[$n-10,$n-1];$stopLow=self::extreme($bars,$stopWin[0],$stopWin[1],'low',false);
            $tgtWin=[$n-21,$n-2];$tgtHigh=self::extreme($bars,$tgtWin[0],$tgtWin[1],'high',true);
            $baseWin=[$n-24,$n-5];$pullWin=[$n-4,$n-2];
            $base=0;for($i=$baseWin[0];$i<=$baseWin[1];$i++)$base+=(float)$bars[$i]['volume'];$base/=20;
            $pull=0;for($i=$pullWin[0];$i<=$pullWin[1];$i++)$pull+=(float)$bars[$i]['volume'];$pull/=3;
            $cand=$b['structure']['candidate']??null;$atr=null;$checks=[];
            $gates=$b['gates']??[];
            if(isset($gates['rising_structure'])){$ok=$up===(bool)$gates['rising_structure'];$repro['rising_structure'][$ok?0:1]++;$checks['rising_structure']=$ok;}
            if($cand!==null){
                $atr=((float)$cand['high']-(float)$cand['low'])/0.8;$tol=2.5;
                $okMid=abs((float)$cand['mid']-$ma)<=2.0;$repro['zone_mid'][$okMid?0:1]++;$checks['zone_mid']=$okMid;
                $okT=(float)$cand['target']===floor((float)$tgtHigh['price']);$repro['target'][$okT?0:1]++;$checks['target']=$okT;
                $okS=abs((float)$cand['stop']-((float)$stopLow['price']-0.2*$atr))<=$tol;$repro['stop'][$okS?0:1]++;$checks['stop']=$okS;
                if(isset($gates['near_ma20'])){$near=(float)$nearLow['price']<=$ma+0.75*$atr;$ok=$near===(bool)$gates['near_ma20'];$repro['near_ma20'][$ok?0:1]++;$checks['near_ma20']=$ok;}
                if(isset($gates['volume_contracted'])){$dry=$base>0&&$pull<$base*0.85;$ok=$dry===(bool)$gates['volume_contracted'];$repro['volume_contracted'][$ok?0:1]++;$checks['volume_contracted']=$ok;}
            }
            $H=$pts['H']['main'];$L=$pts['L']['main'];
            $hDates=array_merge([$H['date']],array_column($pts['H']['alternatives'],'date'));
            $lDates=array_merge([$L['date']],array_column($pts['P']['alternatives'],'date'),[$pts['P']['main']['date']]);
            $tMain=$tgtHigh['date']===$H['date'];$sMain=$stopLow['date']===$L['date'];
            $tAny=in_array($tgtHigh['date'],$hDates,true);$sAny=in_array($stopLow['date'],$lDates,true);
            $pstat=$b['pattern_status'];$blockers=$b['independent_blockers']??[];
            $dataLimit=!empty($b['quality_reasons'])||$cand===null||($c['data_notes']!==[]&&self::notesTouchWindows($c,$bars,$n));
            $matchAny=$tAny&&$sAny;$matchMain=$tMain&&$sMain;
            if($dataLimit)$primary='data_limit';
            elseif($pstat==='ready'&&$blockers)$primary='common_risk_block';
            elseif($pstat==='rejected_rr'&&$matchAny)$primary='structure_ok_excluded_by_target_rr';
            elseif($matchMain)$primary='consistent';
            elseif($matchAny||$c['ambiguity']['level']==='high')$primary='chart_ambiguous';
            else $primary='different_segments';
            $tags=[];
            if($blockers)$tags[]='common_risk_block';
            if($pstat==='rejected_rr'&&$primary!=='structure_ok_excluded_by_target_rr')$tags[]='excluded_by_target_rr';
            if(!$tMain)$tags[]='target_ref_not_A_H';
            if(!$sMain)$tags[]='stop_ref_not_A_L';
            $inWin=fn(int $idx,array $w)=>$idx>=$w[0]&&$idx<=$w[1];
            $sIdx=$pts['S']['main']['index'];$hIdx=$H['index'];
            $pct=fn(float $a,float $b2)=>$b2>0?round(($a-$b2)/$b2*100,1):null;
            $fmt=fn($v)=>number_format((float)$v,0,'.',',');
            $notes=[];
            if(!$tMain)$notes[]='목표 기준 고점 '.$tgtHigh['date'].'('.$fmt($tgtHigh['price']).')은 A의 H '.$H['date'].'('.$fmt($H['price']).')와 날짜가 다르다.';
            if(!$sMain)$notes[]='손절 기준 저점 '.$stopLow['date'].'('.$fmt($stopLow['price']).')은 A의 L '.$L['date'].'('.$fmt($L['price']).')와 날짜가 다르다.';
            $stopBeforeH=$stopLow['index']<$hIdx;
            if($stopBeforeH)$notes[]='손절 기준 저점 '.$stopLow['date'].'은 A의 H '.$H['date'].'보다 앞이다. 코드의 10봉 창이 눌림이 아니라 그 앞의 상승 구간 저점을 잡았다.';
            foreach(['손절'=>$stopLow,'목표'=>$tgtHigh] as $kind=>$ref)if((float)$bars[$ref['index']]['volume']<=0)$notes[]=$kind.' 기준 봉 '.$ref['date'].'의 거래량이 0이다. 가격 파일 한계로 읽는다.';
            if(!$inWin($L['index'],$stopWin))$notes[]='A의 L '.$L['date'].'은 코드의 손절 기준 창(최근 10봉, '.$bars[$stopWin[0]]['date'].'부터) 밖이다. 창이 눌림 구간보다 짧다.';
            if(!$inWin($hIdx,$tgtWin))$notes[]='A의 H '.$H['date'].'는 목표 기준 창('.$bars[max(0,$tgtWin[0])]['date'].'~'.$bars[$tgtWin[1]]['date'].') 밖이다.';
            // High breakout, close breakout and target<=entry are three separate facts. The target is the highest high of the 20 bars
            // before the last bar and the entry is the decision-day close, so target<=entry needs the CLOSE at or above that reference, not the high.
            $dHigh=(float)$bars[$n-1]['high'];$dClose=(float)$bars[$n-1]['close'];$hh=(float)$H['price'];$ref=(float)$tgtHigh['price'];
            $firstHigh=null;$firstClose=null;
            for($j=$hIdx+1;$j<$n;$j++){
                if(!empty($bars[$j]['ohlc_invalid']))continue;
                if($firstHigh===null&&(float)$bars[$j]['high']>$hh)$firstHigh=$bars[$j]['date'];
                if($firstClose===null&&(float)$bars[$j]['close']>$hh)$firstClose=$bars[$j]['date'];
            }
            $hasPlan=$b['entry']!==null;
            $entryVal=$hasPlan?(float)$b['entry']:floor($dClose);
            $targetVal=$hasPlan?(float)$b['target']:(float)($cand['target']??floor($ref));
            $closeAtRef=floor($dClose)>=floor($ref);
            $breakout=[
                'A_H'=>['date'=>$H['date'],'high'=>$hh,
                    'high_breakout'=>['any_after_H'=>$firstHigh!==null,'first_date'=>$firstHigh,'on_decision_day'=>$dHigh>$hh],
                    'close_breakout'=>['any_after_H'=>$firstClose!==null,'first_date'=>$firstClose,'on_decision_day'=>$dClose>$hh]],
                'target_reference'=>['date'=>$tgtHigh['date'],'high'=>$ref,'window'=>'20 bars before the last bar, decision day excluded',
                    'decision_high_above_ref'=>$dHigh>$ref,'decision_close_above_ref'=>$dClose>$ref,'decision_close_at_or_above_ref_after_truncation'=>$closeAtRef],
                'target_vs_entry'=>['basis'=>$hasPlan?'stored_entry':'decision_close_no_entry_plan','entry_or_close'=>$entryVal,'target'=>$targetVal,
                    'target_le_entry'=>$targetVal<=$entryVal,
                    'note'=>$hasPlan?'저장된 진입가와 목표가를 비교한 값':'실제 진입 계획이 없어 당일 종가(소수점 버림)와 후보 목표가를 비교한 값'],
                'high_above_ref_but_close_not'=>$dHigh>$ref&&!$closeAtRef,
            ];
            if($dHigh>$ref)$notes[]='판정일 고가 '.$fmt($dHigh).'가 목표 기준 최고 '.$fmt($ref).'를 넘었다(고가 돌파).';
            if($dHigh>$ref&&!$closeAtRef)$notes[]='종가 '.$fmt($dClose).'는 목표 기준 아래라 목표가 '.($hasPlan?'진입가':'당일 종가').'보다 높다. 고가 돌파만으로 목표가 진입가 이하가 되지 않는다.';
            if($targetVal<=$entryVal)$notes[]='목표가 '.$fmt($targetVal).'가 '.($hasPlan?'저장된 진입가':'당일 종가(실제 진입 계획 없음)').' '.$fmt($entryVal).' 이하이다. 종가가 목표 기준 이상인 경우다.';
            if($firstHigh!==null||$firstClose!==null)$notes[]='A의 H '.$H['date'].' 이후 고가 돌파 '.($firstHigh??'없음').' · 종가 돌파 '.($firstClose??'없음').'.';
            if($blockers)$notes[]='공통 차단 사유: '.implode(', ',$blockers).' → 최종 상태 '.$b['final_status'].'.';
            if($b['selected_pattern']!=='trend_pullback')$notes[]='이 날의 최종 계획은 '.$b['selected_pattern'].'이다. 눌림 패턴 자체 상태는 '.$pstat.'.';
            if(!empty($c['correction_state'])&&$c['correction_state']==='possibly_ended'&&$pstat!=='ready')$notes[]='A는 눌림이 끝났을 수 있다고 읽었다.';
            $out[]=[
                'case_id'=>$id,'symbol'=>$c['symbol'],'name'=>$c['name'],'session_date'=>$c['session_date'],
                'category'=>$meta[$id]['category'],'dataset'=>$meta[$id]['dataset'],
                'pattern_status'=>$pstat,'final_status'=>$b['final_status'],'selected_pattern'=>$b['selected_pattern'],
                'pullback_gates'=>$gates,'common_block_reasons'=>$blockers,'pattern_blockers'=>$b['pattern_blockers']['trend_pullback']??[],
                'stored'=>['entry'=>$b['entry'],'stop'=>$b['stop'],'target'=>$b['target'],'reward_risk'=>$b['reward_risk'],'zone_low'=>$cand['low']??null,'zone_high'=>$cand['high']??null,'candidate_stop'=>$cand['stop']??null,'candidate_target'=>$cand['target']??null],
                'explicit_start_coordinate'=>'명시적 시작 좌표 없음',
                'package_says'=>['pullback_start'=>$b['structure']['pullback_start']??null,'coordinates_missing'=>$b['coordinates_missing']??[]],
                'windows'=>[
                    'trend_recent_20'=>self::win($bars,$recent[0],$recent[1])+['low'=>$rMin,'high'=>$rMax],
                    'trend_prior_20'=>self::win($bars,$prior[0],$prior[1])+['low'=>$pMin,'high'=>$pMax],
                    'near_5_bars_before_last'=>self::win($bars,$nearWin[0],$nearWin[1])+['low'=>$nearLow],
                    'stop_last_10_bars'=>self::win($bars,$stopWin[0],$stopWin[1])+['low'=>$stopLow],
                    'target_20_bars_before_last'=>self::win($bars,$tgtWin[0],$tgtWin[1])+['high'=>$tgtHigh],
                    'volume_base_20'=>self::win($bars,$baseWin[0],$baseWin[1])+['mean'=>round($base)],
                    'volume_pullback_3'=>self::win($bars,$pullWin[0],$pullWin[1])+['mean'=>round($pull),'ratio_to_base'=>$base>0?round($pull/$base,3):null],
                ],
                'atr_recovered_from_zone'=>$atr!==null?round($atr,1):null,
                'reproduction'=>$checks,
                'against_A'=>[
                    'target_ref_date_is_A_H'=>$tMain,'target_ref_date_is_A_H_or_alternative'=>$tAny,'target_ref_vs_A_H_price_pct'=>$pct((float)$tgtHigh['price'],(float)$H['price']),
                    'stop_ref_date_is_A_L'=>$sMain,'stop_ref_date_is_A_L_or_P_candidate'=>$sAny,'stop_ref_low_vs_A_L_price_pct'=>$pct((float)$stopLow['price'],(float)$L['price']),
                    'A_S_inside_trend_windows'=>$inWin($sIdx,[$n-40,$n-1]),'A_H_inside_target_window'=>$inWin($hIdx,$tgtWin),'A_H_is_decision_window_last_bar'=>$hIdx===$n-1,
                    'A_L_inside_stop_window'=>$inWin($L['index'],$stopWin),'stop_ref_before_A_H'=>$stopBeforeH,'decision_day_high_is_outside_target_window_and_higher'=>(float)$bars[$n-1]['high']>(float)$tgtHigh['price'],
                ],
                'primary_category'=>$primary,'tags'=>$tags,'notes'=>$notes,'breakout_target'=>$breakout,
            ];
        }
        $sum=['cases'=>count($out),'primary'=>[],'target_ref_is_A_H'=>0,'target_ref_is_A_H_or_alt'=>0,'stop_ref_is_A_L'=>0,'stop_ref_is_A_L_or_P'=>0,
            'A_L_outside_stop_window'=>0,'A_H_outside_target_window'=>0,'decision_day_high_above_target_ref'=>0,'with_common_block'=>0,'stop_ref_before_A_H'=>0];
        foreach($out as $o){
            $a=$o['against_A'];$sum['primary'][$o['primary_category']]=($sum['primary'][$o['primary_category']]??0)+1;
            $sum['target_ref_is_A_H']+=(int)$a['target_ref_date_is_A_H'];$sum['target_ref_is_A_H_or_alt']+=(int)$a['target_ref_date_is_A_H_or_alternative'];
            $sum['stop_ref_is_A_L']+=(int)$a['stop_ref_date_is_A_L'];$sum['stop_ref_is_A_L_or_P']+=(int)$a['stop_ref_date_is_A_L_or_P_candidate'];
            $sum['A_L_outside_stop_window']+=(int)!$a['A_L_inside_stop_window'];$sum['A_H_outside_target_window']+=(int)!$a['A_H_inside_target_window'];
            $sum['decision_day_high_above_target_ref']+=(int)$a['decision_day_high_is_outside_target_window_and_higher'];$sum['with_common_block']+=(int)($o['common_block_reasons']!==[]);$sum['stop_ref_before_A_H']+=(int)$a['stop_ref_before_A_H'];
        }
        $bs=['high_breakout_of_A_H_on_decision_day'=>0,'close_breakout_of_A_H_on_decision_day'=>0,'high_breakout_of_A_H_any_day_after_H'=>0,'close_breakout_of_A_H_any_day_after_H'=>0,
            'decision_high_above_target_ref'=>0,'decision_close_above_target_ref'=>0,'high_above_ref_but_close_not'=>0,
            'target_le_entry_with_stored_plan'=>0,'plans'=>0,'target_le_close_without_plan'=>0,'no_plan'=>0,'target_le_entry_matches_close_at_or_above_ref'=>0];
        foreach($out as $o){
            $t=$o['breakout_target'];
            $bs['high_breakout_of_A_H_on_decision_day']+=(int)$t['A_H']['high_breakout']['on_decision_day'];$bs['close_breakout_of_A_H_on_decision_day']+=(int)$t['A_H']['close_breakout']['on_decision_day'];
            $bs['high_breakout_of_A_H_any_day_after_H']+=(int)$t['A_H']['high_breakout']['any_after_H'];$bs['close_breakout_of_A_H_any_day_after_H']+=(int)$t['A_H']['close_breakout']['any_after_H'];
            $bs['decision_high_above_target_ref']+=(int)$t['target_reference']['decision_high_above_ref'];$bs['decision_close_above_target_ref']+=(int)$t['target_reference']['decision_close_above_ref'];
            $bs['high_above_ref_but_close_not']+=(int)$t['high_above_ref_but_close_not'];
            if($t['target_vs_entry']['basis']==='stored_entry'){$bs['plans']++;$bs['target_le_entry_with_stored_plan']+=(int)$t['target_vs_entry']['target_le_entry'];}
            else{$bs['no_plan']++;$bs['target_le_close_without_plan']+=(int)$t['target_vs_entry']['target_le_entry'];}
            $bs['target_le_entry_matches_close_at_or_above_ref']+=(int)($t['target_vs_entry']['target_le_entry']===$t['target_reference']['decision_close_at_or_above_ref_after_truncation']);
        }
        $sum['breakout_target']=$bs;
        return ['version'=>self::VERSION,'stage'=>'B','summary'=>$sum,'a_freeze_commit_note'=>'annotations were frozen before this file was written','a_freeze_sha256'=>$freezeSha,
            'code_read'=>'src/TrendPullback.php (windows, gates, stop, target), B json of the 24 cases. C files were not opened.',
            'reproduction_totals'=>array_map(fn($p)=>['matched'=>$p[0],'mismatched'=>$p[1]],$repro),
            'rule'=>[
                'primary_order'=>'data_limit, then structure_ok_excluded_by_target_rr (pattern status rejected_rr and both reference dates are A main or alternative dates), then consistent (both reference dates are A main dates), then chart_ambiguous (reference dates are A alternatives, or A ambiguity high), else different_segments. common_risk_block is a primary category only when the pattern itself was ready; otherwise it is a tag.',
                'reference_dates'=>'target reference = date of the highest high in the 20 bars before the last bar; stop reference = date of the lowest low in the last 10 bars. These are window extremes, not intended structural points.',
                'no_bug_claim'=>'A difference between a window extreme and an A mark is a difference of definition. No source or spec states the intended start of the segment.',
            ],
            'cases'=>$out];
    }

    public const CATLABEL=['consistent'=>'일치','different_segments'=>'구간이 다름','chart_ambiguous'=>'차트가 모호','structure_ok_excluded_by_target_rr'=>'구조는 맞으나 목표/손익비로 제외','common_risk_block'=>'공통 위험 차단','data_limit'=>'자료 한계'];
    public const SAMPLELABEL=['selected'=>'눌림이 최종 선택','waiting'=>'눌림 자체 상태가 대기','dropped'=>'눌림 자체 상태가 탈락(rejected_rr)'];
    public const PATLABEL=['wait_pullback'=>'눌림 대기','await_confirmation'=>'반등 확인 대기','rejected_rr'=>'확인 후 손익비 탈락','ready'=>'준비'];

    private static function reasonKo(?string $r):string
    {
        if($r===null)return '';
        if(str_contains($r,'fewer than'))return '판정일까지 오른쪽 완료 봉이 3개 미만';
        if(str_contains($r,'neighbouring'))return '3봉 안에 같거나 더 극단적인 봉이 있음';
        if(str_contains($r,'left bars'))return '왼쪽 봉이 가격 파일에 없음';
        return '비교 봉에 비정상 봉이 있음';
    }

    private static function e(mixed $s):string
    {
        return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    private static function pointRow(string $code,string $name,array $p,string $session,bool $alt=false):string
    {
        $conf=$p['pivot']['confirmed']?'확정 '.$p['pivot']['confirmed_date']:'관찰만 · '.self::reasonKo($p['pivot']['reason']);
        $c=self::COLORS[$code];$e=fn($s)=>self::e($s);
        return '<tr'.($alt?' class="alt"':'').'><td><b style="color:'.$c.'">'.$e($name).'</b></td><td>'.$e($p['date']).'</td><td class="num">'.$e(PaperReviewCharts::num((float)$p['price'])).'</td><td>'.$e($p['date']).' 마감</td><td>'.$e($conf).'</td><td>'.$e($p['why']).'</td></tr>';
    }

    /** Offline first screen: A annotations first, the code comparison collapsed inside each case. */
    public static function html(array $ann,array $cmp,array $manifest,array $freeze,array $a2,array $re):string
    {
        $e=fn($s)=>self::e($s);$cm=[];foreach($cmp['cases'] as $c)$cm[$c['case_id']]=$c;
        $a2m=[];foreach($a2['cases'] as $c)$a2m[$c['case_id']]=$c;$rem=[];foreach($re['cases'] as $c)$rem[$c['case_id']]=$c;
        $mf=[];foreach($manifest['cases'] as $c)$mf[$c['case_id']]=$c;
        $h=[];
        $h[]='<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>눌림 구간 표시 24건</title><style>
body{font-family:"Malgun Gothic","Apple SD Gothic Neo","Noto Sans CJK KR",sans-serif;margin:0;background:#f4f5f7;color:#222;line-height:1.5}
main{max-width:1020px;margin:0 auto;padding:16px}
h1{font-size:20px;margin:8px 0}h2{font-size:17px;margin:0 0 6px}
.note{background:#fff;border:1px solid #d5d8dc;border-radius:6px;padding:10px 14px;margin:10px 0;font-size:14px}
.card{background:#fff;border:1px solid #d5d8dc;border-radius:6px;padding:12px 14px;margin:14px 0}
.chips span{display:inline-block;border:1px solid #bbb;border-radius:12px;padding:1px 9px;margin:0 6px 4px 0;font-size:12px;background:#fafafa}
table{border-collapse:collapse;width:100%;font-size:13px;margin:6px 0}th,td{border:1px solid #e1e4e8;padding:3px 7px;text-align:left;vertical-align:top}th{background:#f0f2f4}
td.num{text-align:right;white-space:nowrap}tr.alt td{color:#555;background:#fafafa}
img{max-width:100%;height:auto;display:block;margin:6px 0;border:1px solid #eee}
details{margin:8px 0}summary{cursor:pointer;font-weight:bold;font-size:14px}
.cmp{background:#f8f9fb;border:1px solid #e1e4e8;border-radius:4px;padding:8px 10px}
nav{font-size:12px;line-height:1.9}nav a{margin-right:8px;text-decoration:none;color:#1f618d}
.small{font-size:12px;color:#555}
</style></head><body><main>';
        $h[]='<h1>상승 눌림 현재 구간 표시 24건 (A 표시 먼저, 코드 대조는 접어 둠)</h1>';
        $h[]='<div class="note">각 카드는 <b>판정일까지의 완료 봉만</b> 보고 표시한 S(시작 후보) · H(눌림이 시작된 고점) · L(관찰 최저점) · P(지지 후보)와 재상승 근거입니다. 사후 가격 결과와 성과 수치는 넣지 않았습니다. 표시는 <b>'.$e($freeze['frozen_at_kst']).'(한국 시간)</b>에 고정했고 이후 바꾸지 않았습니다. 코드 대조는 고정 뒤에 쓴 것이며 각 카드 안의 접힌 칸에 있습니다. ●는 오른쪽 3봉으로 확정된 피벗, ○는 관찰만, □는 대안입니다.</div>';
        $h[]='<details class="note"><summary>읽는 방법과 한계</summary><p>이 표본은 시장 대표 표본이 아니고, 표시자는 각 사례의 분류와 종목명을 알고 있었으므로 완전한 블라인드가 아닙니다. 확정 피벗은 이번 연구의 해석(k=3)이며 원문 규칙이 아닙니다. 눌림이 끝났다고 단정하지 않고 상태를 "진행 중으로 봄 / 끝났을 수 있음 / 알 수 없음"으로만 적었습니다. 자세한 기준은 <a href="protocol.md">protocol.md</a>, 검증은 <a href="verify.md">verify.md</a>, 공통 쟁점은 <a href="review.md">review.md</a>.</p></details>';
        $nav=[];foreach($ann['cases'] as $k=>$c)$nav[]='<a href="#c'.$k.'">'.($k+1).' '.$e($c['name']).'</a>';
        $h[]='<nav>'.implode('',$nav).'</nav>';
        foreach($ann['cases'] as $k=>$c){
            $id=$c['case_id'];$pts=$c['points'];$x=$cm[$id];
            $h[]='<section class="card" id="c'.$k.'"><h2>'.($k+1).'. '.$e($c['name']).' <span class="small">'.$e($c['symbol']).' · 판정일 '.$e($c['session_date']).' · '.$e($id).'</span></h2>';
            $h[]='<div class="chips"><span>눌림 상태(A): '.$e(self::STATELABEL[$c['correction_state']]).'</span><span>재상승 근거: '.$e(self::RLABEL[$c['rebound']['state']]).'</span><span>모호함: '.$e(self::AMBLABEL[$c['ambiguity']['level']]).'</span></div>';
            $h[]='<img src="charts/'.$e($id).'-120.svg" alt="'.$e($c['name']).' 120거래일 A 표시" loading="lazy">';
            $h[]='<table><tr><th>표시</th><th>날짜</th><th class="num">가격</th><th>관찰 가능 시점</th><th>확정</th><th>읽은 이유</th></tr>';
            $names=['S'=>'S 시작 후보','H'=>'H 눌림 시작 고점','L'=>'L 관찰 최저점','P'=>'P 지지 후보'];
            foreach(['S','H','L','P'] as $code){
                $h[]=self::pointRow($code,$names[$code],$pts[$code]['main'],$c['session_date']);
                foreach($pts[$code]['alternatives'] as $j=>$a)$h[]=self::pointRow($code,$code.'-'.chr(97+$j).' 대안',$a,$c['session_date'],true);
            }
            $h[]='</table>';
            $ev=[];foreach($c['rebound']['evidence'] as $v)$ev[]=$e($v['date']).' · '.$e($v['note']);
            $h[]='<p><b>재상승 근거 ('.$e(self::RLABEL[$c['rebound']['state']]).')</b>: '.$e($c['rebound']['why']).($ev?'<br><span class="small">'.implode('<br>',$ev).'</span>':'').'</p>';
            $h[]='<p><b>모호한 점</b> ('.$e(self::AMBLABEL[$c['ambiguity']['level']]).'): '.$e($c['ambiguity']['why']).'</p>';
            if($c['data_notes'])$h[]='<p class="small">자료 메모: '.$e(implode(' / ',$c['data_notes'])).'</p>';
            $h[]='<img src="charts/'.$e($id).'-40.svg" alt="'.$e($c['name']).' 40거래일 보조" loading="lazy">';
            // Code comparison, collapsed.
            $w=$x['windows'];$a=$x['against_A'];
            $h[]='<details><summary>코드 대조 (A 고정 뒤 작성) · '.$e(self::CATLABEL[$x['primary_category']]).'</summary><div class="cmp">';
            $h[]='<p>표본 구분: '.$e(self::SAMPLELABEL[$mf[$id]['category']]).' · 눌림 자체 상태: <b>'.$e($x['pattern_status']).'</b> ('.$e(self::PATLABEL[$x['pattern_status']]??'').') · 그날 최종 상태: '.$e($x['final_status']).' · 최종 계획 패턴: '.$e($x['selected_pattern']).'</p>';
            $h[]='<p>공통 차단 사유: '.($x['common_block_reasons']?$e(implode(', ',$x['common_block_reasons'])):'없음').' · 눌림 자체 차단: '.($x['pattern_blockers']?$e(implode(', ',$x['pattern_blockers'])):'없음').'</p>';
            $h[]='<p><b>구간 시작 좌표: '.$e($x['explicit_start_coordinate']).'</b> <span class="small">(패키지 기록: pullback_start='.$e($x['package_says']['pullback_start']).')</span></p>';
            $h[]='<table><tr><th>코드 창</th><th>기간</th><th>창 안 극값</th><th>A 표시와 비교</th></tr>';
            $fmt=fn($v)=>PaperReviewCharts::num((float)$v);
            $row=function(string $name,array $win,string $kind,string $extra) use($e,$fmt){
                $ex=$win[$kind];return '<tr><td>'.$e($name).'</td><td>'.$e($win['from']).' ~ '.$e($win['to']).' ('.$win['bars'].'봉)</td><td>'.($kind==='high'?'최고 ':'최저 ').$e($ex['date']).' · '.$e($fmt($ex['price'])).'</td><td>'.$extra.'</td></tr>';
            };
            $H=$pts['H']['main'];$L=$pts['L']['main'];$S=$pts['S']['main'];
            $h[]=$row('목표 기준(마지막 봉 제외 20봉)',$w['target_20_bars_before_last'],'high',$a['target_ref_date_is_A_H']?'A의 H와 같은 날':'A의 H '.$e($H['date']).' · '.$e($fmt($H['price'])).'와 다름');
            $h[]=$row('손절 기준(최근 10봉)',$w['stop_last_10_bars'],'low',$a['stop_ref_date_is_A_L']?'A의 L과 같은 날':'A의 L '.$e($L['date']).' · '.$e($fmt($L['price'])).'와 다름'.($a['A_L_inside_stop_window']?'':' · A의 L이 창 밖').($a['stop_ref_before_A_H']?' · A의 H보다 앞':''));
            $h[]=$row('추세 비교: 최근 20봉',$w['trend_recent_20'],'high','A의 H '.$e($H['date']).'는 이 창 안: '.($H['date']>=$w['trend_recent_20']['from']?'예':'아니오'));
            $h[]=$row('추세 비교: 직전 20봉',$w['trend_prior_20'],'low','A의 S '.$e($S['date']).'는 최근 40봉 안: '.($a['A_S_inside_trend_windows']?'예':'아니오'));
            $h[]=$row('근접 확인(판정일 직전 5봉)',$w['near_5_bars_before_last'],'low','MA20+0.75ATR 이하인지 보는 창');
            $v=$w['volume_pullback_3'];
            $h[]='<tr><td>거래량 확인</td><td>'.$e($v['from']).' ~ '.$e($v['to']).' (3봉) vs '.$e($w['volume_base_20']['from']).' ~ '.$e($w['volume_base_20']['to']).' (20봉)</td><td>비율 '.$e($v['ratio_to_base']).'</td><td>코드 기준 0.85 미만이면 감소로 봄</td></tr>';
            $h[]='</table>';
            $g=[];foreach($x['pullback_gates'] as $name=>$val)$g[]=$e($name).'='.($val===true?'예':($val===false?'아니오':$e(json_encode($val))));
            $h[]='<p class="small">눌림 코드 게이트: '.implode(' · ',$g).'</p>';
            $s=$x['stored'];
            $h[]='<p class="small">저장된 값(가격 수준): 진입 '.$e($s['entry']??'-').' · 손절 '.$e($s['candidate_stop']??'-').' · 목표 '.$e($s['candidate_target']??'-').' · 손익비 '.$e($s['reward_risk']??'-').' · 후보 구간 '.$e($s['zone_low']??'-').'~'.$e($s['zone_high']??'-').'</p>';
            $bt=$x['breakout_target'];$yn=fn($v)=>$v?'예':'아니오';$ah=$bt['A_H'];$tr=$bt['target_reference'];$tv=$bt['target_vs_entry'];
            $h[]='<table><tr><th colspan="3">돌파와 목표의 관계 (세 항목은 따로 계산)</th></tr>';
            $h[]='<tr><td>A의 H '.$e($ah['date']).' · '.$e($fmt($ah['high'])).' 고가 돌파</td><td>판정일 '.$yn($ah['high_breakout']['on_decision_day']).'</td><td>H 이후 처음 '.$e($ah['high_breakout']['first_date']??'없음').'</td></tr>';
            $h[]='<tr><td>A의 H 종가 돌파</td><td>판정일 '.$yn($ah['close_breakout']['on_decision_day']).'</td><td>H 이후 처음 '.$e($ah['close_breakout']['first_date']??'없음').'</td></tr>';
            $h[]='<tr><td>목표 기준 '.$e($tr['date']).' · '.$e($fmt($tr['high'])).' (판정일 제외 20봉 최고)</td><td>판정일 고가 초과 '.$yn($tr['decision_high_above_ref']).'</td><td>판정일 종가 초과 '.$yn($tr['decision_close_above_ref']).'</td></tr>';
            $h[]='<tr><td>목표가 ≤ 진입 기준: 목표 '.$e($fmt($tv['target'])).' / '.($tv['basis']==='stored_entry'?'저장된 진입가':'당일 종가(실제 진입 계획 없음)').' '.$e($fmt($tv['entry_or_close'])).'</td><td colspan="2"><b>'.$yn($tv['target_le_entry']).'</b> <span class="small">'.$e($tv['note']).'</span></td></tr></table>';
            $h[]='<ul>';foreach($x['notes'] as $n)$h[]='<li>'.$e($n).'</li>';
            $h[]='</ul>';
            if($x['tags'])$h[]='<p class="small">태그: '.$e(implode(', ',$x['tags'])).'</p>';
            $h[]='</div></details>';
            // Follow-up review, collapsed. Written after B was seen, so it is not blind.
            $o=$a2m[$id];$r=$rem[$id];
            $h[]='<details><summary>추가 검토 (B 확인 뒤, 블라인드 아님) · 큰 조정 시작 고점과 최근 재하락 시작 고점'.($r['in_13']?' · 손절 기준 재비교 대상':'').'</summary><div class="cmp">';
            $h[]='<img src="charts-a2/'.$e($id).'-120.svg" alt="'.$e($c['name']).' 추가 검토 120거래일" loading="lazy">';
            $stl=['marked'=>'표시','hold'=>'보류','none'=>'없음'];
            $h[]='<table><tr><th>구분</th><th>상태</th><th>날짜</th><th class="num">가격</th><th>확정</th><th>읽은 이유</th></tr>';
            $pr=function(string $name,string $state,?array $p,string $why) use($e){
                if($p===null)return '<tr><td>'.$e($name).'</td><td>'.$e($state).'</td><td colspan="3">-</td><td>'.$e($why).'</td></tr>';
                $conf=$p['pivot']['confirmed']?'확정 '.$p['pivot']['confirmed_date']:'관찰만 · '.self::reasonKo($p['pivot']['reason']);
                return '<tr><td>'.$e($name).'</td><td>'.$e($state).'</td><td>'.$e($p['date']).'</td><td class="num">'.$e(PaperReviewCharts::num((float)$p['price'])).'</td><td>'.$e($conf).'</td><td>'.$e($why).'</td></tr>';
            };
            $h[]=$pr('큰 조정 시작 고점 (H_big)',$stl[$o['H_big']['status']],$o['H_big']['point'],$o['H_big']['why']);
            $h[]=$pr('최근 재하락 시작 고점 (H_recent)',$stl[$o['H_recent']['status']].($o['H_recent']['relation_to_H_big']==='same'?' · H_big과 같음':''),$o['H_recent']['point'],$o['H_recent']['why']);
            $h[]=$pr('H_big 뒤 최저점',$stl[$o['H_big']['status']],$o['adjusted_lows']['from_H_big'],'계산값');
            $h[]=$pr('H_recent 뒤 최저점',$stl[$o['H_recent']['status']],$o['adjusted_lows']['from_H_recent'],'계산값');
            $h[]='<tr><td>코드 손절 기준 저점(B)</td><td>-</td><td>'.$e($r['code_stop_reference_low']['date']).'</td><td class="num">'.$e(PaperReviewCharts::num((float)$r['code_stop_reference_low']['price'])).'</td><td>-</td><td>창 '.$e($r['code_stop_reference_low']['window']).'</td></tr></table>';
            $h[]='<p><b>재비교'.($r['in_13']?'':' (참고: 이 사례는 손절 기준 저점이 A의 L과 같았다)').'</b>: '.$e(self::RECHECK_LABEL[$r['label']]).($r['relies_on_hold_or_none']?' <span class="small">(보류 또는 없음 후보를 포함한 비교)</span>':'').'</p>';
            $h[]='</div></details></section>';
        }
        $h[]='<div class="note small">A 입력: 가격 파일 json/a/*-input.json (판정일까지의 완료 봉). 이 화면은 외부 파일 없이 열리며 차트는 charts 폴더의 SVG를 상대 경로로 부릅니다.</div></main></body></html>';
        return implode("\n",$h)."\n";
    }

    /**
     * A-stage freeze check that tells a line-ending change from a content change. a-freeze.json holds the original byte hashes
     * (two of them were taken on CRLF working copies); a-freeze-lf.json holds the hash of the same content with CRLF folded to LF.
     * @return array{ok:bool,files:array<string,array>,charts:array{content_changed:list<string>,line_ending_only:int,byte_identical:int},consistent:bool}
     */
    public static function freezeCheck(string $dir):array
    {
        $fr=self::loadJson($dir.'/a-freeze.json');$lf=self::loadJson($dir.'/a-freeze-lf.json');
        $files=[];$ok=true;$consistent=true;
        foreach($fr['files_sha256'] as $name=>$frozen){
            $raw=(string)file_get_contents($dir.'/'.$name);$norm=str_replace("\r\n","\n",$raw);$rec=$lf['files'][$name];
            $status=hash('sha256',$norm)!==$rec['lf_sha256']?'content_changed':(hash('sha256',$raw)===$frozen?'byte_identical':'line_ending_only');
            // The original hash must be reproducible from the recorded LF content and the recorded line ending.
            $again=$rec['frozen_line_ending']==='crlf'?str_replace("\n","\r\n",$norm):$norm;
            $reproduces=$status!=='content_changed'?hash('sha256',$again)===$frozen:null;
            if($reproduces===false||$rec['frozen_sha256']!==$frozen)$consistent=false;
            if($status==='content_changed')$ok=false;
            $files[$name]=['status'=>$status,'frozen_line_ending'=>$rec['frozen_line_ending'],'frozen_hash_reproduced_from_lf_content'=>$reproduces];
        }
        $charts=['content_changed'=>[],'line_ending_only'=>0,'byte_identical'=>0];
        foreach($lf['charts_lf_sha256'] as $name=>$sha){
            $path=$dir.'/charts/'.$name;
            if(!is_file($path)){$charts['content_changed'][]=$name;continue;}
            $raw=(string)file_get_contents($path);
            if(hash('sha256',str_replace("\r\n","\n",$raw))!==$sha)$charts['content_changed'][]=$name;
            elseif(hash('sha256',$raw)===$fr['charts_sha256'][$name])$charts['byte_identical']++;
            else $charts['line_ending_only']++;
        }
        $extra=array_diff(array_map('basename',glob($dir.'/charts/*.svg')?:[]),array_keys($lf['charts_lf_sha256']));
        foreach($extra as $e)$charts['content_changed'][]='unexpected '.$e;
        if($charts['content_changed']!==[])$ok=false;
        return ['ok'=>$ok&&$consistent,'files'=>$files,'charts'=>$charts,'consistent'=>$consistent];
    }

    /** Independent checks. The SVG is parsed back and compared with the A price file, not with the drawing code's own numbers. */
    public static function verify(string $pack,string $dir):array
    {
        $ann=self::loadJson($dir.'/annotations-a.json');$man=self::loadJson($dir.'/sample-manifest.json');$cmp=self::loadJson($dir.'/code-comparison.json');
        $freeze=self::loadJson($dir.'/a-freeze.json');$r=[];$fail=[];
        $ok=function(string $name,bool $pass,mixed $detail=null) use(&$r,&$fail){$r[$name]=['pass'=>$pass,'detail'=>$detail];if(!$pass)$fail[]=$name;};
        // 1 counts
        $counts=[];foreach($man['cases'] as $c)$counts[$c['dataset']][$c['category']]=($counts[$c['dataset']][$c['category']]??0)+1;
        $want=['selected'=>6,'waiting'=>3,'dropped'=>3];$good=count($man['cases'])===24&&count($counts)===2;
        foreach($counts as $cats)if($cats!==$want)$good=false;
        $ok('counts_per_period_and_category',$good,['total'=>count($man['cases']),'by_period'=>$counts]);
        $ok('annotations_cover_the_same_24',array_column($ann['cases'],'case_id')===(function() use($man){$i=array_column($man['cases'],'case_id');sort($i);return $i;})(),null);
        // 2 dates and forbidden keys in A files
        $future=[];$bad=0;$markers=0;$markerFail=[];$volFail=[];$volChecked=0;$confBad=[];$dateInSvg=0;
        $forbid=['category','pullback_status','slot','engine','outcome','return','profit','win','loss','final_status','selected_pattern'];
        $keys=function($v,&$found) use(&$keys,$forbid){if(is_array($v))foreach($v as $k=>$x){if(is_string($k)&&in_array($k,$forbid,true))$found[]=$k;$keys($x,$found);}};
        $found=[];$keys($ann,$found);$ok('annotations_have_no_category_status_or_outcome_keys',$found===[],$found);
        foreach($ann['cases'] as $c){
            $id=$c['case_id'];$D=$c['session_date'];$bars=self::bars($pack,$id);$n=count($bars);$last=$bars[$n-1];
            if($last['date']!==$D||$bars[$n-1]['date']>$D)$future[]=$id.' price file';
            foreach($bars as $b)if($b['date']>$D){$future[]=$id.' bar '.$b['date'];break;}
            $dates=[];array_walk_recursive($c,function($v,$k) use(&$dates){if(is_string($v)&&preg_match_all('/\b(20\d\d-\d\d-\d\d)\b/',$v,$m))foreach($m[1] as $d)$dates[]=$d;});
            foreach($dates as $d)if($d>$D)$future[]=$id.' annotation date '.$d;
            foreach($c['points'] as $code=>$set)foreach(array_merge([$set['main']],$set['alternatives']) as $p){
                if($p['pivot']['confirmed']&&($p['pivot']['confirmed_at']>(int)$last['available_at']||$p['pivot']['confirmed_date']>$D))$confBad[]=$id.' '.$code.' '.$p['date'];
                if(!$p['pivot']['confirmed']&&$p['pivot']['confirmed_at']!==null)$confBad[]=$id.' '.$code.' unconfirmed with time';
                if($p['observable_at']>(int)$last['available_at'])$confBad[]=$id.' '.$code.' observable after D';
                $bar=$bars[$p['index']];if($bar['date']!==$p['date']||(float)$bar[$p['field']]!==(float)$p['price'])$markerFail[]=$id.' '.$code.' '.$p['date'].' json vs price file';
            }
            foreach([['charts',120],['charts',40],['charts-a2',120]] as [$sub,$count]){
                $svg=(string)file_get_contents($dir.'/'.$sub.'/'.$id.'-'.$count.'.svg');
                preg_match_all('/\b(20\d\d-\d\d-\d\d)\b/',$svg,$m);foreach($m[1] as $d){$dateInSvg++;if($d>$D)$future[]=$id.' '.$count.' svg date '.$d;}
                $view=min($count,$n);$first=$n-$view;$step=(960-72-16)/$view;
                preg_match_all('/<line x1="([\d.]+)" y1="([\d.]+)" x2="\1" y2="([\d.]+)" stroke="#(?:c0392b|2471a3)"\/>/',$svg,$cl,PREG_SET_ORDER);
                $candles=[];foreach($cl as $x)$candles[]=['cx'=>(float)$x[1],'y1'=>(float)$x[2],'y2'=>(float)$x[3]];
                preg_match_all('/<(?:circle|rect)[^>]*data-code="([^"]*)" data-date="([^"]*)" data-price="([^"]*)" data-field="([^"]*)"[^>]*>/',$svg,$mk,PREG_SET_ORDER);
                foreach($mk as $x){
                    $markers++;$tag=$x[0];
                    if(str_starts_with($tag,'<circle')){preg_match('/cx="([\d.]+)" cy="([\d.]+)"/',$tag,$q);$cx=(float)$q[1];$cy=(float)$q[2];}
                    else{preg_match('/x="([\d.]+)" y="([\d.]+)"/',$tag,$q);$cx=(float)$q[1]+3.5;$cy=(float)$q[2]+3.5;}
                    $i=(int)floor(($cx-72)/$step);$bar=$bars[$first+$i]??null;
                    $line=null;foreach($candles as $cd)if(abs($cd['cx']-$cx)<0.06){$line=$cd;break;}
                    $field=$x[4];$okp=$bar!==null&&$bar['date']===$x[2]&&(float)$bar[$field]===(float)$x[3]&&$line!==null
                        &&abs($cy-($field==='high'?$line['y1']:$line['y2']))<0.15;
                    if(!$okp)$markerFail[]=$id.' '.$count.' '.$x[1].' '.$x[2];
                }
                // volume bars: rects in the volume pane, height proportional to volume
                preg_match_all('/<rect x="([\d.]+)" y="([\d.]+)" width="4\.4" height="([\d.]+)" fill="#(?:c0392b|2471a3)"\/>/',$svg,$rc,PREG_SET_ORDER);
                $vols=[];foreach($rc as $x)if((float)$x[2]>=28+286+8-0.01)$vols[]=['i'=>(int)floor(((float)$x[1]+2.2-72)/$step),'h'=>(float)$x[3]];
                $vmax=0;for($i=0;$i<$view;$i++)$vmax=max($vmax,(float)$bars[$first+$i]['volume']);
                if(count($vols)!==$view-count(array_filter(array_slice($bars,$first),fn($b)=>!empty($b['ohlc_invalid']))))$volFail[]=$id.' '.$count.' volume rect count '.count($vols);
                foreach($vols as $v){
                    $vol=(float)$bars[$first+$v['i']]['volume'];$exp=$vmax>0?$vol/$vmax*80:0;$volChecked++;
                    if($exp>=1.0&&abs($v['h']-$exp)>0.06)$volFail[]=$id.' '.$count.' bar '.$bars[$first+$v['i']]['date'].' h='.$v['h'].' exp='.round($exp,2);
                    if($exp<1.0&&abs($v['h']-1.0)>0.06)$volFail[]=$id.' '.$count.' floor '.$bars[$first+$v['i']]['date'];
                }
            }
        }
        $ok('no_date_after_decision_day_in_inputs_marks_and_charts',$future===[],array_slice($future,0,10)+['date_strings_checked_in_svg'=>$dateInSvg]);
        $ok('confirmed_pivots_confirmed_by_decision_day',$confBad===[],$confBad);
        $ok('marked_coordinates_match_price_file_and_chart_pixels',$markerFail===[],['markers_checked'=>$markers,'failures'=>array_slice($markerFail,0,10)]);
        $ok('volume_bar_heights_proportional_to_volume',$volFail===[],['bars_checked'=>$volChecked,'failures'=>array_slice($volFail,0,10)]);
        // chart candles equal the package A chart (the overlay only adds marks and legend)
        $diff=0;
        foreach($ann['cases'] as $c){
            $mine=(string)file_get_contents($dir.'/charts/'.$c['case_id'].'-120.svg');$theirs=(string)file_get_contents($pack.'/a/'.$c['case_id'].'-120.svg');
            $strip=function(string $s):string{
                $s=preg_replace('/<text x="72" y="18"[^>]*>.*?<\/text>/su','',$s);
                $s=preg_replace('/<(circle|rect)[^>]*data-code="[^>]*>/u','',$s);
                $s=preg_replace('/<text[^>]*paint-order="stroke"[^>]*>.*?<\/text>/su','',$s);
                $s=preg_replace('/<text x="72" y="(4[3-9]\d|50[0-4])"[^>]*>.*?<\/text>/su','',$s);
                return $s;
            };
            if($strip($mine)!==$strip($theirs))$diff++;
        }
        $ok('annotated_120_chart_candles_equal_package_A_chart',$diff===0,['different_charts'=>$diff]);
        // freeze
        $fc=self::freezeCheck($dir);
        $ok('a_stage_content_unchanged_since_freeze',$fc['ok'],['files'=>$fc['files'],'charts'=>$fc['charts'],'frozen_hashes_consistent_with_lf_hashes'=>$fc['consistent']]);
        // links
        $html=(string)file_get_contents($dir.'/index.html');preg_match_all('/(?:src|href)="([^"#:]+)(?:#[^"]*)?"/',$html,$lk);$missing=[];
        foreach(array_unique($lk[1]) as $l)if(!is_file($dir.'/'.$l))$missing[]=$l;
        $ok('index_relative_links_exist',$missing===[],['links'=>count(array_unique($lk[1])),'missing'=>$missing]);
        $files=['protocol.md','sample-manifest.json','annotations-a.src.json','annotations-a.json','a-freeze.json','a-freeze-lf.json','annotations-a2.src.json','annotations-a2.json','highs-review.json','code-comparison.json','index.html','review.md','verify.md'];
        $absent=array_values(array_filter($files,fn($f)=>!is_file($dir.'/'.$f)&&$f!=='verify.md'));
        $ok('deliverable_files_present',$absent===[],$absent);
        // first screen order and absence of outcome words
        $cut=strpos($html,'<section');$firstCard=substr($html,$cut,(int)strpos($html,'<details>',$cut)-$cut);
        $ok('index_shows_A_marks_before_code_comparison',str_contains($firstCard,'S 시작 후보')&&!str_contains($firstCard,'rejected_rr')&&!str_contains($firstCard,'ready'),null);
        $ok('index_has_no_return_or_win_loss_wording',!preg_match('/수익률|승률|손실률|win rate|PnL/u',$html),null);
        $ok('code_comparison_covers_24_with_valid_categories',count($cmp['cases'])===24&&count(array_diff(array_column($cmp['cases'],'primary_category'),self::COMPARE_CATEGORIES))===0,$cmp['summary']['primary']);
        $rp=$cmp['reproduction_totals'];$allMatch=true;foreach($rp as $t)if($t['mismatched']!==0)$allMatch=false;
        $ok('code_windows_reproduce_stored_values',$allMatch,$rp);
        // Follow-up review: statuses, dates, hash of the existing A annotation, and an independent recomputation of the breakout facts.
        $a2=self::loadJson($dir.'/annotations-a2.json');$re=self::loadJson($dir.'/highs-review.json');$a2src=self::loadJson($dir.'/annotations-a2.src.json');
        $okSet=array_column($a2['cases'],'case_id')===array_column($ann['cases'],'case_id')&&count($a2['cases'])===24&&$a2['blind']===false;
        $ok('a2_covers_the_same_24_and_is_not_called_blind',$okSet&&!preg_match('/블라인드(?!가 아니)/u',(string)json_encode($a2['disclosure'],JSON_UNESCAPED_UNICODE)),$a2['status_counts']);
        $ok('a2_src_hash_recorded_matches',$a2['inputs']['annotations_a2_src_sha256']===hash('sha256',(string)file_get_contents($dir.'/annotations-a2.src.json'))||$a2['inputs']['annotations_a2_src_sha256']===hash('sha256',str_replace("\r\n","\n",(string)file_get_contents($dir.'/annotations-a2.src.json'))),null);
        $a2Bad=[];$a2Future=[];
        foreach($a2['cases'] as $o){
            $id=$o['case_id'];$bars=self::bars($pack,$id);$n=count($bars);$D=$o['session_date'];$last=(int)$bars[$n-1]['available_at'];
            $pts=[$o['H_big']['point'],$o['H_recent']['point'],$o['adjusted_lows']['from_H_big'],$o['adjusted_lows']['from_H_recent']];
            foreach($pts as $p){
                if($p===null)continue;
                if($p['date']>$D||$p['observable_at']>$last||($p['pivot']['confirmed']&&$p['pivot']['confirmed_at']>$last))$a2Future[]=$id.' '.$p['date'];
                $bar=$bars[$p['index']]??null;if($bar===null||$bar['date']!==$p['date']||(float)$bar[$p['field']]!==(float)$p['price'])$a2Bad[]=$id.' '.$p['date'];
                foreach(['first_high_above_after','first_close_above_after'] as $k)if(!empty($p[$k])&&$p[$k]>$D)$a2Future[]=$id.' '.$k;
            }
            $st=$o['H_big']['status'];
            if(($st==='none')!==($o['H_big']['point']===null))$a2Bad[]=$id.' none/point mismatch';
            if(!in_array($st,['marked','hold','none'],true)||!in_array($o['H_recent']['status'],['marked','hold'],true))$a2Bad[]=$id.' bad status';
        }
        $ok('a2_points_match_price_file_and_stay_within_decision_day',$a2Bad===[]&&$a2Future===[],['bad'=>array_slice($a2Bad,0,10),'future'=>array_slice($a2Future,0,10)]);
        $rec13=array_values(array_filter($re['cases'],fn($x)=>$x['in_13']));
        $stopNotA=array_values(array_filter($cmp['cases'],fn($x)=>!$x['against_A']['stop_ref_date_is_A_L']));
        $ok('stop_recheck_covers_exactly_the_13_cases_whose_stop_reference_differed',count($rec13)===13&&array_column($rec13,'case_id')===array_column($stopNotA,'case_id'),['cases'=>count($rec13),'labels'=>$re['summary']['labels']]);
        // Breakout facts recomputed from the A price file alone, then compared with code-comparison.json.
        $boBad=[];$mismatchLe=0;
        foreach($cmp['cases'] as $x){
            $id=$x['case_id'];$bars=self::bars($pack,$id);$n=count($bars);$hDate=$ann['cases'][array_search($id,array_column($ann['cases'],'case_id'),true)]['points']['H']['main']['date'];
            $hi=null;foreach($bars as $i=>$b)if($b['date']===$hDate)$hi=$i;$hh=(float)$bars[$hi]['high'];$dH=(float)$bars[$n-1]['high'];$dC=(float)$bars[$n-1]['close'];
            $ref=0;for($i=$n-21;$i<=$n-2;$i++)$ref=max($ref,(float)$bars[$i]['high']);
            $t=$x['breakout_target'];
            if($t['A_H']['high_breakout']['on_decision_day']!==($dH>$hh))$boBad[]=$id.' high breakout';
            if($t['A_H']['close_breakout']['on_decision_day']!==($dC>$hh))$boBad[]=$id.' close breakout';
            if($t['target_reference']['decision_high_above_ref']!==($dH>$ref)||$t['target_reference']['decision_close_above_ref']!==($dC>$ref))$boBad[]=$id.' target reference';
            $le=floor($ref)<=floor($dC);
            if($t['target_vs_entry']['target_le_entry']!==$le)$mismatchLe++;
            if(($x['stored']['entry']!==null)!==($t['target_vs_entry']['basis']==='stored_entry'))$boBad[]=$id.' basis';
            if($t['target_vs_entry']['basis']==='stored_entry'&&(float)$x['stored']['entry']!==floor($dC))$boBad[]=$id.' stored entry is not the truncated decision close';
        }
        $ok('breakout_and_target_facts_recomputed_from_price_file',$boBad===[]&&$mismatchLe===0,['bad'=>$boBad,'target_le_entry_mismatches'=>$mismatchLe,'summary'=>$cmp['summary']['breakout_target']]);
        return ['version'=>self::VERSION,'all_passed'=>$fail===[],'failed'=>$fail,'checks'=>$r];
    }

    private static function notesTouchWindows(array $c,array $bars,int $n):bool
    {
        // Invalid candles or zero-volume bars inside the last 40 bars touch every code window.
        for($i=$n-40;$i<$n;$i++)if(!empty($bars[$i]['ohlc_invalid'])||(float)$bars[$i]['volume']<=0)return true;
        return false;
    }

    /** Index of the lowest low after bar $idx through the last bar. Earliest bar wins ties. Null when no bar follows. */
    private static function lowAfter(array $bars,int $idx):?int
    {
        $best=null;
        for($i=$idx+1;$i<count($bars);$i++){
            if(!empty($bars[$i]['ohlc_invalid']))continue;
            if($best===null||(float)$bars[$i]['low']<(float)$bars[$best]['low'])$best=$i;
        }
        return $best;
    }

    /** Resolved point plus the first later bar whose high / close is above it. */
    private static function resolveHigh(array $bars,array $by,int $idx,?string $why,string $id):array
    {
        $p=self::resolve($bars,$by,'H',$bars[$idx]['date'],$why,$id);$hh=(float)$bars[$idx]['high'];$fh=null;$fc=null;
        for($j=$idx+1;$j<count($bars);$j++){
            if(!empty($bars[$j]['ohlc_invalid']))continue;
            if($fh===null&&(float)$bars[$j]['high']>$hh)$fh=$bars[$j]['date'];
            if($fc===null&&(float)$bars[$j]['close']>$hh)$fc=$bars[$j]['date'];
        }
        return $p+['first_high_above_after'=>$fh,'first_close_above_after'=>$fc];
    }

    /**
     * Follow-up review written after B was seen (not blind). H_big and H_recent come from the rules in the src file, computed on the
     * A price file only. The existing A annotation is read, never changed.
     */
    public static function a2(string $pack,string $srcPath,array $ann,string $srcSha):array
    {
        $src=self::loadJson($srcPath);$A=[];foreach($ann['cases'] as $c)$A[$c['case_id']]=$c;$out=[];
        foreach($src['cases'] as $s){
            $id=$s['case_id'];$c=$A[$id]??throw new RuntimeException($id.' has no A annotation');
            $bars=self::bars($pack,$id);$n=count($bars);$by=self::index($bars);$first=max(0,$n-120);
            $gi=null;
            for($i=$first;$i<$n;$i++){
                if(!empty($bars[$i]['ohlc_invalid']))continue;
                if($gi===null||(float)$bars[$i]['high']>(float)$bars[$gi]['high'])$gi=$i;
            }
            $isD=$gi===$n-1;$edge=$first>0&&$gi-$first<5;$fewBig=!$isD&&$n-1-$gi<2;
            $bs=$s['H_big']['status'];
            if($isD!==($bs==='none'))throw new RuntimeException($id.': H_big status none must match a window high on the decision day');
            if(($edge||$fewBig)&&$bs!=='hold')throw new RuntimeException($id.': H_big must be hold (window edge or fewer than 2 bars after)');
            $lbi=$isD?null:self::lowAfter($bars,$gi);
            $rec=null;
            if($lbi!==null)for($i=$lbi+1;$i<$n;$i++){
                if(!empty($bars[$i]['ohlc_invalid'])||(float)$bars[$i]['high']>=(float)$bars[$gi]['high'])continue;
                if(self::pivot($bars,$i,'high')['confirmed'])$rec=$i;
            }
            $rs=$s['H_recent'];
            if($rs['source']==='A_main')$ri=$c['points']['H']['main']['index'];
            else{if($isD)throw new RuntimeException($id.': H_recent by rule needs a window high before the decision day');$ri=$rec??$gi;}
            if($n-1-$ri<2&&$rs['status']!=='hold')throw new RuntimeException($id.': H_recent must be hold (fewer than 2 bars after)');
            $big=$bs==='none'?null:self::resolveHigh($bars,$by,$gi,$s['H_big']['why'],$id);
            $recent=self::resolveHigh($bars,$by,$ri,$rs['why'],$id);
            $lowOf=function(?int $from) use($bars,$by,$id):?array{
                if($from===null)return null;$li=self::lowAfter($bars,$from);
                return $li===null?null:self::resolve($bars,$by,'L',$bars[$li]['date'],'H 다음 봉부터 판정일까지의 가장 낮은 저가. 도구가 계산한 값이다',$id);
            };
            $hMain=$c['points']['H']['main'];$lMain=$c['points']['L']['main'];
            $out[]=[
                'case_id'=>$id,'symbol'=>$c['symbol'],'name'=>$c['name'],'session_date'=>$c['session_date'],
                'H_big'=>['status'=>$bs,'why'=>$s['H_big']['why'],'candidate_flags'=>['window_high_is_decision_day'=>$isD,'window_edge'=>$edge,'fewer_than_2_bars_after'=>$fewBig],'point'=>$big],
                'H_recent'=>['status'=>$rs['status'],'why'=>$rs['why'],'source'=>$rs['source'],'rule_found_re_decline'=>$rec!==null,'fewer_than_2_bars_after'=>$n-1-$ri<2,'point'=>$recent,
                    'relation_to_H_big'=>$big===null?'no_H_big':($ri===$gi?'same':'different')],
                'adjusted_lows'=>['from_H_big'=>$bs==='none'?null:$lowOf($gi),'from_H_recent'=>$lowOf($ri)],
                'A_reference'=>['H_main'=>$hMain['date'],'L'=>$lMain['date'],'H_main_is_H_big'=>$big!==null&&$hMain['date']===$bars[$gi]['date'],'H_main_is_H_recent'=>$hMain['date']===$bars[$ri]['date']],
            ];
        }
        if(count($out)!==count($A))throw new RuntimeException('A2 covers '.count($out).' cases, A has '.count($A));
        usort($out,fn($a,$b)=>strcmp($a['case_id'],$b['case_id']));
        $st=['H_big'=>[],'H_recent'=>[]];
        foreach($out as $o)foreach(['H_big','H_recent'] as $k)$st[$k][$o[$k]['status']]=($st[$k][$o[$k]['status']]??0)+1;
        return ['version'=>self::VERSION,'stage'=>'A2','blind'=>false,
            'disclosure'=>'B 확인과 코드 대조를 본 뒤에 만든 추가 검토다. 블라인드가 아니다. 기존 A 표시(annotations-a.json)는 덮어쓰지 않았다. H_big·H_recent의 날짜는 A 가격 파일에서 규칙으로 계산했고, 코드의 창은 쓰지 않았다.',
            'rules'=>$src['rules'],'inputs'=>['annotations_a2_src_sha256'=>$srcSha,'annotations_a_json'=>'read only, unchanged'],'status_counts'=>$st,'cases'=>$out];
    }

    public const RECHECK_LABEL=['matches_both'=>'두 고점 기준 저점이 같고 코드 기준과 일치','matches_recent_low'=>'최근 재하락 저점과 일치','matches_big_low'=>'큰 조정 저점과 일치','before_recent_high'=>'코드 기준 저점이 최근 재하락 시작 고점보다 앞','neither'=>'어느 쪽과도 다름'];

    /** Re-compare the code's stop-reference low with the lows after each high. Applies to every case; in_13 marks the cases whose stop reference differed from A's L. */
    public static function a2Recheck(array $a2,array $ann,array $cmp,array $hashes):array
    {
        $A=[];foreach($ann['cases'] as $c)$A[$c['case_id']]=$c;$X=[];foreach($cmp['cases'] as $c)$X[$c['case_id']]=$c;
        $out=[];$sum=['in_13'=>0,'labels'=>[],'relies_on_hold_or_none'=>0];
        foreach($a2['cases'] as $o){
            $id=$o['case_id'];$x=$X[$id];$a=$A[$id];$stop=$x['windows']['stop_last_10_bars']['low'];$win=$x['windows']['stop_last_10_bars'];
            $in13=!$x['against_A']['stop_ref_date_is_A_L'];
            $lb=$o['adjusted_lows']['from_H_big'];$lr=$o['adjusted_lows']['from_H_recent'];$aL=$a['points']['L']['main'];
            $pct=fn(?array $l)=>$l===null?null:round(((float)$stop['price']-(float)$l['price'])/(float)$l['price']*100,1);
            $mb=$lb!==null&&$lb['date']===$stop['date'];$mr=$lr!==null&&$lr['date']===$stop['date'];
            $hr=$o['H_recent']['point']['index'];
            $label=$mb&&$mr?'matches_both':($mr?'matches_recent_low':($mb?'matches_big_low':($stop['index']<$hr?'before_recent_high':'neither')));
            $reliesHold=$o['H_big']['status']!=='marked'||$o['H_recent']['status']!=='marked';
            $rec=['case_id'=>$id,'name'=>$o['name'],'in_13'=>$in13,
                'code_stop_reference_low'=>['date'=>$stop['date'],'price'=>$stop['price'],'window'=>$win['from'].' ~ '.$win['to']],
                'A_L'=>['date'=>$aL['date'],'price'=>$aL['price']],
                'L_from_H_big'=>$lb===null?null:['date'=>$lb['date'],'price'=>$lb['price'],'in_stop_window'=>$lb['date']>=$win['from']],
                'L_from_H_recent'=>$lr===null?null:['date'=>$lr['date'],'price'=>$lr['price'],'in_stop_window'=>$lr['date']>=$win['from']],
                'stop_ref_equals'=>['A_L'=>$stop['date']===$aL['date'],'L_from_H_big'=>$mb,'L_from_H_recent'=>$mr],
                'stop_ref_vs_L_from_H_big_pct'=>$pct($lb),'stop_ref_vs_L_from_H_recent_pct'=>$pct($lr),
                'stop_ref_before_H_big'=>$o['H_big']['point']!==null&&$stop['index']<$o['H_big']['point']['index'],'stop_ref_before_H_recent'=>$stop['index']<$hr,
                'H_big_status'=>$o['H_big']['status'],'H_recent_status'=>$o['H_recent']['status'],
                'label'=>$label,'relies_on_hold_or_none'=>$reliesHold];
            if($in13){$sum['in_13']++;$sum['labels'][$label]=($sum['labels'][$label]??0)+1;$sum['relies_on_hold_or_none']+=(int)$reliesHold;}
            $out[]=$rec;
        }
        return ['version'=>self::VERSION,'stage'=>'A2 recheck','blind'=>false,
            'disclosure'=>'B 확인 뒤의 추가 검토다. 코드의 손절 기준 저점(최근 10봉의 최저 저가)이 A의 L, 큰 조정 시작 고점 뒤 최저점, 최근 재하락 시작 고점 뒤 최저점 중 무엇과 같은 날인지 본다. 같다는 것은 날짜가 같다는 뜻이며 코드가 그 구조를 의도했다는 뜻이 아니다.',
            'inputs'=>$hashes,'summary'=>$sum,'labels'=>self::RECHECK_LABEL,'cases'=>$out];
    }

    /** Chart marks for the follow-up review. The code stop reference comes from B and is drawn as a square. */
    public static function overlayA2(array $a2case,array $re,array $bars):array
    {
        $marks=[];$legend=[];
        $add=function(string $code,?array $p,string $field,bool $main,string $color,string $text) use(&$marks,&$legend){
            if($p===null)return;
            $marks[]=['at'=>$p['observable_at'],'price'=>(float)$p['price'],'field'=>$field,'code'=>$code,'main'=>$main,'confirmed'=>$p['pivot']['confirmed'],'color'=>$color,'date'=>$p['date']];
            $conf=$p['pivot']['confirmed']?'확정 '.$p['pivot']['confirmed_date']:'관찰만';
            $legend[]=['color'=>$color,'text'=>$text.' '.$p['date'].' · '.PaperReviewCharts::num((float)$p['price']).' · '.$conf];
        };
        $sl=['marked'=>'','hold'=>' (보류)','none'=>''];
        $hb=$a2case['H_big'];$hr=$a2case['H_recent'];
        if($hb['point']!==null)$add('Hb',$hb['point'],'high',$hb['status']==='marked','#922b21','큰 조정 시작 고점'.$sl[$hb['status']]);
        else $legend[]=['color'=>'#922b21','text'=>'큰 조정 시작 고점 없음 (판정일 고가가 창 최고)'];
        $same=$hr['relation_to_H_big']==='same';
        $add('Hr',$hr['point'],'high',$hr['status']==='marked','#d35400','최근 재하락 시작 고점'.$sl[$hr['status']].($same?' · 큰 조정 시작 고점과 같음':''));
        $add('Lb',$a2case['adjusted_lows']['from_H_big'],'low',true,'#1f618d','큰 조정 시작 고점 뒤 최저점');
        $add('Lr',$a2case['adjusted_lows']['from_H_recent'],'low',true,'#117a65','최근 재하락 시작 고점 뒤 최저점');
        $s=$re['code_stop_reference_low'];$i=null;foreach($bars as $k=>$b)if($b['date']===$s['date'])$i=$k;
        $cp=self::resolve($bars,self::index($bars),'L',$s['date'],null,$a2case['case_id']);
        $add('Cs',$cp,'low',false,'#566573','코드 손절 기준 저점(B에서 계산)');
        return ['marks'=>$marks,'legend'=>$legend];
    }

    /** View of the last $count A bars with the indicator values stored in the price file. */
    public static function viewOf(array $bars,int $count):array
    {
        $slice=array_slice($bars,-$count);
        return ['bars'=>$slice,'ma20'=>array_map(fn($b)=>$b['ma20']??null,$slice),'ma60'=>array_map(fn($b)=>$b['ma60']??null,$slice),
            'last_at'=>(int)end($slice)['available_at']];
    }

    /** Overlay for one chart window from a resolved annotation. */
    public static function overlay(array $case,int $count):array
    {
        $marks=[];$view=$count===120?'in_view_120':'in_view_40';$outside=[];
        $fmt=fn(array $p)=>self::md($p['date'],$case['session_date']);
        foreach($case['points'] as $code=>$set){
            foreach(array_merge([$set['main']+['is_main'=>true]],array_map(fn($a)=>$a+['is_main'=>false],$set['alternatives'])) as $k=>$p){
                $label=$p['is_main']?$code:$code.'-'.chr(96+$k);
                if(!$p[$view]){$outside[]=$label.' '.$fmt($p);continue;}
                $marks[]=['at'=>$p['observable_at'],'price'=>(float)$p['price'],'field'=>$p['field'],'code'=>$label,'main'=>$p['is_main'],
                    'confirmed'=>$p['pivot']['confirmed'],'color'=>self::COLORS[$code],'date'=>$p['date']];
            }
        }
        $legend=[];
        $names=['S'=>'S 시작 후보','H'=>'H 눌림 시작 고점','L'=>'L 관찰 최저점','P'=>'P 지지 후보'];
        foreach(['S','H','L','P'] as $code){
            $p=$case['points'][$code]['main'];
            if($code==='P'&&$case['points']['P']['same_as_L'])continue;
            $conf=$p['pivot']['confirmed']?'확정 '.$p['pivot']['confirmed_date']:'관찰만';
            $legend[]=['color'=>self::COLORS[$code],'text'=>$names[$code].($code==='L'&&$case['points']['P']['same_as_L']?'·P 지지 후보':'').' '.$p['date'].' · '.PaperReviewCharts::num((float)$p['price']).' · '.$conf.($p[$view]?'':' · 이 창 밖')];
        }
        $alts=[];
        foreach($case['points'] as $code=>$set)foreach($set['alternatives'] as $k=>$a)$alts[]=$code.'-'.chr(97+$k).' '.self::md($a['date'],$case['session_date']).($a[$view]?'':'(창 밖)');
        if($alts){
            $t='대안 '.implode(' · ',$alts);
            $legend[]=['color'=>'#555','text'=>mb_strlen($t)>82?mb_substr($t,0,81).'…':$t];
        }
        $legend[]=['color'=>'#222','text'=>'재상승 근거 '.self::RLABEL[$case['rebound']['state']].' · 눌림 상태 '.self::STATELABEL[$case['correction_state']].' · 모호함 '.self::AMBLABEL[$case['ambiguity']['level']].'  (●확정 ○관찰만 □대안)'];
        return ['marks'=>$marks,'legend'=>$legend];
    }
    public const RLABEL=['confirmed'=>'확인됨','partial'=>'일부','not_confirmed'=>'확인 안 됨','unknown'=>'판단 불가'];
    public const STATELABEL=['ongoing'=>'진행 중으로 봄','possibly_ended'=>'끝났을 수 있음','unknown'=>'알 수 없음'];
    public const AMBLABEL=['low'=>'낮음','medium'=>'중간','high'=>'높음'];

    private static function md(string $date,string $session):string
    {
        return substr($date,0,4)===substr($session,0,4)?substr($date,5):substr($date,2);
    }

    public static function title(array $case,int $count):string
    {
        return $case['name'].' '.$case['symbol'].' · 판정일 '.$case['session_date'].' · '.$count.'거래일 · A 표시';
    }

    public static function select(string $pack,string $zip,string $commit):array
    {
        $manifest=self::loadJson($pack.'/meta/manifest.json');
        $pool=[];$statusCounts=[];$exposed=[];
        foreach($manifest['case_index'] as $row){
            if($row['pattern']!=='trend_pullback'||!in_array($row['cohort'],['selected','not_selected'],true))continue;
            // Selected rows carry a trade status in this index. It is an outcome field and is never read here.
            $status=$row['cohort']==='selected'?null:$row['status'];
            $statusCounts[$row['dataset']][$row['cohort']][$status??'(not read)']=($statusCounts[$row['dataset']][$row['cohort']][$status??'(not read)']??0)+1;
            if(in_array($row['case_id'],self::EXPOSED,true)){$exposed[]=$row['case_id'];continue;}
            $a=self::loadJson($pack.'/json/a/'.$row['case_id'].'.json');
            $category=$row['cohort']==='selected'?'selected':(in_array($status,self::WAIT,true)?'waiting':(in_array($status,self::DROP,true)?'dropped':null));
            $pool[$row['dataset']][]=['category'=>$category,'case_id'=>$row['case_id'],'symbol'=>$a['symbol'],'name'=>$a['name'],
                'session_date'=>$a['session_date'],'month'=>substr($a['session_date'],0,7),'status'=>$status];
        }
        $cases=[];$available=[];$notes=[];$shortfalls=[];
        foreach(['kr-saved-scan-20261009','kr-saved-scan-20251008'] as $dataset){
            $usedSymbols=[];$usedMonths=[];
            foreach(self::PLAN as $plan){
                $cat=$plan['category'];
                $candidates=array_values(array_filter($pool[$dataset]??[],fn($r)=>$r['category']===$cat));
                $available[$dataset][$cat]=count($candidates);
                $r=self::pick($candidates,$plan['count'],$plan['status_order']??null,$usedSymbols,$usedMonths,$dataset,$cat);
                foreach($r['picked'] as $p)$cases[]=['dataset'=>$dataset,'category'=>$cat,'slot'=>$p['slot'],'case_id'=>$p['case_id'],
                    'symbol'=>$p['symbol'],'name'=>$p['name'],'session_date'=>$p['session_date'],'pullback_status'=>$p['status'],
                    'order_key'=>$p['order_key'],'pass'=>$p['pass']];
                foreach($r['notes'] as $n)$notes[]=$dataset.' '.$cat.' '.$n;
                if($r['shortfall']>0)$shortfalls[]=['dataset'=>$dataset,'category'=>$cat,'wanted'=>$plan['count'],'got'=>$plan['count']-$r['shortfall']];
            }
        }
        $actual=[];foreach($cases as $c)$actual[$c['dataset']][$c['category']]=($actual[$c['dataset']][$c['category']]??0)+1;
        $detail=[];foreach($cases as $c)if($c['pullback_status']!==null)$detail[$c['dataset']][$c['pullback_status']]=($detail[$c['dataset']][$c['pullback_status']]??0)+1;
        return [
            'version'=>self::VERSION,'seed'=>self::SEED,
            'sources'=>['zip'=>'docs/pattern-review-pack.zip','zip_sha256'=>hash_file('sha256',$zip),'zip_bytes'=>filesize($zip),
                'package_manifest_sha256'=>hash_file('sha256',$pack.'/meta/manifest.json'),'main_commit'=>$commit,
                'package_cases'=>$manifest['cases'],'strategy_fingerprint'=>$manifest['strategy_fingerprint'],
                'note'=>'PR #61 corrected package as merged. A files supply symbol, name and decision date only for this list.'],
            'purpose'=>'Mark the current pullback segment on A charts before reading engine facts. Not a profitability study and not a market sample.',
            'metadata_used'=>['manifest: case_id, dataset, cohort, pattern, status (not_selected only)','A json: symbol, name, session_date'],
            'metadata_not_used'=>['trade status, returns, outcome files, C files, B files, PR #62 labels, performance tables'],
            'category_map'=>[
                'selected'=>'manifest cohort selected and pattern trend_pullback: the final selected plan was the pullback plan',
                'waiting'=>'manifest cohort not_selected, pattern trend_pullback, pattern status wait_pullback (not yet near the zone) or await_confirmation (zone reached, confirmation pending)',
                'dropped'=>'manifest cohort not_selected, pattern trend_pullback, pattern status rejected_rr (confirmed, then dropped by reward/risk). The pullback pattern has no separate invalidated status',
                'not_used'=>'ready_blocked and ready_not_selected: the pattern itself was ready, so they are neither waiting nor dropped by the pattern. A pullback seen only in another pattern\'s final plan is never counted as a pullback selection',
            ],
            'rule'=>[
                'order'=>'per dataset: selected, waiting, dropped. Candidates sorted by sha256(seed|dataset|category|case_id).',
                'distribution'=>'a symbol is used once per dataset. Pass 1 also wants a month not yet used in that dataset. Pass 2 drops the month rule. Waiting slots alternate wait_pullback, await_confirmation, wait_pullback.',
                'adjacent_duplicates'=>'one case per symbol per dataset, so adjacent days of one structure cannot both appear',
                'exposure'=>'the engine page of f0445c951f5182c2 was shown to the agent in earlier work, so it is not a candidate',
                'not_representative'=>'the package itself holds only up to 10 structures per pattern, status and dataset for non-selected cases. This list is not a market sample.',
            ],
            'package_status_counts'=>$statusCounts,'available_after_exclusion'=>$available,'exposed_excluded'=>$exposed,
            'planned'=>['per_dataset'=>['selected'=>6,'waiting'=>3,'dropped'=>3],'total'=>24],
            'actual'=>$actual,'pullback_status_detail'=>$detail,'shortfalls'=>$shortfalls,'selection_notes'=>$notes,
            'total'=>count($cases),'cases'=>$cases,
        ];
    }
}
