<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
use ChartEntryLab\{YahooChartClient,CandleClock};
function ym(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);echo "OK $m\n";}
function kst(string $s):int{return (new DateTimeImmutable($s,new DateTimeZone('Asia/Seoul')))->getTimestamp();}
$now=kst('2026-10-07 01:46:00');
// Observed Yahoo response for 387690.KQ: real O/H/L/V, missing daily close, final regular quote.
$payload=['chart'=>['result'=>[['meta'=>['symbol'=>'387690.KQ','regularMarketTime'=>1791268215,'regularMarketPrice'=>19550.0],
 'timestamp'=>[1790899200,1791244800], 'indicators'=>['quote'=>[['open'=>[19100.0,19010.0],'high'=>[19600.0,21500.0],
 'low'=>[18650.0,18860.0],'close'=>[18970.0,null],'volume'=>[537035,2722926]]]]]]]];
$tmp=sys_get_temp_dir().'/missing-close-'.bin2hex(random_bytes(5));mkdir($tmp);
function fetchFixture(array $p,int $now,string $tmp):array{
 $c=new YahooChartClient($tmp,null,fn($url)=>$p,fn()=>$now);
 return (new ReflectionMethod($c,'fetchLive'))->invoke($c,'387690.KQ','2y','1d');
}
try{
 $bars=fetchFixture($payload,$now,$tmp);$complete=CandleClock::completed($bars,'387690.KQ',$now);$last=end($complete);
 ym(count($complete)===2&&$last['close']===19550.0&&$last['available_at']===kst('2026-10-06 15:30:00'),'actual response retains Oct 6 completed candle');
 ym($last['open']===19010.0&&$last['high']===21500.0&&$last['low']===18860.0&&$last['volume']===2722926,'real OHLCV preserved, no synthetic flat candle');
 ym(!$last['synthetic']&&$last['close_source']==='yahoo_regular_market_final','final regular quote provenance retained');
 foreach(['before_close','wrong_day','future','wrong_symbol','no_high','outside_range','no_volume'] as $case){
  $p=$payload;$r=&$p['chart']['result'][0];
  if($case==='before_close')$r['meta']['regularMarketTime']=kst('2026-10-06 15:20:00');
  if($case==='wrong_day')$r['meta']['regularMarketTime']=kst('2026-10-02 15:30:00');
  if($case==='future')$r['meta']['regularMarketTime']=kst('2026-10-07 15:30:00');
  if($case==='wrong_symbol')$r['meta']['symbol']='005930.KS';
  if($case==='no_high')$r['indicators']['quote'][0]['high'][1]=null;
  if($case==='outside_range')$r['meta']['regularMarketPrice']=22000;
  if($case==='no_volume')$r['indicators']['quote'][0]['volume'][1]=null;
  unset($r);
  $c=CandleClock::completed(fetchFixture($p,$now,$tmp),'387690.KQ',$now);
  ym(!array_filter($c,fn($b)=>$b['available_at']===kst('2026-10-06 15:30:00')),'cannot invent final close: '.$case);
 }
 ym(count(CandleClock::completed(fetchFixture($payload,kst('2026-10-06 13:00:00'),$tmp),'387690.KQ',kst('2026-10-06 13:00:00')))===1,'intraday cannot borrow future final quote');
 echo "YAHOO_MISSING_CLOSE_PASS\n";
}finally{rmdir($tmp.'/naver');rmdir($tmp);}
