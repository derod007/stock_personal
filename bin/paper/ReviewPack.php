<?php
declare(strict_types=1);
require_once __DIR__.'/PatternReplay.php';
require_once __DIR__.'/ReviewCharts.php';
use ChartEntryLab\CandleClock;
use ChartEntryLab\PaperQuality;

/** Builds a review package. It does not change plans, stops, targets or the strategy fingerprint. */
final class PaperReviewPack
{
    private const A_FORBIDDEN=['pattern','entry','stop','target','net_return_pct','ready','score','outcome','selected','reward_risk','win','exit_reason','cohort'];

    public static function loadJson(string $path):array
    {
        $text=(string)file_get_contents($path);$at=strpos($text,'{');
        if($at===false)throw new RuntimeException('JSON missing in '.$path);
        $data=json_decode(substr($text,$at),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('JSON is not an object');
        return $data;
    }

    public static function caseId(string $dataset,string $cohort,string $symbol,int $session,string $pattern):string
    {
        return substr(hash('sha256',$dataset.'|'.$cohort.'|'.$symbol.'|'.$session.'|'.$pattern),0,16);
    }

    /** @param list<array{month:string,symbol:string,case_id:string}> $rows */
    public static function sample(array $rows,int $n,int $symbolCap):array
    {
        $by=[];foreach($rows as $r)$by[$r['month']][]=$r;
        ksort($by);$months=array_keys($by);$m=count($months);
        if($m===0||$n<=0)return [];
        $base=intdiv($n,$m);$rem=$n%$m;$take=[];
        foreach($months as $i=>$month){
            $want=$base+($i<$rem?1:0);
            $pool=$by[$month];usort($pool,fn($a,$b)=>$a['case_id']<=>$b['case_id']);
            $picked=[];$used=[];
            foreach($pool as $r){if(count($picked)>=$want)break;if(($used[$r['symbol']]??0)>=$symbolCap)continue;$picked[]=$r;$used[$r['symbol']]=($used[$r['symbol']]??0)+1;}
            if(count($picked)<$want)foreach($pool as $r){if(count($picked)>=$want)break;if(in_array($r,$picked,true))continue;$picked[]=$r;}
            foreach($picked as $r)$take[]=$r;
        }
        return $take;
    }

    /** @param list<array{case_id:string}> $rows */
    public static function takeByCaseId(array $rows,int $n):array
    {
        usort($rows,fn($a,$b)=>$a['case_id']<=>$b['case_id']);
        return array_slice($rows,0,$n);
    }

    public static function bucket(array $e):?string
    {
        if($e['pattern']==='higher_low')return match($e['stage']??''){'watch'=>'higher_low_watch','breakout'=>'higher_low_breakout',default=>null};
        if(!empty($e['operational_ready']))return null;
        $ready=!empty($e['raw']['ready']);$blocks=$e['independent_blockers']??[];
        if($ready&&$blocks!==[])return 'ready_blocked';
        if($ready&&$blocks===[])return 'ready_not_selected';
        if(($e['raw']['status']??'')==='rejected_rr')return 'rejected_rr';
        if(in_array($e['raw']['status']??'',['await_confirmation','await_retest','await_recovery','await_higher_low','wait_pullback','invalidated','expired','no_upper_target'],true))return 'await_or_invalid';
        return null;
    }

    /** @param array{id:string,dir:string,replay:string} $ds */
    public static function build(string $out,array $datasets,string $commit):array
    {
        if(!is_dir($out)&&!mkdir($out,0770,true)&&!is_dir($out))throw new RuntimeException('Cannot create '.$out);
        $fingerprint=PaperStrategyVersion::current();
        $cases=[];$counts=[];$problems=[];
        foreach($datasets as $ds){
            $verify=PaperHistoryResearch::verify($ds['dir']);
            if($verify)$problems[]=$ds['id'].' verify: '.implode('; ',$verify);
            $replay=self::loadJson($ds['replay']);
            $meta=PaperHistoryResearch::readJson($ds['dir'].'/dataset.json');
            if(($replay['profile']??'')!=='account1'||($replay['context_applied']??false)!==true)$problems[]=$ds['id'].' profile';
            if(($replay['strategy_fingerprint']??'')!==$fingerprint)$problems[]=$ds['id'].' fingerprint';
            if(($replay['start']??'')!==PaperHistoryResearch::evalStartOf($meta)||($replay['end']??'')!==$meta['as_of']['day'])$problems[]=$ds['id'].' window';
            $expect=['kr-saved-scan-20261009'=>[82,70],'kr-saved-scan-20251008'=>[75,67]];
            $made=self::dataset($out,$ds,$replay,$meta,$commit,$fingerprint,$problems);
            $cases=array_merge($cases,$made['cases']);
            foreach($made['counts'] as $k=>$v)$counts[$k][$ds['id']]=$v;
            if(isset($expect[$ds['id']])){
                $got=$made['counts']['selected']['trend_pullback']??[];
                $signals=array_sum($got);$closed=$got['closed']??0;
                if($signals!==$expect[$ds['id']][0]||$closed!==$expect[$ds['id']][1]){
                    $problems[]=$ds['id'].' pullback selected '.$signals.' closed '.$closed
                        .' (kept expectation '.$expect[$ds['id']][0].'/'.$expect[$ds['id']][1].')';
                }
            }
        }
        $ids=array_column($cases,'case_id');sort($ids);
        $index=self::index($ids);
        file_put_contents($out.'/OPEN.txt',"index.html 을 여세요.\n엔진 판정과 이후 결과는 사례 화면 안의 링크를 눌러 따로 엽니다.\n이 자료는 종목과 날짜를 포함하므로 완전한 블라인드 실험이 아닙니다.\n");
        file_put_contents($out.'/index.html',$index);
        file_put_contents($out.'/form.html',self::form());
        $manifest=['schema'=>1,'kind'=>'pattern_review_pack_v1','commit'=>$commit,'strategy_fingerprint'=>$fingerprint,
            'profile'=>'account1','context_applied'=>true,'sampling'=>$made['rule']??self::rule(),
            'counts'=>$counts,'cases'=>count($cases),'problems'=>$problems,
            'case_index'=>array_map(fn($c)=>['case_id'=>$c['case_id'],'dataset'=>$c['dataset'],'cohort'=>$c['cohort'],'pattern'=>$c['pattern'],'status'=>$c['status']],$cases)];
        self::put($out.'/meta/manifest.json',$manifest);
        return $manifest;
    }

    private static function rule():array
    {
        return [
            'selected'=>'every final selected trade of the three patterns, every status',
            'not_selected'=>'at most 10 structures per dataset, pattern and bucket, in case_id order. Buckets: ready_blocked, ready_not_selected, rejected_rr, await_or_invalid, higher_low_watch, higher_low_breakout. Returns are not used. Repeated days of one replay key stay one structure.',
            'no_signal'=>'60 evaluated sessions per dataset with no replay event. Spread across months; remainder goes to the earliest months. Within a month, case_id order, at most 3 per symbol unless the month would otherwise be short.',
            'quality_blocked'=>'at most 20 per dataset, same month and symbol rule. No later prices are used to choose them.',
            'case_id'=>'sha256(dataset|cohort|symbol|session|pattern) first 16 hex characters',
        ];
    }

    private static function dataset(string $out,array $ds,array $replay,array $meta,string $commit,string $fingerprint,array &$problems):array
    {
        $cutoff=(int)$meta['as_of']['close_ts'];$start=$replay['start'];
        $events=[];$eventSessions=[];
        foreach($replay['symbols'] as $s)foreach($s['events'] as $e){
            $events[]=$e+['name'=>$s['name']];
            $eventSessions[$e['symbol']][(int)$e['session']]=true;
        }
        $selected=[];$pooled=[];
        foreach($events as $e){
            if(in_array($e['pattern'],['breakout_retest','trend_pullback','trend_recovery'],true)&&!empty($e['trades']['selected_baseline'])){
                $selected[]=$e;continue;
            }
            $bucket=self::bucket($e);if($bucket===null)continue;
            $id=self::caseId($ds['id'],'not_selected',$e['symbol'],(int)$e['session'],$e['pattern'].':'.$bucket);
            $pooled[$e['pattern'].'|'.$bucket][]=['month'=>substr($e['date'],0,7),'symbol'=>$e['symbol'],'case_id'=>$id,'event'=>$e,'bucket'=>$bucket];
        }
        $picked=[];
        foreach($pooled as $group)foreach(self::takeByCaseId($group,10) as $row)$picked[]=$row;
        $days=self::sessions($ds['dir'],$replay,$start,$cutoff);
        $no=[];$blocked=[];
        foreach($days as $row){
            $id=self::caseId($ds['id'],$row['quality']?'quality_blocked':'no_signal',$row['symbol'],$row['session'],'none');
            $item=['month'=>substr($row['date'],0,7),'symbol'=>$row['symbol'],'case_id'=>$id,'row'=>$row];
            if($row['quality'])$blocked[]=$item;elseif(empty($eventSessions[$row['symbol']][$row['session']]))$no[]=$item;
        }
        $noSample=self::sample($no,60,3);$qSample=self::sample($blocked,20,3);
        $cases=[];
        $write=function(array $spec) use ($out,$ds,$meta,$replay,$commit,$fingerprint,$cutoff,&$cases,&$problems):void{
            $built=self::writeCase($out,$ds,$meta,$replay,$commit,$fingerprint,$cutoff,$spec);
            if($built['problem']!==null)$problems[]=$built['problem'];
            $cases[]=$built['index'];
        };
        foreach($selected as $e)$write(['cohort'=>'selected','event'=>$e,'bucket'=>null]);
        foreach($picked as $row)$write(['cohort'=>'not_selected','event'=>$row['event'],'bucket'=>$row['bucket']]);
        foreach($noSample as $row)$write(['cohort'=>'no_signal','event'=>null,'row'=>$row['row'],'bucket'=>null]);
        foreach($qSample as $row)$write(['cohort'=>'quality_blocked','event'=>null,'row'=>$row['row'],'bucket'=>null]);
        $selectedStatus=[];foreach($selected as $e){$st=$e['trades']['selected_baseline']['status'];$selectedStatus[$e['pattern']][$st]=($selectedStatus[$e['pattern']][$st]??0)+1;}
        $bucketCounts=[];foreach($pooled as $k=>$g)$bucketCounts[$k]=count($g);
        return ['cases'=>$cases,'counts'=>['selected'=>$selectedStatus,'buckets'=>$bucketCounts,'bucket_charts'=>count($picked),
            'no_signal_candidates'=>count($no),'no_signal_charts'=>count($noSample),'quality_candidates'=>count($blocked),'quality_charts'=>count($qSample)],
            'rule'=>self::rule()];
    }

    /** @return list<array{symbol:string,name:string,session:int,date:string,quality:bool,reasons:list<string>}> */
    private static function sessions(string $dir,array $replay,string $start,int $cutoff):array
    {
        $out=[];
        foreach($replay['symbols'] as $s){
            fwrite(STDERR,"sessions ".$s['symbol']."\n");
            $bars=PaperHistoryResearch::readJson($dir.'/bars/'.$s['symbol'].'.json');
            $rows=$bars['rows']??[];
            foreach($rows as &$b)$b['available_at']=CandleClock::closeTime($b,$s['symbol']);unset($b);
            usort($rows,fn($a,$b)=>$a['available_at']<=>$b['available_at']);
            $prefix=[];
            foreach($rows as $bar){
                if((int)$bar['available_at']>$cutoff)continue;
                $prefix[]=$bar;$session=(int)$bar['available_at'];
                if(PaperHistoryResearch::day($session)<$start||!empty($bar['synthetic'])||($bar['is_complete']??true)===false)continue;
                $q=PaperQuality::inspect($prefix,$s['symbol'],$session,['sha256'=>'review-pack','price_basis'=>PaperHistoryResearch::PRICE_BASIS]);
                $out[]=['symbol'=>$s['symbol'],'name'=>$s['name'],'session'=>$session,'date'=>PaperHistoryResearch::day($session),
                    'quality'=>!$q['can_simulate'],'reasons'=>$q['reasons']??[]];
            }
        }
        return $out;
    }

    private static function writeCase(string $out,array $ds,array $meta,array $replay,string $commit,string $fingerprint,int $cutoff,array $spec):array
    {
        $event=$spec['event'];$cohort=$spec['cohort'];
        fwrite(STDERR,"case ".$cohort." ".($event['symbol']??$spec['row']['symbol'])."\n");
        if($event){$symbol=$event['symbol'];$name=$event['name'];$session=(int)$event['session'];$pattern=$event['pattern'];}
        else{$symbol=$spec['row']['symbol'];$name=$spec['row']['name'];$session=(int)$spec['row']['session'];$pattern='none';}
        $id=self::caseId($ds['id'],$cohort,$symbol,$session,$event?$pattern.':'.($spec['bucket']??'selected'):'none');
        $bars=PaperHistoryResearch::readJson($ds['dir'].'/bars/'.$symbol.'.json');
        $rows=$bars['rows']??[];foreach($rows as &$b)$b['available_at']=CandleClock::closeTime($b,$symbol);unset($b);
        usort($rows,fn($a,$c)=>$a['available_at']<=>$c['available_at']);
        $past=array_values(array_filter($rows,fn($b)=>$b['available_at']<=$session));
        $completed=PaperReviewCharts::completedThrough($past,$session);
        $problem=null;$engine=null;
        if($cohort!=='quality_blocked'){
            $engine=PaperPatternReplay::day($past,$symbol,$session,'account1');
            if($event&&($engine['input_hash']??null)!==($event['input_hash']??null))$problem=$id.' input hash';
            if($cohort==='no_signal'&&($engine['status']??'')==='evaluated')foreach($engine['analysis']['plan']['diagnostics']['patterns'] as $patternName=>$raw){
                if(($raw['signal_at']??null)===$session)$problem=$id.' replay has no '.$patternName.' event';
            }
            if($event&&isset($event['trades']['selected_baseline'])){
                $again=PaperPatternReplay::trade($engine['analysis']['plan'],PaperPatternReplay::future($rows,$symbol,$session,$cutoff));
                if($again!=$event['trades']['selected_baseline'])$problem=$id.' outcome';
            }
        }
        $qualityNotes=self::qualityNotes($completed);
        $a=['case_id'=>$id,'symbol'=>$symbol,'name'=>$name,'session_date'=>PaperHistoryResearch::day($session),'session'=>$session,
            'dataset_id'=>$ds['id'],'dataset_hash'=>hash_file('sha256',$ds['dir'].'/dataset.json'),
            'bars_sha256'=>hash_file('sha256',$ds['dir'].'/bars/'.$symbol.'.json'),'commit'=>$commit,
            'strategy_fingerprint'=>$fingerprint,'profile'=>'account1','completed_bars'=>count($completed),
            'first_date'=>$completed?PaperHistoryResearch::day((int)$completed[0]['available_at']):null,
            'last_available_at'=>$completed?(int)end($completed)['available_at']:null,
            'data_notes'=>$qualityNotes,'adjustment_unverified'=>true,
            'charts'=>['d120'=>'a/'.$id.'-120.svg','d40'=>'a/'.$id.'-40.svg','week'=>'a/'.$id.'-week.svg']];
        self::put($out.'/json/a/'.$id.'.json',$a);
        $v120=PaperReviewCharts::view($completed,$session,120);
        $v40=PaperReviewCharts::view($completed,$session,40);
        $week=PaperReviewCharts::weekly($completed,$session,$symbol);
        $weekBars=array_slice($week,-40);
        $weekView=['bars'=>$weekBars,'ma20'=>array_slice(PaperReviewCharts::alignedSma($week,20),-count($weekBars)),'ma60'=>array_fill(0,count($weekBars),null),
            'last_at'=>$weekBars?(int)end($weekBars)['available_at']:0];
        $title=$name.' '.$a['session_date'].' 까지';
        self::put($out.'/a/'.$id.'-120.svg',PaperReviewCharts::svg($v120,[],$title.' · 120거래일',true));
        self::put($out.'/a/'.$id.'-40.svg',PaperReviewCharts::svg($v40,[],$title.' · 40거래일',true));
        self::put($out.'/a/'.$id.'-week.svg',PaperReviewCharts::svg($weekView,[],$title.' · 완료 주봉',true));
        foreach([$v120,$v40,$weekView] as $view){
            foreach($view['bars'] as $bar)if((int)$bar['available_at']>$session)$problem=$id.' chart passes the decision day';
        }
        $probe=$completed;$probe[]=['available_at'=>$session+86400,'open'=>1,'high'=>1,'low'=>1,'close'=>1,'volume'=>1,'is_complete'=>true];
        $again=PaperReviewCharts::view(PaperReviewCharts::completedThrough($probe,$session),$session,120);
        if($again['bars']!==$v120['bars']||$again['ma20']!==$v120['ma20']||$again['ma60']!==$v120['ma60'])$problem=$id.' a later bar changed the decision-day chart';
        $showOutcome=$cohort==='selected'||$cohort==='not_selected';
        self::put($out.'/a/'.$id.'.html',self::pageA($a,$showOutcome));
        $marks=$event?self::marks($event['raw'],$pattern):[];
        $b=['case_id'=>$id,'cohort'=>$cohort,'sampling_rule'=>self::rule()[$cohort]??$cohort,'pattern'=>$pattern,
            'bucket'=>$spec['bucket'],'engine_status'=>$engine['status']??'not_recomputed',
            'pattern_status'=>$event['raw']['status']??null,'gates'=>$event['raw']['gates']??null,
            'day_patterns'=>self::dayPatterns($engine),
            'independent_blockers'=>$event['independent_blockers']??null,'final_status'=>$event['final_status']??null,
            'operational_ready'=>$event['operational_ready']??false,'input_hash'=>$event['input_hash']??($engine['input_hash']??null),
            'entry'=>$event['raw']['entry']??null,'stop'=>$event['raw']['stop']??null,'target'=>$event['raw']['target']??null,
            'reward_risk'=>$event['raw']['reward_risk']??null,'reason'=>$event['raw']['reason']??null,
            'structure'=>$event?self::structure($event['raw'],$pattern):null,
            'coordinates_missing'=>$event?self::missing($event['raw'],$pattern):[],
            'quality_reasons'=>$spec['row']['reasons']??null,'charts'=>['annotated'=>'b/'.$id.'.svg']];
        self::put($out.'/json/b/'.$id.'.json',$b);
        $wide=$v120;
        $earliest=null;foreach($marks as $m)if(($m['at']??null)!==null)$earliest=$earliest===null?min($earliest??$m['at'],$m['at']):min($earliest,$m['at']);
        if($earliest!==null&&$v120['bars']&&$earliest<(int)$v120['bars'][0]['available_at']){
            $need=count(array_filter($completed,fn($bar)=>$bar['available_at']>=$earliest&&$bar['available_at']<=$session));
            $wide=PaperReviewCharts::view($completed,$session,max(120,$need));
        }
        self::put($out.'/b/'.$id.'.svg',PaperReviewCharts::svg($wide,$marks,$title.' · 코드가 기록한 수준',false));
        self::put($out.'/b/'.$id.'.html',self::pageB($id,$b));
        $trade=$event['trades']['selected_baseline']??null;
        if($showOutcome){
            $future=array_values(array_filter(PaperReviewCharts::completedThrough($rows,$cutoff),fn($bar)=>$bar['available_at']>$session));
            $cBars=array_merge($v40['bars'],$future);
            $cView=['bars'=>$cBars,'ma20'=>array_fill(0,count($cBars),null),'ma60'=>array_fill(0,count($cBars),null)];
            $cMarks=[];
            if($trade&&($trade['entry_at']??null))$cMarks[]=['label'=>'진입','price'=>$trade['entry_fill']??null,'at'=>$trade['entry_at'],'kind'=>'entry'];
            if($trade&&($trade['exit_at']??null))$cMarks[]=['label'=>'청산','price'=>$trade['exit_fill']??null,'at'=>$trade['exit_at'],'kind'=>'stop'];
            self::put($out.'/c/'.$id.'.svg',PaperReviewCharts::svg($cView,$cMarks,$title.' 이후 · 결과',false));
            $c=['case_id'=>$id,'status'=>$trade['status']??'no_selected_trade','trade'=>$trade,'horizons'=>$event['horizons']??null,
                'cost'=>'fee 10bp each side, slippage 5bp, daily bar order as TradeSimulator','outcome_limited_to'=>$replay['end'],
                'chart'=>'c/'.$id.'.svg'];
            self::put($out.'/json/c/'.$id.'.json',$c);
            self::put($out.'/c/'.$id.'.html',self::pageC($id,$c));
        }
        return ['problem'=>$problem,'index'=>['case_id'=>$id,'dataset'=>$ds['id'],'cohort'=>$cohort,'pattern'=>$pattern,
            'status'=>$trade['status']??($spec['row']['quality']??false?'quality_blocked':($event['raw']['status']??'no_event'))]];
    }

    private static function qualityNotes(array $completed):array
    {
        $bad=[];$repair=[];
        foreach($completed as $b){
            $day=PaperHistoryResearch::day((int)$b['available_at']);
            if(!empty($b['ohlc_invalid']))$bad[]=['date'=>$day,'open'=>$b['open'],'high'=>$b['high'],'low'=>$b['low'],'close'=>$b['close'],'volume'=>$b['volume']??null];
            if(isset($b['historical_close_repair']))$repair[]=$day;
        }
        return ['invalid_ohlc_dates'=>$bad,'close_repair_dates'=>$repair,'note'=>'수정주가·분할 반영 여부는 확인하지 않았다. 오류 봉은 정상 캔들로 고치지 않는다.'];
    }

    private static function marks(array $raw,string $pattern):array
    {
        $m=[];$add=function(?string $label,$price,$at,string $kind) use (&$m):void{
            if($label===null)return;$m[]=['label'=>$label,'price'=>is_numeric($price)?(float)$price:null,'at'=>is_numeric($at)?(int)$at:null,'kind'=>$kind];
        };
        $add('진입',$raw['entry']??null,$raw['signal_at']??null,'entry');
        $add('손절',$raw['stop']??null,null,'stop');
        $add('목표',$raw['target']??null,null,'target');
        if($pattern==='breakout_retest'){$add('저항',$raw['level']??null,$raw['breakout_at']??null,'structure');$add('재지지',null,$raw['retest_at']??null,'structure');}
        if($pattern==='trend_pullback'&&isset($raw['candidate'])){$add('후보하단',$raw['candidate']['low']??null,null,'structure');$add('후보상단',$raw['candidate']['high']??null,null,'structure');}
        if($pattern==='higher_low'){
            $add('참고종가',$raw['reference_close']??null,null,'structure');
            $ev=$raw['evidence']??[];
            foreach(['support'=>'지지','breakdown'=>'이탈','low'=>'저점','resistance'=>'저항','higher_low'=>'높은저점'] as $k=>$label){
                $add($label,$ev[$k]['price']??null,$ev[$k]['at']??null,'structure');
            }
        }
        if($pattern==='trend_recovery'){$ev=$raw['evidence']??[];
            foreach(['resistance'=>'저항','reference_low'=>'이탈저점','higher_low'=>'높은저점'] as $k=>$label){
                $add($label,$ev[$k]['price']??null,$ev[$k]['at']??null,'structure');
            }
            $add('돌파',$ev['breakout']['close']??null,$ev['breakout']['at']??null,'structure');
            $add('확인',$ev['confirmation']['close']??null,$ev['confirmation']['at']??null,'structure');
        }
        return $m;
    }

    private static function structure(array $raw,string $pattern):array
    {
        return match($pattern){
            'breakout_retest'=>['breakout_at'=>$raw['breakout_at']??null,'retest_at'=>$raw['retest_at']??null,'level'=>$raw['level']??null],
            'trend_recovery'=>$raw['evidence']??[],
            'trend_pullback'=>['signal_at'=>$raw['signal_at']??null,'candidate'=>$raw['candidate']??null,'pullback_start'=>'unrecorded'],
            'higher_low'=>['setup_id'=>$raw['setup_id']??null,'stage'=>$raw['stage']??null,'reference_close'=>$raw['reference_close']??null,'evidence'=>$raw['evidence']??[]],
            default=>[],
        };
    }

    private static function missing(array $raw,string $pattern):array
    {
        $miss=[];
        if($pattern==='trend_pullback')$miss[]='pullback_interval_start';
        if($pattern==='breakout_retest'&&!isset($raw['level']))$miss[]='level';
        if($pattern==='trend_recovery'&&!isset($raw['evidence']['higher_low']))$miss[]='higher_low';
        if($pattern==='higher_low'&&!isset($raw['evidence']))$miss[]='evidence';
        return $miss;
    }

    private static function put(string $path,string|array $body):void
    {
        $dir=dirname($path);if(!is_dir($dir))mkdir($dir,0770,true);
        if(is_array($body))$body=(string)json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        file_put_contents($path,$body);
    }

    private static function dayPatterns(?array $engine):array
    {
        $out=[];$patterns=$engine['analysis']['plan']['diagnostics']['patterns']??[];
        foreach($patterns as $name=>$raw)$out[$name]=['status'=>$raw['status']??null,'ready'=>$raw['ready']??null,'reason'=>$raw['reason']??null,'gates'=>$raw['gates']??null];
        return $out;
    }

    private static function pageA(array $a,bool $outcome):string
    {
        $id=$a['case_id'];$notes=htmlspecialchars(json_encode($a['data_notes'],JSON_UNESCAPED_UNICODE),ENT_QUOTES,'UTF-8');
        $when=(new DateTimeImmutable('@'.$a['last_available_at']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i T');
        $html='<!doctype html><meta charset="utf-8"><title>'.$id.'</title><style>html,body{background:#fff;color:#222;font-family:"Malgun Gothic","Apple SD Gothic Neo",sans-serif;margin:24px}a{color:#1a5276}img{max-width:100%;height:auto}</style>'
            .'<p><a href="../index.html">목록</a></p><h1>'.htmlspecialchars($a['name'].' · '.$a['session_date'],ENT_QUOTES,'UTF-8').'</h1>'
            .'<p>판정일까지 완료된 봉 '.$a['completed_bars'].'개. 마지막 봉 완료 '.htmlspecialchars($when,ENT_QUOTES,'UTF-8')
            .'. 수정주가 여부는 확인되지 않았다.</p><p>'.$notes.'</p>'
            .'<img src="'.$id.'-120.svg" alt="120"><img src="'.$id.'-40.svg" alt="40"><img src="'.$id.'-week.svg" alt="week">'
            .'<p><a href="../b/'.$id.'.html">엔진이 기록한 판정을 연다</a></p>';
        if($outcome)$html.='<p><a href="../c/'.$id.'.html">판정일 이후 결과를 연다</a></p>';
        return $html;
    }

    private static function pageB(string $id,array $b):string
    {
        $json=htmlspecialchars(json_encode($b,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),ENT_QUOTES,'UTF-8');
        $missing=$b['coordinates_missing']??[];
        $note=$missing!==[]?'<p>좌표 미기록: '.htmlspecialchars(implode(', ',$missing),ENT_QUOTES,'UTF-8').'</p>':'';
        return '<!doctype html><meta charset="utf-8"><title>engine '.$id.'</title><style>html,body{background:#fff;color:#222;font-family:"Malgun Gothic","Apple SD Gothic Neo",sans-serif;margin:24px}pre{white-space:pre-wrap}img{max-width:100%;height:auto}</style>'
            .'<p><a href="../a/'.$id.'.html">차트로 돌아간다</a></p><h1>코드 판정</h1>'.$note.'<img src="'.$id.'.svg" alt="annotated"><pre>'.$json.'</pre>';
    }

    private static function pageC(string $id,array $c):string
    {
        $json=htmlspecialchars(json_encode($c,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),ENT_QUOTES,'UTF-8');
        return '<!doctype html><meta charset="utf-8"><title>outcome '.$id.'</title><style>html,body{background:#fff;color:#222;font-family:"Malgun Gothic","Apple SD Gothic Neo",sans-serif;margin:24px}pre{white-space:pre-wrap}img{max-width:100%;height:auto}</style>'
            .'<p><a href="../a/'.$id.'.html">차트로 돌아간다</a></p><h1>이후 결과</h1><img src="'.$id.'.svg" alt="outcome"><pre>'.$json.'</pre>';
    }

    private static function index(array $ids):string
    {
        $links='';foreach($ids as $id)$links.='<li><a href="a/'.$id.'.html">'.$id.'</a></li>';
        return '<!doctype html><meta charset="utf-8"><title>패턴 검토</title><style>html,body{background:#fff;color:#222;font-family:"Malgun Gothic","Apple SD Gothic Neo",sans-serif;margin:24px;max-width:720px}</style>'
            .'<h1>판정일까지의 차트</h1><p>목록은 사례 번호만 보여 준다. 각 화면의 종목과 날짜를 아는 사람은 이후 가격을 떠올릴 수 있으므로, 완전한 블라인드 실험이 아니다.</p>'
            .'<p>엔진 판정과 이후 가격은 각 사례 안에서 따로 열어야 한다. <a href="form.html">검토 양식</a></p><ul>'.$links.'</ul>';
    }

    /** @param list<array{id:string,dir:string,replay:string}> $datasets */
    public static function verifyWritten(string $out,array $datasets):array
    {
        $manifest=self::loadJson($out.'/meta/manifest.json');
        $problems=[];$missing=[];$notes=[];
        $ids=array_column($manifest['case_index'],'case_id');
        if(count($ids)!==count(array_unique($ids)))$problems[]='duplicate case_id';
        $index=(string)file_get_contents($out.'/index.html');
        if(substr_count($index,'<li>')!==count($ids))$problems[]='index link count '.substr_count($index,'<li>').' != '.count($ids);
        foreach(['trend_pullback','breakout_retest','trend_recovery','net_return','reward_risk'] as $word){
            if(str_contains($index,$word))$problems[]='index leaks '.$word;
        }
        $closedPullback=0;$cohorts=[];
        foreach($manifest['case_index'] as $c){
            $cohorts[$c['cohort']]=($cohorts[$c['cohort']]??0)+1;
            if($c['cohort']==='selected'&&$c['pattern']==='trend_pullback'&&$c['status']==='closed')$closedPullback++;
            $id=$c['case_id'];$needC=$c['cohort']==='selected'||$c['cohort']==='not_selected';
            foreach(['a/'.$id.'.html','a/'.$id.'-120.svg','a/'.$id.'-40.svg','a/'.$id.'-week.svg','b/'.$id.'.html','b/'.$id.'.svg','json/a/'.$id.'.json','json/b/'.$id.'.json'] as $rel){
                if(!is_file($out.'/'.$rel))$missing[]=$rel;
            }
            foreach(['c/'.$id.'.html','c/'.$id.'.svg','json/c/'.$id.'.json'] as $rel){
                $exists=is_file($out.'/'.$rel);
                if($needC&&!$exists)$missing[]=$rel;
                if(!$needC&&$exists)$problems[]=$id.' has an outcome file';
            }
            if(!is_file($out.'/json/a/'.$id.'.json'))continue;
            $a=self::loadJson($out.'/json/a/'.$id.'.json');
            foreach(self::A_FORBIDDEN as $key)if(array_key_exists($key,$a))$problems[]=$id.' A contains '.$key;
            if(in_array($a['name']??'',['trend_pullback','trend_recovery','breakout_retest','higher_low','none'],true))$problems[]=$id.' display name is a pattern id';
            if((int)($a['last_available_at']??0)>(int)($a['session']??0))$problems[]=$id.' last bar is after the decision day';
            foreach(['-120','-40','-week'] as $suffix){
                $svg=(string)file_get_contents($out.'/a/'.$id.$suffix.'.svg');
                if(!preg_match('/last_available_at=(\d+)/',$svg,$m))$problems[]=$id.$suffix.' has no decision stamp';
                elseif((int)$m[1]>(int)$a['session'])$problems[]=$id.$suffix.' draws a bar after the decision day';
            }
            $bSvg=(string)file_get_contents($out.'/b/'.$id.'.svg');
            if(preg_match('/last_available_at=(\d+)/',$bSvg,$m)&&(int)$m[1]>(int)$a['session'])$problems[]=$id.' annotated chart passes the decision day';
        }
        if($closedPullback!==137)$problems[]='closed pullback charts '.$closedPullback.' (kept expectation 137)';
        $dirs=[];foreach($datasets as $ds)$dirs[$ds['id']]=$ds['dir'];
        $cache=[];$mismatch=0;$compared=0;
        foreach($manifest['case_index'] as $c){
            $id=$c['case_id'];$a=self::loadJson($out.'/json/a/'.$id.'.json');
            $key=$a['dataset_id'].'|'.$a['symbol'];
            if(!isset($cache[$key])){
                $rows=PaperHistoryResearch::readJson($dirs[$a['dataset_id']].'/bars/'.$a['symbol'].'.json')['rows']??[];
                foreach($rows as &$bar)$bar['available_at']=CandleClock::closeTime($bar,$a['symbol']);
                unset($bar);
                usort($rows,fn($x,$y)=>$x['available_at']<=>$y['available_at']);
                $cache[$key]=$rows;
            }
            $session=(int)$a['session'];
            $past=array_values(array_filter($cache[$key],fn($bar)=>$bar['available_at']<=$session));
            $completed=PaperReviewCharts::completedThrough($past,$session);
            $title=$a['name'].' '.$a['session_date'].' 까지';
            $views=[
                '-120'=>PaperReviewCharts::view($completed,$session,120),
                '-40'=>PaperReviewCharts::view($completed,$session,40),
            ];
            $week=PaperReviewCharts::weekly($completed,$session,$a['symbol']);
            $weekBars=array_slice($week,-40);
            $views['-week']=['bars'=>$weekBars,'ma20'=>array_slice(PaperReviewCharts::alignedSma($week,20),-count($weekBars)),'ma60'=>array_fill(0,count($weekBars),null),'last_at'=>$weekBars?(int)end($weekBars)['available_at']:0];
            $labels=['-120'=>' · 120거래일','-40'=>' · 40거래일','-week'=>' · 완료 주봉'];
            foreach($views as $suffix=>$view){
                $compared++;
                if(PaperReviewCharts::svg($view,[],$title.$labels[$suffix],true)!==(string)file_get_contents($out.'/a/'.$id.$suffix.'.svg'))$mismatch++;
            }
        }
        if($mismatch)$problems[]=$mismatch.' of '.$compared.' decision-day charts do not match a fresh render of the stored bars';
        else $notes[]=$compared.' decision-day charts match a fresh render of the stored bars';
        $eventById=[];$cutoffs=[];
        foreach($datasets as $ds){
            $meta=PaperHistoryResearch::readJson($ds['dir'].'/dataset.json');
            $cutoffs[$ds['id']]=(int)$meta['as_of']['close_ts'];
            $replay=self::loadJson($ds['replay']);
            foreach($replay['symbols'] as $s)foreach($s['events'] as $e){
                $e['name']=$s['name'];
                if(in_array($e['pattern'],['breakout_retest','trend_pullback','trend_recovery'],true)&&!empty($e['trades']['selected_baseline'])){
                    $eventById[self::caseId($ds['id'],'selected',$e['symbol'],(int)$e['session'],$e['pattern'].':selected')]=$e;
                }
                $bucket=self::bucket($e);
                if($bucket!==null)$eventById[self::caseId($ds['id'],'not_selected',$e['symbol'],(int)$e['session'],$e['pattern'].':'.$bucket)]=$e;
            }
        }
        $annotated=0;$annotatedMismatch=0;$outcomeMismatch=0;$outcomeChecked=0;
        foreach($manifest['case_index'] as $c){
            $id=$c['case_id'];$a=self::loadJson($out.'/json/a/'.$id.'.json');
            $session=(int)$a['session'];$rows=$cache[$a['dataset_id'].'|'.$a['symbol']];
            $past=array_values(array_filter($rows,fn($bar)=>$bar['available_at']<=$session));
            $completed=PaperReviewCharts::completedThrough($past,$session);
            $v120=PaperReviewCharts::view($completed,$session,120);
            $event=$eventById[$id]??null;
            $marks=$event?self::marks($event['raw'],$event['pattern']):[];
            $wide=$v120;$earliest=null;
            foreach($marks as $m)if(($m['at']??null)!==null)$earliest=$earliest===null?$m['at']:min($earliest,$m['at']);
            if($earliest!==null&&$v120['bars']&&$earliest<(int)$v120['bars'][0]['available_at']){
                $need=count(array_filter($completed,fn($bar)=>$bar['available_at']>=$earliest&&$bar['available_at']<=$session));
                $wide=PaperReviewCharts::view($completed,$session,max(120,$need));
            }
            $title=$a['name'].' '.$a['session_date'].' 까지';
            $annotated++;
            if(PaperReviewCharts::svg($wide,$marks,$title.' · 코드가 기록한 수준',false)!==(string)file_get_contents($out.'/b/'.$id.'.svg'))$annotatedMismatch++;
            if($c['cohort']!=='selected'&&$c['cohort']!=='not_selected')continue;
            $trade=$event['trades']['selected_baseline']??null;
            $future=array_values(array_filter(PaperReviewCharts::completedThrough($rows,$cutoffs[$a['dataset_id']]),fn($bar)=>$bar['available_at']>$session));
            $v40=PaperReviewCharts::view($completed,$session,40);
            $cBars=array_merge($v40['bars'],$future);
            $cView=['bars'=>$cBars,'ma20'=>array_fill(0,count($cBars),null),'ma60'=>array_fill(0,count($cBars),null)];
            $cMarks=[];
            if($trade&&($trade['entry_at']??null))$cMarks[]=['label'=>'진입','price'=>$trade['entry_fill']??null,'at'=>$trade['entry_at'],'kind'=>'entry'];
            if($trade&&($trade['exit_at']??null))$cMarks[]=['label'=>'청산','price'=>$trade['exit_fill']??null,'at'=>$trade['exit_at'],'kind'=>'stop'];
            $outcomeChecked++;
            if(PaperReviewCharts::svg($cView,$cMarks,$title.' 이후 · 결과',false)!==(string)file_get_contents($out.'/c/'.$id.'.svg'))$outcomeMismatch++;
            $cSvg=(string)file_get_contents($out.'/c/'.$id.'.svg');
            if(preg_match('/last_available_at=(\d+)/',$cSvg,$m)&&(int)$m[1]>$cutoffs[$a['dataset_id']])$problems[]=$id.' outcome chart passes the dataset cutoff';
        }
        if($annotatedMismatch)$problems[]=$annotatedMismatch.' annotated charts differ from the stored coordinates';
        else $notes[]=$annotated.' annotated charts match the stored coordinates';
        if($outcomeMismatch)$problems[]=$outcomeMismatch.' outcome charts differ from the stored bars';
        else $notes[]=$outcomeChecked.' outcome charts match the stored bars through the dataset cutoff';
        $notes[]='cohorts '.json_encode($cohorts,JSON_UNESCAPED_UNICODE);
        return ['cases'=>count($ids),'closed_pullback'=>$closedPullback,'chart_files_compared'=>$compared,'chart_mismatches'=>$mismatch,'missing_count'=>count($missing),'missing'=>array_slice($missing,0,30),'problems'=>$problems,'notes'=>$notes,'manifest_problems'=>$manifest['problems']??[]];
    }

    private static function form():string
    {
        return '<!doctype html><meta charset="utf-8"><title>검토 양식</title><style>html,body{background:#fff;color:#222;font-family:"Malgun Gothic","Apple SD Gothic Neo",sans-serif;margin:24px;max-width:720px}label{display:block;margin:12px 0 4px}textarea,input{width:100%;box-sizing:border-box}</style>'
            .'<h1>검토 양식</h1><p>이후 결과를 열기 전에 적는다.</p>'
            .'<label>case_id</label><input name="case_id">'
            .'<label>판정: 원문 패턴에 부합 / 불충분 / 불일치 / 판정 불가</label><input name="verdict">'
            .'<label>근거 원문</label><textarea name="source"></textarea>'
            .'<label>차트상의 근거</label><textarea name="chart"></textarea>'
            .'<label>코드와 차이가 나는 조건</label><textarea name="diff"></textarea>'
            .'<label>데이터 문제 가능성</label><textarea name="data"></textarea>'
            .'<label>판단 확신도</label><input name="confidence">'
            .'<label>미래 결과 공개 전 판정 시각</label><input name="judged_at">';
    }
}
