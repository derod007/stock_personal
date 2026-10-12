<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/HistoryResearch.php';
require_once __DIR__.'/DatedTop100Replay.php';
use ChartEntryLab\{CandleClock,PaperQuality};

/**
 * Research only: prepare dated KRX amount TOP100 candidates and daily bars for 2025-10-08..2026-10-08.
 * No account replay. Never writes operational accounts, caches, strategy files, or the Oct 2025 dataset.
 */
final class PaperDatedTop100Prep
{
    public const VERSION='dated_krx_amount_top100_prep_v1';
    public const LABEL='KRX 일별 거래대금 TOP100 연구';
    public const DATASET='kr-dated-amount-top100-20251008-20261008';
    public const CANDIDATES='docs/historical-top100-20251008-20261008/candidates.json';
    public const UNIVERSE='docs/historical-top100-20251008-20261008/universe.json';
    public const EVAL_START='2025-10-08';
    public const AS_OF='2026-10-08';
    public const POLICY='dated_krx_amount_top100_20251008_20261008_v1';

    public static function sessionClose(string $day):int
    {
        if(preg_match('/^\d{8}$/',$day))$day=substr($day,0,4).'-'.substr($day,4,2).'-'.substr($day,6,2);
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$day))throw new InvalidArgumentException('Invalid day '.$day);
        return (new DateTimeImmutable($day.' 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();
    }

    public static function loadCandidates(string $path):array
    {
        $j=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(($j['kind']??'')!=='dated_krx_amount_top100_candidates_v1')throw new RuntimeException('Not a dated TOP100 candidate file');
        $byTs=[];$union=[];
        foreach($j['dates'] as $day=>$rec){
            if((int)($rec['n']??0)!==100||count($rec['rows']??[])!==100)throw new RuntimeException('Candidate day '.$day.' is not 100 names');
            $codes=[];$symbols=[];
            foreach($rec['rows'] as $r){
                $sym=(string)$r['symbol'];
                if(isset($symbols[$sym]))throw new RuntimeException('Duplicate candidate '.$sym.' on '.$day);
                $symbols[$sym]=true;$codes[]=$r['code'];$union[$sym]=true;
            }
            if(count($codes)!==count(array_unique($codes)))throw new RuntimeException('Duplicate codes on '.$day);
            $byTs[self::sessionClose($day)]=['day'=>$day,'symbols'=>array_keys($symbols),'rows'=>$rec['rows'],'source_sha256'=>$rec['source_sha256']??null];
        }
        ksort($byTs);
        return ['file'=>$path,'file_sha256'=>hash_file('sha256',$path),'days'=>$j['days'],'by_session'=>$byTs,'union'=>array_keys($union),'raw'=>$j];
    }

    public static function initDataset(string $stateRoot,string $universePath):array
    {
        $dir=PaperHistoryResearch::datasetDir($stateRoot,self::DATASET);
        PaperHistoryResearch::assertResearchDir($dir);
        $uni=json_decode((string)file_get_contents($universePath),true,512,JSON_THROW_ON_ERROR);
        if(($uni['policy']??'')!==self::POLICY)throw new RuntimeException('Universe policy mismatch');
        if(is_file($dir.'/dataset.json')){
            $ds=PaperHistoryResearch::readJson($dir.'/dataset.json');
            if(!is_array($ds)||is_array($ds['as_of']??null)||is_dir($dir.'/status')||is_dir($dir.'/raw'))
                throw new RuntimeException('Dataset already has a fixed universe; use a new --dataset id');
        }elseif(is_dir($dir)&&(glob($dir.'/*')?:[])!==[])throw new RuntimeException('Refusing to write into a non-empty folder without dataset.json');
        $bytes=PaperHistoryResearch::encode($uni,true);
        PaperHistoryResearch::writeFile($dir.'/universe.json',$bytes);
        $asTs=self::sessionClose(self::AS_OF);
        $ds=['schema'=>1,'policy'=>self::POLICY,'dataset'=>self::DATASET,'created_at'=>time(),
            'universe_sha256'=>hash('sha256',$bytes),'requested_as_of'=>self::AS_OF,'evaluation_start_day'=>self::EVAL_START,
            'primary_range'=>PaperHistoryResearch::EXTENDED_RANGE,
            'as_of'=>['day'=>self::AS_OF,'close_ts'=>$asTs,'fixed_at'=>time()],
            'as_of_basis'=>'dated TOP100 range last session 2026-10-08 15:30 KST',
            'note'=>'Union is a collection list. New-entry candidates stay the dated TOP100. No account replay in this step.'];
        PaperHistoryResearch::writeFile($dir.'/dataset.json',PaperHistoryResearch::encode($ds,true));
        return $ds+['dir'=>$dir];
    }

    /**
     * Per dated TOP100 session, using only bars through that close. Dataset-wide hold is not applied.
     * Does not rank, trade, or compute returns.
     *
     * @param array<string,array<string,mixed>> $symbolState
     * @param null|callable(string,array<string,mixed>):array<string,mixed> $hydrate
     */
    public static function sessionCoverage(array $candidates,array $symbolState,string $evalStart=self::EVAL_START,?callable $hydrate=null):array
    {
        $reasonKeys=['analyzable','unmapped','no_bars','insufficient_history','missing_session','price_jump','other_blocked'];
        $occ=array_fill_keys($reasonKeys,0);
        $sym=array_fill_keys($reasonKeys,[]);
        $sess=array_fill_keys($reasonKeys,[]);
        $byDay=[];$lastTop=[];$memberOf=[];
        foreach($candidates['by_session'] as $ts=>$rec){
            $byDay[$rec['day']]=array_fill_keys($reasonKeys,0)+['day'=>$rec['day'],'top100'=>count($rec['rows'])];
            foreach($rec['rows'] as $row){
                $symbol=(string)$row['symbol'];
                $lastTop[$symbol]=$rec['day'];
                $memberOf[$symbol][]=(int)$ts;
            }
        }
        $postChecked=0;$postMissing=0;$postSym=[];$postSess=[];
        $earlierNotWiped=0;$laterJumpSessions=0;
        $all=array_unique(array_merge(array_keys($memberOf),array_keys($symbolState)));
        foreach($all as $symbol){
            $st=$symbolState[$symbol]??['status'=>'missing','state'=>'missing','rows'=>[],'bars_sha256'=>null];
            if($hydrate!==null)$st=$hydrate($symbol,$st);
            $have=[];
            if(($st['rows']??[])!==[]){
                foreach(CandleClock::completed($st['rows'],$symbol,PHP_INT_MAX) as $b)$have[$b['available_at']]=true;
            }
            $jumpDay=($st['rows']??[])===[]?null:self::firstPriceJumpDay($st['rows'],$symbol);
            foreach($memberOf[$symbol]??[] as $ts){
                $day=$candidates['by_session'][$ts]['day'];
                $class=self::classifyAtSession($symbol,$st,(int)$ts,$evalStart);
                if($jumpDay!==null&&$day>=$jumpDay&&!in_array('unmapped',$class['tags'],true)&&!in_array('no_bars',$class['tags'],true))
                    $class['tags']=array_values(array_unique(array_merge($class['tags'],['price_jump'])));
                foreach($class['tags'] as $tag){
                    $occ[$tag]++;$byDay[$day][$tag]++;$sym[$tag][$symbol]=true;$sess[$tag][$day]=true;
                }
            }
            if(($st['status']??'')!=='provider_symbol_unmapped'&&isset($lastTop[$symbol])){
                foreach($candidates['by_session'] as $ts=>$rec){
                    if($lastTop[$symbol]>=$rec['day'])continue;
                    $postChecked++;
                    if(($st['state']??'')!=='done'||empty($have[$ts])){
                        $postMissing++;$postSym[$symbol]=true;$postSess[$rec['day']]=true;
                    }
                }
            }
            if($jumpDay!==null){
                $hadEarlier=false;
                foreach($memberOf[$symbol]??[] as $ts){
                    $day=$candidates['by_session'][$ts]['day'];
                    if($day<$jumpDay)$hadEarlier=true;
                    else $laterJumpSessions++;
                }
                if($hadEarlier)$earlierNotWiped++;
            }
            unset($st['rows']);
        }
        $uniq=static fn(array $v):int=>count($v);
        return [
            'schema'=>1,'kind'=>'dated_top100_session_coverage_v1','label'=>self::LABEL,
            'eval_start'=>$evalStart,'account_replay'=>false,
            'note'=>'Quality uses bars through that session only. A later jump does not by itself remove an earlier session.',
            'day_count'=>count($byDay),'days'=>array_values($byDay),
            'occurrence'=>$occ,'unique_symbols'=>array_map($uniq,$sym),'unique_sessions'=>array_map($uniq,$sess),
            'post_dropout'=>[
                'symbol_sessions_checked'=>$postChecked,
                'missing_bars'=>$postMissing,
                'unique_symbols'=>$uniq($postSym),
                'unique_sessions'=>$uniq($postSess),
            ],
            'later_jump_does_not_wipe_earlier'=>[
                'symbols_with_later_jump_and_earlier_clear'=>$earlierNotWiped,
                'sessions_tagged_price_jump'=>$laterJumpSessions,
                'pass'=>$earlierNotWiped>0||$laterJumpSessions===0,
            ],
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    public static function firstPriceJumpDay(array $rows,string $symbol):?string
    {
        $completed=CandleClock::completed($rows,$symbol,PHP_INT_MAX);
        $n=count($completed);
        for($i=1;$i<$n;$i++){
            $a=$completed[$i-1];$b=$completed[$i];
            if(($a['close']??0)>0&&is_numeric($b['close']??null)&&abs($b['close']/$a['close']-1)>PaperHistoryResearch::JUMP_LIMIT)
                return PaperHistoryResearch::day($b['available_at']);
        }
        return null;
    }

    /** @param array<string,mixed> $st */
    public static function classifyAtSession(string $symbol,array $st,int $session,string $evalStart):array
    {
        if(($st['status']??'')==='provider_symbol_unmapped'){
            return ['tags'=>['unmapped'],'quality'=>null,'inspect'=>null];
        }
        if(($st['state']??'')!=='done'||($st['rows']??[])===[]){
            return ['tags'=>['no_bars'],'quality'=>null,'inspect'=>null];
        }
        $source=['sha256'=>$st['bars_sha256']??null,'price_basis'=>PaperHistoryResearch::PRICE_BASIS];
        $quality=PaperQuality::inspect($st['rows'],$symbol,$session,$source,[]);
        $tags=[];
        if($quality['can_simulate'])$tags[]='analyzable';
        else{
            $mapped=false;
            foreach($quality['reasons']??[] as $r){
                if($r==='insufficient_history'){$tags[]='insufficient_history';$mapped=true;}
                elseif($r==='missing_session'){$tags[]='missing_session';$mapped=true;}
            }
            if(!$mapped)$tags[]='other_blocked';
        }
        return ['tags'=>array_values(array_unique($tags)),'quality'=>$quality];
    }

    public static function classifyKrxCode(string $code):string
    {
        if(preg_match('/^\d{6}$/',$code))return 'six_digit';
        if(preg_match('/^\d{5}[A-Za-z]$/',$code)||preg_match('/^\d{4}[A-Za-z]{2}$/',$code))return 'preferred_or_class_letter_suffix';
        if(preg_match('/^\d{4}[A-Za-z]\d$/',$code)||preg_match('/[A-Za-z]/',$code))return 'alphanumeric_short_code';
        return 'other';
    }

    /** @param list<string> $symbols */
    public static function appearanceCounts(array $candidates,array $symbols):array
    {
        $want=array_fill_keys($symbols,[]);
        foreach($candidates['by_session']??[] as $rec){
            $day=$rec['day'];
            foreach($rec['rows']??[] as $row){
                $sym=(string)($row['symbol']??'');
                if(isset($want[$sym]))$want[$sym][]=$day;
            }
        }
        $days=[];$n=0;
        foreach($want as $list){$n+=count($list);foreach($list as $d)$days[$d]=true;}
        $by=[];
        foreach($want as $sym=>$list)$by[$sym]=['n'=>count($list),'first'=>$list[0]??null,'last'=>$list?$list[count($list)-1]:null];
        ksort($by);
        return ['unique_symbols'=>count($symbols),'appearances'=>$n,'distinct_days'=>count($days),'by_symbol'=>$by];
    }

    /**
     * Probe Yahoo chart/search and Naver daily HTML. Writes a new gitignored version. Does not mix into frozen bars.
     * @param list<array<string,mixed>> $rows
     */
    public static function probeProviders(array $rows,string $probeRoot,int $sleepMs=800):array
    {
        if(!is_dir($probeRoot)&&!@mkdir($probeRoot,0770,true)&&!is_dir($probeRoot))throw new RuntimeException('Cannot create '.$probeRoot);
        PaperHistoryResearch::resetHttpAttempts();
        $symbols=[];$started=(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
        foreach($rows as $i=>$rec){
            $symbols[]=self::probeOne($rec,$probeRoot);
            if($i+1<count($rows)&&$sleepMs>0)usleep($sleepMs*1000);
        }
        $outcomes=[];
        foreach($symbols as $s)$outcomes[$s['outcome']]=($outcomes[$s['outcome']]??0)+1;
        $attempts=PaperHistoryResearch::$httpAttempts;
        return [
            'schema'=>1,'kind'=>'dated_top100_provider_review_v1','label'=>self::LABEL,
            'generated_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s'),
            'started'=>$started,'probe_root_gitignored'=>$probeRoot,
            'existing_dataset_not_overwritten'=>true,'frozen_hashes_not_rewritten'=>true,'no_rank_101_fill'=>true,
            'symbols'=>$symbols,'outcomes'=>$outcomes,
            'request_count'=>count($attempts),
            'requests'=>array_map(fn($r)=>['url'=>$r['url'],'http_code'=>$r['http_code']??null,'error'=>$r['error']??null,'attempt'=>$r['attempt']??null],$attempts),
            'note'=>['숫자 6자리가 아니라고 수집을 생략하지 않았다.','검색으로 나온 다른 심볼 가격을 연결하지 않았다.','네이버 일별 시세와 Yahoo chart를 한 시계열로 붙이지 않았다.'],
        ];
    }

    /** @param array<string,mixed> $rec */
    private static function probeOne(array $rec,string $probeRoot):array
    {
        $code=(string)$rec['code'];$symbol=(string)$rec['symbol'];$name=(string)($rec['name']??'');
        $out=['kind'=>$rec['kind']??'review','code'=>$code,'symbol'=>$symbol,'name'=>$name,'market'=>$rec['market']??null,
            'code_class'=>self::classifyKrxCode($code),'yahoo_six_digit'=>(bool)preg_match('/^\d{6}\.(KS|KQ)$/',$symbol),
            'successor_not_attached'=>true,'attempts'=>[]];
        $chartUrl='https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol).'?range=1y&interval=1d';
        $chart=self::httpProbe($chartUrl,$probeRoot.'/yahoo-'.str_replace('.','_',$symbol).'.json');
        $parsed=self::yahooChartMeta($chart['body']);
        $out['attempts'][]=['provider'=>'yahoo_chart_v8','url'=>$chartUrl,'http_code'=>$chart['http_code'],'error'=>$chart['error'],
            'supported'=>$parsed['ok'],'parsed'=>$parsed,'price_basis'=>$parsed['ok']?'yahoo_chart_quote_ohlcv_daily_adjustment_unverified':null];
        $searchUrl='https://query2.finance.yahoo.com/v1/finance/search?q='.rawurlencode($code);
        $search=self::httpProbe($searchUrl,$probeRoot.'/yahoo-search-'.$code.'.json');
        $quotes=[];
        $sj=json_decode($search['body'],true);
        if(is_array($sj))foreach(array_slice($sj['quotes']??[],0,5) as $q)$quotes[]=['symbol'=>$q['symbol']??null,'shortname'=>$q['shortname']??null,'quoteType'=>$q['quoteType']??null];
        $out['attempts'][]=['provider'=>'yahoo_search','url'=>$searchUrl,'http_code'=>$search['http_code'],'error'=>$search['error'],
            'quotes'=>$quotes,'identity_confirmed'=>false,'note'=>'Search hits are not used as OHLCV. Successor or lookalike symbols are not attached.'];
        $naverUrl='https://finance.naver.com/item/sise_day.naver?code='.rawurlencode($code).'&page=1';
        $naver=self::httpProbe($naverUrl,$probeRoot.'/naver-'.$code.'.html');
        $hasTable=str_contains($naver['body'],'종가')||str_contains($naver['body'],'종 가');
        $empty=str_contains($naver['body'],'데이터가 없습니다')||str_contains($naver['body'],'페이지가 없습니다');
        $table=$hasTable&&!$empty;
        $out['attempts'][]=['provider'=>'naver_sise_day_page1','url'=>$naverUrl,'http_code'=>$naver['http_code'],'error'=>$naver['error'],
            'html_table_present'=>$table,'price_basis'=>$table?'naver_sise_day_html_ohlcv_adjustment_unverified_recent_pages_only':null,
            'not_mixed_into_yahoo_dataset'=>true];
        if($parsed['ok']){$out['outcome']='yahoo_chart_supported_not_imported';$out['reason']='Yahoo chart returned a result. Not written into the frozen 5y dataset.';}
        elseif($table){$out['outcome']='alternative_provider_seen_not_imported';$out['reason']='Naver page has a daily table. Basis differs from Yahoo chart; not mixed.';}
        else{$out['outcome']='not_secured';$out['reason']='No Yahoo chart result and no Naver daily table on page 1. Not filled with rank 101.';}
        return $out;
    }

    /** @return array{http_code:?int,error:?string,body:string} */
    private static function httpProbe(string $url,string $savePath):array
    {
        try{
            $res=PaperHistoryResearch::httpGet($url);
            file_put_contents($savePath,$res['body']);
            return ['http_code'=>$res['http_code'],'error'=>null,'body'=>$res['body']];
        }catch(Throwable $e){
            return ['http_code'=>null,'error'=>$e->getMessage(),'body'=>''];
        }
    }

    /** @return array{ok:bool,symbol?:mixed,exchange?:mixed,currency?:mixed,bar_count?:int,reason?:string} */
    private static function yahooChartMeta(string $body):array
    {
        if($body==='')return ['ok'=>false,'reason'=>'empty'];
        $j=json_decode($body,true);
        if(!is_array($j))return ['ok'=>false,'reason'=>'not_json'];
        $err=$j['chart']['error']??null;
        $result=$j['chart']['result'][0]??null;
        if($err)return ['ok'=>false,'reason'=>'chart_error'];
        if(!is_array($result))return ['ok'=>false,'reason'=>'no_chart_result'];
        $meta=$result['meta']??[];
        return ['ok'=>true,'symbol'=>$meta['symbol']??null,'exchange'=>$meta['exchangeName']??null,'currency'=>$meta['currency']??null,'bar_count'=>count($result['timestamp']??[])];
    }

    /** Confirmed request counts from stored evidence. Does not recollect. */
    public static function yahooRequestEvidence(string $dir):array
    {
        $uni=PaperHistoryResearch::readJson($dir.'/universe.json')??[];
        $included=0;$done=0;$failed=0;$unmapped=0;
        $successRequests=0;$failedAttempts=0;$statusWithRequestsOnFail=0;$indexFiles=0;
        $failedRows=[];
        foreach(($uni['rows']??[]) as $u){
            if(($u['status']??'')==='provider_symbol_unmapped'){$unmapped++;continue;}
            if(($u['status']??'')!=='included')continue;
            $included++;
            $st=PaperHistoryResearch::readStatus($dir,(string)$u['symbol']);
            if(($st['state']??'')==='done'){
                $done++;
                $successRequests+=count($st['requests']??[]);
            }elseif(($st['state']??'')==='failed'){
                $failed++;
                $failedAttempts+=(int)($st['attempts']??0);
                if(!empty($st['requests']))$statusWithRequestsOnFail++;
                $failedRows[]=['symbol'=>$u['symbol'],'attempts'=>$st['attempts']??null,'error'=>$st['error']??null,
                    'requests_recorded'=>count($st['requests']??[])];
            }
        }
        foreach(glob($dir.'/raw/yahoo/index/*.json')?:[] as $f)$indexFiles++;
        return [
            'schema'=>1,'kind'=>'yahoo_request_evidence_v1','dataset'=>self::DATASET,
            'confirmed'=>[
                'included_symbols'=>$included,
                'collected_done'=>$done,
                'failed_symbols'=>$failed,
                'unmapped_skipped_before_http'=>$unmapped,
                'success_status_request_records'=>$successRequests,
                'failed_status_attempts_sum'=>$failedAttempts,
                'failed_status_with_request_array'=>$statusWithRequestsOnFail,
                'raw_yahoo_index_files'=>$indexFiles,
            ],
            'unconfirmed'=>[
                'httpGet_inner_retries'=>'not_in_existing_status_or_index_logs',
                'failed_symbol_curl_attempts'=>'status.attempts exists; per-curl http_code was not stored',
            ],
            'previous_report_yahoo_requests'=>561,
            'previous_report_note'=>'561 counted collected symbols with a successful request record, not failed HTTP or inner retries.',
            'failed'=>$failedRows,
            'no_recollect'=>true,
        ];
    }
}
