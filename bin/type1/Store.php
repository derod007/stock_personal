<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;
final class Store
{
    public static function folder(): string {return (getenv('PAPER_STATE_DIR')?:dirname(__DIR__,3).'/../stock-personal-paper').'/type1-observations';}
    public static function load(string $folder): array {return is_file($folder.'/latest.json')?json_decode(file_get_contents($folder.'/latest.json'),true,512,JSON_THROW_ON_ERROR):Tracker::empty();}
    public static function atomic(string $path,array $data): void
    {
        $bytes=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$tmp=tempnam(dirname($path),'write-');
        try{if(file_put_contents($tmp,$bytes)!==strlen($bytes)||!rename($tmp,$path))throw new \RuntimeException('Tracker write failed');}finally{if(is_file($tmp))unlink($tmp);}
    }
    public static function update(string $folder,callable $collect,Classifier $classifier): array
    {
        if(!is_dir($folder)&&!mkdir($folder,0770,true)&&!is_dir($folder))throw new \RuntimeException('Tracker directory unavailable');
        $lock=fopen($folder.'/update.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('Tracker already running');
        try{
            $state=self::load($folder);$files=glob($folder.'/snapshot-*.json');sort($files);
            foreach($files as $f){$day=substr(basename($f),9,10);if($state['last_day']!==null&&$day<=$state['last_day'])continue;
                $s=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR);$state=Tracker::advance($state,$s,$classifier);self::atomic($folder.'/latest.json',$state);}
            $snapshot=$collect($state);if($snapshot===null)return ['status'=>'skipped','day'=>$state['last_day']];
            if($snapshot['day']===$state['last_day'])return ['status'=>'unchanged','day'=>$state['last_day']];
            $next=Tracker::advance($state,$snapshot,$classifier);$path=$folder.'/snapshot-'.$snapshot['day'].'.json';
            if(file_exists($path))throw new \RuntimeException('Snapshot already exists');
            self::atomic($path,$snapshot);self::atomic($folder.'/latest.json',$next);
            return ['status'=>'saved','day'=>$next['last_day'],'trades'=>count($next['trades']),'classification_errors'=>count(array_filter($next['captures'][$next['last_day']]['rows'],static fn($r)=>$r['error']!==null)),'unknown'=>count(array_filter($next['trades'],static fn($t)=>$t['status']==='unknown'))];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
