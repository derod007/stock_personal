<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/HistoryResearch.php';
use ChartEntryLab\{NaverDailyQuotes,SectorMap};

/**
 * Freezes one sector_bucket per symbol for the historical account replay.
 *
 * Reads the operational sector cache and the saved-scan files. Does not call SectorMap::resolve,
 * which would refresh and rewrite the cache. The bucket comes from SectorMap::bucketOf, the same
 * rule the operational map uses, applied to the industry name already stored in the cache.
 * A missing or placeholder cache entry stays "unclassified" for every such symbol together.
 */
final class PaperSectorFreeze
{
    public const KIND='research_sector_map_v1';
    public const UNCLASSIFIED='unclassified';
    /** Written when the industry name was empty. Not a researched classification. */
    public const PLACEHOLDER_SECTOR='기타';
    public const ASSUMPTION='현재 운영 섹터 캐시에 저장된 업종명을 SectorMap::bucketOf로 분류해 두 기간에 같이 적용한다. 당시 업종을 복원한 것이 아니다.';

    /**
     * @param array<string,string> $datasetDirs period => dataset directory
     * @param list<string> $savedScanFiles absolute paths of the saved scan bundles
     * @return array<string,mixed>
     */
    public static function build(string $cacheDir,array $datasetDirs,array $savedScanFiles):array
    {
        $cacheDir=rtrim(str_replace('\\','/',$cacheDir),'/');
        if(!is_dir($cacheDir))throw new RuntimeException('Sector cache not found: '.$cacheDir);
        $before=self::fingerprint($cacheDir);
        $scanBefore=[];
        foreach($savedScanFiles as $f){
            if(!is_file($f))throw new RuntimeException('Saved scan not found: '.$f);
            $scanBefore[$f]=hash_file('sha256',$f);
        }
        $seen=self::savedScanSymbols($savedScanFiles);
        $ruleDir=sys_get_temp_dir().'/noramu-sector-rule-'.bin2hex(random_bytes(4));
        $rules=new SectorMap($ruleDir);
        $symbols=[];
        foreach($datasetDirs as $period=>$dir){
            foreach(self::usableSymbols($dir) as $symbol=>$name){
                if(!isset($symbols[$symbol]))$symbols[$symbol]=['name'=>$name,'periods'=>[]];
                $symbols[$symbol]['periods'][]=$period;
                if($symbols[$symbol]['name']==='')$symbols[$symbol]['name']=$name;
            }
        }
        ksort($symbols);
        $rows=[];$sectors=[];$unconfirmed=[];$secured=0;$differs=0;
        foreach($symbols as $symbol=>$info){
            sort($info['periods']);
            $code=NaverDailyQuotes::codeOf($symbol);
            $cacheFile=$code!==null?$cacheDir.'/sector_'.$code.'.json':null;
            $saved=isset($seen[$symbol]);
            $cached=null;$reason=null;
            if(!$saved)$reason='not_in_saved_scan';
            elseif($cacheFile===null||!is_file($cacheFile))$reason='cache_missing';
            else{
                $cached=json_decode((string)file_get_contents($cacheFile),true);
                if(!is_array($cached))$reason='cache_unreadable';
                else{
                    $industry=trim((string)($cached['sector']??''));
                    if($industry===''||$industry===self::PLACEHOLDER_SECTOR)$reason='cache_placeholder';
                }
            }
            $row=['symbol'=>$symbol,'name'=>$info['name'],'periods'=>$info['periods'],'saved_scan_seen'=>$saved];
            if($reason!==null){
                $row+=['status'=>'unconfirmed','bucket'=>self::UNCLASSIFIED,'reason'=>$reason,
                    'source'=>$cacheFile!==null&&is_file($cacheFile)?self::rel($cacheFile):null];
                $unconfirmed[]=['symbol'=>$symbol,'reason'=>$reason,'name'=>$info['name']];
            }else{
                $industry=trim((string)$cached['sector']);
                $stockName=trim((string)($cached['name']??''));
                $bucket=$rules->bucketOf($industry,$stockName);
                $stored=(string)($cached['sector_bucket']??'');
                $row+=['status'=>'secured','bucket'=>$bucket,'sector_name'=>$industry,
                    'sector_label'=>SectorMap::BUCKETS[$bucket]??$bucket,
                    'stored_bucket'=>$stored!==''?$stored:null,
                    'bucket_rule_differs'=>$stored!==''&&$stored!==$bucket,
                    'source'=>self::rel($cacheFile),'source_sha256'=>hash_file('sha256',$cacheFile),
                    'source_mtime_kst'=>(new DateTimeImmutable('@'.filemtime($cacheFile)))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d'),
                    'rule'=>'SectorMap::bucketOf'];
                if($row['bucket_rule_differs'])$differs++;
                $secured++;
            }
            $sectors[$symbol]=$row['bucket'];
            $rows[$symbol]=$row;
        }
        $buckets=[];foreach($sectors as $b)$buckets[$b]=($buckets[$b]??0)+1;ksort($buckets);
        $reasons=[];foreach($unconfirmed as $u)$reasons[$u['reason']]=($reasons[$u['reason']]??0)+1;ksort($reasons);
        if(self::fingerprint($cacheDir)!==$before)throw new RuntimeException('Sector cache changed while it was being read');
        foreach($scanBefore as $f=>$h)if(!hash_equals($h,(string)hash_file('sha256',$f)))throw new RuntimeException('Saved scan changed while it was being read');
        if(is_dir($ruleDir)&&str_contains($ruleDir,'noramu-sector-rule-'))@rmdir($ruleDir);
        $out=['schema'=>1,'kind'=>self::KIND,'assumption'=>self::ASSUMPTION,
            'unclassified_bucket'=>self::UNCLASSIFIED,'unclassified_is_one_shared_bucket'=>true,
            'rule'=>'ChartEntryLab\\SectorMap::bucketOf','cache_dir'=>self::rel($cacheDir),
            'saved_scan_files'=>array_map(fn($f)=>['path'=>self::rel($f),'sha256'=>$scanBefore[$f]],$savedScanFiles),
            'counts'=>['symbols'=>count($symbols),'secured'=>$secured,'unconfirmed'=>count($unconfirmed),'bucket_rule_differs'=>$differs],
            'bucket_counts'=>$buckets,'unconfirmed_reasons'=>$reasons,
            'sectors'=>$sectors,'symbols'=>$rows,'unconfirmed'=>$unconfirmed];
        self::assertReplayMap($out);
        return $out;
    }

