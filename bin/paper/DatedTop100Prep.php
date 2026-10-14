<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/HistoryResearch.php';
require_once __DIR__.'/DatedTop100Replay.php';
use ChartEntryLab\{CandleClock,NaverDailyQuotes,PaperQuality};

/**
 * Research only: prepare dated KRX amount TOP100 candidates and daily bars for 2025-10-08..2026-10-08.
 * No account replay. Never writes operational accounts, caches, strategy files, or the Oct 2025 dataset.
 */
final class PaperDatedTop100Prep
{
    public const VERSION='dated_krx_amount_top100_prep_v2';
    public const LABEL='KRX 일별 거래대금 TOP100 연구';
    public const DATASET='kr-dated-amount-top100-20251008-20261008';
    public const DATASET_V2='kr-dated-amount-top100-20251008-20261008-v2';
    public const CANDIDATES='docs/historical-top100-20251008-20261008/candidates.json';
    public const UNIVERSE='docs/historical-top100-20251008-20261008/universe.json';
    public const UNIVERSE_V2='docs/historical-top100-20251008-20261008/universe-v2.json';
    public const SECTOR_COLLECTION='docs/historical-top100-20251008-20261008/sector-collection.json';
    public const SECTOR_MAP_V2='docs/historical-top100-20251008-20261008/sector-map-v2.json';
    public const SECTOR_MAP_FULL='docs/historical-top100-20251008-20261008/sector-map-full-v2.json';
    public const SECTOR_COLLECTION_V2='docs/historical-top100-20251008-20261008/sector-collection-v2.json';
    public const EVAL_START='2025-10-08';
    public const AS_OF='2026-10-08';
    public const POLICY='dated_krx_amount_top100_20251008_20261008_v1';
    public const POLICY_V2='dated_krx_amount_top100_20251008_20261008_v2';
    public const PROFILE_RECENT='20251008-20261008';
    public const PROFILE_PRIOR='20241008-20251002';
    private const KOSPI_EXCHANGES=['KSC','KSE','SEO'];
    private const KOSDAQ_EXCHANGES=['KOE'];

    /** @return array<string,mixed> */
    public static function spec(string $profile=self::PROFILE_RECENT): array
    {
        if($profile==='' || $profile==='recent' || $profile===self::PROFILE_RECENT){
            return [
                'id'=>self::PROFILE_RECENT,
                'eval_start'=>self::EVAL_START,
                'as_of'=>self::AS_OF,
                'dataset'=>self::DATASET,
                'dataset_v2'=>self::DATASET_V2,
                'candidates'=>self::CANDIDATES,
                'universe'=>self::UNIVERSE,
                'universe_v2'=>self::UNIVERSE_V2,
                'sector_collection'=>self::SECTOR_COLLECTION,
                'sector_map_v2'=>self::SECTOR_MAP_V2,
                'sector_map_full'=>self::SECTOR_MAP_FULL,
                'sector_collection_v2'=>self::SECTOR_COLLECTION_V2,
                'docs'=>'docs/historical-top100-20251008-20261008',
                'policy'=>self::POLICY,
                'policy_v2'=>self::POLICY_V2,
                'as_of_basis'=>'dated TOP100 range last session 2026-10-08 15:30 KST',
                'reuse_from'=>['kr-saved-scan-20261009','kr-saved-scan-20251008','kr-dated-amount-top100-202510'],
            ];
        }
        if($profile==='prior' || $profile===self::PROFILE_PRIOR){
            $docs='docs/historical-top100-20241008-20251002';
            return [
                'id'=>self::PROFILE_PRIOR,
                'eval_start'=>'2024-10-08',
                'as_of'=>'2025-10-02',
                'dataset'=>'kr-dated-amount-top100-20241008-20251002',
                'dataset_v2'=>'kr-dated-amount-top100-20241008-20251002-v2',
                'candidates'=>$docs.'/candidates.json',
                'universe'=>$docs.'/universe.json',
                'universe_v2'=>$docs.'/universe-v2.json',
                'sector_collection'=>$docs.'/sector-collection.json',
                'sector_map_v2'=>$docs.'/sector-map-v2.json',
                'sector_map_full'=>$docs.'/sector-map-full-v2.json',
                'sector_collection_v2'=>$docs.'/sector-collection-v2.json',
                'docs'=>$docs,
                'policy'=>'dated_krx_amount_top100_20241008_20251002_v1',
                'policy_v2'=>'dated_krx_amount_top100_20241008_20251002_v2',
                'as_of_basis'=>'dated TOP100 range last session 2025-10-02 15:30 KST',
                'reuse_from'=>['kr-saved-scan-20261009','kr-saved-scan-20251008','kr-dated-amount-top100-202510','kr-dated-amount-top100-20251008-20261008','kr-dated-amount-top100-20251008-20261008-v2'],
            ];
        }
        throw new InvalidArgumentException('Unknown dated TOP100 prep profile '.$profile);
    }

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

