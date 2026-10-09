<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/HistoryResearch.php';
use ChartEntryLab\CandleClock;
use ChartEntryLab\NaverDailyQuotes;
use ChartEntryLab\NaverHistoricalClose;
use ChartEntryLab\YahooChartClient;

function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function rmrf(string $p):void{
    if(is_file($p)||is_link($p)){@unlink($p);return;}
    foreach(glob($p.'/{,.}*',GLOB_BRACE)?:[] as $f)if(!in_array(basename($f),['.','..'],true))rmrf($f);
    @rmdir($p);
}
$kst=new DateTimeZone('Asia/Seoul');
$tmp=sys_get_temp_dir().'/history-test-'.bin2hex(random_bytes(4));mkdir($tmp,0777,true);
register_shutdown_function(fn()=>rmrf($tmp));

// ---- universe ---------------------------------------------------------------------------------------------
$scan=$tmp.'/scan';mkdir($scan);
$rec=fn(string $s,string $n,array $extra=[])=>['symbol'=>$s,'name'=>$n,'amount_rank'=>1,'session'=>1,'status'=>'unavailable']+$extra;
$bundle=fn(array $records)=>json_encode(['schema'=>1,'version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only',
    'recorded_at'=>1,'fetched_at'=>'2026-09-22 20:23:11','records'=>$records]);
file_put_contents($scan.'/20260922-112311-aaaaaaaaaaaa.json',$bundle([
    $rec('005930.KS','삼성전자'),$rec('000660.KS','SK하이닉스'),$rec('069500.KS','KODEX 200'),$rec('111111.KQ','제1호스팩'),
    $rec('222222.KS','가나'),$rec('333333.KS','정상이름',['analysis'=>['decision'=>['reason'=>'OTHER.KS [account1] test']]]),
    $rec('444444.KS','공통명'),$rec('555555.KS','공통명'),$rec('AAPL','Apple')]));
file_put_contents($scan.'/20260923-112311-bbbbbbbbbbbb.json',$bundle([
    $rec('005930.KS','삼성전자'),$rec('222222.KS','다라')]));
file_put_contents($scan.'/20260924-112311-cccccccccccc.json',json_encode(['schema'=>1,'version'=>PaperRrAudit::VERSION,
    'membership'=>'something_else','records'=>[$rec('999999.KS','제외되어야함')]]));
$u=PaperHistoryResearch::universe([$scan]);
check($u['counts']['unique_codes']===8,'codes are de-duplicated by code, not by observation');
check($u['counts']['included']===2&&$u['counts']['excluded']===2&&$u['counts']['hold']===4,'included/excluded/hold counts come from the data');
check($u['rows']['069500']['status']==='excluded'&&$u['rows']['111111']['exclusion']['rule']==='KrAmountLeadersClient::excludedFromAmountRank','existing exclusion function reused');
check($u['rows']['222222']['reasons']===['code_name_conflict']&&$u['rows']['222222']['status']==='hold','same code with two names is held');
check($u['rows']['333333']['reasons']===['analysis_symbol_mismatch'],'analysis identity mismatch is held');
check($u['rows']['444444']['reasons']===['name_code_conflict']&&$u['rows']['555555']['status']==='hold','same name on two codes is held');
check($u['counts']['invalid_symbol_rows']===1&&!isset($u['rows']['999999']),'non Korean symbols and non-observed bundles are not mixed in');
check($u['rows']['005930']['observations']===2&&count($u['sources'])===2&&strlen($u['sources'][0]['sha256'])===64,'source files and observations are recorded with hashes');

// ---- research path guard ----------------------------------------------------------------------------------
$dir=PaperHistoryResearch::datasetDir($tmp,'t1');
foreach([dirname(__DIR__).'/data/ohlcv',$tmp.'/rr-audit/paper-kr',$tmp.'/history-research',$tmp.'/history-research/../x'] as $bad){
    $blocked=false;try{PaperHistoryResearch::assertResearchDir($bad);}catch(RuntimeException){$blocked=true;}
    check($blocked,'operational paths rejected: '.basename($bad));
}
PaperHistoryResearch::initDataset($dir,$u,'t1');
PaperHistoryResearch::initDataset($dir,$u,'t1'); // nothing collected yet, so re-extraction is harmless
check(is_file($dir.'/universe.json')&&$u['rows']['005930']['code']==='005930','universe can be re-extracted until collection starts');

