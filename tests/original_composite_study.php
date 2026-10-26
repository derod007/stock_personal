<?php
declare(strict_types=1);
require dirname(__DIR__).'/docs/research/OriginalCompositeStudy.php';
use ChartEntryLab\{FeatureEngine,AccountPlaybook};
function ok(bool $v,string $s):void {if(!$v)throw new RuntimeException($s);echo "OK $s\n";}
$bars=[];
for($i=0;$i<130;$i++){$d=(new DateTimeImmutable('2023-01-01'))->modify('+'.$i.' days')->format('Y-m-d');$t=OriginalCompositeStudy::close($d);$p=100+$i*.1+sin($i)*2;$bars[]=['time'=>$t-23400,'time_kst'=>$d.' 09:00:00','available_at'=>$t,'open'=>$p,'high'=>$p+3,'low'=>$p-3,'close'=>$p+.5,'volume'=>1000];}
$t=end($bars)['available_at'];$s=['sha256'=>'fixture','price_basis'=>'synthetic'];$a=OriginalCompositeStudy::assess($bars,'000001.KS',$t,$s);
$f=(new FeatureEngine())->extract($bars);ok($a['features']===$f,'unchanged original feature calculation');ok($a['decision']===AccountPlaybook::forProfile('custom')->decide($f,'000001.KS'),'unchanged original decisions');
$poison=end($bars);$poison['available_at']=$t+86400;$poison['close']=999999;$poison['high']=999999;$bars[]=$poison;
ok(OriginalCompositeStudy::assess($bars,'000001.KS',$t,$s)===$a,'future poison cannot alter signal');
ok(in_array('warmup_under_120',OriginalCompositeStudy::assess(array_slice($bars,0,119),'000001.KS',$bars[118]['available_at'],$s)['blocked'],true),'120-bar warmup enforced');
$plan=['ready'=>true,'entry'=>100,'stop'=>90,'target'=>120,'signal_at'=>OriginalCompositeStudy::close('2024-01-01'),'order_valid_bars'=>3];
$days=['2024-01-02','2024-01-03','2024-01-04'];$by=[];
foreach($days as $day){$nt=OriginalCompositeStudy::close($day);$by[$nt]=['time'=>$nt-23400,'available_at'=>$nt,'open'=>105,'high'=>110,'low'=>101,'close'=>106,'volume'=>100];}
$x=OriginalCompositeStudy::execute($plan,$days,$by,'000001.KS');ok($x['trade']['status']==='unfilled'&&$x['trade']['complete'],'TTL expires on third day without fourth bar');
$nt=OriginalCompositeStudy::close($days[0]);$by[$nt]['open']=100;$by[$nt]['low']=85;$x=OriginalCompositeStudy::execute($plan,$days,$by,'000001.KS');ok($x['trade']['status']==='closed'&&$x['trade']['first_exit']==='stop','same-day entry and stop handled by original simulator');
$by[$nt]['low']=95;$x=OriginalCompositeStudy::execute($plan,$days,$by,'000001.KS');ok($x['trade']['status']==='unresolved'&&$x['trade']['filled'],'open end position not forced closed');
unset($by[OriginalCompositeStudy::close($days[1])]);$x=OriginalCompositeStudy::execute($plan,$days,$by,'000001.KS');ok($x['trade']['unresolved_reason']==='missing_or_invalid_future_bar'&&$x['trade']['filled'],'missing future preserves known fill but unknown return');
$plan['stop']=101;$x=OriginalCompositeStudy::execute($plan,$days,$by,'000001.KS');ok($x['trade']['status']==='invalid_levels','no repair of invalid original levels');
