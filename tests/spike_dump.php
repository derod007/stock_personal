<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
use ChartEntryLab\SpikeDump;
function sd(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);echo "OK $m\n";}
function candle(float $close,float $high,float $low,float $open=100):array{return compact('open','high','low','close')+['volume'=>1000];}
$base=array_fill(0,20,candle(100,105,95));$engine=new SpikeDump();
$peak=candle(135,140,99);
$cases=[
 'peak candle low precedes high'=>[...$base,$peak],
 'support holding pullback'=>[...$base,$peak,candle(110,136,106,135)],
 'lower wick below support'=>[...$base,$peak,candle(110,136,90,135)],
 'close exactly on breakout'=>[...$base,$peak,candle(105,136,95,135)],
 'peak day reversal still above support'=>[...$base,candle(110,140,98)],
 'support recovered after breakdown'=>[...$base,$peak,candle(100,136,98,135),candle(110,112,99)],
];
foreach($cases as $label=>$bars){$r=$engine->analyze($bars);sd($r['status']==='none'&&$r['score_adjustment']===0,$label);}
$collapsed=$engine->analyze([...$base,$peak,candle(100,136,98,135)]);
sd($collapsed['status']==='confirmed'&&$collapsed['score_adjustment']<0,'close breakdown still blocks');
sd($collapsed['support_price']===105.0&&$collapsed['support_source']==='pre_burst_20bar_high','breakout support is fixed before burst');
sd($collapsed['retrace_basis']==='close'&&$collapsed['last_close']===100.0,'evidence records close basis and last close');
$wickA=$engine->analyze([...$base,$peak,candle(100,136,98,135)]);
$wickB=$engine->analyze([...$base,$peak,candle(100,136,70,135)]);
sd($wickA===$wickB,'lower wick does not change severity');
$prior=$base;$prior[0]=candle(100,150,95);$prior[15]=candle(100,105,90);
$nonBreakout=candle(104,140,99);
sd($engine->analyze([...$prior,$nonBreakout,candle(92,104,91,104)])['status']==='none','confirmed prior low held without breakout');
$r=$engine->analyze([...$prior,$nonBreakout,candle(88,104,87,104)]);
sd($r['status']==='confirmed'&&$r['support_source']==='pre_burst_confirmed_low'&&$r['support_price']===90.0,'confirmed prior low breakdown retained');
$noPivot=$base;$noPivot[0]=candle(100,150,95);
$r=$engine->analyze([...$noPivot,$nonBreakout,candle(92,104,91,104)]);
sd($r['status']==='confirmed'&&$r['support_source']==='pre_burst_previous_low','no pivot uses explicit preceding bar low');
sd($engine->analyze([...$base,candle(110,115,99),candle(99,110,98,110)])['status']==='none','ordinary move without spike remains unblocked');
echo "SPIKE_DUMP_PASS\n";