    /** @param array<string,mixed>|null $spec */
    public static function initDataset(string $stateRoot,string $universePath,?array $spec=null):array
    {
        $spec??=self::spec();
        $dir=PaperHistoryResearch::datasetDir($stateRoot,(string)$spec['dataset']);
        PaperHistoryResearch::assertResearchDir($dir);
        $uni=json_decode((string)file_get_contents($universePath),true,512,JSON_THROW_ON_ERROR);
        if(($uni['policy']??'')!==$spec['policy'])throw new RuntimeException('Universe policy mismatch');
        if(is_file($dir.'/dataset.json')){
            $ds=PaperHistoryResearch::readJson($dir.'/dataset.json');
            if(!is_array($ds)||is_array($ds['as_of']??null)||is_dir($dir.'/status')||is_dir($dir.'/raw'))
                throw new RuntimeException('Dataset already has a fixed universe; use a new --dataset id');
        }elseif(is_dir($dir)&&(glob($dir.'/*')?:[])!==[])throw new RuntimeException('Refusing to write into a non-empty folder without dataset.json');
        $bytes=PaperHistoryResearch::encode($uni,true);
        PaperHistoryResearch::writeFile($dir.'/universe.json',$bytes);
        $asTs=self::sessionClose((string)$spec['as_of']);
        $ds=['schema'=>1,'policy'=>$spec['policy'],'dataset'=>$spec['dataset'],'created_at'=>time(),
            'universe_sha256'=>hash('sha256',$bytes),'requested_as_of'=>$spec['as_of'],'evaluation_start_day'=>$spec['eval_start'],
            'primary_range'=>PaperHistoryResearch::EXTENDED_RANGE,
            'as_of'=>['day'=>$spec['as_of'],'close_ts'=>$asTs,'fixed_at'=>time()],
            'as_of_basis'=>$spec['as_of_basis'],
            'note'=>'Union is a collection list. New-entry candidates stay the dated TOP100. No account replay in this step.'];
        PaperHistoryResearch::writeFile($dir.'/dataset.json',PaperHistoryResearch::encode($ds,true));
        return $ds+['dir'=>$dir];
    }

    public static function datasetFingerprint(string $dir):array
    {
        $files=[];
        foreach(['dataset.json','universe.json'] as $f){
            $p=$dir.'/'.$f;
            $files[$f]=is_file($p)?hash_file('sha256',$p):null;
        }
        return ['dir'=>$dir,'files'=>$files,
            'status_files'=>count(glob($dir.'/status/*.json')?:[]),
            'bars_files'=>count(glob($dir.'/bars/*.json')?:[])];
    }

