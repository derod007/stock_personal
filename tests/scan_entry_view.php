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
$ready=$near;$ready['symbol']='ready';$ready['entry_status']='ready';$ready['order_ready']=true;$ready['order_plan']=['ready'=>true,'status'=>'ready','entry'=>42000,'stop'=>35018,'target'=>51200,'reward_risk'=>1.318];
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
$data=json_decode(file_get_contents(__DIR__.'/../docs/spike-dump-20261006/evening-rr-audit-20261006-2023.json'),true);
$record=array_values(array_filter($data['records'],fn($r)=>$r['name']==='삼성E&A'))[0];
$plan=$record['analysis']['plan'];
$samsung=['price'=>48050,'score'=>24,'analysis_mode'=>'completed','entry_status'=>'ready','order_ready'=>true,'entry_candidate'=>$plan['candidate'],'order_plan'=>$plan];
$v=ScanEntryView::decorate($samsung)['entry_view'];
sv($v['confirmed']&&$v['order_distance_pct']==0.0,'Samsung actual limit equals price despite being below candidate band');
sv(str_contains($v['note'],'관심 구간 아래')&&!str_contains($v['note'],'회복 필요'),'zone position does not invent recovery gate');
sv(str_contains($v['order_note'],'48,050')&&str_contains($v['order_note'],'3.994')&&str_contains($v['order_note'],'실제 주문·체결 상태 아님'),'actual levels RR and lifecycle limitation shown');
$noPlan=$samsung;unset($noPlan['order_plan']);sv(!ScanEntryView::decorate($noPlan)['entry_view']['confirmed'],'ready flag alone cannot imply executable plan');
$invalid=$samsung;$invalid['order_plan']['stop']=48050;sv(!ScanEntryView::decorate($invalid)['entry_view']['confirmed'],'invalid actual plan rejected');
$farOrder=$samsung;$farOrder['symbol']='far_order';$farOrder['price']=49500;$farOrder['score']=99;
$samsung['symbol']='samsung';$ranked=ScanEntryView::rows([$farOrder,$samsung]);
sv($ranked[0]['symbol']==='samsung','ready plans sort by actual limit distance, not candidate zone or score');
foreach([46260,55200] as $px){$bad=$samsung;$bad['price']=$px;sv(!ScanEntryView::decorate($bad)['entry_view']['confirmed'],'actual stop/target boundary demotes display');}
sv($samsung['order_plan']===$plan,'display never mutates operational plan');
echo "SCAN_ENTRY_VIEW_PASS\n";