// ---- fake provider ----------------------------------------------------------------------------------------
$now=(new DateTimeImmutable('2026-10-09 23:00:00',$kst))->getTimestamp();
$asDay='2026-10-08';
$as=['day'=>$asDay,'close_ts'=>CandleClock::closeTime(['time'=>(new DateTimeImmutable($asDay.' 09:00:00',$kst))->getTimestamp()],'005930.KS')];
function weekdays(string $end,int $n):array{
    $d=new DateTimeImmutable($end);$out=[];
    while(count($out)<$n){if((int)$d->format('N')<6)$out[]=$d->format('Y-m-d');$d=$d->modify('-1 day');}
    return array_reverse($out);
}
function body(string $symbol,array $days,array $o=[]):string{
    $k=new DateTimeZone('Asia/Seoul');$ts=[];$op=[];$hi=[];$lo=[];$cl=[];$vo=[];
    foreach($days as $i=>$d){
        $t=(new DateTimeImmutable($d.' 09:00:00',$k))->getTimestamp();$p=1000+$i;
        $ts[]=$t;$op[]=$p;$hi[]=$p+5;$lo[]=$p-5;$cl[]=$p+1;$vo[]=1000;
    }
    foreach($o['mutate']??[] as $i=>$f){$f($op[$i],$hi[$i],$lo[$i],$cl[$i],$vo[$i]);}
    if(!empty($o['reverse'])){$ts=array_reverse($ts);}
    return json_encode(['chart'=>['result'=>[['meta'=>['symbol'=>$o['meta_symbol']??$symbol,'currency'=>'KRW','exchangeName'=>'KSC',
        'firstTradeDate'=>$o['first']??946684800,'dataGranularity'=>'1d','range'=>'x'],'timestamp'=>$ts,
        'indicators'=>['quote'=>[['open'=>$op,'high'=>$hi,'low'=>$lo,'close'=>$cl,'volume'=>$vo]]]]],'error'=>null]]);
}
$days=fn(int $n)=>weekdays($asDay,$n);
$first=fn(int $n)=>(new DateTimeImmutable(weekdays($asDay,$n)[0].' 09:00:00',$kst))->getTimestamp();
$cases=[
    '005930.KS'=>['2y'=>body('005930.KS',$days(520)),'5y'=>body('005930.KS',$days(1200))],   // enough preparation in 2y
    '000660.KS'=>['2y'=>body('000660.KS',$days(400)),'5y'=>body('000660.KS',$days(900))],    // 2y is short, 5y is not
    '300001.KQ'=>['2y'=>body('300001.KQ',$days(120),['first'=>$first(120)])],                // recent listing
    '300002.KQ'=>['2y'=>body('300002.KQ',$days(40),['first'=>$first(40)])],                  // too short
    '300003.KQ'=>['2y'=>body('300003.KQ',$days(520),['mutate'=>[
        300=>function(&$o,&$h,&$l,&$c,&$v){$v=-5;},301=>function(&$o,&$h,&$l,&$c,&$v){$c=$h+50;}]])],
    '300004.KQ'=>['2y'=>body('300004.KQ',$days(520),['meta_symbol'=>'OTHER.KQ'])],
    '300005.KQ'=>['2y'=>body('300005.KQ',$days(520),['reverse'=>true])],
    '300006.KQ'=>['2y'=>body('300006.KQ',$days(520),['mutate'=>[450=>function(&$o,&$h,&$l,&$c,&$v){$o*=2;$h*=2;$l*=2;$c*=2;}]])],
];
$calls=[];$failing=[];$cacheRoot=$dir;
$http=function(string $url) use (&$calls,&$failing,&$cases,$now,&$cacheRoot):array{
    preg_match('#/chart/([^?]+)\?range=([^&]+)#',$url,$m);$symbol=rawurldecode($m[1]);$range=$m[2];$calls[]=$symbol.':'.$range;
    // Keep the Naver session cache offline: an empty fresh cache means "no extra evidence".
    $naver=$cacheRoot.'/cache/naver';@mkdir($naver,0777,true);file_put_contents($naver.'/naver_day_'.substr($symbol,0,6).'_1.json','[]');
    if(isset($failing[$symbol]))return ['body'=>'','http_code'=>$failing[$symbol],'fetched_at'=>$now-60];
    if(!isset($cases[$symbol][$range]))return ['body'=>'{"chart":{"result":null,"error":{"code":"Not Found"}}}','http_code'=>404,'fetched_at'=>$now-60];
    return ['body'=>$cases[$symbol][$range],'http_code'=>200,'fetched_at'=>$now-60];
};
$factory=fn(string $cache,Closure $t,?Closure $c)=>new YahooChartClient($cache,new NaverDailyQuotes($cache.'/naver'),$t,$c,
    new NaverHistoricalClose($cache.'/historical-close',fn()=>throw new RuntimeException('offline')));
