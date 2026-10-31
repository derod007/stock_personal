<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';foreach(['Classifier','Tracker','Store','Collector','Charts','BreakoutRetest'] as $f)require __DIR__.'/type1/'.$f.'.php';
use ChartEntryLab\Type1Scan\{Classifier,Tracker,Store,Collector};
try{
    $o=getopt('',['snapshot:','state-dir:']);if(isset($o['snapshot'])&&!isset($o['state-dir']))throw new RuntimeException('Replay requires --state-dir');
    $folder=$o['state-dir']??Store::folder();$classifier=new Classifier(dirname(__DIR__).'/data/type1/centers.json');
    $result=Store::update($folder,static function($state)use($o,$folder){
        if(isset($o['snapshot']))return json_decode(file_get_contents($o['snapshot']),true,512,JSON_THROW_ON_ERROR);
        $leaders=new ChartEntryLab\KrAmountLeadersClient($folder.'/cache/leaders');$prices=new ChartEntryLab\YahooChartClient($folder.'/cache/prices');
        return Collector::capture($state,static fn()=>$leaders->topByAmount(100,useCache:false,market:'all'),static fn($s)=>$prices->fetch($s,range:'2y',interval:'1d',useCache:false),
            static function($today){$fetch=static function($symbol){$url='https://fchart.stock.naver.com/sise.nhn?symbol='.$symbol.'&timeframe=day&count=600&requestType=0';
                $ctx=stream_context_create(['http'=>['timeout'=>30,'header'=>"User-Agent: Mozilla/5.0\r\n"]]);$raw=file_get_contents($url,false,$ctx);if($raw===false)throw new RuntimeException('Index unavailable');return $raw;};
                return Collector::calendar($fetch('KOSPI'),$fetch('KOSDAQ'),$today);},time());
    },$classifier);
    if(!isset($o['snapshot'])){
        try{
            $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
            $client=new ChartEntryLab\YahooChartClient($folder.'/cache/retest');
            $result['breakout_retest']=\ChartEntryLab\Type1Scan\BreakoutRetest::record($folder,Store::load($folder),$today,
                static fn($s)=>$client->fetch($s,range:'5d',interval:'5m',useCache:false),
                static fn($s)=>$client->fetch($s,range:'1mo',interval:'1d',useCache:false));
        }catch(Throwable $err){$result['breakout_retest']=['status'=>'기록 실패','reason'=>$err->getMessage()];}
    }
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,'Type1 tracking failed: '.$e->getMessage()."\n");exit(1);}
