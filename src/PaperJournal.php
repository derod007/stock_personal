<?php
declare(strict_types=1);
namespace ChartEntryLab;

final class PaperJournal
{
    public function __construct(private string $path) {}
    public static function encode(mixed $value): string
    {
        return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
    }
    public function read(): ?array
    {
        if(!is_file($this->path)) return null;
        $d=json_decode(file_get_contents($this->path),true,512,JSON_THROW_ON_ERROR);
        $prev='';
        foreach($d['events'] as $event) {
            $hash=$event['hash'];unset($event['hash']);
            if($event['previous']!==$prev || hash('sha256',self::encode($event))!==$hash) throw new \RuntimeException('Journal integrity check failed');
            $prev=$hash;
        }
        if(hash('sha256',self::encode($d['state']))!==$d['state_hash']) throw new \RuntimeException('State integrity check failed');
        return $d;
    }
    public function transact(callable $fn): array
    {
        $dir=dirname($this->path);
        if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new \RuntimeException('Cannot create journal directory');
        $lock=fopen($this->path.'.lock','c');
        if(!$lock || !flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock journal');
        try {
            $d=$this->read()??['state'=>null,'events'=>[]];
            $emit=function(string $type,array $payload) use (&$d): void {
                $previous=$d['events']===[]?'':$d['events'][array_key_last($d['events'])]['hash'];
                $event=['id'=>count($d['events'])+1,'recorded_at'=>time(),'type'=>$type,'payload'=>$payload,'previous'=>$previous];
                $event['hash']=hash('sha256',self::encode($event));$d['events'][]=$event;
            };
            $fn($d['state'],$emit);
            $d['state_hash']=hash('sha256',self::encode($d['state']));
            $tmp=tempnam($dir,'.paper-');
            if($tmp===false) throw new \RuntimeException('Cannot create transaction file');
            try {
                $encoded=self::encode($d);
                if(file_put_contents($tmp,$encoded)!==strlen($encoded) || !rename($tmp,$this->path)) throw new \RuntimeException('Cannot commit journal');
            } finally { if(is_file($tmp)) unlink($tmp); }
            return $d;
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
}
