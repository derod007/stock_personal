<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
use ChartEntryLab\{IntradayAnalysis,CurrentQuoteClient,ChartPlanEngine,CandleClock};
function ck(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);echo "OK $m\n";}
$tz=new DateTimeZone('Asia/Seoul');$now=(new DateTimeImmutable('2026-09-28 11:00:00',$tz))->getTimestamp();
$raw=[];$today=new DateTimeImmutable('2026-09-28 15:30:00',$tz);
for($i=120;$i>=1;$i--){$t=$today->modify('-'.$i.' weekdays')->getTimestamp();$c=100+sin($i/3)*3;$raw[]=['time'=>$t,'time_kst'=>date('Y-m-d H:i:s',$t),'open'=>$c,'high'=>$c+2,'low'=>$c-2,'close'=>$c,'volume'=>1000];}
$bar=['time'=>$now,'observed_at'=>$now,'open'=>102,'high'=>125,'low'=>101,'close'=>123,'volume'=>100000,'source'=>'fixture'];
$before=serialize($raw);$engine=new ChartPlanEngine();$closed=$engine->analyze($raw,'005930.KS',$now);
$live=(new IntradayAnalysis())->analyze($raw,'005930.KS',$now,'account1',$bar);
ck($live['mode']==='intraday' && $live['features']['price']===123.0,'manual features use current daily close');
ck($live['features']['score_breakdown']!==$closed['features']['score_breakdown'],'today OHLCV changes scoring breakdown');
ck(!$live['plan']['ready'] && $live['plan']['confirmation_status']==='provisional','live score never confirms closing signal');
ck($live['completed_plan']===$closed['plan'],'confirmed plan preserved separately');
ck($live['daily']['low']===101.0 && $live['daily']['volume']===100000.0,'actual low and cumulative volume preserved');
ck(serialize($raw)===$before && $engine->analyze($raw,'005930.KS',$now)===$closed,'scheduled engine and raw history unchanged');
ck(CandleClock::completed([...$raw,$live['daily']],'005930.KS',$now)===CandleClock::completed($raw,'005930.KS',$now),'provisional candle remains incomplete');
foreach(['open','high','low','volume','observed_at'] as $key){$bad=$bar;unset($bad[$key]);ck(!IntradayAnalysis::validBar($bad,'005930.KS',$now),'missing '.$key.' rejected');}
$old=$bar;$old['observed_at']=$now-1201;ck(!IntradayAnalysis::validBar($old,'005930.KS',$now),'stale current candle rejected');
$future=$bar;$future['observed_at']=$now+1;ck(!IntradayAnalysis::validBar($future,'005930.KS',$now),'future observation rejected');
$yesterday=$bar;$yesterday['time']=$now-86400;ck(!IntradayAnalysis::validBar($yesterday,'005930.KS',$now),'prior-session candle not today');
$bad=$bar;$bad['low']=124;ck(!IntradayAnalysis::validBar($bad,'005930.KS',$now),'invalid OHLC rejected');
$bad=$bar;$bad['synthetic']=true;ck(!IntradayAnalysis::validBar($bad,'005930.KS',$now),'synthetic quote candle rejected');
$failed=(new IntradayAnalysis())->analyze($raw,'005930.KS',$now,'account1',null);
ck($failed['mode']==='completed_fallback' && $failed['features']===$closed['features'],'unavailable data explicitly falls back to confirmed score');
$after=$today->modify('+1 minute')->getTimestamp();$final=$bar;$final['observed_at']=$today->getTimestamp();
$done=(new IntradayAnalysis())->analyze($raw,'005930.KS',$after,'account1',$final);
ck($done['mode']==='completed' && $done['features']['price']===123.0,'post-close quote becomes completed day');
ck(!IntradayAnalysis::validBar($bar,'005930.KS',$after),'pre-close snapshot cannot masquerade as final close');
$us=(new DateTimeImmutable('2026-09-28 11:00:00',new DateTimeZone('America/New_York')))->getTimestamp();$b=$bar;$b['time']=$us;$b['observed_at']=$us;
ck(IntradayAnalysis::validBar($b,'MU',$us),'US session uses exchange timezone');
$client=new CurrentQuoteClient(fn($u)=>['symbolCode'=>'A005930','date'=>'2026-09-28','time'=>'11:00:00','openingPrice'=>102,'highPrice'=>125,'lowPrice'=>101,'tradePrice'=>123,'accTradeVolume'=>100000]);
ck($client->daily('005930.KS',$now)['low']===101,'dated provider OHLCV decoded');
$fallback=new CurrentQuoteClient(function($u)use($now){if(str_contains($u,'daum'))return ['symbolCode'=>'A005930','tradePrice'=>123];return ['chart'=>['result'=>[['meta'=>['symbol'=>'005930.KS','regularMarketTime'=>$now],'timestamp'=>[$now],'indicators'=>['quote'=>[['open'=>[102],'high'=>[125],'low'=>[101],'close'=>[123],'volume'=>[100000]]]]]]]];});
ck($fallback->daily('005930.KS',$now)['source']==='Yahoo','missing primary OHLCV falls back to actual dated candle');
$noDate=new CurrentQuoteClient(fn($u)=>['symbolCode'=>'A005930','tradePrice'=>123]);
ck($noDate->daily('005930.KS',$now)===null,'price alone cannot create daily candle');
echo "INTRADAY_PASS\n";
