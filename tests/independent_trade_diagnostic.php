<?php
require_once dirname(__DIR__).'/docs/research/IndependentTradeDiagnostic.php';
$n=0;function ck($x,$m){global $n;$n++;if(!$x)throw new RuntimeException($m);}
$rows=[];$days=[];$start=new DateTimeImmutable('2020-01-01');
for($i=0;$i<81;$i++){$d=$start->modify('+'.$i.' days')->format('Y-m-d');$rows[]=['time_kst'=>$d.' 09:00:00','open'=>100,'high'=>101,'low'=>99,'close'=>100,'volume'=>100];if($i>=59)$days[]=$d;}
$s=['symbol'=>'TEST.KS','anchor'=>$days[0],'end'=>$days[20],'entry'=>100,'stop'=>95,'target'=>115];
$r=IndependentTradeDiagnostic::run($s,$days,$rows,[],'fixture');ck($r['status']==='closed'&&$r['first_exit']==='time','twenty bars time exit');ck($r['bars']===20,'holding bars unchanged');ck($r['net_return_pct']<0,'costs included');
$r=IndependentTradeDiagnostic::run($s,$days,$rows,[$days[1]],'fixture');ck($r['status']==='unresolved'&&!$r['known_filled']&&$r['net_return_pct']===null,'event before fill unknown');
$x=$rows;$x[60]['low']=94;$r=IndependentTradeDiagnostic::run($s,$days,$x,[$days[2]],'fixture');ck($r['status']==='closed'&&$r['first_exit']==='stop','event after exit ignored');
$r=IndependentTradeDiagnostic::run($s,$days,$rows,[$days[2]],'fixture');ck($r['status']==='unresolved'&&$r['known_filled'],'event while held unknown');
$x=$rows;unset($x[60]);$r=IndependentTradeDiagnostic::run($s,$days,$x,[],'fixture');ck($r['status']==='unresolved'&&str_contains($r['reason'],'missing_session'),'missing price not zero return');
$x=$rows;$x[60]['open']=102;$x[60]['low']=101;$x[60]['high']=103;$x[60]['close']=102;$r=IndependentTradeDiagnostic::run($s,$days,$x,[$days[2]],'fixture');ck($r['status']==='unfilled','unfilled ttl before later event');
echo "OK $n checks\n";
