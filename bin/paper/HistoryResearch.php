<?php
declare(strict_types=1);
require_once __DIR__.'/RrView.php';
use ChartEntryLab\CandleClock;
use ChartEntryLab\KrAmountLeadersClient;
use ChartEntryLab\PaperQuality;
use ChartEntryLab\YahooChartClient;

/**
 * Data preparation for a later "pattern history" study. It freezes a symbol set taken from saved scans,
 * collects completed daily bars into a separate research directory and records quality evidence.
 * It never touches operational caches, ledgers, followups or strategy files, and it never ranks anything.
 */
final class PaperHistoryResearch
{
    public const SCHEMA=1;
    public const POLICY='saved_scan_fixed_universe_history_v1';
    public const PROVIDER='yahoo_chart_v8';
    public const PRICE_BASIS='yahoo_chart_quote_ohlcv_daily_adjustment_unverified';
    public const MEMBERSHIP='saved_scan_fixed_universe_not_point_in_time_top100';
    /** PaperQuality refuses fewer completed bars. */
    public const MIN_BARS=60;
    /** Longest history the existing research uses (EnvelopeResearch requires an MA240). */
    public const PREP_BARS=240;
    public const EVAL_MONTHS=12;
    public const PRIMARY_RANGE='2y';
    /** The next range Yahoo accepts after 2y. */
    public const EXTENDED_RANGE='5y';
    public const MAX_ATTEMPTS=3;
    /** KRX daily price limit is 30%; a larger adjacent close-to-close move suggests an unadjusted break. */
    public const JUMP_LIMIT=0.31;
    private const LIST_CAP=40;
    public const HOLD_REASONS=['provider_no_result','identity_mismatch','currency_not_krw','timestamps_not_increasing',
        'duplicate_session','negative_volume','insufficient_history','missing_session','price_jump_beyond_daily_limit',
        'no_evaluation_bars'];

    // ---- paths and files -------------------------------------------------------------------------------------

    public static function datasetDir(string $stateDir,string $dataset):string
    {
        if(!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/',$dataset))throw new InvalidArgumentException('Invalid dataset id');
        return rtrim(str_replace('\\','/',$stateDir),'/').'/history-research/'.$dataset;
    }

    /** Writes only inside <state>/history-research/<dataset>, never inside the repository data folders. */
    public static function assertResearchDir(string $dir):void
    {
        $d=str_replace('\\','/',$dir);
        if(!preg_match('#/history-research/[a-z0-9][a-z0-9_.-]{0,63}/?$#',$d)||str_contains($d,'..'))
            throw new RuntimeException('Research files must live under <state>/history-research/<dataset>');
        if(preg_match('#/data/(ohlcv|raw|cache|paper)#',$d))throw new RuntimeException('Operational data folders are not research paths');
    }

    public static function encode(array $v,bool $pretty=false):string
    {
        return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR|($pretty?JSON_PRETTY_PRINT:0));
    }