    /** @param array<string,mixed> $attempt */
    public static function yahooIdentityOk(array $rec,array $attempt):array
    {
        $parsed=$attempt['parsed']??[];
        $issues=[];
        $want=strtoupper((string)$rec['symbol']);
        $got=strtoupper((string)($parsed['symbol']??''));
        if($got==='')$issues[]='yahoo_symbol_missing';
        elseif($got!==$want)$issues[]='yahoo_symbol_mismatch';
        $ccy=strtoupper((string)($parsed['currency']??$attempt['price_basis']??''));
        if(($parsed['currency']??'')!=='KRW')$issues[]='currency_not_krw';
        $ex=strtoupper((string)($parsed['exchange']??''));
        $market=strtoupper((string)($rec['market']??''));
        $okEx=$market==='KOSPI'?self::KOSPI_EXCHANGES:($market==='KOSDAQ'?self::KOSDAQ_EXCHANGES:[]);
        if($ex===''||!in_array($ex,$okEx,true))$issues[]='exchange_mismatch';
        $n=(int)($parsed['bar_count']??0);
        return ['ok'=>$issues===[],'issues'=>$issues,'yahoo_symbol'=>$parsed['symbol']??null,'exchange'=>$parsed['exchange']??null,
            'currency'=>$parsed['currency']??null,'one_year_bars'=>$n,
            'one_year_covers_min_60'=>$n>=PaperHistoryResearch::MIN_BARS,
            'one_year_covers_prep_240'=>$n>=PaperHistoryResearch::PREP_BARS,
            'one_year_covers_eval_prep'=>false,
            'need_5y'=>true];
    }

    /** @param array<string,mixed>|null $spec */
    public static function universeV2FromReview(array $uni,array $review,?array $spec=null):array
    {
        $spec??=self::spec();
        if(($uni['policy']??'')!==$spec['policy'])throw new RuntimeException('v2 universe must start from v1 policy');
        $bySym=[];
        foreach($review['symbols']??[] as $s){
            if(($s['kind']??'')!=='unmapped'||($s['outcome']??'')!=='yahoo_chart_supported_not_imported')continue;
            $chart=null;
            foreach($s['attempts']??[] as $a)if(($a['provider']??'')==='yahoo_chart_v8')$chart=$a;
            $id=self::yahooIdentityOk($s,$chart??[]);
            $bySym[(string)$s['symbol']]=$id+['name'=>$s['name']??null,'code'=>$s['code']??null];
        }
        $rows=[];$admitted=[];$rejected=[];
        foreach($uni['rows'] as $code=>$u){
            $sym=(string)$u['symbol'];
            if(($u['status']??'')==='provider_symbol_unmapped'&&isset($bySym[$sym])&&$bySym[$sym]['ok']){
                $id=$bySym[$sym];
                $u['status']='included';
                $u['hold_reason']=null;
                $u['admitted_from']='yahoo_chart_identity_ok_not_six_digit';
                $u['yahoo_identity']=$id;
                $u['one_year_probe_insufficient_for_prep']=true;
                $admitted[]=$sym;
            }elseif(($u['status']??'')==='provider_symbol_unmapped'){
                $u['hold_reason']=$u['hold_reason']??'provider_symbol_unmapped';
                $u['yahoo_identity']=$bySym[$sym]??['ok'=>false,'issues'=>['not_in_supported_probe']];
                $rejected[]=$sym;
            }
            $rows[$code]=$u;
        }
        $included=0;$unmapped=0;
        foreach($rows as $u){
            if(($u['status']??'')==='included')$included++;
            if(($u['status']??'')==='provider_symbol_unmapped')$unmapped++;
        }
        $out=$uni;
        $out['policy']=$spec['policy_v2'];
        $out['note']='v2 admits alphanumeric Yahoo chart listings that passed identity checks. v1 universe and dataset are unchanged.';
        $out['counts']['included']=$included;
        $out['counts']['provider_symbol_unmapped']=$unmapped;
        $out['counts']['v2_admitted']=$admitted;
        $out['counts']['v2_still_unmapped']=$rejected;
        $out['rows']=$rows;
        return $out;
    }