$deps=PaperHistoryResearch::makeClient($dir,$http,fn()=>$now,$factory);
$uRow=fn(string $s,string $n)=>['symbol'=>$s,'name'=>$n];

$r=PaperHistoryResearch::collectSymbol($dir,$uRow('005930.KS','삼성전자'),$as,$deps);
$st=$r['status'];
check($r['action']==='collected'&&$st['extension']==='not_needed'&&$st['final_range']==='2y'&&count($calls)===1,'enough 2y history needs one request only');
check($st['quality']['status']==='ok'&&$st['quality']['bars_through_evaluation_start']>=240,'2y history covers 12 months plus 240 preparation bars');
check($st['quality']['last_completed']===$asDay&&strlen($st['bars_sha256'])===64&&is_file($dir.'/'.$st['requests'][0]['raw_path']),'raw response and bars file are stored with hashes');
check($st['raw_checks']['sha256']===$st['requests'][0]['raw_sha256']&&hash('sha256',(string)file_get_contents($dir.'/'.$st['requests'][0]['raw_path']))===$st['raw_checks']['sha256'],'raw hash matches the stored bytes');

$again=false;try{PaperHistoryResearch::initDataset($dir,$u,'t1');}catch(RuntimeException){$again=true;}
check($again,'fixed universe cannot be replaced once collection has started');
$before=count($calls);
$r=PaperHistoryResearch::collectSymbol($dir,$uRow('005930.KS','삼성전자'),$as,$deps);
check($r['action']==='reused'&&count($calls)===$before,'a collected symbol is not downloaded again');

$r=PaperHistoryResearch::collectSymbol($dir,$uRow('000660.KS','SK하이닉스'),$as,$deps);$st=$r['status'];
check($st['extension']==='requested'&&$st['final_range']==='5y'&&count($st['requests'])===2,'short 2y history triggers one 5y request');
check($st['quality']['bars_through_evaluation_start']>=240&&$st['quality']['completed_bars']===900,'5y response is used whole, not stitched to the 2y one');
check($st['requests'][0]['raw_sha256']!==$st['requests'][1]['raw_sha256']&&is_file($dir.'/'.$st['requests'][0]['raw_path']),'both raw responses are preserved');

$r=PaperHistoryResearch::collectSymbol($dir,$uRow('300001.KQ','신규상장'),$as,$deps);$st=$r['status'];$q=$st['quality'];
check($st['extension']==='skipped_provider_history_exhausted'&&!in_array('5y',array_column($st['requests'],'range'),true),'recent listing does not trigger useless extension');
check($q['completed_bars']===120&&$q['status']==='ok_with_warnings'&&in_array('history_short_for_240',$q['warnings'],true)
    &&!in_array('insufficient_history',$q['hold_reasons'],true),'recent listing keeps its real 120 bars and is flagged, not filled or dropped');

$r=PaperHistoryResearch::collectSymbol($dir,$uRow('300002.KQ','너무짧음'),$as,$deps);$q=$r['status']['quality'];
check($q['status']==='hold'&&in_array('insufficient_history',$q['hold_reasons'],true)&&$q['completed_bars']===40,'fewer than 60 bars is held with a reason');

$r=PaperHistoryResearch::collectSymbol($dir,$uRow('300003.KQ','불량봉'),$as,$deps);$q=$r['status']['quality'];
check(in_array('negative_volume',$q['hold_reasons'],true)&&$q['negative_volume_days']!==[],'negative volume is held');
check(in_array('invalid_ohlcv_history',$q['warnings'],true)&&$q['invalid_ohlcv_days']!==[],'OHLC relation errors are listed, not silently dropped');

