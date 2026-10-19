<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/docs/research/InverseBottomOnly.php';
function ck(bool $b,string $s):void {if(!$b)throw new RuntimeException($s);echo "OK $s\n";}
ck(InverseBottomOnly::score('confirmed')===12&&InverseBottomOnly::score('warning')===5&&InverseBottomOnly::score('watch')===0,'existing score mapping');
$raw=[];$days=[];
for($i=0;$i<100;$i++){$d=date('Y-m-d',strtotime('2020-01-01 +'.$i.' days'));$days[]=$d;$raw[]=['day'=>$d,'open'=>100,'high'=>102,'low'=>98,'close'=>100,'volume'=>100,'is_complete'=>true];}
$a=InverseBottomOnly::evaluate($raw,$days[64],[]);
ck($a['eligible']&&$a['score']===0,'flat eligible and no invented bonus');
$future=$raw;$future[90]['high']=9999;
ck(InverseBottomOnly::evaluate($future,$days[64],[])===$a,'future price cannot alter score');
ck(!InverseBottomOnly::evaluate($raw,$days[63],[])['eligible'],'under65 excluded');
ck(!InverseBottomOnly::evaluate($raw,$days[64],[$days[50]])['eligible'],'past discontinuity excluded');
ck(InverseBottomOnly::evaluate($raw,$days[64],[$days[70]])===$a,'future discontinuity not entry filter');
$window=array_slice($raw,0,65);
foreach($window as $i=>&$b){$v=100+25*sin($i/8)+$i*.1;$b['open']=$v;$b['close']=$v+.3;$b['high']=$v+2;$b['low']=$v-2;}unset($b);
$anchor=max(array_column($window,'high'))+min(array_column($window,'low'));
$mirror=array_map(fn($b)=>array_merge($b,['open'=>$anchor-$b['open'],'close'=>$anchor-$b['close'],'high'=>$anchor-$b['low'],'low'=>$anchor-$b['high']]),$window);
$detector=new ChartEntryLab\NoramuTopPattern();
ck($detector->analyze($window)['inverse_bottom_status']===$detector->analyze($mirror)['top_status'],'inverse is original detector on mirrored prices');
$bad=$raw;$bad[62]['volume']=0;ck(!InverseBottomOnly::evaluate($bad,$days[64],[])['eligible'],'invalid recent data blocked');
$p=InverseBottomOnly::path($raw,$days,64,[]);ck($p['complete']&&abs($p['close20_pct'])<1e-12,'fixed future window');
$p=InverseBottomOnly::path($raw,$days,64,[$days[70]]);ck(!$p['complete']&&isset($p['close5_pct'])&&!isset($p['close10_pct']),'unknown future preserves only earlier horizons');