    /** @param array<string,mixed>|null $spec */
    public static function initDatasetV2(string $stateRoot,string $universePath,?array $spec=null):array
    {
        $spec??=self::spec();
        $dir=PaperHistoryResearch::datasetDir($stateRoot,(string)$spec['dataset_v2']);
        PaperHistoryResearch::assertResearchDir($dir);
        $v1=PaperHistoryResearch::datasetDir($stateRoot,(string)$spec['dataset']);
        if(rtrim(str_replace('\\','/',$dir),'/')===rtrim(str_replace('\\','/',$v1),'/'))
            throw new RuntimeException('v2 dataset id must not be the v1 folder');
        $uni=json_decode((string)file_get_contents($universePath),true,512,JSON_THROW_ON_ERROR);
        if(($uni['policy']??'')!==$spec['policy_v2'])throw new RuntimeException('Universe policy mismatch for v2');
        if(is_file($dir.'/dataset.json')){
            $ds=PaperHistoryResearch::readJson($dir.'/dataset.json');
            if(!is_array($ds)||is_array($ds['as_of']??null)||is_dir($dir.'/status')||is_dir($dir.'/raw'))
                throw new RuntimeException('Dataset already has a fixed universe; use a new --dataset id');
        }elseif(is_dir($dir)&&(glob($dir.'/*')?:[])!==[])throw new RuntimeException('Refusing to write into a non-empty folder without dataset.json');
        $bytes=PaperHistoryResearch::encode($uni,true);
        PaperHistoryResearch::writeFile($dir.'/universe.json',$bytes);
        $asTs=self::sessionClose((string)$spec['as_of']);
        $ds=['schema'=>1,'policy'=>$spec['policy_v2'],'dataset'=>$spec['dataset_v2'],'created_at'=>time(),
            'universe_sha256'=>hash('sha256',$bytes),'requested_as_of'=>$spec['as_of'],'evaluation_start_day'=>$spec['eval_start'],
            'primary_range'=>PaperHistoryResearch::EXTENDED_RANGE,
            'as_of'=>['day'=>$spec['as_of'],'close_ts'=>$asTs,'fixed_at'=>time()],
            'as_of_basis'=>$spec['as_of_basis'],
            'source_v1_dataset'=>$spec['dataset'],
            'note'=>'v2 reuses v1 bars after hash check and collects only identity-ok alphanumeric listings. No account replay.'];
        PaperHistoryResearch::writeFile($dir.'/dataset.json',PaperHistoryResearch::encode($ds,true));
        return $ds+['dir'=>$dir];
    }

    /** Byte-copy intact bars from v1. Never writes the source. */
    public static function copyVerifiedBars(string $dest,string $srcDir,array $u):string
    {
        $symbol=(string)$u['symbol'];
        $st=PaperHistoryResearch::readStatus($dest,$symbol);
        if($st&&($st['state']??'')==='done'){
            $path=$dest.'/'.($st['bars_path']??'');
            if(is_file($path)&&hash_equals((string)$st['bars_sha256'],(string)hash_file('sha256',$path)))return 'reused';
        }
        $src=PaperHistoryResearch::readStatus($srcDir,$symbol);
        if(!$src||($src['state']??'')!=='done')return ($src['state']??'')==='failed'?'source_failed':'absent';
        $srcBars=$srcDir.'/'.($src['bars_path']??'');
        if(!is_file($srcBars))return 'absent';
        $bytes=(string)file_get_contents($srcBars);
        if(!hash_equals((string)$src['bars_sha256'],hash('sha256',$bytes)))return 'source_hash_mismatch';
        foreach($src['requests']??[] as $rq){
            $raw=$srcDir.'/'.($rq['raw_path']??'');
            if(!is_file($raw))return 'absent';
            $destRaw=$dest.'/'.$rq['raw_path'];
            if(!is_file($destRaw))PaperHistoryResearch::writeFile($destRaw,(string)file_get_contents($raw));
            $idxSrc=$srcDir.'/raw/yahoo/index/'.$symbol.'.'.($rq['range']??'').'.json';
            $idxDst=$dest.'/raw/yahoo/index/'.$symbol.'.'.($rq['range']??'').'.json';
            if(is_file($idxSrc)&&!is_file($idxDst))PaperHistoryResearch::writeFile($idxDst,(string)file_get_contents($idxSrc));
        }
        PaperHistoryResearch::writeFile($dest.'/bars/'.$symbol.'.json',$bytes);
        $out=$src;
        $out['imported_from']=basename($srcDir);
        $out['bars_path']='bars/'.$symbol.'.json';
        $out['bars_sha256']=hash('sha256',$bytes);
        PaperHistoryResearch::writeFile($dest.'/status/'.$symbol.'.json',PaperHistoryResearch::encode($out,true));
        return 'copied';
    }

