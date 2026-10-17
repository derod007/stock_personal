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
    /** A declared code release, not a general legacy compatibility exemption. Replay stays pinned. */
    public static function forwardUpgrade(array $state,string $current):bool
    {
        if (($state['mode']??null)!=='forward' || !empty($state['halted'])) return false;
        $from=$state['version']??'';
        if ($from===$current || ($state['strategy_fingerprint']??$from)!==$from) return false;
        $path=dirname(__DIR__,2).'/config/paper-forward-upgrades.json';
        if (!is_file($path)) return false;
        $map=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        $release=$map['releases'][$from]??null;
        return is_array($release) && ($release['to']??null)===$current
            && ($release['policy']??null)==='preserve_existing_filled_orders';
    }
    public static function verify(array $state,string $current):void
    {
        if(self::forwardUpgrade($state,$current)) return;
        if(!self::compatible($state['version'],$current))throw new RuntimeException('Pinned strategy changed or unverified legacy version; use a new account ID');
        if(isset($state['strategy_fingerprint']) && !hash_equals($current,$state['strategy_fingerprint']) && !self::compatible($state['strategy_fingerprint'],$current))throw new RuntimeException('Pinned strategy fingerprint changed');
    }
    public static function adopt(array &$state,string $current,callable $emit):void
    {
        self::verify($state,$current);
        if(self::forwardUpgrade($state,$current)) {
            if(!empty($state['halted']))throw new RuntimeException('Halted account cannot migrate');
            $from=$state['version'];
            $activeHash=hash('sha256',PaperJournal::encode($state['active']??[]));
            $state['version']=$current;$state['strategy_fingerprint']=$current;
            $emit('forward_strategy_upgraded',['from'=>$from,'to'=>$current,
                'preserved_active_hash'=>$activeHash,'action'=>'preserve_existing_filled_orders_and_history',
                'new_fill_policy_account'=>'paper-kr-recovery-v1']);
            return;
        }
        if(isset($state['strategy_fingerprint']))return;
        if(!empty($state['halted']))throw new RuntimeException('Halted account cannot migrate');
        $state['strategy_fingerprint']=$current;
        $emit('strategy_scope_verified',['legacy_version'=>$state['version'],'strategy_fingerprint'=>$current,
            'action'=>'preserve_version_snapshots_orders_and_balances']);
    }
}