$r=PaperHistoryResearch::collectSymbol($dir,$uRow('300004.KQ','식별불일치'),$as,$deps);
check(in_array('identity_mismatch',$r['status']['quality']['hold_reasons'],true),'provider identity mismatch is held');
$r=PaperHistoryResearch::collectSymbol($dir,$uRow('300005.KQ','역순'),$as,$deps);
check(in_array('timestamps_not_increasing',$r['status']['quality']['hold_reasons'],true),'reverse or duplicate provider dates are held');
$r=PaperHistoryResearch::collectSymbol($dir,$uRow('300006.KQ','가격단절'),$as,$deps);$q=$r['status']['quality'];
check(in_array('price_jump_beyond_daily_limit',$q['hold_reasons'],true)&&$q['price_jumps'][0]['change_pct']>31,'adjacent close beyond the daily limit is held as a possible unadjusted break');

// weekends are not "missing bars": the normal case has no gap or missing flag
$q=PaperHistoryResearch::readStatus($dir,'005930.KS')['quality'];
check($q['calendar_gaps']===[]&&!in_array('missing_session',$q['hold_reasons'],true),'weekends and holidays are not treated as missing sessions');

// ---- provisional and later bars --------------------------------------------------------------------------
$earlyNow=(new DateTimeImmutable('2026-10-08 10:00:00',$kst))->getTimestamp();
$asEarly=['day'=>'2026-10-07','close_ts'=>CandleClock::closeTime(['time'=>(new DateTimeImmutable('2026-10-07 09:00:00',$kst))->getTimestamp()],'005930.KS')];
@mkdir($tmp.'/cc/naver',0777,true);
$clockClient=new YahooChartClient($tmp.'/cc',new NaverDailyQuotes($tmp.'/cc/naver'),fn()=>json_decode($cases['005930.KS']['2y'],true),fn()=>$earlyNow,
    new NaverHistoricalClose($tmp.'/cc/hc',fn()=>throw new RuntimeException('offline')));
file_put_contents($tmp.'/cc/naver/naver_day_005930_1.json','[]');
$rows=$clockClient->fetch('005930.KS','2y','1d',false,null);
$q=PaperHistoryResearch::inspectRows($rows,'005930.KS',$asEarly['close_ts'],PaperHistoryResearch::evalStartDay('2026-10-07'),[]);
check($q['excluded_from_evaluation']['incomplete']===1&&$q['last_completed']==='2026-10-07'&&$q['status']!=='hold','a bar still in session is flagged and kept out of the completed bars');
$q=PaperHistoryResearch::inspectRows($rows,'005930.KS',$asEarly['close_ts']-86400*2,PaperHistoryResearch::evalStartDay('2026-10-05'),[]);
check($q['excluded_from_evaluation']['after_as_of']>=1,'completed bars after the fixed analysis date are not evaluation bars');

// repaired close keeps its original value
$rows[10]['historical_close_repair']=['policy'=>'matching_ohlv_and_neighbors_v1','original'=>['close'=>999],'source_sha256'=>str_repeat('a',64)];
$q=PaperHistoryResearch::inspectRows($rows,'005930.KS',$asEarly['close_ts'],PaperHistoryResearch::evalStartDay('2026-10-07'),[]);
check($q['corrections']['count']===1&&$q['corrections']['items'][0]['original_close']===999&&in_array('historical_close_repair_applied',$q['warnings'],true),'verified close repair evidence is carried into the quality record');

// ---- failure, bounded retry, resume ----------------------------------------------------------------------
$failing['300007.KQ']=500;$cases['300007.KQ']=['2y'=>body('300007.KQ',$days(520))];
for($i=1;$i<=3;$i++){
    $r=PaperHistoryResearch::collectSymbol($dir,$uRow('300007.KQ','일시실패'),$as,$deps,3);
    check($r['action']==='failed'&&$r['status']['attempts']===$i,'failed attempt '.$i.' is recorded');
}
$n=count($calls);$r=PaperHistoryResearch::collectSymbol($dir,$uRow('300007.KQ','일시실패'),$as,$deps,3);
check($r['action']==='skipped_attempt_limit'&&count($calls)===$n,'retries stop at the attempt limit');
unset($failing['300007.KQ']);
$r=PaperHistoryResearch::collectSymbol($dir,$uRow('300007.KQ','일시실패'),$as,$deps,3,true);
check($r['action']==='collected'&&$r['status']['attempts']===4&&$r['status']['error']===null,'explicit retry resumes after the provider recovers');
$r=PaperHistoryResearch::collectSymbol($dir,$uRow('300008.KQ','없는종목'),$as,$deps);
check($r['action']==='failed'&&str_contains((string)$r['status']['error'],'404'),'unknown symbol is a recorded failure, not a skipped one');