    /**
     * Offline: admitted codes are research-only, and stored sector buckets still match the 31-name collection.
     * Does not fetch.
     */
    public static function verifyAdmittedOffline(array $admittedMap,array $admittedCollection):array
    {
        $issues=[];$n=0;
        foreach($admittedMap['symbols']??[] as $sym){
            $n++;
            $sym=(string)$sym;
            if(NaverDailyQuotes::codeOf($sym)!==null)$issues[]=$sym.': operational codeOf accepted an alphanumeric listing';
            $code=PaperHistoryResearch::krCode($sym);
            if($code===null)$issues[]=$sym.': research krCode rejected the listing';
            $mapB=$admittedMap['sectors'][$sym]??null;
            $colB=$admittedCollection['symbols'][$sym]['bucket']??null;
            if($mapB===null||$colB===null||$mapB!==$colB)$issues[]=$sym.': sector bucket mismatch';
        }
        return ['pass'=>$issues===[],'issues'=>$issues,'symbols'=>$n,'recollected'=>false];
    }

    /**
     * Merge the 895-name collection with the 31-name map onto the candidate union.
     * Does not rewrite the source files. Conflicts are refused, not overwritten.
     *
     * @param list<string> $union
     */
    /** @param array<string,mixed>|null $spec */
    public static function mergeUnionSectorMap(array $union,array $baseCollection,array $admittedMap,array $sourceMeta=[],?array $spec=null):array
    {
        $spec??=self::spec();
        $want=array_values(array_unique($union));
        sort($want);
        if($want===[])throw new InvalidArgumentException('Candidate union is empty');
        $baseRows=$baseCollection['symbols']??[];
        $admit=$admittedMap['sectors']??[];
        if(!is_array($baseRows)||!is_array($admit))throw new InvalidArgumentException('Sector sources need symbols/sectors');
        $extraAdmit=array_values(array_diff(array_keys($admit),$want));
        if($extraAdmit!==[])throw new RuntimeException('Admitted map has symbols outside the candidate union: '.implode(',',$extraAdmit));
        $conflicts=[];$sectors=[];$unconfirmed=[];$fromBase=0;$fromAdmit=0;
        foreach($want as $sym){
            $base=$baseRows[$sym]??null;
            $baseBucket=is_array($base)&&($base['status']??'')==='secured'?(string)($base['bucket']??''):'';
            $baseOk=$baseBucket!==''&&$baseBucket!==PaperSectorFreeze::UNCLASSIFIED;
            $admitBucket=isset($admit[$sym])?(string)$admit[$sym]:'';
            $admitOk=$admitBucket!==''&&$admitBucket!==PaperSectorFreeze::UNCLASSIFIED;
            if($baseOk&&$admitOk&&$baseBucket!==$admitBucket){
                $conflicts[]=['symbol'=>$sym,'base'=>$baseBucket,'admitted'=>$admitBucket];
                continue;
            }
            if($baseOk){$sectors[$sym]=$baseBucket;$fromBase++;}
            elseif($admitOk){$sectors[$sym]=$admitBucket;$fromAdmit++;}
            else{
                $sectors[$sym]=PaperSectorFreeze::UNCLASSIFIED;
                $unconfirmed[]=['symbol'=>$sym,'reason'=>is_array($base)?($base['reason']??'unconfirmed'):'missing_from_sources'];
            }
        }
        if($conflicts!==[])throw new RuntimeException('Sector merge conflicts were not overwritten: '.json_encode($conflicts,JSON_UNESCAPED_UNICODE));
        ksort($sectors);
        $buckets=[];foreach($sectors as $b)$buckets[$b]=($buckets[$b]??0)+1;ksort($buckets);
        $out=[
            'schema'=>1,'kind'=>PaperSectorFreeze::KIND_V2,
            'assumption'=>'현재 네이버 모바일 업종 JSON을 과거에 같이 적용한다. 당시 업종을 복원한 것이 아니다.',
            'unclassified_bucket'=>PaperSectorFreeze::UNCLASSIFIED,'unclassified_is_one_shared_bucket'=>true,
            'rule'=>'ChartEntryLab\\SectorMap::bucketOf',
            'dataset'=>$spec['dataset_v2'],
            'execution_path'=>$spec['sector_map_full'],
            'sources_not_overwritten'=>true,'account_replay'=>false,
            'sources'=>$sourceMeta,
            'counts'=>['symbols'=>count($sectors),'secured'=>$fromBase+$fromAdmit,'from_base'=>$fromBase,'from_admitted'=>$fromAdmit,
                'unconfirmed'=>count($unconfirmed)],
            'bucket_counts'=>$buckets,
            'sectors'=>$sectors,'unconfirmed'=>$unconfirmed,
        ];
        PaperSectorFreeze::assertCoversUnion($want,$out);
        return $out;
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
        $tagKeys=['unmapped','no_bars','insufficient_history','missing_session','other_blocked','price_jump_warning'];
        $occ=array_fill_keys($tagKeys,0);
        $sym=array_fill_keys($tagKeys,[]);
        $sess=array_fill_keys($tagKeys,[]);
        $part=['analyzable'=>0,'blocked'=>0];
        $partSym=['analyzable'=>[],'blocked'=>[]];
        $byDay=[];$firstTop=[];$lastTop=[];$inTop=[];$memberOf=[];
        foreach($candidates['by_session'] as $ts=>$rec){
            $byDay[$rec['day']]=['day'=>$rec['day'],'top100'=>count($rec['rows']),
                'analyzable'=>0,'blocked'=>0]+array_fill_keys($tagKeys,0);
            foreach($rec['rows'] as $row){
                $symbol=(string)$row['symbol'];
                if(!isset($firstTop[$symbol]))$firstTop[$symbol]=$rec['day'];
                $lastTop[$symbol]=$rec['day'];
                $inTop[$symbol][$rec['day']]=true;
                $memberOf[$symbol][]=(int)$ts;
            }
        }
        $gap=['intermediate'=>['checked'=>0,'missing'=>0,'symbols'=>[],'sessions'=>[]],
            'final'=>['checked'=>0,'missing'=>0,'symbols'=>[],'sessions'=>[]]];
        $earlierNotWiped=0;$laterJumpSessions=0;$sumFail=0;
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
                $blocked=!empty($class['engine_blocked']);
                $side=$blocked?'blocked':'analyzable';
                $part[$side]++;$byDay[$day][$side]++;$partSym[$side][$symbol]=true;
                $tags=$class['tags'];
                if($jumpDay!==null&&$day>=$jumpDay&&!in_array('unmapped',$tags,true)&&!in_array('no_bars',$tags,true))
                    $tags[]='price_jump_warning';
                foreach(array_values(array_unique($tags)) as $tag){
                    if(!isset($occ[$tag]))continue;
                    $occ[$tag]++;$byDay[$day][$tag]++;$sym[$tag][$symbol]=true;$sess[$tag][$day]=true;
                }
            }
            if(($st['status']??'')!=='provider_symbol_unmapped'&&isset($firstTop[$symbol])){
                foreach($candidates['by_session'] as $ts=>$rec){
                    $day=$rec['day'];
                    if($day<$firstTop[$symbol])continue;
                    if(!empty($inTop[$symbol][$day]))continue;
                    $kind=$day<$lastTop[$symbol]?'intermediate':'final';
                    $gap[$kind]['checked']++;
                    if(($st['state']??'')!=='done'||empty($have[$ts])){
                        $gap[$kind]['missing']++;
                        $gap[$kind]['symbols'][$symbol]=true;
                        $gap[$kind]['sessions'][$day]=true;
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
        foreach($byDay as $d){
            if(($d['analyzable']+$d['blocked'])!==$d['top100'])$sumFail++;
        }
        $uniq=static fn(array $v):int=>count($v);
        $gapOut=static function(array $g)use($uniq):array{
            return ['checked'=>$g['checked'],'potential_missing_bars'=>$g['missing'],
                'unique_symbols'=>$uniq($g['symbols']),'unique_sessions'=>$uniq($g['sessions'])];
        };
        $total=$part['analyzable']+$part['blocked'];
        return [
            'schema'=>2,'kind'=>'dated_top100_session_coverage_v2','label'=>self::LABEL,
            'eval_start'=>$evalStart,'account_replay'=>false,
            'note'=>'Each TOP100 name-session is analyzable or blocked exactly once. Jump is a warning, not a PaperQuality block. Dropout gaps are potential holding/pending bars, not account halts.',
            'day_count'=>count($byDay),'slot_count'=>$total,
            'partition'=>[
                'analyzable'=>$part['analyzable'],
                'blocked'=>$part['blocked'],
                'sum'=>$total,
                'expected'=>array_sum(array_map(fn($d)=>$d['top100'],$byDay)),
                'sum_matches'=>$total===array_sum(array_map(fn($d)=>$d['top100'],$byDay))&&$sumFail===0,
                'unique_symbols'=>['analyzable'=>$uniq($partSym['analyzable']),'blocked'=>$uniq($partSym['blocked'])],
            ],
            'days'=>array_values($byDay),
            'blocked_tags'=>$occ,
            'unique_symbols'=>array_map($uniq,$sym),
            'unique_sessions'=>array_map($uniq,$sess),
            'price_jump_is_not_paper_quality_block'=>true,
            'potential_holding_or_pending_bar_gaps'=>[
                'label'=>'관리용 봉의 잠재 누락',
                'not_account_halt_count'=>true,
                'intermediate_dropout'=>$gapOut($gap['intermediate']),
                'final_dropout'=>$gapOut($gap['final']),
            ],
            'later_jump_does_not_wipe_earlier'=>[
                'symbols_with_later_jump_and_earlier_clear'=>$earlierNotWiped,
                'sessions_tagged_price_jump_warning'=>$laterJumpSessions,
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
            return ['tags'=>['unmapped'],'engine_blocked'=>true,'quality'=>null];
        }
        if(($st['state']??'')!=='done'||($st['rows']??[])===[]){
            return ['tags'=>['no_bars'],'engine_blocked'=>true,'quality'=>null];
        }
        $source=['sha256'=>$st['bars_sha256']??null,'price_basis'=>PaperHistoryResearch::PRICE_BASIS];
        $quality=PaperQuality::inspect($st['rows'],$symbol,$session,$source,[]);
        $tags=[];
        if(!$quality['can_simulate']){
            $mapped=false;
            foreach($quality['reasons']??[] as $r){
                if($r==='insufficient_history'){$tags[]='insufficient_history';$mapped=true;}
                elseif($r==='missing_session'){$tags[]='missing_session';$mapped=true;}
            }
            if(!$mapped)$tags[]='other_blocked';
        }
        return ['tags'=>array_values(array_unique($tags)),'engine_blocked'=>!$quality['can_simulate'],'quality'=>$quality];
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
    public static function yahooRequestEvidence(string $dir,?string $dataset=null):array
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
        $ds=$dataset??self::DATASET;
        $out=[
            'schema'=>1,'kind'=>'yahoo_request_evidence_v1','dataset'=>$ds,
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
            'failed'=>$failedRows,
            'no_recollect'=>true,
        ];
        if($ds===self::DATASET){
            $out['previous_report_yahoo_requests']=561;
            $out['previous_report_note']='561 counted collected symbols with a successful request record, not failed HTTP or inner retries.';
        }
        return $out;
    }
}
