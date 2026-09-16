<?php
declare(strict_types=1);
use ChartEntryLab\PaperJournal;
final class PaperStrategyVersion
{
    public static function current(?string $root=null):string
    {
        $root??=dirname(__DIR__,2);
        $paths=json_decode(file_get_contents($root.'/config/paper-strategy-files.json'),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($paths)||!$paths)throw new RuntimeException('Invalid strategy manifest');
        sort($paths,SORT_STRING);$hashes=[];
        foreach($paths as $path){
            if(!preg_match('~^(src|bin)/[A-Za-z0-9_./-]+\.php$~',$path)||str_contains($path,'..'))throw new RuntimeException('Invalid strategy path');
            if(!is_file($root.'/'.$path))throw new RuntimeException('Strategy file missing: '.$path);
            $hashes[$path]=self::fileHash($root.'/'.$path);
        }
        return hash('sha256',PaperJournal::encode(['schema'=>1,'files'=>$hashes]));
    }
    public static function fileHash(string $path): string
    {
        $bytes=file_get_contents($path);
        if($bytes===false)throw new RuntimeException('Cannot read strategy file');
        return hash('sha256',str_replace("\r\n","\n",$bytes));
    }

    public static function compatible(string $version,string $current,?string $root=null):bool
    {
        if(hash_equals($current,$version))return true;
        $root??=dirname(__DIR__,2);
        $map=json_decode(file_get_contents($root.'/config/paper-legacy-versions.json'),true,512,JSON_THROW_ON_ERROR);
        $record=$map['versions'][$version]??null;
        return is_array($record) && isset($record['compatible_strategy']) && hash_equals($current,$record['compatible_strategy']);
    }
    public static function verify(array $state,string $current):void
    {
        if(!self::compatible($state['version'],$current))throw new RuntimeException('Pinned strategy changed or unverified legacy version; use a new account ID');
        if(isset($state['strategy_fingerprint']) && !hash_equals($current,$state['strategy_fingerprint']))throw new RuntimeException('Pinned strategy fingerprint changed');
    }
    public static function adopt(array &$state,string $current,callable $emit):void
    {
        self::verify($state,$current);
        if(isset($state['strategy_fingerprint']))return;
        if(!empty($state['halted']))throw new RuntimeException('Halted account cannot migrate');
        $state['strategy_fingerprint']=$current;
        $emit('strategy_scope_verified',['legacy_version'=>$state['version'],'strategy_fingerprint'=>$current,
            'action'=>'preserve_version_snapshots_orders_and_balances']);
    }
}