// ---- manifest and verify ---------------------------------------------------------------------------------
PaperHistoryResearch::fixAsOf($dir,$as,'test');
check(PaperHistoryResearch::fixAsOf($dir,['day'=>'2030-01-01','close_ts'=>1],'other')['as_of']['day']===$asDay,'analysis date is fixed once');
$m=PaperHistoryResearch::manifest($dir);
check($m['summary']['requested']===2,'manifest requests only the included symbols of the fixed universe');
$full=json_decode((string)file_get_contents($dir.'/universe.json'),true);
foreach(['300001.KQ','300002.KQ','300003.KQ'] as $s){$full['rows'][substr($s,0,6)]=['code'=>substr($s,0,6),'symbol'=>$s,'name'=>$s,'status'=>'included','names'=>[$s],'symbols'=>[$s],'reasons'=>[],'exclusion'=>null,'identity_conflicts'=>[],'observations'=>1,'sources'=>[]];}
$bytes=PaperHistoryResearch::encode($full,true);file_put_contents($dir.'/universe.json',$bytes);
$ds=json_decode((string)file_get_contents($dir.'/dataset.json'),true);$ds['universe_sha256']=hash('sha256',$bytes);
file_put_contents($dir.'/dataset.json',json_encode($ds));
$m=PaperHistoryResearch::manifest($dir);$s=$m['summary'];
check($s['requested']===5&&$s['collected']===5&&$s['failed']===0,'manifest counts requested and collected symbols');
check($s['insufficient_history']===1&&$s['history_short_for_240']===2&&$s['quality_hold']===2&&$s['quality_ok_with_warnings']===1&&$s['quality_ok']===2,'manifest separates short history, holds and warnings');
check($m['quality_hold'][0]['symbol']==='300002.KQ'&&$m['excluded_instruments'][0]['kind']==='etf_or_fund_brand'&&count($m['identity_hold'])===4,'held, excluded and conflicting symbols are all listed');
check(str_contains($m['universe_note'],'neither the whole market'),'manifest states that the set is not the market or a point-in-time top 100');
check(PaperHistoryResearch::verify($dir)===[],'verify passes on untouched files');
// ---- second dataset: same symbols, explicit past analysis date, 5y as the primary range -------------------
$dir2=PaperHistoryResearch::datasetDir($tmp,'t2');
$d2=PaperHistoryResearch::copyUniverse($dir,$dir2,'t2');
check(file_get_contents($dir2.'/universe.json')===file_get_contents($dir.'/universe.json')&&$d2['universe_source']['dataset']==='t1','same symbols: universe.json is copied byte for byte');
$t1Rows=PaperHistoryResearch::readJson($dir.'/bars/005930.KS.json')['rows'];
$pastDay='2026-06-30';
$asPast=PaperHistoryResearch::asOfOnOrBefore($t1Rows,'005930.KS',$pastDay,$now);
check($asPast!==null&&$asPast['day']===$pastDay&&$asPast['close_ts']===CandleClock::closeTime(['time'=>(new DateTimeImmutable($pastDay.' 09:00:00',$kst))->getTimestamp()],'005930.KS'),'an explicit analysis date resolves to that session\'s close');
$wk=PaperHistoryResearch::asOfOnOrBefore($t1Rows,'005930.KS','2026-06-27',$now);
check($wk['day']==='2026-06-26'&&$wk['requested_day']==='2026-06-27','a non-session request resolves to the session before it and keeps the requested day');
check(PaperHistoryResearch::asOfOnOrBefore($t1Rows,'005930.KS','2026-10-20',$now)['day']==='2026-10-08'&&PaperHistoryResearch::asOfOnOrBefore($t1Rows,'005930.KS','2000-01-03',$now)===null,'a later request cannot pass the last completed session; one before all data finds none');
$badDate=false;try{PaperHistoryResearch::asOfOnOrBefore($t1Rows,'005930.KS','2026/06/30',$now);}catch(InvalidArgumentException){$badDate=true;}
check($badDate,'malformed analysis dates are rejected');
$ds2=PaperHistoryResearch::fixAsOf($dir2,$asPast,'test explicit date','5y','2026-07-04');
$as2=$ds2['as_of']+['primary_range'=>$ds2['primary_range'],'evaluation_start_day'=>PaperHistoryResearch::evalStartOf($ds2)];
check($ds2['primary_range']==='5y'&&$as2['evaluation_start_day']==='2025-07-04'&&$as2['day']===$pastDay,'the evaluation window starts twelve months before the requested day, not before the resolved session');
check(PaperHistoryResearch::evalStartOf(['as_of'=>['day'=>'2026-10-08']])==='2025-10-08','a dataset without a recorded start keeps the twelve months before its session');
$cases['300009.KQ']=['5y'=>body('300009.KQ',$days(120),['first'=>$first(120)])];
$cases['300010.KQ']=['5y'=>body('300010.KQ',$days(700),['mutate'=>[660=>function(&$o,&$h,&$l,&$c,&$v){$o*=2;$h*=2;$l*=2;$c*=2;}]])];
$cacheRoot=$dir2;$deps2=PaperHistoryResearch::makeClient($dir2,$http,fn()=>$now,$factory);$before=count($calls);
$r=PaperHistoryResearch::collectSymbol($dir2,$uRow('005930.KS','삼성전자'),$as2,$deps2);$q=$r['status']['quality'];
check($r['action']==='collected'&&array_slice($calls,$before)===['005930.KS:5y']&&$r['status']['extension']==='not_needed','5y is requested directly, with no 2y request and no extension');
check($q['last_completed']===$pastDay&&$q['evaluation_start_day']==='2025-07-04'&&$q['preparation_start_day']==='2024-07-04'&&$q['preparation_window_bars']>=240&&$q['bars_before_preparation_window']>0&&$q['excluded_from_evaluation']['after_as_of']>0&&$q['bars_through_evaluation_start']>=240&&$q['status']==='ok_with_warnings','bars after the analysis date are kept only as outcome bars');
check(count(PaperHistoryResearch::readJson($dir2.'/bars/005930.KS.json')['rows'])===1200,'the bars file keeps the provider response whole');
$r=PaperHistoryResearch::collectSymbol($dir2,$uRow('300009.KQ','신규'),$as2,$deps2);
check($r['status']['extension']==='skipped_provider_history_exhausted'&&in_array('history_short_for_240',$r['status']['quality']['warnings'],true),'a recent listing under a 5y primary range is not filled or extended');
$r=PaperHistoryResearch::collectSymbol($dir2,$uRow('300010.KQ','후속분할'),$as2,$deps2);
check($r['status']['quality']['hold_reasons']===[]&&in_array('price_jump_in_outcome_bars',$r['status']['quality']['warnings'],true)&&$r['status']['quality']['outcome_price_jumps']!==[],'an unadjusted jump after the analysis date is flagged for outcome measurement');
$mm=PaperHistoryResearch::compare($dir,$dir2);
check($mm['summary']['symbols_in_both']===1&&$mm['summary']['mismatch_days']>0&&$mm['mismatches'][0]['symbol']==='005930.KS','compare reports shared days whose prices differ between datasets');
$same=PaperHistoryResearch::compare($dir,$dir);
check($same['summary']['mismatch_days']===0&&$same['summary']['overlap_days']>0,'compare is clean for identical data');
$again2=false;try{PaperHistoryResearch::copyUniverse($dir,$dir2,'t2');}catch(RuntimeException){$again2=true;}
check($again2,'a dataset that already has a fixed analysis date cannot take another universe');
check(PaperHistoryResearch::verify($dir2)===[],'verify passes for the second dataset');
$bars=$dir.'/bars/005930.KS.json';file_put_contents($bars,file_get_contents($bars).' ');
check(count(PaperHistoryResearch::verify($dir))===1,'verify detects a changed bars file');

// ---- isolation ---------------------------------------------------------------------------------------------
$top=array_values(array_diff(scandir($tmp),['.','..','scan','cc','history-research']));
check($top===[],'nothing was written outside the research dataset');
$files=file_get_contents(dirname(__DIR__).'/config/paper-strategy-files.json');
check(!str_contains($files,'History')&&!str_contains($files,'paper_history'),'strategy fingerprint file list is untouched');
echo "OK\n";
