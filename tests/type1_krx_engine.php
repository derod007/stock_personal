<?php
require_once dirname(__DIR__).'/docs/research/Type1KrxEngineReplay.php';
require_once __DIR__.'/type1_engine_replay.php';
[$input,$data]=fixture();$input['price_basis']='krx_tdd_ohlcv_adjustment_unverified';
$cfg=json_decode(file_get_contents(dirname(__DIR__).'/config/paper-kr-recovery-v1.json'),true);$cfg['id']='research-type1-engine-krx-fixture';
$base=Type1KrxEngineReplay::run($input,$cfg,'type1',fn($s)=>$data[$s]);
check($base['status']==='completed','KRX no event completes');
$sym='000001.KS';$input['reference_events']=[$sym=>[$input['days'][1]]];
$r=Type1KrxEngineReplay::run($input,$cfg,'type1',fn($s)=>$data[$s]);
check($r['status']==='halted','Pending event halts');check(($r['counts']['fill']??0)===0,'No invented fill on unresolved date');
check($r['last_good_day']===$input['days'][0],'Last good before event');check($r['return_pct']===null,'No annual result');
$input['reference_events']=[$sym=>[$input['days'][2]]];$r=Type1KrxEngineReplay::run($input,$cfg,'type1',fn($s)=>$data[$s]);
check(($r['counts']['fill']??0)===1,'Filled before later event');check($r['status']==='halted','Held event halts');
$input['reference_events']=['999999.KS'=>[$input['days'][1]]];$r=Type1KrxEngineReplay::run($input,$cfg,'type1',fn($s)=>$data[$s]);check($r['status']==='completed','Inactive symbol does not halt');
echo "KRX checks passed total=$checks\n";