    /**
     * Read-only count of real industry names. A placeholder ("기타" or empty) is not a classification.
     * Does not construct SectorMap against the cache, so nothing is refreshed or rewritten.
     *
     * @param list<string> $symbols
     * @return array<string,mixed>
     */
    public static function survey(string $cacheDir,array $symbols):array
    {
        $cacheDir=rtrim(str_replace('\\','/',$cacheDir),'/');
        if(!is_dir($cacheDir))throw new RuntimeException('Sector cache not found: '.$cacheDir);
        $before=self::fingerprint($cacheDir);
        $counts=['secured'=>0,'placeholder'=>0,'missing'=>0,'unreadable'=>0];
        $rows=[];
        foreach($symbols as $symbol){
            $code=NaverDailyQuotes::codeOf($symbol);
            $file=$code!==null?$cacheDir.'/sector_'.$code.'.json':null;
            $entry=self::readCacheEntry($file);
            $counts[$entry['class']]++;
            $row=['class'=>$entry['class']];
            if($entry['class']==='secured'){
                $row['sector_name']=$entry['sector_name'];
                $row['stored_bucket']=$entry['stored_bucket'];
                $row['sha256']=hash_file('sha256',$entry['file']);
            }
            $rows[$symbol]=$row;
        }
        if(self::fingerprint($cacheDir)!==$before)throw new RuntimeException('Sector cache changed while it was being read');
        return ['dir'=>self::rel($cacheDir),'files'=>count(glob($cacheDir.'/sector_*.json')?:[]),'fingerprint'=>$before,
            'counts'=>$counts,'symbols'=>$rows];
    }

    /**
     * Scanner path wins. The other path fills only a placeholder or a missing file, and a real-name disagreement is listed.
     *
     * @param array<string,mixed> $primary
     * @param array<string,mixed> $supplement
     * @return array{supplement_used:list<string>,conflicts:list<array<string,string>>}
     */
    public static function compareSurveys(array $primary,array $supplement):array
    {
        $used=[];$conflicts=[];
        foreach($primary['symbols'] as $symbol=>$p){
            $s=$supplement['symbols'][$symbol]??['class'=>'missing'];
            $pReal=($p['class']??'')==='secured';
            $sReal=($s['class']??'')==='secured';
            if($pReal&&$sReal&&($p['sector_name']??'')!==($s['sector_name']??'')){
                $conflicts[]=['symbol'=>$symbol,'primary_sector'=>$p['sector_name'],'supplement_sector'=>$s['sector_name'],
                    'primary_bucket'=>$p['stored_bucket']??null,'supplement_bucket'=>$s['stored_bucket']??null];
            }elseif(!$pReal&&$sReal){
                $used[]=$symbol;
            }
        }
        sort($used);
        return ['supplement_used'=>$used,'conflicts'=>$conflicts];
    }

    /** @return array{class:string,sector_name?:string,stored_bucket?:string,file?:string} */
    public static function readCacheEntry(?string $file):array
    {
        if($file===null||!is_file($file))return ['class'=>'missing'];
        $cached=json_decode((string)file_get_contents($file),true);
        if(!is_array($cached))return ['class'=>'unreadable'];
        $industry=trim((string)($cached['sector']??''));
        if($industry===''||$industry===self::PLACEHOLDER_SECTOR)return ['class'=>'placeholder'];
        return ['class'=>'secured','sector_name'=>$industry,'stored_bucket'=>(string)($cached['sector_bucket']??''),'file'=>$file];
    }

