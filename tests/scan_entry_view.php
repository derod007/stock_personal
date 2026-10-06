<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
use ChartEntryLab\ScanEntryView;
function sv(bool $ok,string $text):void{if(!$ok)throw new RuntimeException($text);echo "PASS $text\n";}
$hana=['symbol'=>'067310.KQ','price'=>53400,'score'=>66,'amount_rank'=>1,'analysis_mode'=>'completed','entry_status'=>'wait_pullback','order_ready'=>false,
    'entry_candidate'=>['low'=>39173,'high'=>41301,'stop'=>35018,'target'=>51200]];
$h=ScanEntryView::decorate($hana);sv(!$h['entry_view']['confirmed']&&$h['entry_view']['target_exceeded'],'Hana target exceeded never confirmed');
sv(abs($h['entry_view']['distance_pct']-22.6573033707865)<1e-8,'distance uses current price and nearest zone boundary');
$near=$hana;$near['symbol']='near';$near['price']=42000;$near['score']=40;
$far=$near;$far['symbol']='far';$far['price']=49000;$far['score']=95;
$ready=$near;$ready['symbol']='ready';$ready['entry_status']='ready';$ready['order_ready']=true;
$live=$ready;$live['symbol']='live';$live['analysis_mode']='intraday';$live['entry_status']='intraday_preview';
$fallback=$ready;$fallback['symbol']='fallback';$fallback['analysis_mode']='completed_fallback';
$broken=$ready;$broken['symbol']='broken';$broken['price']=35018;
$missing=$ready;$missing['symbol']='missing';unset($missing['price']);
$rows=ScanEntryView::rows([$hana,$far,$near,$fallback,$live,$broken,$missing,$ready]);
sv($rows[0]['symbol']==='ready','confirmed plan above observations');
$ids=array_column($rows,'symbol');sv(array_search('near',$ids)<array_search('far',$ids)&&array_search('far',$ids)<array_search('067310.KQ',$ids),'distance precedes score, target exceeded below waiting');
foreach([$fallback,$live,$broken,$missing] as $r)sv(!ScanEntryView::decorate($r)['entry_view']['confirmed'],'not confirmed: '.$r['symbol']);
$blocked=$ready;$blocked['entry_status']='risk_blocked';sv(!ScanEntryView::decorate($blocked)['entry_view']['confirmed'],'risk flag cannot be overridden by order_ready');
sv($hana['score']===66&&$hana['entry_candidate']['target']===51200,'display classification preserves score and levels');
foreach($rows as $i=>$r)sv($r['entry_view']['sort_order']===$i,'browser restores server order '.$i);
sv(str_contains(ScanEntryView::modeLabel('completed_fallback'),'대체'),'fallback has visible distinct label');
$client=new ChartEntryLab\CurrentQuoteClient(fn($url)=>[]);
sv($client->daily('005930.KS',1791258000)===null,'missing live input remains missing');
sv(str_contains($client->dailyFailureReason(),'Daum:')&&str_contains($client->dailyFailureReason(),'Yahoo:'),'both provider failures are recorded');
$ui=file_get_contents(__DIR__.'/../index.php');sv(str_contains($ui,'data-entry-order')&&!str_contains($ui,'const entryTier'),'no divergent browser tier logic');
echo "SCAN_ENTRY_VIEW_PASS\n";