    public static function writeFile(string $path,string $bytes):void
    {
        $dir=dirname($path);
        if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Cannot create '.$dir);
        $tmp=$path.'.'.getmypid().'.tmp';
        if(file_put_contents($tmp,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Short write '.$path);
        if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Cannot publish '.$path);}
    }

    public static function readJson(string $path):?array
    {
        if(!is_file($path))return null;
        $v=json_decode((string)file_get_contents($path),true);
        return is_array($v)?$v:null;
    }

    private static function safeName(string $symbol):string
    {
        if(!preg_match('/^\d{6}\.(KS|KQ)$/D',$symbol))throw new InvalidArgumentException('Invalid symbol '.$symbol);
        return $symbol;
    }

    public static function day(int $ts):string
    {
        return (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
    }

    public static function evalStartDay(string $asOfDay):string
    {
        return (new DateTimeImmutable($asOfDay,new DateTimeZone('Asia/Seoul')))->modify('-'.self::EVAL_MONTHS.' months')->format('Y-m-d');
    }

    /** Latest completed regular session in the rows, judged by the existing candle clock. */
    public static function asOfFromRows(array $rows,string $symbol,int $now):?array
    {
        $best=null;
        foreach($rows as $r){
            if(!empty($r['synthetic'])||($r['is_complete']??true)===false)continue;
            $close=CandleClock::closeTime($r,$symbol);
            if($close>$now)continue;
            if($best===null||$close>$best['close_ts'])$best=['day'=>self::day($close),'close_ts'=>$close];
        }
        return $best;
    }

    // ---- 1. fixed universe -----------------------------------------------------------------------------------

    private static function exclusionKind(string $name):string
    {
        return match(true){
            preg_match('/스팩|기업인수목적/u',$name)===1=>'spac',
            preg_match('/ETN/ui',$name)===1=>'etn',
            preg_match('/단일종목/u',$name)===1=>'single_stock_product',
            preg_match('/인버스|레버리지/u',$name)===1=>'inverse_or_leveraged',
            default=>'etf_or_fund_brand',
        };
    }

    /** @param list<string> $dirs saved scan folders holding rr-audit bundles */
    public static function universe(array $dirs):array
    {
        $sources=[];$errors=[];$codes=[];$invalid=[];$records=0;
        foreach($dirs as $dir){
            $dir=rtrim(str_replace('\\','/',$dir),'/');
            if(!is_dir($dir)){$errors[]=['dir'=>$dir,'error'=>'not_a_directory'];continue;}
            $files=PaperRrView::files($dir);sort($files,SORT_STRING);
            foreach($files as $file){
                $path=$dir.'/'.$file;
                try{$bundle=PaperRrView::load($path);}
                catch(Throwable $e){$errors[]=['dir'=>$dir,'file'=>$file,'error'=>$e->getMessage()];continue;}
                if(($bundle['membership']??'')!=='observed_scan_only'){
                    $errors[]=['dir'=>$dir,'file'=>$file,'error'=>'membership_not_observed_scan_only'];unset($bundle);continue;
                }
                $sources[]=['dir'=>$dir,'file'=>$file,'sha256'=>hash_file('sha256',$path),'bytes'=>filesize($path),
                    'fetched_at'=>$bundle['fetched_at']??null,'recorded_at'=>$bundle['recorded_at']??null,'records'=>count($bundle['records'])];
                foreach($bundle['records'] as $r){
                    $records++;
                    $symbol=(string)($r['symbol']??'');$name=trim((string)($r['name']??''));
                    if(!preg_match('/^(\d{6})\.(KS|KQ)$/D',$symbol,$m)){
                        $invalid[]=['symbol'=>$symbol,'name'=>$name,'file'=>$file,'reason'=>'not_kr_symbol_format'];continue;
                    }
                    $c=&$codes[$m[1]];
                    $c??=['symbols'=>[],'names'=>[],'sources'=>[],'identity'=>[]];
                    $c['symbols'][$symbol]=true;$c['names'][$name]=true;
                    $id=PaperTrackingInput::identity($r);
                    if($id['status']==='analysis_symbol_mismatch')
                        $c['identity'][]=['file'=>$file,'session'=>$r['session']??null,'expected'=>$id['expected'],'evidence'=>$id['evidence']];
                    $c['sources'][]=['dir'=>$dir,'file'=>$file,'session'=>$r['session']??null,'rank'=>$r['amount_rank']??null,
                        'scan_status'=>$r['status']??null];
                    unset($c);
                }
                unset($bundle);
            }
        }
        if($sources===[]||$codes===[])throw new RuntimeException('No saved scan bundle with Korean symbols found in '.implode(', ',$dirs));
        ksort($codes,SORT_STRING);
        $byName=[];
        foreach($codes as $code=>$c)foreach(array_keys($c['names']) as $n)$byName[$n][$code]=true;
        $rows=[];$counts=['included'=>0,'excluded'=>0,'hold'=>0];
        foreach($codes as $code=>$c){
            $names=array_keys($c['names']);sort($names,SORT_STRING);
            $symbols=array_keys($c['symbols']);sort($symbols,SORT_STRING);
            $reasons=[];
            if(count($names)>1)$reasons[]='code_name_conflict';
            if(count($symbols)>1)$reasons[]='exchange_suffix_conflict';
            if($c['identity'])$reasons[]='analysis_symbol_mismatch';
            foreach($names as $n){
                if($n==='')$reasons[]='name_missing';
                elseif(count($byName[$n])>1)$reasons[]='name_code_conflict';
            }
            $excludedNames=array_values(array_filter($names,fn($n)=>KrAmountLeadersClient::excludedFromAmountRank($n)));
            if($excludedNames)$reasons[]='excluded_instrument';
            $reasons=array_values(array_unique($reasons));
            $hold=array_values(array_diff($reasons,['excluded_instrument']));
            $status=$hold?'hold':($excludedNames?'excluded':'included');
            $counts[$status]++;
            $rows[$code]=['code'=>(string)$code,'symbol'=>count($symbols)===1?$symbols[0]:null,'symbols'=>$symbols,'names'=>$names,
                'name'=>count($names)===1?$names[0]:null,'status'=>$status,'reasons'=>$reasons,
                'exclusion'=>$excludedNames?['rule'=>'KrAmountLeadersClient::excludedFromAmountRank','kind'=>self::exclusionKind($excludedNames[0]),
                    'names'=>$excludedNames]:null,
                'identity_conflicts'=>$c['identity'],'observations'=>count($c['sources']),'sources'=>$c['sources']];
        }
        return ['schema'=>self::SCHEMA,'policy'=>self::POLICY,'membership'=>self::MEMBERSHIP,
            'note'=>'Symbols seen in saved scans. Not the whole market and not a point-in-time top 100.',
            'sources'=>$sources,'source_errors'=>$errors,'invalid_symbol_rows'=>$invalid,
            'counts'=>['source_files'=>count($sources),'scan_records'=>$records,'invalid_symbol_rows'=>count($invalid),
                'unique_codes'=>count($rows)]+$counts,
            'rows'=>$rows];
    }

    /** The symbol set is fixed once. A different set needs a new dataset id. */
    public static function initDataset(string $dir,array $universe,string $dataset):array
    {
        self::assertResearchDir($dir);
        if(is_file($dir.'/dataset.json')){
            // The set may be re-extracted only while nothing has been collected from it.
            $ds=self::readJson($dir.'/dataset.json');
            if(!is_array($ds)||is_array($ds['as_of']??null)||is_dir($dir.'/status')||is_dir($dir.'/raw'))
                throw new RuntimeException('Dataset already has a fixed universe; use a new --dataset id');
        }elseif(is_dir($dir)&&(glob($dir.'/*')?:[])!==[])throw new RuntimeException('Refusing to write into a non-empty folder without dataset.json');
        $bytes=self::encode($universe,true);
        self::writeFile($dir.'/universe.json',$bytes);
        $ds=['schema'=>self::SCHEMA,'policy'=>self::POLICY,'dataset'=>$dataset,'created_at'=>time(),
            'universe_sha256'=>hash('sha256',$bytes),'as_of'=>null,'as_of_basis'=>null];
        self::writeFile($dir.'/dataset.json',self::encode($ds,true));
        return $ds;
    }

    /** The analysis date is written once and reused by every resumed run. */
    public static function fixAsOf(string $dir,array $as,string $basis):array
    {
        $ds=self::readJson($dir.'/dataset.json')??throw new RuntimeException('dataset.json missing');
        if(is_array($ds['as_of']))return $ds;
        $ds['as_of']=['day'=>$as['day'],'close_ts'=>$as['close_ts'],'fixed_at'=>time()];$ds['as_of_basis']=$basis;
        self::writeFile($dir.'/dataset.json',self::encode($ds,true));
        return $ds;
    }

    // ---- 2. quality checks -----------------------------------------------------------------------------------

    /** Provider response level checks on the exact bytes that were received. */
    public static function inspectRaw(string $body,string $symbol):array
    {
        $out=['sha256'=>hash('sha256',$body),'bytes'=>strlen($body),'hold_reasons'=>[],'meta'=>[],'timestamps'=>0];
        $j=json_decode($body,true);
        $res=is_array($j)?($j['chart']['result'][0]??null):null;
        if(!is_array($res)){$out['hold_reasons'][]='provider_no_result';return $out;}
        $meta=is_array($res['meta']??null)?$res['meta']:[];
        foreach(['symbol','currency','exchangeName','instrumentType','firstTradeDate','dataGranularity','range','timezone','gmtoffset','regularMarketTime']as $k)
            if(array_key_exists($k,$meta))$out['meta'][$k]=$meta[$k];
        if(strtoupper((string)($meta['symbol']??''))!==strtoupper($symbol))$out['hold_reasons'][]='identity_mismatch';
        if(($meta['currency']??'')!=='KRW')$out['hold_reasons'][]='currency_not_krw';
        $ts=is_array($res['timestamp']??null)?$res['timestamp']:[];
        $out['timestamps']=count($ts);
        $inc=true;$prev=null;
        foreach($ts as $t){
            if(!is_int($t)||($prev!==null&&$t<=$prev))$inc=false;
            $prev=is_int($t)?$t:$prev;
        }
        if(!$inc)$out['hold_reasons'][]='timestamps_not_increasing';
        $out['first_ts']=$ts[0]??null;$out['last_ts']=$ts?end($ts):null;
        $quote=$res['indicators']['quote'][0]??[];$nulls=[];$nullClose=[];
        foreach(['open','high','low','close','volume']as $k){
            $n=0;foreach($ts as $i=>$_)if(!isset($quote[$k][$i]))$n++;
            $nulls[$k]=$n;
        }
        foreach($ts as $i=>$t)if(!isset($quote['close'][$i])&&is_int($t))$nullClose[]=self::day($t);
        $out['null_fields']=$nulls;$out['null_close_days']=array_slice($nullClose,0,self::LIST_CAP);
        $out['null_close_count']=count($nullClose);
        $adj=$res['indicators']['adjclose'][0]['adjclose']??null;$differs=0;
        if(is_array($adj))foreach($ts as $i=>$_){
            $c=$quote['close'][$i]??null;$a=$adj[$i]??null;
            if(is_numeric($c)&&is_numeric($a)&&abs((float)$c-(float)$a)>1e-9*max(1.0,abs((float)$c)))$differs++;
        }
        $out['adjclose_present']=is_array($adj);$out['adjclose_differs_bars']=$differs;
        return $out;
    }

    /**
     * Row level checks. Nothing is dropped or repaired here; incomplete and later bars are only counted.
     * @param list<array<string,mixed>> $rows rows exactly as the existing Yahoo client returned them
     */
    public static function inspectRows(array $rows,string $symbol,int $asOfClose,string $evalStartDay,array $raw=[]):array
    {
        $asOfDay=self::day($asOfClose);
        $dayCount=[];$synthetic=[];$incomplete=[];$after=[];$invalid=[];$invalidVolume=[];$negative=[];$zero=[];$corrections=[];
        foreach($rows as $r){
            $close=CandleClock::closeTime($r,$symbol);$day=self::day($close);
            $dayCount[$day]=($dayCount[$day]??0)+1;
            if(isset($r['historical_close_repair']))
                $corrections[]=['date'=>$day,'type'=>'historical_close_repair','original_close'=>$r['historical_close_repair']['original']['close']??null,
                    'close'=>$r['close']??null,'source_sha256'=>$r['historical_close_repair']['source_sha256']??null];
            elseif(($r['ohlcv_source']??null)==='naver_daily')$corrections[]=['date'=>$day,'type'=>'naver_daily_ohlcv'];
            elseif(($r['close_source']??'yahoo_daily')!=='yahoo_daily')$corrections[]=['date'=>$day,'type'=>(string)$r['close_source']];
            if(!empty($r['synthetic'])){$synthetic[]=$day;continue;}
            if(($r['is_complete']??true)===false){$incomplete[]=$day;continue;}
            if($close>$asOfClose){$after[]=$day;continue;}
            if(CandleClock::completed([$r],$symbol,$asOfClose)===[])$invalid[]=$day;
            $v=$r['volume']??null;
            if(!is_numeric($v)||!is_finite((float)$v))$invalidVolume[]=$day;
            elseif($v<0)$negative[]=$day;
            elseif($v==0)$zero[]=$day;
        }
        $duplicates=array_keys(array_filter($dayCount,fn($n)=>$n>1));
        $completed=CandleClock::completed($rows,$symbol,$asOfClose);
        $n=count($completed);$first=$n?self::day($completed[0]['available_at']):null;$last=$n?self::day($completed[$n-1]['available_at']):null;
        $firstEval=null;$through=0;
        foreach($completed as $i=>$b){
            if(self::day($b['available_at'])>=$evalStartDay){$firstEval=$i;break;}
        }
        $evalBars=$firstEval===null?0:$n-$firstEval;
        if($firstEval!==null)$through=$firstEval+1; // bars up to and including the first evaluation day
        $jumps=[];$gaps=[];
        for($i=1;$i<$n;$i++){
            $a=$completed[$i-1];$b=$completed[$i];$days=(int)round(($b['available_at']-$a['available_at'])/86400);
            if($days>10)$gaps[]=['from'=>self::day($a['available_at']),'to'=>self::day($b['available_at']),'calendar_days'=>$days];
            elseif($a['close']>0&&abs($b['close']/$a['close']-1)>self::JUMP_LIMIT)
                $jumps[]=['date'=>self::day($b['available_at']),'previous_close'=>$a['close'],'close'=>$b['close'],
                    'change_pct'=>round(($b['close']/$a['close']-1)*100,2)];
        }
        $hold=$raw['hold_reasons']??[];
        if($duplicates)$hold[]='duplicate_session';
        if($negative)$hold[]='negative_volume';
        if($n<self::MIN_BARS)$hold[]='insufficient_history';
        if($n===0||$last!==$asOfDay)$hold[]='missing_session';
        if($jumps)$hold[]='price_jump_beyond_daily_limit';
        if($evalBars===0)$hold[]='no_evaluation_bars';
        $warn=[];
        if($n>0&&$evalBars>0&&$through<self::PREP_BARS)$warn[]='history_short_for_240';
        if($first!==null&&$first>$evalStartDay)$warn[]='evaluation_window_starts_after_listing';
        if($invalid)$warn[]='invalid_ohlcv_history';
        if($invalidVolume)$warn[]='invalid_volume_history';
        if(array_filter($corrections,fn($c)=>$c['type']==='historical_close_repair'))$warn[]='historical_close_repair_applied';
        if($synthetic||$incomplete||$after)$warn[]='non_evaluation_bars_present';
        if($gaps)$warn[]='long_calendar_gap';
        if($zero)$warn[]='zero_volume_days';
        if(($raw['null_close_count']??0)>0)$warn[]='provider_rows_without_close';
        $cap=fn(array $v)=>array_slice($v,0,self::LIST_CAP);
        $quality=PaperQuality::inspect($rows,$symbol,$asOfClose,['sha256'=>$raw['sha256']??null,'price_basis'=>self::PRICE_BASIS]);
        $hold=array_values(array_unique($hold));
        return ['status'=>$hold?'hold':($warn?'ok_with_warnings':'ok'),'hold_reasons'=>$hold,'warnings'=>$warn,
            'rows'=>count($rows),'completed_bars'=>$n,'first_completed'=>$first,'last_completed'=>$last,
            'as_of_day'=>$asOfDay,'evaluation_start_day'=>$evalStartDay,
            'evaluation_bars'=>$evalBars,'bars_through_evaluation_start'=>$through,
            'eligible_from_60'=>$n>=60?self::day($completed[59]['available_at']):null,
            'eligible_from_240'=>$n>=self::PREP_BARS?self::day($completed[self::PREP_BARS-1]['available_at']):null,
            'excluded_from_evaluation'=>['synthetic'=>count($synthetic),'incomplete'=>count($incomplete),'after_as_of'=>count($after),
                'incomplete_days'=>$cap($incomplete),'after_as_of_days'=>$cap($after)],
            'duplicate_days'=>$cap($duplicates),'invalid_ohlcv_days'=>$cap($invalid),'invalid_volume_days'=>$cap($invalidVolume),
            'negative_volume_days'=>$cap($negative),'zero_volume_count'=>count($zero),
            'price_jumps'=>$cap($jumps),'calendar_gaps'=>$cap($gaps),
            'corrections'=>['count'=>count($corrections),'items'=>$cap($corrections)],
            'paper_quality_at_as_of'=>['status'=>$quality['status'],'reasons'=>$quality['reasons'],'warnings'=>$quality['warnings'],
                'invalid_bar_count'=>count($quality['invalid_bars'])]];
    }

    // ---- 3. collection ---------------------------------------------------------------------------------------

    /** Raw HTTP with a few bounded retries. Returns the exact body so its hash can be kept. */
    public static function httpGet(string $url):array
    {
        $ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
        $last='';
        for($attempt=1;$attempt<=3;$attempt++){
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>30,CURLOPT_ENCODING=>'',
                CURLOPT_HTTPHEADER=>['User-Agent: '.$ua,'Accept: application/json,text/plain,*/*','Accept-Language: en-US,en;q=0.9,ko;q=0.8']]);
            $body=curl_exec($ch);
            if($body===false){$last=curl_error($ch);curl_close($ch);usleep(400000*$attempt);continue;}
            $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            if($code===429||$code===503){$last='HTTP '.$code;usleep(800000*$attempt);continue;}
            return ['body'=>(string)$body,'http_code'=>$code,'fetched_at'=>time()];
        }
        throw new RuntimeException('Yahoo fetch failed: '.($last!==''?$last:'unknown'));
    }

    /**
     * @param Closure(string):array{body:string,http_code:int,fetched_at:int} $http
     * @return array{client:YahooChartClient,context:ArrayObject}
     */
    public static function makeClient(string $dir,Closure $http,?Closure $clock=null,?Closure $clientFactory=null):array
    {
        $ctx=new ArrayObject(['symbol'=>null]);
        $transport=function(string $url) use ($dir,$http,$ctx):array{
            $symbol=(string)$ctx['symbol'];
            parse_str((string)parse_url($url,PHP_URL_QUERY),$q);$range=(string)($q['range']??'');
            if($symbol===''||!preg_match('/^[0-9a-z]{1,4}$/',$range))throw new RuntimeException('Request outside collection context');
            $res=$http($url);
            if($res['http_code']>=400||$res['body']==='')throw new RuntimeException('Yahoo HTTP '.$res['http_code'].' for '.$symbol);
            $sha=hash('sha256',$res['body']);
            $raw=$dir.'/raw/yahoo/'.$sha.'.json';
            if(!is_file($raw))self::writeFile($raw,$res['body']);
            // Evidence is published before the parsed rows can be used.
            self::writeFile($dir.'/raw/yahoo/index/'.self::safeName($symbol).'.'.$range.'.json',self::encode(
                ['symbol'=>$symbol,'range'=>$range,'interval'=>'1d','url'=>$url,'sha256'=>$sha,'bytes'=>strlen($res['body']),
                 'http_code'=>$res['http_code'],'fetched_at'=>$res['fetched_at']]));
            $decoded=json_decode($res['body'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($decoded))throw new RuntimeException('Malformed chart response');
            return $decoded;
        };
        $factory=$clientFactory??fn(string $cache,Closure $t,?Closure $c)=>new YahooChartClient($cache,null,$t,$c);
        return ['client'=>$factory($dir.'/cache',$transport,$clock),'context'=>$ctx];
    }

    /** @return array{rows:list<array<string,mixed>>,raw:array<string,mixed>,request:array<string,mixed>} */
    private static function fetchRange(string $dir,string $symbol,string $range,array $deps):array
    {
        $deps['context']['symbol']=$symbol;
        $indexPath=$dir.'/raw/yahoo/index/'.self::safeName($symbol).'.'.$range.'.json';
        $cache=$dir.'/cache/'.$symbol.'_'.$range.'_1d_closed_v2.json';
        // A cached parse without its evidence record cannot be used.
        if(is_file($cache)&&!is_file($indexPath))@unlink($cache);
        $rows=$deps['client']->fetch($symbol,$range,'1d',true,null);
        $idx=self::readJson($indexPath);
        $body=is_array($idx)?@file_get_contents($dir.'/raw/yahoo/'.$idx['sha256'].'.json'):false;
        if($body===false||!hash_equals((string)$idx['sha256'],hash('sha256',$body)))throw new RuntimeException('Raw provider evidence missing or damaged');
        $raw=self::inspectRaw($body,$symbol);
        return ['rows'=>$rows,'raw'=>$raw,'request'=>['range'=>$range,'interval'=>'1d','url'=>$idx['url'],'fetched_at'=>$idx['fetched_at'],
            'http_code'=>$idx['http_code'],'raw_sha256'=>$idx['sha256'],'raw_bytes'=>$idx['bytes'],'raw_path'=>'raw/yahoo/'.$idx['sha256'].'.json']];
    }

    private static function providerExhausted(array $raw):bool
    {
        $first=$raw['meta']['firstTradeDate']??null;$ts=$raw['first_ts']??null;
        return is_numeric($first)&&is_int($ts)&&$ts-(int)$first<10*86400;
    }

    public static function readStatus(string $dir,string $symbol):?array
    {
        return self::readJson($dir.'/status/'.self::safeName($symbol).'.json');
    }

    private static function barsIntact(string $dir,array $st):bool
    {
        $path=$dir.'/'.($st['bars_path']??'');
        return ($st['state']??'')==='done'&&is_file($path)&&hash_equals((string)($st['bars_sha256']??''),(string)hash_file('sha256',$path));
    }

    /**
     * One resumable step. A symbol with intact bars is never downloaded again.
     * @return array{action:string,status:array<string,mixed>}
     */
    public static function collectSymbol(string $dir,array $u,array $as,array $deps,int $maxAttempts=self::MAX_ATTEMPTS,bool $retryFailed=false):array
    {
        self::assertResearchDir($dir);
        $symbol=self::safeName((string)$u['symbol']);
        $st=self::readStatus($dir,$symbol)??['schema'=>self::SCHEMA,'symbol'=>$symbol,'name'=>$u['name'],'attempts'=>0];
        if(self::barsIntact($dir,$st))return ['action'=>'reused','status'=>$st];
        if(($st['attempts']??0)>=$maxAttempts&&!$retryFailed)return ['action'=>'skipped_attempt_limit','status'=>$st];
        $st['attempts']=($st['attempts']??0)+1;$st['state']='running';
        self::writeFile($dir.'/status/'.$symbol.'.json',self::encode($st,true));
        try{
            $evalStart=self::evalStartDay($as['day']);
            $res=self::fetchRange($dir,$symbol,self::PRIMARY_RANGE,$deps);
            $info=self::inspectRows($res['rows'],$symbol,$as['close_ts'],$evalStart,$res['raw']);
            $requests=[$res['request']];$extension='not_needed';
            if($info['bars_through_evaluation_start']<self::PREP_BARS&&$info['completed_bars']>0&&!array_diff($info['hold_reasons'],['insufficient_history','no_evaluation_bars'])
                &&$info['evaluation_bars']>0){
                if(self::providerExhausted($res['raw']))$extension='skipped_provider_history_exhausted';
                else{
                    // A different range is a different response. Use it whole, never stitched to the 2y response.
                    $ext=self::fetchRange($dir,$symbol,self::EXTENDED_RANGE,$deps);
                    $requests[]=$ext['request'];$extension='requested';
                    $extInfo=self::inspectRows($ext['rows'],$symbol,$as['close_ts'],$evalStart,$ext['raw']);
                    $res=$ext;$info=$extInfo;
                }
            }
            $final=$requests[array_key_last($requests)];
            $bars=['schema'=>self::SCHEMA,'policy'=>self::POLICY,'symbol'=>$symbol,'name'=>$u['name'],'provider'=>self::PROVIDER,
                'price_basis'=>self::PRICE_BASIS,'request'=>$final,'as_of_session'=>$as['day'],'as_of_close_ts'=>$as['close_ts'],
                'pipeline'=>'Rows are the output of src/YahooChartClient (Yahoo chart + existing Naver session evidence + verified close repair). '.
                    'Pass them through CandleClock::completed with session=as_of_close_ts.',
                'raw_meta'=>$res['raw']['meta'],'quality'=>$info,'rows'=>$res['rows']];
            $bytes=self::encode($bars);$barsPath='bars/'.$symbol.'.json';
            self::writeFile($dir.'/'.$barsPath,$bytes);
            $st=['schema'=>self::SCHEMA,'symbol'=>$symbol,'name'=>$u['name'],'attempts'=>$st['attempts'],'state'=>'done','error'=>null,
                'extension'=>$extension,'final_range'=>$final['range'],'requests'=>$requests,'bars_path'=>$barsPath,
                'bars_sha256'=>hash('sha256',$bytes),'bars_bytes'=>strlen($bytes),'collected_at'=>time(),'quality'=>$info,
                'raw_checks'=>$res['raw']];
            self::writeFile($dir.'/status/'.$symbol.'.json',self::encode($st,true));
            return ['action'=>'collected','status'=>$st];
        }catch(Throwable $e){
            $st['state']='failed';$st['error']=$e->getMessage();
            self::writeFile($dir.'/status/'.$symbol.'.json',self::encode($st,true));
            return ['action'=>'failed','status'=>$st];
        }
    }

    // ---- 4. manifest -----------------------------------------------------------------------------------------

    public static function manifest(string $dir):array
    {
        $ds=self::readJson($dir.'/dataset.json')??throw new RuntimeException('dataset.json missing');
        $uni=self::readJson($dir.'/universe.json')??throw new RuntimeException('universe.json missing');
        if(!is_array($ds['as_of']))throw new RuntimeException('Analysis date is not fixed yet; run collect first');
        $symbols=[];$sum=['requested'=>0,'collected'=>0,'failed'=>0,'not_attempted'=>0,'quality_ok'=>0,'quality_ok_with_warnings'=>0,'quality_hold'=>0,
            'history_short_for_240'=>0,'insufficient_history'=>0,'extension_requested'=>0,'provider_history_exhausted'=>0,'with_close_repair'=>0];
        $holdBy=[];$warnBy=[];$failed=[];$held=[];
        foreach($uni['rows'] as $code=>$u){
            if($u['status']!=='included')continue;
            $sum['requested']++;
            $st=self::readStatus($dir,$u['symbol']);
            $row=['symbol'=>$u['symbol'],'name'=>$u['name']];
            if($st===null||!in_array($st['state']??'',['done','failed','running'],true)){$sum['not_attempted']++;$row['state']='not_attempted';$symbols[]=$row;continue;}
            $row['state']=$st['state'];$row['attempts']=$st['attempts'];
            if($st['state']!=='done'){
                $sum['failed']++;$row['error']=$st['error']??'interrupted';$failed[]=['symbol'=>$u['symbol'],'name'=>$u['name'],'attempts'=>$st['attempts'],'error'=>$row['error']];
                $symbols[]=$row;continue;
            }
            $sum['collected']++;$q=$st['quality'];
            $sum['quality_'.$q['status']]++;
            foreach($q['hold_reasons'] as $r)$holdBy[$r]=($holdBy[$r]??0)+1;
            foreach($q['warnings'] as $r)$warnBy[$r]=($warnBy[$r]??0)+1;
            if(in_array('history_short_for_240',$q['warnings'],true))$sum['history_short_for_240']++;
            if(in_array('insufficient_history',$q['hold_reasons'],true))$sum['insufficient_history']++;
            if(($st['extension']??'')==='requested')$sum['extension_requested']++;
            if(($st['extension']??'')==='skipped_provider_history_exhausted')$sum['provider_history_exhausted']++;
            if(in_array('historical_close_repair_applied',$q['warnings'],true))$sum['with_close_repair']++;
            if($q['hold_reasons'])$held[]=['symbol'=>$u['symbol'],'name'=>$u['name'],'reasons'=>$q['hold_reasons']];
            $symbols[]=$row+['quality'=>$q['status'],'hold_reasons'=>$q['hold_reasons'],'warnings'=>$q['warnings'],
                'range'=>$st['final_range'],'extension'=>$st['extension'],'rows'=>$q['rows'],'completed_bars'=>$q['completed_bars'],
                'first_completed'=>$q['first_completed'],'last_completed'=>$q['last_completed'],
                'evaluation_bars'=>$q['evaluation_bars'],'bars_through_evaluation_start'=>$q['bars_through_evaluation_start'],
                'eligible_from_60'=>$q['eligible_from_60'],'eligible_from_240'=>$q['eligible_from_240'],
                'fetched_at'=>$st['requests'][array_key_last($st['requests'])]['fetched_at'],
                'raw_sha256'=>$st['requests'][array_key_last($st['requests'])]['raw_sha256'],
                'bars_path'=>$st['bars_path'],'bars_sha256'=>$st['bars_sha256']];
        }
        ksort($holdBy);ksort($warnBy);
        $excluded=[];$conflict=[];
        foreach($uni['rows'] as $u){
            if($u['status']==='excluded')$excluded[]=['code'=>$u['code'],'names'=>$u['names'],'kind'=>$u['exclusion']['kind']??null];
            if($u['status']==='hold')$conflict[]=['code'=>$u['code'],'symbols'=>$u['symbols'],'names'=>$u['names'],'reasons'=>$u['reasons']];
        }
        return ['schema'=>self::SCHEMA,'policy'=>self::POLICY,'dataset'=>$ds['dataset'],'generated_at'=>time(),
            'membership'=>self::MEMBERSHIP,
            'universe_note'=>'Symbols seen in saved scans. This is neither the whole market nor the top 100 of the day each bar was evaluated.',
            'as_of'=>$ds['as_of']+['basis'=>$ds['as_of_basis']],
            'window'=>['evaluation_months'=>self::EVAL_MONTHS,'evaluation_start_day'=>self::evalStartDay($ds['as_of']['day']),
                'preparation_bars_target'=>self::PREP_BARS,'minimum_bars'=>self::MIN_BARS,'primary_range'=>self::PRIMARY_RANGE,
                'extended_range'=>self::EXTENDED_RANGE],
            'provider'=>['name'=>self::PROVIDER,'price_basis'=>self::PRICE_BASIS,'interval'=>'1d'],
            'caveats'=>[
                'Corporate action and dividend adjustment of the provider prices could not be verified; none was assumed.',
                'Prices fetched now are not guaranteed to equal what the scheduled scans stored on the day.',
                'Recent listings keep only the bars the provider has; nothing is generated or filled.',
                'Only completed sessions up to as_of are evaluation bars; provisional bars are flagged and excluded.'],
            'files'=>['dataset.json'=>hash_file('sha256',$dir.'/dataset.json'),'universe.json'=>hash_file('sha256',$dir.'/universe.json')],
            'universe'=>['sources'=>$uni['sources'],'source_errors'=>$uni['source_errors'],'counts'=>$uni['counts']],
            'summary'=>$sum,'hold_reason_counts'=>$holdBy,'warning_counts'=>$warnBy,
            'excluded_instruments'=>$excluded,'identity_hold'=>$conflict,'failed'=>$failed,'quality_hold'=>$held,'symbols'=>$symbols];
    }

    /** Recompute hashes and quality from the stored files. Returns problems; empty means the dataset is intact. */
    public static function verify(string $dir):array
    {
        $problems=[];
        $ds=self::readJson($dir.'/dataset.json');$uni=self::readJson($dir.'/universe.json');
        if(!$ds||!$uni)return ['dataset.json or universe.json missing'];
        if(!is_array($ds['as_of']))return ['analysis date is not fixed yet'];
        if(($ds['universe_sha256']??'')!==hash_file('sha256',$dir.'/universe.json'))$problems[]='universe.json hash changed';
        $evalStart=self::evalStartDay($ds['as_of']['day']);
        foreach($uni['rows'] as $u){
            if($u['status']!=='included')continue;
            $st=self::readStatus($dir,$u['symbol']);
            if(!$st||($st['state']??'')!=='done')continue;
            if(!self::barsIntact($dir,$st)){$problems[]=$u['symbol'].': bars file missing or hash changed';continue;}
            foreach($st['requests'] as $rq){
                $p=$dir.'/'.$rq['raw_path'];
                if(!is_file($p)||!hash_equals($rq['raw_sha256'],(string)hash_file('sha256',$p)))$problems[]=$u['symbol'].': raw '.$rq['range'].' evidence missing or changed';
            }
            $bars=self::readJson($dir.'/'.$st['bars_path']);
            $raw=self::inspectRaw((string)@file_get_contents($dir.'/raw/yahoo/'.$st['requests'][array_key_last($st['requests'])]['raw_sha256'].'.json'),$u['symbol']);
            $q=self::inspectRows($bars['rows']??[],$u['symbol'],$ds['as_of']['close_ts'],$evalStart,$raw);
            if($q['hold_reasons']!==$st['quality']['hold_reasons']||$q['completed_bars']!==$st['quality']['completed_bars'])
                $problems[]=$u['symbol'].': recomputed quality differs from status';
        }
        return $problems;
    }
}
