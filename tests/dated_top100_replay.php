<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/DatedTop100Replay.php';
use ChartEntryLab\{PaperJournal,CandleClock};

function dcheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('FAIL '.$why);echo "PASS $why\n";}
function dthrows(callable $f,string $needle):bool{try{$f();}catch(Throwable $e){return str_contains($e->getMessage(),$needle);}return false;}
$root=dirname(__DIR__);$cfgs=PaperDatedTop100Replay::configs($root);$base=$cfgs['base'];$research=$cfgs['research'];
$tmp=sys_get_temp_dir().'/dated-top100-test-'.getmypid();
function drm(string $dir):void{
    if(!is_dir($dir)||!str_contains($dir,'dated-top100-test-'))return;
    foreach(scandir($dir) as $n){if($n==='.'||$n==='..')continue;$p=$dir.'/'.$n;is_dir($p)?drm($p):unlink($p);}
    rmdir($dir);
}
drm($tmp);mkdir($tmp,0770,true);register_shutdown_function(fn()=>drm($tmp));

$T0=(new DateTimeImmutable('2025-10-01 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();
const PREP=70;
function dts(int $i):int{global $T0;return $T0+($i-PREP)*86400;}
function dmk(array $symbols,int $days,array $overrides=[],array $drop=[]):array
{
    $out=[];
    foreach($symbols as $sym){
        $rows=[];
        for($i=0;$i<$days;$i++){
            if(isset($drop[$sym])&&in_array($i,$drop[$sym],true))continue;
            [$o,$h,$l,$c]=$overrides[$sym][$i]??[100,101,99,100];
            $rows[]=['available_at'=>dts($i),'time'=>dts($i)-23400,'open'=>$o,'high'=>$h,'low'=>$l,'close'=>$c,'volume'=>1000];
        }
        $out[$sym]=['name'=>$sym,'rows'=>$rows,'bars_sha256'=>hash('sha256',PaperJournal::encode($rows)),'quality'=>['status'=>'ok','hold_reasons'=>[]],'failed'=>false];
    }
    ksort($out);
    return ['dataset'=>'synthetic','dir'=>'','start_day'=>PaperHistoryResearch::day(dts(PREP)),'as_of_day'=>PaperHistoryResearch::day(dts($days-1)),
        'as_of_ts'=>dts($days-1),'symbols'=>$out,'missing'=>[],'hold'=>[],'files'=>['dataset.json'=>'x','universe.json'=>'y']];
}
function dplan(int $day,float $entry=100,float $stop=95,float $target=110,int $ttl=3):array
{
    return ['ready'=>true,'status'=>'ready','pattern'=>'injected','entry'=>$entry,'stop'=>$stop,'target'=>$target,'reward_risk'=>round(($target-$entry)/($entry-$stop),3),
        'signal_at'=>dts($day),'order_valid_bars'=>$ttl,'reason'=>'injected for a functional test'];
}
function dprovider(array $plans):callable
{
    return function(string $symbol,array $valid,int $date)use($plans):?array{
        foreach($valid as $b)if($b['available_at']>$date)throw new RuntimeException('future bar reached the signal step');
        foreach($plans[$symbol]??[] as $i=>$p)if(dts($i)===$date)return $p;
        return ['ready'=>false,'status'=>'no_injected_plan','reason'=>'test plan not injected'];
    };
}
function dcand(array $byIndex):array
{
    $byTs=[];$union=[];$days=[];
    foreach($byIndex as $i=>$symbols){
        $day=PaperHistoryResearch::day(dts($i));$days[]=$day;$rows=[];$seen=[];
        $rank=1;
        foreach($symbols as $sym){
            $seen[$sym]=true;$union[$sym]=true;
            $rows[]=['rank'=>$rank++,'symbol'=>$sym,'code'=>substr($sym,0,6),'name'=>$sym,'market'=>'KOSPI','amount_won'=>1000-$rank];
        }
        $byTs[dts($i)]=['day'=>$day,'symbols'=>array_keys($seen),'rows'=>$rows,'source_sha256'=>str_repeat('a',64)];
    }
    ksort($byTs);
    return ['file'=>'synthetic','file_sha256'=>str_repeat('b',64),'days'=>$days,'by_session'=>$byTs,'union'=>array_keys($union),'raw'=>[]];
}
$n=0;
function dcfg(array $over=[]):array
{
    global $base,$n;$n++;
    return array_replace($base,['id'=>'hist-smoke-kr-dated-top100-v1-t'.$n],$over);
}
function djpath(string $id):string{global $tmp;return $tmp.'/history-account/'.$id.'/account-replay.json';}
function dgo(array $data,array $cfg,array $plans,array $cand,array $opts=[]):array
{
    $r=PaperDatedTop100Replay::run($data,$cfg,djpath($cfg['id']),$cand,$opts+['plan_provider'=>dprovider($plans),'chunk'=>4,'smoke'=>true]);
    return [$r,(new PaperJournal(djpath($cfg['id'])))->read()];
}
function devs(array $j,string $type):array{return array_values(array_map(fn($e)=>$e['payload'],array_filter($j['events'],fn($e)=>$e['type']===$type)));}

$fp='934012285f2ddb3867fff4576dba05fb7df290a36a987c36fc260e8917bbe465';
dcheck(PaperStrategyVersion::current($root)===$fp,'operational strategy fingerprint is unchanged');
$c=PaperAccountReplay::accountConfig($base,'hist-kr-dated-top100-v1-202510',$research);
dcheck($c['id']==='hist-kr-dated-top100-v1-202510'&&$c===array_replace($base,['id'=>$c['id']]),'research account config equals paper-kr-recovery-v1 except its ID');
dcheck(dthrows(fn()=>PaperAccountReplay::accountConfig($base,'paper-kr-recovery-v1',$research),'own ID prefix'),'the operational account ID is refused');

// New entries are only the dated TOP100. Union members outside that day do not get a ready plan.
$data=dmk(['A1.KS','B2.KS','C3.KS'],PREP+8);
$cand=dcand([PREP=>['A1.KS','B2.KS'],PREP+1=>['A1.KS','B2.KS'],PREP+2=>['C3.KS','B2.KS'],PREP+3=>['C3.KS','B2.KS']]);
$plans=['A1.KS'=>[PREP=>dplan(PREP),PREP+1=>dplan(PREP+1),PREP+2=>dplan(PREP+2)],
    'B2.KS'=>[PREP=>dplan(PREP)],'C3.KS'=>[PREP=>dplan(PREP),PREP+1=>dplan(PREP+1)]];
[$r,$j]=dgo($data,dcfg(),$plans,$cand,['start'=>PaperHistoryResearch::day(dts(PREP)),'through'=>PaperHistoryResearch::day(dts(PREP+3))]);
$logs=devs($j,'day_log');
$ready=[];foreach($logs as $d)foreach($d['signals'] as $sg)$ready[$d['date']][]=$sg['symbol'];
dcheck(($ready[$logs[0]['date']]??[])===['A1.KS','B2.KS'],'day 1 new-entry signals are only that day TOP100');
dcheck(!in_array('A1.KS',$ready[$logs[2]['date']]??[],true),'a name that left TOP100 does not get a new-entry signal');
dcheck($logs[0]['top100']===2&&$logs[1]['top100']===2,'each session records 2 dated TOP100 names, not the union');
$held=[];foreach($logs as $d)if(isset($d['held']['A1.KS']))$held[]=['date'=>$d['date'],'in'=>$d['held']['A1.KS']['in_dated_top100']??null];
dcheck($held!==[]&&$held[0]['in']===true,'a fill from day-1 TOP100 is still held after it drops out');
$orders=devs($j,'order');
dcheck($orders[0]['symbol']==='A1.KS','the first order is the day-1 TOP100 name');
dcheck(PaperAccountReplay::reconcile($j,$data)['pass'],'dated replay books reconcile');

// Pending order leaves TOP100, then fills on the next bar.
$dataF=dmk(['F1.KS','F2.KS'],PREP+6);
$candF=dcand([PREP=>['F1.KS','F2.KS'],PREP+1=>['F2.KS'],PREP+2=>['F2.KS']]);
[$r,$j]=dgo($dataF,dcfg(),['F1.KS'=>[PREP=>dplan(PREP)]],$candF,['start'=>PaperHistoryResearch::day(dts(PREP)),'through'=>PaperHistoryResearch::day(dts(PREP+2))]);
$fills=devs($j,'fill');
dcheck(count($fills)===1&&$fills[0]['symbol']==='F1.KS'&&$fills[0]['session']===dts(PREP+1),'a pending order fills after it leaves TOP100');
$heldF=[];foreach(devs($j,'day_log') as $d)if(isset($d['held']['F1.KS']))$heldF[]=$d['held']['F1.KS']['in_dated_top100']??null;
dcheck($heldF!==[]&&!in_array(true,$heldF,true),'the fill after drop-out is held with in_dated_top100=false');
dcheck(PaperAccountReplay::reconcile($j,$dataF)['pass'],'drop-out fill reconciles');

// Pending order leaves TOP100, then expires unfilled on the third bar.
$dataX=dmk(['X1.KS','X2.KS'],PREP+8,['X1.KS'=>[PREP+1=>[103,104,102,103],PREP+2=>[103,104,102,103],PREP+3=>[103,104,102,103]]]);
$candX=dcand([PREP=>['X1.KS','X2.KS'],PREP+1=>['X2.KS'],PREP+2=>['X2.KS'],PREP+3=>['X2.KS'],PREP+4=>['X2.KS']]);
[$r,$j]=dgo($dataX,dcfg(),['X1.KS'=>[PREP=>dplan(PREP)]],$candX,['start'=>PaperHistoryResearch::day(dts(PREP)),'through'=>PaperHistoryResearch::day(dts(PREP+4))]);
$cxlX=devs($j,'order_cancelled');
dcheck(count($cxlX)===1&&$cxlX[0]['reason']==='unfilled'&&$cxlX[0]['session']===dts(PREP+3)&&devs($j,'fill')===[],'a pending order expires unfilled after it leaves TOP100');
dcheck(PaperAccountReplay::reconcile($j,$dataX)['pass'],'drop-out unfilled expiry reconciles');

$lf="{\n  \"a\": 1\n}\n";$crlf=str_replace("\n","\r\n",$lf);
file_put_contents($tmp.'/cand-lf.json',$lf);file_put_contents($tmp.'/cand-crlf.json',$crlf);
$recorded=hash('sha256',$crlf);
$linkLf=PaperDatedTop100Replay::linkRecordedHash($tmp.'/cand-lf.json',$recorded);
$linkCrlf=PaperDatedTop100Replay::linkRecordedHash($tmp.'/cand-crlf.json',$recorded);
dcheck($linkLf['pass']&&$linkLf['match']==='crlf_from_lf'&&$linkLf['ending']==='lf','Git LF bytes link to a recorded CRLF hash');
dcheck($linkCrlf['pass']&&$linkCrlf['match']==='raw'&&$linkCrlf['ending']==='crlf','a CRLF working copy matches the recorded raw hash');
dcheck(!PaperDatedTop100Replay::linkRecordedHash($tmp.'/cand-lf.json',str_repeat('0',64))['pass'],'a different recorded hash does not link');

// Missing bar on a pending order cancels; missing bar on a held position still halts.
$dataP=dmk(['P1.KS','P2.KS'],PREP+6,[],['P1.KS'=>[PREP+1]]);
$candP=dcand([PREP=>['P1.KS','P2.KS'],PREP+1=>['P1.KS','P2.KS'],PREP+2=>['P1.KS','P2.KS'],PREP+3=>['P2.KS']]);
[$r,$j]=dgo($dataP,dcfg(),['P1.KS'=>[PREP=>dplan(PREP)]],$candP,['start'=>PaperHistoryResearch::day(dts(PREP)),'through'=>PaperHistoryResearch::day(dts(PREP+3))]);
$cxl=devs($j,'order_cancelled');
dcheck(count($cxl)===1&&$cxl[0]['reason']==='data_quality'&&!$r['halted'],'a missing bar cancels a pending order');
$dataH=dmk(['H1.KS','H2.KS'],PREP+8,[],['H1.KS'=>[PREP+4]]);
$candH=dcand([PREP=>['H1.KS','H2.KS'],PREP+1=>['H1.KS'],PREP+2=>['H2.KS'],PREP+3=>['H2.KS'],PREP+4=>['H2.KS']]);
[$r,$j]=dgo($dataH,dcfg(),['H1.KS'=>[PREP=>dplan(PREP)]],$candH,['start'=>PaperHistoryResearch::day(dts(PREP)),'through'=>PaperHistoryResearch::day(dts(PREP+4))]);
dcheck($r['halted']&&$j['state']['halt_reason']==='unpriced_position','a held name missing a bar still halts; the rule is not bypassed');

// Rerun adds nothing; chunked run matches one shot; future bars do not leak.
$dataR=dmk(['R1.KS','R2.KS'],PREP+10,['R1.KS'=>[PREP+3=>[100,111,99,110]]]);
$candR=dcand([PREP=>['R1.KS','R2.KS'],PREP+1=>['R1.KS','R2.KS'],PREP+2=>['R2.KS'],PREP+3=>['R2.KS'],PREP+4=>['R2.KS']]);
$plR=['R1.KS'=>[PREP=>dplan(PREP)],'R2.KS'=>[PREP+1=>dplan(PREP+1)]];
$cfgA=dcfg();[$r,$whole]=dgo($dataR,$cfgA,$plR,$candR,['start'=>PaperHistoryResearch::day(dts(PREP)),'through'=>PaperHistoryResearch::day(dts(PREP+4))]);
$cfgB=dcfg();$pB=djpath($cfgB['id']);
foreach([PaperHistoryResearch::day(dts(PREP+1)),PaperHistoryResearch::day(dts(PREP+3)),PaperHistoryResearch::day(dts(PREP+4))] as $k=>$th){
    PaperDatedTop100Replay::run($dataR,$cfgB,$pB,$candR,['plan_provider'=>dprovider($plR),'chunk'=>$k+1,'through'=>$th,'start'=>PaperHistoryResearch::day(dts(PREP)),'smoke'=>true]);
}
$resumed=(new PaperJournal($pB))->read();
$na=PaperAccountReplay::normalized($whole);$nb=PaperAccountReplay::normalized($resumed);
$ea=$na['events'];$eb=$nb['events'];
unset($ea[0]['payload']['config']['id'],$eb[0]['payload']['config']['id']);
dcheck($ea===$eb,'a run split across through-dates matches one shot');
$before=hash_file('sha256',$pB);
$r=PaperDatedTop100Replay::run($dataR,$cfgB,$pB,$candR,['plan_provider'=>dprovider($plR),'chunk'=>5,'start'=>PaperHistoryResearch::day(dts(PREP)),'through'=>PaperHistoryResearch::day(dts(PREP+4)),'smoke'=>true]);
dcheck($r['processed_sessions']===0&&hash_file('sha256',$pB)===$before,'running the same dates again adds no event');

$dataF=$dataR;$extra=end($dataF['symbols']['R1.KS']['rows']);
$extra['available_at']+=86400;$extra['time']=$extra['available_at']-23400;$extra['high']=9999;$extra['close']=5000;
$dataF['symbols']['R1.KS']['rows'][]=$extra;
$c1=dcfg();$c2=dcfg();
PaperDatedTop100Replay::run($dataR,$c1,djpath($c1['id']),$candR,['plan_provider'=>dprovider($plR),'start'=>PaperHistoryResearch::day(dts(PREP)),'through'=>PaperHistoryResearch::day(dts(PREP+4)),'smoke'=>true]);
PaperDatedTop100Replay::run($dataF,$c2,djpath($c2['id']),$candR,['plan_provider'=>dprovider($plR),'start'=>PaperHistoryResearch::day(dts(PREP)),'through'=>PaperHistoryResearch::day(dts(PREP+4)),'smoke'=>true]);
$g1=(new PaperJournal(djpath($c1['id'])))->read();
$g2=(new PaperJournal(djpath($c2['id'])))->read();
$e1=PaperAccountReplay::normalized($g1)['events'];$e2=PaperAccountReplay::normalized($g2)['events'];
unset($e1[0]['payload']['config']['id'],$e2[0]['payload']['config']['id']);
foreach([&$e1,&$e2] as &$evs){foreach($evs as &$ev)if($ev['type']==='account_started')unset($ev['payload']['research']['bars_sha256']);unset($ev);}
unset($evs);
dcheck($e1===$e2,'bars after the last dated session do not change earlier recommendations or the ledger');

$cfgBad=dcfg();
PaperDatedTop100Replay::run($dataR,$cfgBad,djpath($cfgBad['id']),$candR,['plan_provider'=>dprovider($plR),'start'=>PaperHistoryResearch::day(dts(PREP)),'through'=>PaperHistoryResearch::day(dts(PREP+1)),'smoke'=>true]);
$changed=$candR;$changed['file_sha256']=str_repeat('c',64);
dcheck(dthrows(fn()=>PaperDatedTop100Replay::run($dataR,$cfgBad,djpath($cfgBad['id']),$changed,['plan_provider'=>dprovider($plR),'start'=>PaperHistoryResearch::day(dts(PREP)),'through'=>PaperHistoryResearch::day(dts(PREP+4)),'smoke'=>true]),'Research input changed'),'a changed candidate file is refused on resume');

echo "OK dated top100 replay tests\n";