    /** @param array<string,mixed> $map */
    public static function assertReplayMap(array $map):void
    {
        if(($map['kind']??'')!==self::KIND)throw new InvalidArgumentException('Not a research sector map');
        if(($map['unclassified_bucket']??'')!==self::UNCLASSIFIED||empty($map['unclassified_is_one_shared_bucket']))
            throw new InvalidArgumentException('Unclassified symbols must share one bucket');
        if(!str_contains((string)($map['assumption']??''),'당시 업종을 복원한 것이 아니다'))
            throw new InvalidArgumentException('The map must say it is not a restored historical classification');
        $sectors=$map['sectors']??null;
        if(!is_array($sectors))throw new InvalidArgumentException('Sector map needs sectors');
        $allowed=array_keys(SectorMap::BUCKETS);$allowed[]=self::UNCLASSIFIED;
        $unclassified=[];
        foreach($sectors as $symbol=>$bucket){
            if(!is_string($symbol)||!is_string($bucket)||!in_array($bucket,$allowed,true))
                throw new InvalidArgumentException('Unexpected sector bucket for '.$symbol);
            if(str_contains($bucket,$symbol))throw new InvalidArgumentException('A bucket must not be named after one symbol');
            if($bucket===self::UNCLASSIFIED)$unclassified[$symbol]=true;
        }
        foreach($map['unconfirmed']??[] as $u){
            $symbol=(string)($u['symbol']??'');
            if(($sectors[$symbol]??null)!==self::UNCLASSIFIED)
                throw new InvalidArgumentException('An unconfirmed symbol was given its own sector: '.$symbol);
        }
        if(count(array_unique(array_intersect_key($sectors,$unclassified)))>1)
            throw new InvalidArgumentException('Unclassified symbols do not share one bucket');
    }

    /** @return array<string,true> */
    private static function savedScanSymbols(array $files):array
    {
        $seen=[];
        foreach($files as $f){
            $doc=json_decode((string)file_get_contents($f),true);
            if(!is_array($doc['records']??null))throw new RuntimeException('Saved scan has no records: '.$f);
            foreach($doc['records'] as $r){
                $symbol=(string)($r['symbol']??'');
                if(preg_match('/^\d{6}\.(KS|KQ)$/',$symbol))$seen[$symbol]=true;
            }
        }
        return $seen;
    }

    /** @return array<string,string> symbol => name */
    public static function usableSymbols(string $dir):array
    {
        $out=[];
        foreach(glob(rtrim($dir,'/\\').'/status/*.json')?:[] as $f){
            $st=json_decode((string)file_get_contents($f),true);
            if(!is_array($st)||($st['state']??'')!=='done')continue;
            $quality=$st['quality']['status']??'hold';
            if($quality==='hold')continue;
            $symbol=basename($f,'.json');
            if(!preg_match('/^\d{6}\.(KS|KQ)$/',$symbol))continue;
            $out[$symbol]=(string)($st['name']??'');
        }
        ksort($out);
        return $out;
    }

    /** Saved-scan paths cited by a dataset universe, checked against the recorded hashes. @return list<string> */
    public static function savedScanPaths(string $root,string $datasetDir):array
    {
        $uni=PaperHistoryResearch::readJson(rtrim($datasetDir,'/\\').'/universe.json')??throw new RuntimeException('universe.json missing');
        $out=[];
        foreach($uni['sources']??[] as $src){
            $path=rtrim(str_replace('\\','/',$root),'/').'/'.$src['dir'].'/'.$src['file'];
            if(!is_file($path))throw new RuntimeException('Saved scan not found: '.$path);
            $hash=hash_file('sha256',$path);
            if(!hash_equals((string)$src['sha256'],(string)$hash))throw new RuntimeException('Saved scan hash differs: '.$src['file']);
            $out[]=$path;
        }
        if($out===[])throw new RuntimeException('Universe cites no saved scans');
        return $out;
    }

    /** Names, sizes, mtimes and hashes. A change means something wrote the cache. */
    public static function fingerprint(string $dir):string
    {
        $items=[];
        foreach(glob(rtrim($dir,'/\\').'/sector_*.json')?:[] as $f){
            $items[basename($f)]=filesize($f).':'.filemtime($f).':'.hash_file('sha256',$f);
        }
        ksort($items);
        return hash('sha256',json_encode($items));
    }

    private static function rel(string $path):string
    {
        $root=str_replace('\\','/',dirname(__DIR__,2));
        $path=str_replace('\\','/',$path);
        return str_starts_with($path,$root.'/')?substr($path,strlen($root)+1):$path;
    }
}
