<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/TrackingInput.php';
require __DIR__.'/../bin/paper/RrAudit.php';
use ChartEntryLab\{NaverHistoricalClose,YahooChartClient,CandleClock};
function hc(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); echo "OK $message\n"; }
$cases=json_decode(file_get_contents(__DIR__.'/fixtures/historical-close-20260327.json'),true,512,JSON_THROW_ON_ERROR);
$now=strtotime('2026-10-09T15:00:00+09:00');$hash=str_repeat('a',64);$repaired=0;
foreach($cases as $case){
 $before=$case['bars'];$after=NaverHistoricalClose::merge($before,$case['quotes'],$case['symbol'],$now,$now,$hash);
 if($case['expected_repair']){
  $repaired++;hc($after[1]['close']===$case['quotes']['2026-03-27']['close'],'independent source close: '.$case['symbol']);
  hc($after[1]['historical_close_repair']['original']===$before[1]&&$after[0]===$before[0]&&$after[2]===$before[2],'original and neighbors preserved');
  hc(count(CandleClock::completed($after,$case['symbol'],$now))===3,'corrected candle passes unchanged OHLC validation');
  hc(NaverHistoricalClose::merge($after,$case['quotes'],$case['symbol'],$now,$now,$hash)===$after,'repair is idempotent');
 }else hc($after===$before,'different price basis stays blocked: '.$case['symbol']);
}
hc($repaired===10,'ten verified cases; one conflicting case');
$c=$cases[array_search('009150.KS',array_column($cases,'symbol'))];
foreach(['ohlv','neighbor','missing_neighbor','duplicate','synthetic','incomplete','valid','source','future','us','intraday_neighbor'] as $scenario){
 $rows=$c['bars'];$quotes=$c['quotes'];$symbol=$c['symbol'];$at=$now;
 if($scenario==='ohlv')$quotes['2026-03-27']['volume']++;
 if($scenario==='neighbor')$quotes['2026-03-26']['close']++;
 if($scenario==='missing_neighbor')unset($quotes['2026-03-30']);
 if($scenario==='duplicate')$rows[]=$rows[1];
 if($scenario==='synthetic')$rows[1]['synthetic']=true;
 if($scenario==='incomplete')$rows[1]['is_complete']=false;
 if($scenario==='valid')$rows[1]['close']=434000.0;
 if($scenario==='source')$rows[1]['close_source']='other_provider';
 if($scenario==='future')$at++;
 if($scenario==='us')$symbol='MU';
 if($scenario==='intraday_neighbor')$rows[2]['is_complete']=false;
 hc(NaverHistoricalClose::merge($rows,$quotes,$symbol,$at,$now,$hash)===$rows,'refuse unsafe merge: '.$scenario);
}
function xmlFixture(array $quotes,string $code='009150'):string {
 $xml='<?xml version="1.0" encoding="EUC-KR"?><protocol><chartdata symbol="'.$code.'" timeframe="day">';
 foreach($quotes as $day=>$q)$xml.='<item data="'.str_replace('-','',$day).'|'.implode('|',array_map(fn($k)=>$q[$k],['open','high','low','close','volume'])).'" />';
 return $xml.'</chartdata></protocol>';
}
$body=xmlFixture($c['quotes']);hc(NaverHistoricalClose::parse($body,'009150')===$c['quotes'],'EUC-KR-declared numeric XML parsing');
foreach([str_replace('symbol="009150"','symbol="005930"',$body),str_replace('timeframe="day"','timeframe="week"',$body),str_replace('</chartdata>','<item data="20260327|1|2|1|2|3" /></chartdata>',$body)] as $bad){
 try{NaverHistoricalClose::parse($bad,'009150');throw new LogicException('accepted invalid source');}catch(RuntimeException $e){hc(true,'reject wrong identity/interval/duplicate XML');}
}
$tmp=sys_get_temp_dir().'/historical-close-'.bin2hex(random_bytes(5));mkdir($tmp);
function removeHc(string $dir):void{foreach(glob($dir.'/*') as $p){if(is_dir($p))removeHc($p);else unlink($p);}rmdir($dir);}
try{
 $requests=0;$repair=new NaverHistoricalClose($tmp.'/history',function($url)use(&$requests,$body){$requests++;return $body;});
 $out=$repair->repair($c['bars'],$c['symbol'],'1d',$now);$actualHash=hash('sha256',$body);
 hc($out[1]['close']===434000.0&&file_get_contents($tmp.'/history/'.$actualHash.'.xml')===$body,'raw source persisted before repair');
 hc($out[1]['historical_close_repair']['source_sha256']===$actualHash,'repair refers to immutable evidence');
 hc($repair->repair($c['bars'],$c['symbol'],'1d',$now)===$out&&$requests===1,'historical evidence cache reuse');
 $repair->repair($out,$c['symbol'],'1d',$now);$repair->repair($c['bars'],$c['symbol'],'1h',$now);hc($requests===1,'valid and intraday series trigger no historical fetch');
 $failed=new NaverHistoricalClose($tmp.'/failed',fn($url)=>throw new RuntimeException('offline'));
 hc($failed->repair($c['bars'],$c['symbol'],'1d',$now)===$c['bars'],'network failure preserves quality block');
 file_put_contents($tmp.'/history/'.$actualHash.'.xml','corrupt');
 $broken=new NaverHistoricalClose($tmp.'/history',fn($url)=>throw new RuntimeException('offline'));
 hc($broken->repair($c['bars'],$c['symbol'],'1d',$now)===$c['bars'],'corrupt evidence is never trusted');
 file_put_contents($tmp.'/history/'.$actualHash.'.xml',$body);
 // Cached Yahoo input remains untouched, while the public fetch path returns corrected new input.
 $cached=$tmp.'/009150.KS_2y_1d_closed_v2.json';$bytes=json_encode($c['bars']);file_put_contents($cached,$bytes);touch($cached,$now);
 mkdir($tmp.'/naver');file_put_contents($tmp.'/naver/naver_day_009150_1.json','[]');
 $client=new YahooChartClient($tmp,null,fn($url)=>throw new RuntimeException('should use cache'),fn()=>$now,$repair);
 $public=$client->fetch('009150.KS','2y','1d');
 hc($public[1]['close']===434000.0&&file_get_contents($cached)===$bytes,'cached public fetch repairs returned data but preserves raw Yahoo cache');
 // Fresh chart response follows the same correction path; the saved provider cache stays raw.
 mkdir($tmp.'/live');mkdir($tmp.'/live/naver');file_put_contents($tmp.'/live/naver/naver_day_009150_1.json','[]');
 $quote=[];foreach(['open','high','low','close','volume'] as $key)$quote[$key]=array_column($c['bars'],$key);
 $payload=['chart'=>['result'=>[['meta'=>['symbol'=>$c['symbol']], 'timestamp'=>array_column($c['bars'],'time'),'indicators'=>['quote'=>[$quote]]]]]];
 $liveClient=new YahooChartClient($tmp.'/live',null,fn($url)=>$payload,fn()=>$now,$repair);
 $live=$liveClient->fetch($c['symbol'],'2y','1d');
 $stored=json_decode(file_get_contents($tmp.'/live/009150.KS_2y_1d_closed_v2.json'),true);
 hc($live[1]['close']===434000.0&&$stored[1]['close']==457000,'fresh public fetch preserves provider error in raw cache and returns verified correction');
 // Frozen old evidence remains old; tracking must not replay the repaired historical candle.
 $old=$c['bars'];$anchor=CandleClock::closeTime($old[2],$c['symbol']);
 // Move anchor far enough past the invalid candle for the existing old-invalid-history rule.
 $tails=[];for($i=0;$i<65;$i++){$tail=$old[2];$tail['time']=$now-(70-$i)*86400;$tails[]=$tail;}
 $old=array_merge($old,$tails);$anchor=CandleClock::closeTime(end($tails),$c['symbol']);
 $record=['symbol'=>$c['symbol'],'session'=>$anchor,'bars'=>$old,'input_hash'=>hash('sha256',PaperRrAudit::encode($old))];
 $updated=array_merge($out,$tails);
 $tracking=PaperTrackingInput::prepare($record,$updated,$now);
 hc($tracking['status']==='ok'&&count($tracking['raw'])===67,'tracking preserves originally accepted history instead of replaying repaired bar');
 hc($record['bars']===$old,'frozen original remains unchanged');
}finally{removeHc($tmp);}
echo "HISTORICAL_CLOSE_PASS\n";
