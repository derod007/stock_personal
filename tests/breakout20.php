<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/Breakout20Panel.php';
use ChartEntryLab\Breakout20;
$n=0;function check($ok,$s){global $n;if(!$ok)throw new RuntimeException($s);$n++;}
$t=strtotime('2026-09-01 09:00:00 +09:00');$a=[];
for($i=0;$i<25;$i++)$a[]=['time'=>$t+$i*86400,'open'=>90,'high'=>100,'low'=>80,'close'=>95,'is_complete'=>true];
$now=$t+26*86400;
$r=Breakout20::analyze($a,'005930.KS',$now);check($r['level']===100&&$r['status']==='돌파 전','base');check(abs($r['distance_pct']+5)<1e-9,'distance');
$b=$a;$b[24]['high']=150;$b[24]['close']=110;$r=Breakout20::analyze($b,'005930.KS',$now);check($r['level']===100&&$r['status']==='종가 돌파 확인','exclude current');
$b[24]['is_complete']=false;$r=Breakout20::analyze($b,'005930.KS',$t+24*86400+3600);check($r['status']==='장중 돌파 · 종가 미확정','incomplete');
$b=$a;$b[24]['high']=110;check(Breakout20::analyze($b,'005930.KS',$now)['status']==='장중 돌파 후 종가 미유지','wick');
$b[24]['high']=100;$b[24]['close']=100;check(Breakout20::analyze($b,'005930.KS',$now)['status']==='돌파 전','equality');
$b=$a;$b[23]['high']=120;$b[23]['close']=110;$r=Breakout20::analyze($b,'005930.KS',$now);check($r['status']==='돌파 후 기준선 아래'&&$r['previous_breakout']['level']===100,'frozen prior breakout');
$b=$a;$b[0]['high']=1000;check(Breakout20::analyze($b,'005930.KS',$now)['level']===100,'outside twenty');
$b=$a;$b[4]['high']=105;check(Breakout20::analyze($b,'005930.KS',$now)['level']===105,'twentieth included');
check(Breakout20::analyze(array_slice($a,0,20),'005930.KS',$now)['level']===null,'insufficient');
$b=$a;$b[6]['synthetic']=true;check(Breakout20::analyze($b,'005930.KS',$now)['level']===null,'no backfill invalid');
$b=$a;$b[6]['low']=200;check(Breakout20::analyze($b,'005930.KS',$now)['level']===null,'bad ohlc');
$b=$a;$b[]=$a[24];check(str_contains(Breakout20::analyze($b,'005930.KS',$now)['reason'],'중복'),'duplicate');
$b=$a;$b[24]['high']=1000;$b[24]['time']=$now+86400;check(Breakout20::analyze($b,'005930.KS',$now)['level']===100,'future ignored');
$r=Breakout20::analyze($a,'005930.KS',$now);ob_start();paper_breakout20_panel($r);$html=ob_get_clean();check(str_contains($html,'<svg')&&str_contains($html,'직전 20봉'),'chart rendered');
$r['reason']='<script>alert(1)</script>';ob_start();paper_breakout20_panel($r);$html=ob_get_clean();check(!str_contains($html,'<script>'),'escaped reason');
echo "$n checks passed\n";
