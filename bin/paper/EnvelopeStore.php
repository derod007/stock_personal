<?php
declare(strict_types=1);
require_once __DIR__.'/EnvelopeResearch.php';
require_once __DIR__.'/JsonRows.php';

/** Storage-only adapter. Does not change the frozen research execution fingerprint. */
final class PaperEnvelopeStore
{
    private static function hash(array $value):string{return hash('sha256',PaperRrAudit::encode($value));}
    private static function path(string $folder,string $hash):string
    {
        if(!preg_match('/^[a-f0-9]{64}$/',$hash))throw new RuntimeException('Invalid frozen reference');
        return $folder.'/frozen/'.$hash.'.json';
    }
    public static function compact(string $folder,array $row,bool $persist=true):array
    {
        if(isset($row['frozen_ref'])){
            $expected=$row['view_hash']??'';$view=$row;unset($view['view_hash']);
            if(!hash_equals($expected,self::hash($view)))throw new RuntimeException('Compact ledger checksum mismatch');
            if($row['frozen_ref']!==$row['frozen_hash'])throw new RuntimeException('Frozen reference mismatch');
            self::path($folder,$row['frozen_ref']);return $row;
        }
        $f=$row['frozen'];$hash=self::hash($f);
        if(!hash_equals($row['frozen_hash']??'',$hash))throw new RuntimeException('Frozen ledger integrity mismatch');
        if($persist){
            $path=self::path($folder,$hash);$dir=dirname($path);
            if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Cannot create frozen directory');
            if(is_file($path)){
                if(!hash_equals($hash,hash_file('sha256',$path)))throw new RuntimeException('Frozen snapshot checksum mismatch');
            }else{
                $bytes=PaperRrAudit::encode($f);$tmp=tempnam($dir,'write-');
                try{if(file_put_contents($tmp,$bytes)!==strlen($bytes)||!rename($tmp,$path))throw new RuntimeException('Cannot save frozen snapshot');}
                finally{if(is_file($tmp))unlink($tmp);}
            }
        }
        // Retain only what the dashboard/grouping needs. The complete original remains hash-addressed.
        $row['frozen']['record']=array_intersect_key($f['record'],array_flip(['symbol','name','session','captured_at','source_file','observation_hash']));
        unset($row['frozen']['features']['quality']);
        $row['frozen_ref']=$hash;$row['view_hash']=self::hash($row);return $row;
    }
    public static function hydrate(string $folder,array $row):array
    {
        $row=self::compact($folder,$row);$path=self::path($folder,$row['frozen_ref']);
        if(!is_file($path)||!hash_equals($row['frozen_hash'],hash_file('sha256',$path)))throw new RuntimeException('Frozen snapshot missing or corrupt');
        $row['frozen']=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        unset($row['frozen_ref'],$row['view_hash']);return $row;
    }
    public static function load(string $state,string $id,bool $migrate=false):?array
    {
        if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account');
        $folder=$state.'/envelope-research/'.$id;$path=$folder.'/latest.json';if(!is_file($path))return null;
        $rows=[];$stream=PaperJsonRows::read($path);
        foreach($stream as $key=>$row){
            $compact=self::compact($folder,$row,$migrate);
            if($key!==$compact['frozen']['record']['symbol'].'@'.$compact['frozen']['record']['session'])throw new RuntimeException('Frozen row key mismatch');
            $rows[$key]=$compact;unset($row,$compact);
        }
        $report=$stream->getReturn();
        if(($report['kind']??null)!==PaperEnvelopeResearch::KIND||($report['account']??null)!==$id)throw new RuntimeException('Invalid envelope ledger');
        $report['rows']=$rows;$report['storage_format']='frozen_files_v1';return $report;
    }
    public static function register(array $report,string $folder,string $source,int $now,string $execution):array
    {
        $files=PaperRrView::files($source);sort($files,SORT_STRING);$seen=[];
        $report['duplicates']=0;$report['unavailable_observations']=0;
        foreach($files as $file){
            try{
                // At most one TOP100 bundle is resident; no all-days observations array.
                $bundle=PaperRrView::load($source.'/'.$file);
                if(($bundle['membership']??'')!=='observed_scan_only')continue;
                foreach($bundle['records'] as $r){
                    if(($r['status']??'')!=='evaluated'){$report['unavailable_observations']++;continue;}
                    if(!preg_match('/^[A-Za-z0-9.^=_-]{1,40}$/',$r['symbol']??'')||!isset($r['session'],$r['bars'],$r['input_hash']))throw new RuntimeException('Invalid observation');
                    $key=$r['symbol'].'@'.$r['session'];if(isset($seen[$key])){$report['duplicates']++;continue;}$seen[$key]=true;
                    $r['source_file']=$file;$r['captured_at']=(int)($bundle['recorded_at']??$r['session']);$r['observation_hash']=self::hash($r);
                    if($r['session']>$now||$r['captured_at']>$now)continue;
                    if(isset($report['rows'][$key])){
                        if($report['rows'][$key]['frozen']['record']['observation_hash']!==$r['observation_hash'])$report['errors'][]=['symbol'=>$r['symbol'],'error'=>'changed_observation_preserved_original'];
                        continue;
                    }
                    $one=PaperEnvelopeResearch::register(['rows'=>[],'errors'=>[],'added'=>0],['records'=>[$key=>$r],'errors'=>[]],$now,$execution);
                    foreach($one['errors'] as $err)$report['errors'][]=$err;
                    if(isset($one['rows'][$key])){$report['rows'][$key]=self::compact($folder,$one['rows'][$key]);$report['added']++;}
                    unset($one,$r);
                }
                unset($bundle);
            }catch(Throwable $e){$report['errors'][]=['file'=>$file,'error'=>$e->getMessage()];}
        }return $report;
    }
    public static function refresh(string $folder,array $row,callable $provider,string $execution,int $now):array
    {
        try{
            // Unavailable and completed observations do not need their large source loaded.
            if($row['status']==='complete'||$row['frozen']['features']['status']!=='evaluated')return self::compact($folder,$row);
            $full=self::hydrate($folder,$row);
            return self::compact($folder,PaperEnvelopeResearch::refresh($full,$provider,$execution,$now));
        }catch(Throwable $e){
            unset($row['view_hash']);$row['status']='update_error';$row['error']=$e->getMessage();$row['view_hash']=self::hash($row);return $row;
        }
    }
    public static function savedIndex(string $path):array
    {
        if(!is_file($path))return [];$out=[];$stream=PaperJsonRows::read($path);
        foreach($stream as $r)$out[$r['observation_hash']]=array_intersect_key($r,array_flip(['observation_hash','source_file','symbol','session','price_hash','as_of']));
        return $out;
    }
    public static function save(string $folder,array $report):void
    {
        $tmp=tempnam($folder,'write-');$h=fopen($tmp,'wb');if(!$h)throw new RuntimeException('Cannot create report');
        $write=function(string $bytes)use($h):void{if(fwrite($h,$bytes)!==strlen($bytes))throw new RuntimeException('Incomplete report write');};
        try{
            $write('{');$first=true;
            foreach($report as $key=>$value){
                if(!$first)$write(',');$first=false;$write(json_encode($key,JSON_THROW_ON_ERROR).':');
                if($key!=='rows'){$write(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));continue;}
                $write('{');$firstRow=true;
                foreach($value as $rowKey=>$row){if(!$firstRow)$write(',');$firstRow=false;$write(json_encode($rowKey,JSON_THROW_ON_ERROR).':'.PaperRrAudit::encode($row));}
                $write('}');
            }
            $write("}\n");if(!fflush($h))throw new RuntimeException('Cannot flush report');fclose($h);$h=null;
            $history=$folder.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
            if(!copy($tmp,$history)||!rename($tmp,$folder.'/latest.json'))throw new RuntimeException('Cannot publish report');
        }finally{if(is_resource($h))fclose($h);if(is_file($tmp))unlink($tmp);}
    }
}
