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
