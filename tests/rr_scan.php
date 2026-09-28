<?php
declare(strict_types=1);
// Isolated scanner adapters: deterministic, no network or operational state.
namespace ChartEntryLab {
    final class KrAmountLeadersClient {
        public int $calls=0; public bool $lastCache=true;
        public function topByAmount(int $n,bool $useCache,string $market):array {
            $this->calls++;$this->lastCache=$useCache;
            return [['yahoo'=>'005930.KS','rank'=>1,'code'=>'005930','name'=>'fixture','market'=>'kospi',
                'amount_million'=>100,'amount_won'=>100000000,'price'=>1100]];
        }
    }
    final class SectorMap { public const BUCKETS=[];
        public function resolve(string $s,bool $useCache=true):array{return ['sector'=>'semi','sector_bucket'=>'semi','sector_label'=>'semi'];}
    }
    final class ScanSnapshot { public array $saved=[];
        public function save(array $a,bool $overwrite):void{$this->saved[]=$a;}
    }
    final class ProposalService {
        public static array $liveModes=[];
        public function profile():object{return (object)['id'=>'account1'];}
        public function propose(string $s,bool $useCache,?int $cacheMaxAgeSeconds,bool $captureResearch=false,bool $includeInProgress=false):array {
            self::$liveModes[]=$includeInProgress;
            return ['ok'=>true,'features'=>[],'proposal'=>['price'=>900,'action'=>'wait','new_entry'=>['order_ready'=>false,'buy_now'=>false]],
                'research_input'=>$captureResearch?['bars'=>[['close'=>123]],'analysis'=>['sentinel'=>'exact']]:null];
        }
    }
}
namespace {
    require __DIR__.'/../src/KrAmountScanner.php';
    $tmp=sys_get_temp_dir().'/rr-scan-'.bin2hex(random_bytes(4));
    $leaders=new ChartEntryLab\KrAmountLeadersClient();$snap=new ChartEntryLab\ScanSnapshot();
    $scanner=new ChartEntryLab\KrAmountScanner($leaders,new ChartEntryLab\ProposalService(),$tmp,300,new ChartEntryLab\SectorMap(),$snap);
    $plain=$scanner->scan(useCache:false);$observed=[];
    $audited=$scanner->scan(useCache:false,onResearch:static function($leader,$result)use(&$observed){$observed[]=[$leader,$result];});
    unset($plain['fetched_at'],$audited['fetched_at']);
    if($plain!==$audited || count($observed)!==1 || $leaders->calls!==2)throw new RuntimeException('observer changed scan / doubled leaders fetch');
    if($observed[0][1]['research_input']['analysis']['sentinel']!=='exact')throw new RuntimeException('missing exact evidence');
    $cached=$scanner->scan(useCache:true,onResearch:static function(){throw new RuntimeException('cached scan must not pretend to be fresh');});
    if($leaders->calls!==2)throw new RuntimeException('cache fetched leaders');
    $savedCount=count($snap->saved);$file=glob($tmp.'/*.json')[0];$hash=hash_file('sha256',$file);
    $manual=$scanner->scan(useCache:true,interactive:true);
    if($leaders->calls!==3 || $leaders->lastCache)throw new RuntimeException('manual scan reused quote cache');
    if($manual['rows'][0]['price']!==1100 || $manual['rows'][0]['analysis_price']!==900)throw new RuntimeException('live price overwritten by close');
    if($plain['rows'][0]['price']!==900)throw new RuntimeException('recording price changed');
    if(count($snap->saved)!==$savedCount || hash_file('sha256',$file)!==$hash)throw new RuntimeException('manual scan changed recording/cache');
    if(ChartEntryLab\ProposalService::$liveModes!==[false,false,true])throw new RuntimeException('manual mode not separated from recording');
    echo "OK manual live price, uncached ranking, separate analysis, recording unchanged\n";
    foreach($snap->saved as $s)if(isset($s['rows'][0]['research_input']))throw new RuntimeException('widget snapshot polluted');
    foreach(glob($tmp.'/*')as $f)unlink($f);rmdir($tmp);
    echo "OK scan once, observer neutrality, cache evidence, unchanged widget payload\n";
}
