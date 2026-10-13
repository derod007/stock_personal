<?php
declare(strict_types=1);
require_once __DIR__.'/ReviewCharts.php';
require_once __DIR__.'/ReviewPack.php';
require_once __DIR__.'/HistoryResearch.php';
use ChartEntryLab\{CandleClock,ChartPlanEngine,PaperQuality};

/**
 * Review pack for every closed trend_pullback_v1 trade in the dated TOP100 year replay.
 * Research screens only. Does not collect prices, rerun an account, or change strategy files.
 */
final class PaperPullbackYearReview
{
    public const VERSION='pullback_year_review_v1';
    public const DATASET='kr-dated-amount-top100-20251008-20261008-v2';
    public const PATTERN='trend_pullback_v1';
    public const EXPECTED=42;
    public const FINGERPRINT='934012285f2ddb3867fff4576dba05fb7df290a36a987c36fc260e8917bbe465';
    public const A_FORBIDDEN=['pattern','entry','stop','target','net_pnl','net_return_pct','ready','score','outcome',
        'selected','reward_risk','win','exit_reason','fill','cancelled','planned_risk'];

    public static function loadJson(string $path):array
    {
        $data=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('JSON is not an object: '.$path);
        return $data;
    }

    public static function write(string $path,string|array $body):void
    {
        $dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Cannot create '.$dir);
        if(is_array($body))$body=(string)json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
        file_put_contents($path,str_replace("\r\n","\n",$body));
    }

    public static function caseId(string $symbol,string $signalDate):string
    {
        return substr(hash('sha256',self::DATASET.'|closed_pullback|'.$symbol.'|'.$signalDate.'|'.self::PATTERN),0,16);
    }

