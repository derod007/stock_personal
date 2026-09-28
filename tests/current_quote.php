<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
use ChartEntryLab\CurrentQuoteClient;
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "OK $message\n";}
$calls=0;
$client=new CurrentQuoteClient(function($url)use(&$calls){$calls++;return ['symbolCode'=>'A005930','tradePrice'=>1200+$calls];});
$a=$client->fetch('005930.KS');$b=$client->fetch('005930.KS');
check($a['price']===1201.0 && $b['price']===1202.0 && $calls===2,'each lookup requests fresh quote');
check($a['quoted_at']===null && isset($a['fetched_at']),'fetch time not invented as market timestamp');
$meta=['symbol'=>'MU','regularMarketPrice'=>100,'regularMarketTime'=>1000,'postMarketPrice'=>105,'postMarketTime'=>1100,'preMarketPrice'=>90,'preMarketTime'=>900];
$client=new CurrentQuoteClient(fn($url)=>['chart'=>['result'=>[['meta'=>$meta,'indicators'=>['quote'=>[['close'=>[80]]]]]]]]);
$q=$client->fetch('MU');check($q['price']===105.0,'latest timestamped quote wins over daily close and older sessions');
$bad=new CurrentQuoteClient(fn($url)=>['symbolCode'=>'A000660','tradePrice'=>999]);
check($bad->fetch('005930.KS')['price']===null,'mismatched symbol never leaks price');
$empty=new CurrentQuoteClient(fn($url)=>['chart'=>['result'=>[['meta'=>['symbol'=>'MU'],'indicators'=>['quote'=>[['close'=>[80]]]]]]]]);
check($empty->fetch('MU')['status']==='unavailable','daily close never fallback current quote');
$failed=new CurrentQuoteClient(function($url){throw new RuntimeException('offline');});
check($failed->fetch('005930.KS')['price']===null,'network failure never returns stale close');
$retry=new CurrentQuoteClient(function($url){if(str_contains($url,'daum'))throw new RuntimeException('blocked');return ['chart'=>['result'=>[['meta'=>['symbol'=>'005930.KS','regularMarketPrice'=>1300,'regularMarketTime'=>1000]]]]];});
check($retry->fetch('005930.KS')['price']===1300.0,'KR provider failure uses separate timestamped quote');
echo "CURRENT_QUOTE_PASS\n";
