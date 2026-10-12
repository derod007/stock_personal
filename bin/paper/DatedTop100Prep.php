<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/HistoryResearch.php';
require_once __DIR__.'/DatedTop100Replay.php';

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
}