    /** @return list<array<string,mixed>> */
    public static function extract(array $tradesFile):array
    {
        $rows=[];
        foreach($tradesFile['trades']??[] as $t){
            if(($t['pattern']??'')!==self::PATTERN||($t['status']??'')!=='closed')continue;
            $rows[]=$t;
        }
        usort($rows,fn($a,$b)=>[$a['signal_date'],$a['symbol']]<=>[$b['signal_date'],$b['symbol']]);
        foreach($rows as $i=>&$t){$t['case_id']=self::caseId((string)$t['symbol'],(string)$t['signal_date']);$t['index']=$i+1;}
        unset($t);
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows */
    public static function inputRows(array $completed):array
    {
        $ma20=PaperReviewCharts::alignedSma($completed,20);$ma60=PaperReviewCharts::alignedSma($completed,60);$out=[];
        foreach($completed as $i=>$b)$out[]=['date'=>PaperHistoryResearch::day((int)$b['available_at']),'available_at'=>(int)$b['available_at'],
            'open'=>(float)$b['open'],'high'=>(float)$b['high'],'low'=>(float)$b['low'],'close'=>(float)$b['close'],
            'volume'=>isset($b['volume'])&&is_numeric($b['volume'])?(float)$b['volume']:null,'ohlc_invalid'=>!empty($b['ohlc_invalid']),
            'ma20'=>$ma20[$i]??null,'ma60'=>$ma60[$i]??null];
        return $out;
    }

    /** @param list<array<string,mixed>> $bars */
    public static function windows(array $bars,array $features):array
    {
        $span=function(array $slice,string $label) use ($bars):array{
            if($slice===[])return ['label'=>$label,'missing'=>true,'bar_count'=>0];
            $minI=0;$maxI=0;$min=(float)$slice[0]['low'];$max=(float)$slice[0]['high'];
            foreach($slice as $i=>$b){
                if((float)$b['low']<$min){$min=(float)$b['low'];$minI=$i;}
                if((float)$b['high']>$max){$max=(float)$b['high'];$maxI=$i;}
            }
            $first=$slice[0];$last=$slice[array_key_last($slice)];
            return ['label'=>$label,'missing'=>false,'bar_count'=>count($slice),
                'first_date'=>PaperHistoryResearch::day((int)$first['available_at']),
                'last_date'=>PaperHistoryResearch::day((int)$last['available_at']),
                'first_at'=>(int)$first['available_at'],'last_at'=>(int)$last['available_at'],
                'min_low'=>$min,'min_low_date'=>PaperHistoryResearch::day((int)$slice[$minI]['available_at']),
                'min_low_at'=>(int)$slice[$minI]['available_at'],
                'max_high'=>$max,'max_high_date'=>PaperHistoryResearch::day((int)$slice[$maxI]['available_at']),
                'max_high_at'=>(int)$slice[$maxI]['available_at']];
        };
        $n=count($bars);$atr=(float)($features['atr14']??0);
        $closes=array_column($bars,'close');
        $ma=$n>=20?array_sum(array_slice($closes,-20))/20:null;
        $oldMa=$n>=25?array_sum(array_slice($closes,-25,20))/20:null;
        $baseVol=$n>=24?array_sum(array_column(array_slice($bars,-24,20),'volume'))/20:null;
        $pullVol=$n>=4?array_sum(array_column(array_slice($bars,-4,3),'volume'))/3:null;
        return [
            'rising_recent'=>$span(array_slice($bars,-20),'추세 비교 최근 20봉'),
            'rising_prior'=>$span(array_slice($bars,-40,20),'추세 비교 직전 20봉'),
            'ma20_closes'=>$span(array_slice($bars,-20),'MA20 종가 20봉'),
            'old_ma_closes'=>$span(array_slice($bars,-25,20),'직전 MA20 종가 20봉'),
            'near_ma20'=>$span(array_slice($bars,-6,5),'근접 확인 직전 5봉'),
            'stop_window'=>$span(array_slice($bars,-10),'손절 기준 최근 10봉'),
            'target_window'=>$span(array_slice($bars,-21,20),'목표 기준 마지막 봉 제외 20봉'),
            'volume_base'=>$span(array_slice($bars,-24,20),'거래량 기준 20봉'),
            'volume_pull'=>$span(array_slice($bars,-4,3),'눌림 거래량 3봉'),
            'signal_bar'=>$span($n!==0?[end($bars)]:[],'확인 봉'),
            'values'=>['ma20'=>$ma,'old_ma20'=>$oldMa,'atr14'=>$atr,
                'near_threshold'=>$ma!==null?$ma+0.75*$atr:null,
                'stop_raw'=>$n>=10?min(array_column(array_slice($bars,-10),'low'))-0.2*$atr:null,
                'target_raw'=>$n>=21?max(array_column(array_slice($bars,-21,20),'high')):null,
                'base_volume'=>$baseVol,'pull_volume'=>$pullVol,
                'volume_ratio'=>$baseVol>0&&$pullVol!==null?$pullVol/$baseVol:null],
            'pullback_interval_start'=>'unrecorded',
            'note'=>'코드는 눌림 시작 고점·저점을 저장하지 않는다. 위 구간은 TrendPullback이 실제로 쓰는 고정 창이다.',
        ];
    }

    public static function diffs(array $stored,array $pullback,array $plan):array
    {
        $out=[];
        $cmp=function(string $field,$want,$got) use (&$out):void{
            if($want===null&&$got===null)return;
            if(is_numeric($want)&&is_numeric($got)){
                if(abs((float)$want-(float)$got)>1e-6)$out[]=['field'=>$field,'stored'=>$want,'recomputed'=>$got];
                return;
            }
            if($want!==$got)$out[]=['field'=>$field,'stored'=>$want,'recomputed'=>$got];
        };
        $cmp('entry',$stored['entry']??null,$pullback['entry']??null);
        $cmp('stop',$stored['stop']??null,$pullback['stop']??null);
        $cmp('target',$stored['target']??null,$pullback['target']??null);
        $cmp('pattern',$stored['pattern']??null,$pullback['version']??null);
        $ready=$pullback['ready']??null;
        if($ready!==true)$out[]=['field'=>'ready','stored'=>true,'recomputed'=>$ready];
        if(($plan['pattern']??null)!==self::PATTERN)$out[]=['field'=>'selected_pattern','stored'=>self::PATTERN,'recomputed'=>$plan['pattern']??null];
        if(($plan['ready']??null)!==true)$out[]=['field'=>'final_ready','stored'=>true,'recomputed'=>$plan['ready']??null];
        $storedRr=null;
        if(is_numeric($stored['entry']??null)&&is_numeric($stored['stop']??null)&&is_numeric($stored['target']??null)&&$stored['entry']>$stored['stop']){
            $storedRr=($stored['target']-$stored['entry'])/($stored['entry']-$stored['stop']);
        }
        $cmp('reward_risk',$storedRr!==null?round($storedRr,3):null,$pullback['reward_risk']??null);
        return $out;
    }

    public static function build(string $out,string $datasetDir,string $tradesPath,string $commit):array
    {
        $fingerprint=PaperStrategyVersion::current();
        if($fingerprint!==self::FINGERPRINT)throw new RuntimeException('Strategy fingerprint changed; refusing to rebuild review screens');
        $problems=PaperHistoryResearch::verify($datasetDir);
        if($problems)throw new RuntimeException('Dataset: '.implode('; ',$problems)."\n".$datasetDir);
        $meta=PaperHistoryResearch::readJson($datasetDir.'/dataset.json')??throw new RuntimeException('Missing '.$datasetDir.'/dataset.json');
        if(($meta['dataset']??'')!==self::DATASET)throw new RuntimeException('Unexpected dataset '.($meta['dataset']??''));
        $trades=self::extract(self::loadJson($tradesPath));
        if(count($trades)!==self::EXPECTED)throw new RuntimeException('Closed pullback trades '.count($trades).' (expected '.self::EXPECTED.')');
        $ids=[];$cases=[];$mismatchCases=0;
        foreach($trades as $t){
            $built=self::writeCase($out,$datasetDir,$meta,$t,$commit,$fingerprint);
            $ids[]=$built['case_id'];$cases[]=$built;
            if($built['diffs']!==[])$mismatchCases++;
        }
        sort($ids);
        self::write($out.'/OPEN.txt',"index.html 을 여세요.\n코드 판정과 이후 결과는 사례 화면 안의 링크를 눌러 따로 엽니다.\n성과 요약은 이미 알려져 있으므로 완전한 블라인드가 아닙니다. 첫 화면에는 손익을 넣지 않았습니다.\n");
        self::write($out.'/index.html',self::index($cases));
        $list=['schema'=>1,'kind'=>self::VERSION,'label'=>'KRX 일별 거래대금 TOP100 연구','not_fully_blind'=>true,
            'pr77'=>'open_unmerged_analysis_reference_only','expected'=>self::EXPECTED,'count'=>count($trades),
            'trades_path'=>'docs/historical-top100-replay-20251008-20261008/account/trades.json',
            'trades_sha256'=>hash_file('sha256',$tradesPath),'dataset'=>self::DATASET,
            'dataset_json_sha256'=>hash_file('sha256',$datasetDir.'/dataset.json'),
            'strategy_fingerprint'=>$fingerprint,'commit'=>$commit,
            'selection'=>'every closed trend_pullback_v1 row in stored trades.json; not sampled by PnL',
            'cases'=>array_map(fn($c)=>['index'=>$c['index'],'case_id'=>$c['case_id'],'symbol'=>$c['symbol'],'name'=>$c['name'],
                'signal_date'=>$c['signal_date'],'session'=>$c['session'],'bars_sha256'=>$c['bars_sha256'],
                'a_input_sha256'=>$c['a_input_sha256']],$cases)];
        self::write($out.'/list.json',$list);
        self::write($out.'/meta/manifest.json',['schema'=>1,'kind'=>self::VERSION,'commit'=>$commit,'strategy_fingerprint'=>$fingerprint,
            'cases'=>count($cases),'mismatch_cases'=>$mismatchCases,'case_ids'=>$ids]);
        return $list+['mismatch_cases'=>$mismatchCases];
    }

    private static function writeCase(string $out,string $datasetDir,array $meta,array $t,string $commit,string $fingerprint):array
    {
        $symbol=(string)$t['symbol'];$signalDate=(string)$t['signal_date'];$id=(string)$t['case_id'];
        $barsPath=$datasetDir.'/bars/'.$symbol.'.json';
        if(!is_file($barsPath))throw new RuntimeException('Missing stored bars '.$barsPath);
        $file=PaperHistoryResearch::readJson($barsPath)??throw new RuntimeException('Unreadable '.$barsPath);
        $name=(string)($file['name']??$symbol);
        $rows=$file['rows']??[];
        foreach($rows as &$b)$b['available_at']=CandleClock::closeTime($b,$symbol);unset($b);
        usort($rows,fn($a,$c)=>$a['available_at']<=>$c['available_at']);
        $session=null;
        foreach($rows as $b){if(PaperHistoryResearch::day((int)$b['available_at'])===$signalDate){$session=(int)$b['available_at'];break;}}
        if($session===null)throw new RuntimeException('No bar on signal date '.$signalDate.' in '.$barsPath);
        $past=array_values(array_filter($rows,fn($b)=>$b['available_at']<=$session));
        $completed=PaperReviewCharts::completedThrough($past,$session);
        $valid=CandleClock::completed($past,$symbol,$session);
        $source=['sha256'=>hash_file('sha256',$barsPath),'price_basis'=>PaperHistoryResearch::PRICE_BASIS,'kind'=>'research_dataset','dataset'=>self::DATASET];
        $quality=PaperQuality::inspect($past,$symbol,$session,$source,[]);
        $engine=(new ChartPlanEngine())->analyze($valid,$symbol,$session);
        $plan=$engine['plan'];$pullback=$plan['diagnostics']['patterns']['trend_pullback']??[];
        $win=self::windows($valid,$engine['features']);
        $diffs=self::diffs($t,$pullback,$plan);
        $input=self::inputRows($completed);
        $aMeta=['case_id'=>$id,'index'=>$t['index'],'symbol'=>$symbol,'name'=>$name,'session_date'=>$signalDate,'session'=>$session,
            'dataset_id'=>self::DATASET,'bars_sha256'=>hash_file('sha256',$barsPath),'commit'=>$commit,
            'strategy_fingerprint'=>$fingerprint,'completed_bars'=>count($completed),
            'first_date'=>$completed?PaperHistoryResearch::day((int)$completed[0]['available_at']):null,
            'last_available_at'=>$completed?(int)end($completed)['available_at']:null,
            'indicator_input'=>['bars'=>count($input),'first_date'=>$input[0]['date']??null,'last_date'=>$input!==[]?$input[array_key_last($input)]['date']:null,
                'last_available_at'=>$input!==[]?$input[array_key_last($input)]['available_at']:null,'sha256'=>PaperReviewPack::inputHash($input)],
            'charts'=>['d120'=>'a/'.$id.'-120.svg','d240'=>'a/'.$id.'-240.svg']];
        foreach(self::A_FORBIDDEN as $k)if(array_key_exists($k,$aMeta))throw new RuntimeException('A leaked '.$k);
        self::write($out.'/json/a/'.$id.'.json',$aMeta);
        self::write($out.'/json/a/'.$id.'-input.json',['through'=>'decision_day','indicator_input'=>$aMeta['indicator_input'],'bars'=>$input]);
        $v120=PaperReviewCharts::view($completed,$session,120);
        $v240=PaperReviewCharts::view($completed,$session,240);
        $title=$name.' '.$signalDate.' 까지';
        self::write($out.'/a/'.$id.'-120.svg',PaperReviewCharts::svg($v120,[],$title.' · 120거래일',true,$symbol));
        self::write($out.'/a/'.$id.'-240.svg',PaperReviewCharts::svg($v240,[],$title.' · 240거래일',true,$symbol));
        foreach([$v120,$v240] as $view)foreach($view['bars'] as $bar)if((int)$bar['available_at']>$session)throw new RuntimeException($id.' A chart passes the decision day');
        $probe=$completed;$probe[]=['available_at'=>$session+86400,'open'=>1,'high'=>1,'low'=>1,'close'=>1,'volume'=>1,'is_complete'=>true];
        $again=PaperReviewCharts::view(PaperReviewCharts::completedThrough($probe,$session),$session,120);
        if($again['bars']!==$v120['bars']||$again['ma20']!==$v120['ma20']||$again['ma60']!==$v120['ma60'])throw new RuntimeException($id.' later bar changed A');
        self::write($out.'/a/'.$id.'.html',self::pageA($aMeta));
        $bMarks=[
            ['label'=>'진입','price'=>is_numeric($pullback['entry']??null)?(float)$pullback['entry']:null,'at'=>is_numeric($pullback['signal_at']??null)?(int)$pullback['signal_at']:(int)$session,'kind'=>'entry'],
            ['label'=>'손절','price'=>is_numeric($pullback['stop']??null)?(float)$pullback['stop']:null,'at'=>null,'kind'=>'stop'],
            ['label'=>'목표','price'=>is_numeric($pullback['target']??null)?(float)$pullback['target']:null,'at'=>null,'kind'=>'target'],
        ];
        $b=['case_id'=>$id,'symbol'=>$symbol,'session_date'=>$signalDate,
            'recomputed_from'=>'ChartPlanEngine on completed bars through the signal day only',
            'independent_strategy_implementation'=>false,
            'quality'=>['can_simulate'=>$quality['can_simulate']??null,'status'=>$quality['status']??null,'reasons'=>$quality['reasons']??[]],
            'pullback'=>$pullback,'gates'=>$pullback['gates']??null,'windows'=>$win,
            'context'=>$plan['context']??null,'final_status'=>$plan['status']??null,'final_ready'=>$plan['ready']??null,
            'selected_pattern'=>$plan['pattern']??null,'common_reason'=>$plan['reason']??null,
            'features_risk'=>['spike_dump_status'=>$engine['features']['spike_dump_status']??null,
                'top_pattern_status'=>$engine['features']['top_pattern_status']??null,
                'top_pattern_phase'=>$engine['features']['top_pattern_phase']??null,
                'playbook'=>$engine['decision']??null],
            'stored_levels'=>['entry'=>$t['entry'],'stop'=>$t['stop'],'target'=>$t['target']],
            'diffs'=>$diffs,'coordinates_missing'=>['pullback_interval_start'],
            'charts'=>['annotated'=>'b/'.$id.'.svg']];
        self::write($out.'/json/b/'.$id.'.json',$b);
        self::write($out.'/b/'.$id.'.svg',PaperReviewCharts::svg($v240,$bMarks,$title.' · 코드가 사용한 수준',false,$symbol));
        self::write($out.'/b/'.$id.'.html',self::pageB($id,$b));
        $cutoff=(int)$meta['as_of']['close_ts'];
        $future=array_values(array_filter(PaperReviewCharts::completedThrough($rows,$cutoff),fn($bar)=>$bar['available_at']>$session));
        $exitDay=(string)($t['exit']['date']??'');
        $kept=[];$afterExit=0;
        foreach($future as $bar){
            $day=PaperHistoryResearch::day((int)$bar['available_at']);
            if($exitDay!==''&&$day>$exitDay){$afterExit++;if($afterExit>5)continue;}
            $kept[]=$bar;
        }
        $cBars=array_merge(array_slice($v120['bars'],-20),$kept);
        $cView=['bars'=>$cBars,'ma20'=>array_fill(0,count($cBars),null),'ma60'=>array_fill(0,count($cBars),null),
            'last_at'=>$cBars?(int)end($cBars)['available_at']:0];
        $fillAt=null;$exitAt=null;
        foreach($rows as $bar){
            $day=PaperHistoryResearch::day((int)$bar['available_at']);
            if($day===($t['fill']['date']??null))$fillAt=(int)$bar['available_at'];
            if($day===($t['exit']['date']??null))$exitAt=(int)$bar['available_at'];
        }
        $cMarks=[];
        if($fillAt)$cMarks[]=['label'=>'체결','price'=>is_numeric($t['fill']['price']??null)?(float)$t['fill']['price']:null,'at'=>$fillAt,'kind'=>'entry'];
        if($exitAt)$cMarks[]=['label'=>'청산','price'=>is_numeric($t['exit']['price']??null)?(float)$t['exit']['price']:null,'at'=>$exitAt,'kind'=>'stop'];
        self::write($out.'/c/'.$id.'.svg',PaperReviewCharts::svg($cView,$cMarks,$title.' 이후 · 저장 체결·청산',false,$symbol));
        $c=['case_id'=>$id,'status'=>'closed','trade'=>['symbol'=>$symbol,'signal_date'=>$signalDate,'quantity'=>$t['quantity'],
            'entry'=>$t['entry'],'stop'=>$t['stop'],'target'=>$t['target'],'planned_risk'=>$t['planned_risk'],
            'reserved_cash'=>$t['reserved_cash'],'fill'=>$t['fill'],'exit'=>$t['exit']],
            'cost'=>'research fee 10bp each side, slippage 5bp; amounts below are the stored ledger, not a new run',
            'chart'=>'c/'.$id.'.svg'];
        self::write($out.'/json/c/'.$id.'.json',$c);
        self::write($out.'/c/'.$id.'.html',self::pageC($id,$c));
        return ['case_id'=>$id,'index'=>$t['index'],'symbol'=>$symbol,'name'=>$name,'signal_date'=>$signalDate,'session'=>$session,
            'bars_sha256'=>hash_file('sha256',$barsPath),'a_input_sha256'=>$aMeta['indicator_input']['sha256'],'diffs'=>$diffs];
    }

    private static function esc(string $s):string
    {
        return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    private static function pageA(array $a):string
    {
        $id=$a['case_id'];
        $when=(new DateTimeImmutable('@'.$a['last_available_at']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i T');
        return '<!doctype html><meta charset="utf-8"><title>A '.$id.'</title>'
            .'<style>html,body{background:#fff;color:#222;font-family:"Malgun Gothic","Apple SD Gothic Neo",sans-serif;margin:24px}a{color:#1a5276}img{max-width:100%;height:auto}</style>'
            .'<p><a href="../index.html">목록</a></p><h1>'.self::esc($a['name'].' · '.$a['session_date']).'</h1>'
            .'<p>신호일까지 완료된 봉 '.$a['completed_bars'].'개. 마지막 봉 완료 '.self::esc($when)
            .'. 이 화면에는 이후 봉, 성과, 코드 판정을 넣지 않았다.</p>'
            .'<img src="'.$id.'-120.svg" alt="120"><img src="'.$id.'-240.svg" alt="240">'
            .'<p><a href="../b/'.$id.'.html">코드 판정(B)</a></p>'
            .'<p><a href="../c/'.$id.'.html">이후 가격과 저장 체결(C)</a></p>';
    }

    private static function pageB(string $id,array $b):string
    {
        $g=$b['gates']??[];$v=$b['windows']['values']??[];
        $rows='';
        foreach(['rising_recent','rising_prior','near_ma20','stop_window','target_window','volume_base','volume_pull','signal_bar'] as $k){
            $w=$b['windows'][$k]??[];if(($w['missing']??true))continue;
            $rows.='<tr><td>'.self::esc($w['label']).'</td><td>'.$w['first_date'].' ~ '.$w['last_date'].'</td><td>'.$w['bar_count']
                .'</td><td>저 '.$w['min_low_date'].' / '.$w['min_low'].'</td><td>고 '.$w['max_high_date'].' / '.$w['max_high'].'</td></tr>';
        }
        $diff=$b['diffs']===[]?'저장 진입·손절·목표와 재계산이 같다.':self::esc((string)json_encode($b['diffs'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        $json=self::esc((string)json_encode($b,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        return '<!doctype html><meta charset="utf-8"><title>B '.$id.'</title>'
            .'<style>html,body{background:#fff;color:#222;font-family:"Malgun Gothic","Apple SD Gothic Neo",sans-serif;margin:24px}table{border-collapse:collapse}td,th{border:1px solid #ccc;padding:4px 8px}pre{white-space:pre-wrap}img{max-width:100%;height:auto}</style>'
            .'<p><a href="../a/'.$id.'.html">A로</a></p><h1>코드 판정</h1>'
            .'<p>당일까지의 완료봉만 다시 넣었다. 불일치는 고치지 않고 기록한다. 눌림 시작 고점·저점은 코드가 저장하지 않아 정답처럼 표시하지 않는다.</p>'
            .'<img src="'.$id.'.svg" alt="B">'
            .'<h2>게이트</h2><pre>'.self::esc((string)json_encode($g,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)).'</pre>'
            .'<h2>계산 구간</h2><table><tr><th>창</th><th>날짜</th><th>봉</th><th>창 안 최저</th><th>창 안 최고</th></tr>'.$rows.'</table>'
            .'<p>MA20 '.($v['ma20']??'').' · 직전 MA20 '.($v['old_ma20']??'').' · ATR14 '.($v['atr14']??'')
            .' · 근접 기준 '.($v['near_threshold']??'').' · 손절 원값 '.($v['stop_raw']??'').' · 목표 원값 '.($v['target_raw']??'')
            .' · 거래량비 '.($v['volume_ratio']??'').'</p>'
            .'<h2>공통 위험</h2><pre>'.self::esc((string)json_encode(['final_status'=>$b['final_status'],'final_ready'=>$b['final_ready'],
                'selected_pattern'=>$b['selected_pattern'],'reason'=>$b['common_reason'],'context'=>$b['context'],
                'quality'=>$b['quality'],'features_risk'=>$b['features_risk']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)).'</pre>'
            .'<h2>저장 대비</h2><pre>'.$diff.'</pre>'
            .'<p>좌표 미기록: pullback_interval_start</p><h2>원문 JSON</h2><pre>'.$json.'</pre>';
    }

    private static function pageC(string $id,array $c):string
    {
        $json=self::esc((string)json_encode($c,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        return '<!doctype html><meta charset="utf-8"><title>C '.$id.'</title>'
            .'<style>html,body{background:#fff;color:#222;font-family:"Malgun Gothic","Apple SD Gothic Neo",sans-serif;margin:24px}pre{white-space:pre-wrap}img{max-width:100%;height:auto}</style>'
            .'<p><a href="../a/'.$id.'.html">A로</a></p><h1>이후 가격과 저장 체결</h1>'
            .'<p>계좌를 다시 돌리지 않았다. 아래는 저장된 주문·체결·청산·비용·손익이다.</p>'
            .'<img src="'.$id.'.svg" alt="C"><pre>'.$json.'</pre>';
    }

    /** @param list<array{index:int,case_id:string,symbol:string,name:string,signal_date:string}> $cases */
    private static function index(array $cases):string
    {
        $links='';
        foreach($cases as $c)$links.='<li><a href="a/'.$c['case_id'].'.html">#'.$c['index'].' '.$c['case_id'].'</a></li>';
        return '<!doctype html><meta charset="utf-8"><title>상승 눌림 종료 42건 검토</title>'
            .'<style>html,body{background:#fff;color:#222;font-family:"Malgun Gothic","Apple SD Gothic Neo",sans-serif;margin:24px;max-width:720px}</style>'
            .'<h1>판정일까지의 차트</h1>'
            .'<p>KRX 일별 거래대금 TOP100 연구. 운영 다음 TOP100과 같다고 보지 않는다. 1년 실행으로 수익성이 검증됐다고 보지 않는다.</p>'
            .'<p>성과 요약은 이미 알려져 있으므로 완전한 블라인드가 아니다. 이 목록에는 손익을 넣지 않았다. 코드 판정과 이후 가격은 각 사례 안에서 따로 연다.</p>'
            .'<ul>'.$links.'</ul>';
    }

    public static function verify(string $out,string $datasetDir,string $tradesPath):array
    {
        $list=self::loadJson($out.'/list.json');$problems=[];$missing=[];
        $trades=self::extract(self::loadJson($tradesPath));
        if(count($trades)!==self::EXPECTED)$problems[]='extract count '.count($trades);
        if(($list['count']??0)!==self::EXPECTED)$problems[]='list count';
        if(($list['trades_sha256']??'')!==hash_file('sha256',$tradesPath))$problems[]='trades hash';
        $ids=array_column($list['cases'],'case_id');
        if(count($ids)!==count(array_unique($ids)))$problems[]='duplicate case_id';
        $want=array_map(fn($t)=>$t['case_id'],$trades);
        if($ids!==$want)$problems[]='list order or membership does not match extract';
        $index=(string)file_get_contents($out.'/index.html');
        if(substr_count($index,'<li>')!==self::EXPECTED)$problems[]='index links';
        foreach(['net_pnl','reward_risk','exit_reason','planned_risk'] as $w)if(str_contains($index,$w))$problems[]='index leaks '.$w;
        if(str_contains($index,'완전한 블라인드 실험'))$problems[]='index claims a full blind';
        $pnl=[];foreach($trades as $t)$pnl[]=(float)$t['exit']['net_pnl'];
        $sortedByPnl=$trades;usort($sortedByPnl,fn($a,$b)=>$a['exit']['net_pnl']<=>$b['exit']['net_pnl']);
        if(array_column($sortedByPnl,'case_id')===$ids)$problems[]='list appears sorted by PnL';
        foreach($list['cases'] as $i=>$c){
            $id=$c['case_id'];
            foreach(['a/'.$id.'.html','a/'.$id.'-120.svg','a/'.$id.'-240.svg','b/'.$id.'.html','b/'.$id.'.svg',
                'c/'.$id.'.html','c/'.$id.'.svg','json/a/'.$id.'.json','json/a/'.$id.'-input.json','json/b/'.$id.'.json','json/c/'.$id.'.json'] as $rel){
                if(!is_file($out.'/'.$rel))$missing[]=$rel;
            }
            if(!is_file($out.'/json/a/'.$id.'.json'))continue;
            $a=self::loadJson($out.'/json/a/'.$id.'.json');
            foreach(self::A_FORBIDDEN as $k)if(array_key_exists($k,$a))$problems[]=$id.' A contains '.$k;
            $aHtml=(string)file_get_contents($out.'/a/'.$id.'.html');
            foreach(['net_pnl','exit_reason','trend_pullback'] as $w)if(str_contains($aHtml,$w))$problems[]=$id.' A html leaks '.$w;
            $input=self::loadJson($out.'/json/a/'.$id.'-input.json');
            foreach($input['bars']??[] as $bar)if((int)$bar['available_at']>(int)$a['session'])$problems[]=$id.' A prices after decision day';
            if(PaperReviewPack::inputHash($input['bars']??[])!==($a['indicator_input']['sha256']??''))$problems[]=$id.' input hash';
            $barsPath=$datasetDir.'/bars/'.$c['symbol'].'.json';
            if(!is_file($barsPath)){$problems[]='missing '.$barsPath;continue;}
            if(hash_file('sha256',$barsPath)!==$c['bars_sha256'])$problems[]=$id.' bars file hash';
            $file=PaperHistoryResearch::readJson($barsPath);$rows=$file['rows']??[];
            foreach($rows as &$b)$b['available_at']=CandleClock::closeTime($b,$c['symbol']);unset($b);
            $session=(int)$a['session'];
            $past=array_values(array_filter($rows,fn($b)=>$b['available_at']<=$session));
            $completed=PaperReviewCharts::completedThrough($past,$session);
            $fresh=self::inputRows($completed);
            if(PaperReviewPack::inputHash($fresh)!==$a['indicator_input']['sha256'])$problems[]=$id.' OHLCV/MA does not match stored bars';
            foreach(['-120','-240'] as $suffix){
                $svg=(string)file_get_contents($out.'/a/'.$id.$suffix.'.svg');
                if(!preg_match('/last_available_at=(\d+)/',$svg,$m))$problems[]=$id.$suffix.' missing stamp';
                elseif((int)$m[1]>$session)$problems[]=$id.$suffix.' future bar';
                if($svg!==''&&!str_contains($svg,'이 창에 그릴 완료 봉이 없다')&&!str_contains($svg,$a['session_date']))$problems[]=$id.$suffix.' missing date tick';
                $count=$suffix==='-120'?120:240;
                $view=PaperReviewCharts::view($completed,$session,$count);
                $redraw=PaperReviewCharts::svg($view,[],$a['name'].' '.$a['session_date'].' 까지 · '.($count).'거래일',true,$c['symbol']);
                if(str_replace("\r\n","\n",$svg)!==str_replace("\r\n","\n",$redraw))$problems[]=$id.$suffix.' svg does not match redraw';
            }
            $bSvg=(string)file_get_contents($out.'/b/'.$id.'.svg');
            if(preg_match('/last_available_at=(\d+)/',$bSvg,$m)&&(int)$m[1]>$session)$problems[]=$id.' B future bar';
            $bj=self::loadJson($out.'/json/b/'.$id.'.json');
            $valid=CandleClock::completed($past,$c['symbol'],$session);
            $engine=(new ChartPlanEngine())->analyze($valid,$c['symbol'],$session);
            $pb=$engine['plan']['diagnostics']['patterns']['trend_pullback']??[];
            foreach(['entry','stop','target'] as $f){
                $want=$pb[$f]??null;$got=$bj['pullback'][$f]??null;
                if(is_numeric($want)&&is_numeric($got)&&abs((float)$want-(float)$got)>1e-6)$problems[]=$id.' B '.$f.' not independent recompute';
            }
            if(($bj['windows']['pullback_interval_start']??'')!=='unrecorded')$problems[]=$id.' invented pullback start';
            $bHtml=(string)file_get_contents($out.'/b/'.$id.'.html');
            if(str_contains($bHtml,'사람 고점')||str_contains($bHtml,'정답 저점'))$problems[]=$id.' B marks a human answer';
            foreach($bMarksPrice=[$pb['entry']??null,$pb['stop']??null,$pb['target']??null] as $price){
                if(!is_numeric($price))continue;
                $label=PaperReviewCharts::num((float)$price);
                if($bSvg!==''&&!str_contains($bSvg,$label))$problems[]=$id.' B marker '.$label;
            }
            $cj=self::loadJson($out.'/json/c/'.$id.'.json');
            $st=$trades[$i];
            if(($cj['trade']['fill']['price']??null)!=$st['fill']['price'])$problems[]=$id.' C fill';
            if(($cj['trade']['exit']['net_pnl']??null)!=$st['exit']['net_pnl'])$problems[]=$id.' C pnl';
            if(!str_contains((string)file_get_contents($out.'/a/'.$id.'.html'),'href="../b/'.$id.'.html'))$problems[]=$id.' A missing B link';
            if(!str_contains((string)file_get_contents($out.'/a/'.$id.'.html'),'href="../c/'.$id.'.html'))$problems[]=$id.' A missing C link';
        }
        $vol=self::volumeCheck();
        if(!$vol)$problems[]='volume ratio helper failed';
        $pass=$problems===[]&&$missing===[];
        $report=['pass'=>$pass,'cases'=>count($ids),'missing'=>$missing,'problems'=>$problems,
            'trades_sha256'=>$list['trades_sha256']??null,'dataset_json_sha256'=>$list['dataset_json_sha256']??null];
        self::write($out.'/verify-result.json',$report);
        return $report;
    }

    public static function volumeCheck():bool
    {
        return abs(PaperReviewCharts::volumeHeight(400,400,90)/PaperReviewCharts::volumeHeight(100,400,90)-4.0)<1e-9;
    }

    public static function zipPrices(string $out,string $zipPath):array
    {
        $list=self::loadJson($out.'/list.json');
        if(class_exists(ZipArchive::class)){
            $zip=new ZipArchive();
            if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot write '.$zipPath);
            foreach($list['cases'] as $c){
                $rel='json/a/'.$c['case_id'].'-input.json';
                $zip->addFile($out.'/'.$rel,str_replace('\\','/',$rel));
            }
            $zip->addFile($out.'/list.json','list.json');
            $zip->close();
        }else{
            $inputs=glob($out.'/json/a/*-input.json')?:[];
            if(count($inputs)!==count($list['cases']))throw new RuntimeException('A price files missing for zip');
            $cmd='tar -a -C '.escapeshellarg($out).' -c -f '.escapeshellarg($zipPath).' list.json';
            foreach($list['cases'] as $c)$cmd.=' '.escapeshellarg('json/a/'.$c['case_id'].'-input.json');
            exec($cmd,$unused,$code);
            if($code!==0||!is_file($zipPath))throw new RuntimeException('tar zip failed for '.$zipPath);
        }
        return ['path'=>$zipPath,'bytes'=>filesize($zipPath),'sha256'=>hash_file('sha256',$zipPath),'files'=>count($list['cases'])+1];
    }
}
