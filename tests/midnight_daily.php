<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
use ChartEntryLab\{DailyEvidenceMerge,CandleClock,IntradayAnalysis};
function md(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);echo "OK $m\n";}
function at(string $s):int{return (new DateTimeImmutable($s,new DateTimeZone('Asia/Seoul')))->getTimestamp();}
$midnight=at('2026-10-07 00:15:48');$evening=at('2026-10-06 20:23:13');$intraday=at('2026-10-06 13:00:00');
$old=['time'=>at('2026-10-02 09:00:00'),'open'=>100,'high'=>110,'low'=>99,'close'=>105,'volume'=>100,'is_complete'=>true];
$pending=['time'=>at('2026-10-06 09:00:00'),'open'=>105,'high'=>115,'low'=>104,'close'=>110,'volume'=>200,'is_complete'=>false];
$q=['2026-10-06'=>['open'=>105,'high'=>120,'low'=>104,'close'=>118,'volume'=>300]];
foreach([[$old],[$old,$pending]] as $rows){
 $merged=DailyEvidenceMerge::merge($rows,$q,'005930.KS',$evening,$midnight);
 $done=CandleClock::completed($merged,'005930.KS',$midnight);
 md(count($done)===2&&end($done)['close']===118.0,'fresh final OHLCV completes appended or cached provisional bar');
 $stale=DailyEvidenceMerge::merge($rows,$q,'005930.KS',$intraday,$midnight);
 md(count(CandleClock::completed($stale,'005930.KS',$midnight))===1,'elapsed time never promotes stale intraday evidence');
}
$preserved=DailyEvidenceMerge::merge([$old],['2026-10-02'=>$q['2026-10-06']],'005930.KS',$evening,$midnight);
md($preserved===[$old],'existing complete primary candle preserved');
$bad=$q;unset($bad['2026-10-06']['open']);
md(DailyEvidenceMerge::merge([$old],$bad,'005930.KS',$evening,$midnight)===[$old],'partial OHLCV cannot confirm candle');
md(!IntradayAnalysis::isRegularSession('005930.KS',$midnight)&&!IntradayAnalysis::isRegularSession('005930.KS',$evening),'KR evening and midnight use completed mode');
md(IntradayAnalysis::isRegularSession('005930.KS',$intraday),'KR intraday still uses live mode');
md(!IntradayAnalysis::isRegularSession('005930.KS',at('2026-10-10 13:00:00')),'weekend is not live session');
md(IntradayAnalysis::isRegularSession('MU',$midnight),'US session uses exchange timezone');
$p=json_decode(file_get_contents(__DIR__.'/../docs/spike-dump-20261006/evening-rr-audit-20261006-2023.json'),true);
foreach($p['records'] as $record){
 if(!in_array($record['name'],['레메디','아이씨티케이','제이앤티씨'],true))continue;
 $bars=$record['bars'];$i=array_key_last($bars);$final=$bars[$i];$bars[$i]['is_complete']=false;
 $quotes=['2026-10-06'=>$final];
 $fixed=DailyEvidenceMerge::merge($bars,$quotes,$record['symbol'],$evening,$midnight);
 $r=(new IntradayAnalysis())->analyze($fixed,$record['symbol'],$midnight,'account1',null);
 md($r['mode']==='completed'&&$r['features']['price']==$final['close'],'real saved final candle survives midnight: '.$record['name']);
 md($r['features']['spike_dump_status']===$record['analysis']['features']['spike_dump_status'],'same final candle preserves risk result: '.$record['name']);
}
echo "MIDNIGHT_DAILY_PASS\n";
