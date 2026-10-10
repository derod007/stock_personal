<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';require __DIR__.'/../bin/paper/AccountReplay.php';
require __DIR__.'/fixtures/trend_recovery_bars.php';
use ChartEntryLab\{PaperJournal,PaperPortfolio,CandleClock};

function archeck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('FAIL '.$why);echo "PASS $why\n";}
function arthrows(callable $f,string $needle):bool{try{$f();}catch(Throwable $e){return str_contains($e->getMessage(),$needle);}return false;}
$root=dirname(__DIR__);$cfgs=PaperAccountReplay::configs($root);$base=$cfgs['base'];$research=$cfgs['research'];
$tmp=sys_get_temp_dir().'/account-replay-test-'.getmypid();
function arrm(string $dir):void{ // only ever called on this test's own folder
    if(!is_dir($dir)||!str_contains($dir,'account-replay-test-'))return;
    foreach(scandir($dir) as $n){if($n==='.'||$n==='..')continue;$p=$dir.'/'.$n;is_dir($p)?arrm($p):unlink($p);}
    rmdir($dir);
}
arrm($tmp);mkdir($tmp,0770,true);register_shutdown_function(fn()=>arrm($tmp));

$T0=(new DateTimeImmutable('2025-01-02 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();
const PREP=70;
function tsOf(int $i):int{global $T0;return $T0+$i*86400;}
/** Flat 100 bars for every symbol; overrides[symbol][index]=[open,high,low,close]. */
function mkdata(array $symbols,int $days,array $overrides=[],array $drop=[]):array
{
    $out=[];
    foreach($symbols as $sym){
        $rows=[];
        for($i=0;$i<$days;$i++){
            if(isset($drop[$sym])&&in_array($i,$drop[$sym],true))continue;
            [$o,$h,$l,$c]=$overrides[$sym][$i]??[100,101,99,100];
            $rows[]=['available_at'=>tsOf($i),'time'=>tsOf($i)-23400,'open'=>$o,'high'=>$h,'low'=>$l,'close'=>$c,'volume'=>1000];
        }
        $out[$sym]=['name'=>$sym,'rows'=>$rows,'bars_sha256'=>hash('sha256',PaperJournal::encode($rows))];
    }
    ksort($out);
    return ['dataset'=>'synthetic','dir'=>'','start_day'=>PaperHistoryResearch::day(tsOf(PREP)),'as_of_day'=>PaperHistoryResearch::day(tsOf($days-1)),
        'as_of_ts'=>tsOf($days-1),'symbols'=>$out,'excluded'=>[],'files'=>['dataset.json'=>'x','universe.json'=>'y']];
}
function plan(int $day,float $entry=100,float $stop=95,float $target=110,int $ttl=3):array
{
    return ['ready'=>true,'status'=>'ready','pattern'=>'injected','entry'=>$entry,'stop'=>$stop,'target'=>$target,'reward_risk'=>round(($target-$entry)/($entry-$stop),3),
        'signal_at'=>tsOf($day),'order_valid_bars'=>$ttl,'reason'=>'injected for a functional test'];
}
/** plans[symbol][dayIndex]=plan(...) ; refuses to run if any future bar is passed in. */
function provider(array $plans):callable
{
    return function(string $symbol,array $valid,int $date)use($plans):?array{
        foreach($valid as $b)if($b['available_at']>$date)throw new RuntimeException('future bar reached the signal step');
        foreach($plans[$symbol]??[] as $i=>$p)if(tsOf($i)===$date)return $p;
        return null;
    };
}
$n=0;
function acfg(array $over=[]):array
{
    global $base,$n;$n++;
    return array_replace($base,['id'=>'hist-smoke-kr-recovery-v1-t'.$n],$over);
}
function jpath(string $id):string{global $tmp;return $tmp.'/history-account/'.$id.'/account-replay.json';}
function go(array $data,array $cfg,array $plans,array $opts=[]):array
{
    $r=PaperAccountReplay::run($data,$cfg,jpath($cfg['id']),$opts+['plan_provider'=>provider($plans),'chunk'=>7]);
    return [$r,(new PaperJournal(jpath($cfg['id'])))->read()];
}
function evs(array $j,string $type):array{return array_values(array_map(fn($e)=>$e['payload'],array_filter($j['events'],fn($e)=>$e['type']===$type)));}
function qtyFor(float $equity,float $cash,float $entry,float $stop,array $c):int
{
    $risk=min($equity*$c['risk_pct'],$equity*$c['total_risk_pct']);$cap=min($cash,$equity*$c['position_pct'],$equity*$c['sector_pct']);
    $unit=$entry*1.001-$stop*0.9995*0.999;return (int)floor(min($risk/$unit,$cap/($entry*1.001)));
}

// ---- configuration and paths ----
$c=PaperAccountReplay::accountConfig($base,'hist-kr-recovery-v1-20251008',$research);
archeck($c['id']==='hist-kr-recovery-v1-20251008'&&$c===array_replace($base,['id'=>$c['id']]),'research account config equals paper-kr-recovery-v1 except its ID');
archeck(arthrows(fn()=>PaperAccountReplay::accountConfig($base,'paper-kr-recovery-v1',$research),'own ID prefix'),'the operational account ID is refused');
archeck(arthrows(fn()=>PaperAccountReplay::accountConfig($base,'other-account',$research),'own ID prefix'),'an ID without the research prefix is refused');
archeck(arthrows(fn()=>PaperAccountReplay::accountDir($tmp,$research,'paper-kr-recovery-v1'),'research account'),'the operational ID has no research folder');
archeck(PaperAccountReplay::accountId($research,'recent','a1')==='hist-smoke-kr-recovery-v1-20261009-a1'&&arthrows(fn()=>PaperAccountReplay::accountId($research,'recent','A/../x'),'Smoke label'),'smoke IDs are separate and validated');
archeck(str_ends_with(PaperAccountReplay::accountDir($tmp,$research,'hist-kr-recovery-v1-20261009'),'/history-account/hist-kr-recovery-v1-20261009')&&$research['carry_over']==='none','account folder is separate and nothing is carried over');
// ---- order, same-day rule, fill, target exit, books ----
$data=mkdata(['AAA.KS'],PREP+30,['AAA.KS'=>[PREP+3=>[100,111,99,110]]]);
$d0=PREP+1;$cfg=acfg();
[$r,$j]=go($data,$cfg,['AAA.KS'=>[$d0=>plan($d0)]]);
$o=evs($j,'order');$f=evs($j,'fill');$x=evs($j,'exit');
$q=qtyFor(1e8,1e8,100,95,$cfg);
archeck(count($o)===1&&$o[0]['session']===tsOf($d0)&&$o[0]['quantity']===$q,'order is created on the signal session with the operational size ('.$q.' shares)');
archeck(count($f)===1&&$f[0]['session']===tsOf($d0+1)&&$f[0]['session']>$o[0]['session'],'signal-day bars never fill the order; the fill is the next session');
archeck(abs($f[0]['price']-100)<1e-9&&abs($f[0]['fee']-$q*100*0.001)<1e-6,'limit fill price and 10bp fee');
archeck(count($x)===1&&$x[0]['reason']==='target'&&$x[0]['session']===tsOf($d0+2)&&abs($x[0]['price']-110)<1e-9,'target exit at the target price');
$exp=1e8-$q*100*1.001+$q*110*0.999;
archeck(abs($j['state']['cash']-$exp)<0.01&&abs($j['state']['realized']-($q*110*0.999-$q*100*1.001))<0.01&&$j['state']['closed_trades']===1,'cash and realised P&L follow quantity, price and both fees');
$rec=PaperAccountReplay::reconcile($j,$data);
archeck($rec['pass'],'books rebuilt from events and the independent simulator agree ('.implode(',',array_keys($rec['checks'])).')');
archeck($j['state']['research']['plan_source']==='injected_test'&&$j['state']['research']['sector_source']==='none_all_unclassified','injected plans and the sector source are labelled in the journal');
$snap=$j['state']['last_snapshots']['AAA.KS'];
archeck($snap['recorded_at']===$snap['session']&&$snap['origin']==='replay','snapshots use the session close, not the wall clock');

// ---- unfilled, expired, cancelled before entry ----
$data=mkdata(['BBB.KS'],PREP+12,['BBB.KS'=>[PREP+2=>[103,104,102,103],PREP+3=>[103,104,102,103],PREP+4=>[103,104,102,103]]]);
[$r,$j]=go($data,acfg(),['BBB.KS'=>[PREP+1=>plan(PREP+1)]]);
$c=evs($j,'order_cancelled');
archeck(count($c)===1&&$c[0]['reason']==='unfilled'&&$c[0]['session']===tsOf(PREP+4)&&evs($j,'fill')===[],'an order that never reaches its limit expires on the third bar without a fill');
archeck(PaperPortfolio::reserved($j['state'])==0.0&&abs($j['state']['cash']-1e8)<1e-6,'expiry releases the reserved cash');
archeck(PaperAccountReplay::reconcile($j,$data)['pass'],'expired order reconciles');
$data=mkdata(['BBB.KS'],PREP+8,['BBB.KS'=>[PREP+2=>[111,112,109,111]]]);
[$r,$j]=go($data,acfg(),['BBB.KS'=>[PREP+1=>plan(PREP+1)]]);
$c=evs($j,'order_cancelled');
archeck(count($c)===1&&$c[0]['reason']==='cancelled_before_entry'&&evs($j,'fill')===[]&&abs($j['state']['cash']-1e8)<1e-6,'a gap to the target before the entry cancels the order (existing pre-entry cancel rule)');

// ---- stop exits: gap, entry bar, same-bar conflict, target gap ----
$data=mkdata(['CCC.KS'],PREP+10,['CCC.KS'=>[PREP+3=>[92,93,90,91]]]);
[$r,$j]=go($data,acfg(),['CCC.KS'=>[PREP+1=>plan(PREP+1)]]);
$x=evs($j,'exit');
archeck(count($x)===1&&$x[0]['reason']==='stop'&&abs($x[0]['price']-92*0.9995)<1e-9,'a gap below the stop exits at the open less slippage, not at the stop');
$data=mkdata(['CCC.KS'],PREP+10,['CCC.KS'=>[PREP+2=>[100,101,94,96]]]);
[$r,$j]=go($data,acfg(),['CCC.KS'=>[PREP+1=>plan(PREP+1)]]);
$f=evs($j,'fill');$x=evs($j,'exit');
archeck(count($x)===1&&$x[0]['session']===$f[0]['session']&&$x[0]['reason']==='stop'&&abs($x[0]['price']-95*0.9995)<1e-9,'entry and stop on the entry bar close the trade that day at the stop');
$data=mkdata(['CCC.KS'],PREP+10,['CCC.KS'=>[PREP+3=>[100,111,94,100]]]);
[$r,$j]=go($data,acfg(),['CCC.KS'=>[PREP+1=>plan(PREP+1)]]);
$x=evs($j,'exit');
archeck($x[0]['reason']==='stop'&&$x[0]['ambiguous_bar']===true,'stop and target inside one later bar: the stop wins and the bar is marked ambiguous');
$data=mkdata(['CCC.KS'],PREP+10,['CCC.KS'=>[PREP+3=>[112,113,111,112]]]);
[$r,$j]=go($data,acfg(),['CCC.KS'=>[PREP+1=>plan(PREP+1)]]);
$x=evs($j,'exit');
archeck($x[0]['reason']==='target'&&abs($x[0]['price']-110)<1e-9,'a gap above the target on a later bar exits at the target');
archeck(PaperAccountReplay::reconcile($j,$data)['pass'],'gap and conflict trades reconcile');

// ---- holding period and no forced liquidation ----
$data=mkdata(['DDD.KS'],PREP+40);
[$r,$j]=go($data,acfg(),['DDD.KS'=>[PREP+1=>plan(PREP+1)]]);
$x=evs($j,'exit');$f=evs($j,'fill');
archeck($x[0]['reason']==='time'&&$x[0]['session']===tsOf(PREP+2+19)&&abs($x[0]['price']-100*0.9995)<1e-9,'twenty holding bars end with a time exit at the close less slippage');
$data=mkdata(['DDD.KS'],PREP+10);
[$r,$j]=go($data,acfg(),['DDD.KS'=>[PREP+1=>plan(PREP+1)]]);
$s=PaperAccountReplay::summary($j);
archeck(evs($j,'exit')===[]&&count($s['open_positions'])===1&&$s['closed_trades']===0&&abs($s['equity']-$j['state']['cash']-$s['open_positions'][0]['quantity']*100)<0.01,'a position still open at the last session is not liquidated; it is listed and marked');
archeck(abs($s['unrealized_pnl_open_positions'])>0&&PaperAccountReplay::reconcile($j,$data)['pass'],'open position has an unrealised result and the books reconcile');
$data=mkdata(['DDD.KS'],PREP+3);
[$r,$j]=go($data,acfg(),['DDD.KS'=>[PREP+2=>plan(PREP+2)]]);
$s=PaperAccountReplay::summary($j);
archeck(count($s['pending_orders'])===1&&$s['open_positions']===[]&&abs($j['state']['cash']-1e8)<1e-6,'an order placed on the last session stays pending: no later bar is invented');

// ---- limits, reservations, priority ----
$syms=['A1.KS','A2.KS','A3.KS','A4.KS','A5.KS'];
$data=mkdata($syms,PREP+12);$plans=[];foreach($syms as $s)$plans[$s]=[PREP+1=>plan(PREP+1)];
[$r,$j]=go($data,acfg(['risk_pct'=>0.004]),$plans);
$o=evs($j,'order');$sig=evs($j,'day_log')[1]['signals'];
$out=[];foreach($sig as $x)$out[$x['symbol']]=$x['outcome'];
archeck(count($o)===4&&array_column($o,'symbol')===['A1.KS','A2.KS','A3.KS','A4.KS']&&$out['A5.KS']==='position_limit','four positions at most; candidates are taken in symbol order (existing rule)');
[$r,$j]=go($data,acfg(),$plans);
$o=evs($j,'order');$sig=evs($j,'day_log')[1]['signals'];
$out=[];foreach($sig as $x)$out[$x['symbol']]=$x['outcome'];
archeck(count($o)===3&&$out['A4.KS']==='cash_or_risk_or_sector_limit'&&$out['A5.KS']==='cash_or_risk_or_sector_limit','the 3% total-risk limit stops a fourth 1%-risk order');
$sec=['sectors'=>['A1.KS'=>'semi','A2.KS'=>'semi','A3.KS'=>'semi','A4.KS'=>'bio','A5.KS'=>'bio']];
[$r,$j]=go($data,acfg(),$plans,['sector_map'=>$sec]);
$o=evs($j,'order');$byS=[];foreach($o as $x)$byS[$x['symbol']]=$x;
archeck(isset($byS['A3.KS'])&&$byS['A3.KS']['quantity']<$byS['A1.KS']['quantity']/5&&isset($byS['A4.KS']),'the 40% sector limit leaves only the remainder for a third position in one sector');
archeck($j['state']['research']['sector_source']==='explicit_map'&&PaperAccountReplay::reconcile($j,$data)['pass'],'an explicit sector map is recorded and reconciles');
$data2=mkdata(['A1.KS','A2.KS'],PREP+12);
$tiny=acfg(['initial_cash'=>100]);
[$r,$j]=go($data2,$tiny,['A1.KS'=>[PREP+1=>plan(PREP+1)]]);
$sig=evs($j,'day_log')[1]['signals'];
archeck(evs($j,'order')===[]&&$sig[0]['outcome']==='cash_or_risk_or_sector_limit','cash too small for one share: no order and a recorded reason');
// reservation: the first order reserves cash, so the second sees only what is left; freed proceeds are reusable the same session
$big=acfg(['position_pct'=>0.95,'sector_pct'=>1.0,'risk_pct'=>0.9,'total_risk_pct'=>1.0,'initial_cash'=>1000000]);
$data3=mkdata(['B1.KS','B2.KS','B3.KS'],PREP+14,['B1.KS'=>[PREP+3=>[100,111,99,110]]]);
[$r,$j]=go($data3,$big,['B1.KS'=>[PREP+1=>plan(PREP+1)],'B2.KS'=>[PREP+1=>plan(PREP+1)],'B3.KS'=>[PREP+3=>plan(PREP+3)]]);
$o=evs($j,'order');$byS=[];foreach($o as $x)$byS[$x['symbol']]=$x;$x=evs($j,'exit');
archeck(isset($byS['B2.KS'])&&$byS['B2.KS']['quantity']<$byS['B1.KS']['quantity']/5,'a second order the same session is sized from cash minus the first order\'s reservation');
archeck($x[0]['symbol']==='B1.KS'&&$x[0]['session']===$byS['B3.KS']['session']&&$byS['B3.KS']['quantity']>$byS['B2.KS']['quantity']*3,'proceeds of an exit are usable by a signal of the same session');
archeck(PaperAccountReplay::reconcile($j,$data3)['pass'],'reservation and reuse reconcile');
// same symbol cannot be bought twice while it is active
$data4=mkdata(['E1.KS'],PREP+12);
[$r,$j]=go($data4,acfg(),['E1.KS'=>[PREP+1=>plan(PREP+1),PREP+2=>plan(PREP+2),PREP+3=>plan(PREP+3)]]);
$dl=evs($j,'day_log');$outs=[];foreach($dl as $d)foreach($d['signals'] as $sg)$outs[]=$sg['outcome'];
archeck(count(evs($j,'order'))===1&&array_slice($outs,0,3)===['order','already_active','already_active'],'a second signal in a symbol that has an order or position is refused (already_active)');

// ---- data quality ----
$data5=mkdata(['Q1.KS','Q2.KS'],PREP+10,[],['Q1.KS'=>[PREP+2]]);
[$r,$j]=go($data5,acfg(),['Q1.KS'=>[PREP+1=>plan(PREP+1)]]);
$c=evs($j,'order_cancelled');
archeck(count($c)===1&&$c[0]['reason']==='data_quality'&&evs($j,'fill')===[]&&!$r['halted'],'a missing bar cancels a pending order instead of being interpolated');
$bl=evs($j,'day_log');$miss=null;foreach($bl as $d)if($d['session']===tsOf(PREP+2))$miss=$d;
archeck(isset($miss['quality_blocked']['missing_session'])&&in_array('Q1.KS',$miss['quality_blocked']['missing_session'],true),'the exclusion reason is logged for that day');
$data6=mkdata(['Q1.KS','Q2.KS'],PREP+10,[],['Q1.KS'=>[PREP+4]]);
[$r,$j]=go($data6,acfg(),['Q1.KS'=>[PREP+1=>plan(PREP+1)]]);
archeck($r['halted']&&$j['state']['halt_reason']==='unpriced_position'&&evs($j,'account_halted')!==[],'a held position with a missing bar halts the account (existing rule; no inferred execution)');
$after=array_filter($j['events'],fn($e)=>$e['type']==='equity'&&$e['payload']['session']>tsOf(PREP+4));
archeck($after===[]&&$j['state']['last_session']===tsOf(PREP+4),'nothing is processed after a halt');

// ---- rerun, resume, future bars ----
$data7=mkdata(['R1.KS','R2.KS','R3.KS'],PREP+40,['R2.KS'=>[PREP+6=>[96,97,90,92]],'R1.KS'=>[PREP+9=>[100,112,99,111]]]);
$plans7=['R1.KS'=>[PREP+1=>plan(PREP+1),PREP+14=>plan(PREP+14)],'R2.KS'=>[PREP+3=>plan(PREP+3)],'R3.KS'=>[PREP+20=>plan(PREP+20)]];
$cfgA=acfg();
[$r,$whole]=go($data7,$cfgA,$plans7);
$cfgB=acfg();$pB=jpath($cfgB['id']);
$steps=['2025-03-20','2025-04-02','2025-04-22'];$hist=[];
foreach($steps as $k=>$th){
    $r=PaperAccountReplay::run($data7,$cfgB,$pB,['plan_provider'=>provider($plans7),'chunk'=>$k+3,'through'=>$th]);$hist[]=$r['last_day'];
}
$r=PaperAccountReplay::run($data7,$cfgB,$pB,['plan_provider'=>provider($plans7),'chunk'=>50]);
$resumed=(new PaperJournal($pB))->read();
$na=PaperAccountReplay::normalized($whole);$nb=PaperAccountReplay::normalized($resumed);
$ea=$na['events'];$eb=$nb['events'];
unset($ea[0]['payload']['config']['id'],$eb[0]['payload']['config']['id']);
archeck($ea===$eb&&count($whole['events'])>20,'a run interrupted three times and resumed gives the same events as one run ('.count($whole['events']).' events)');
$sa=$whole['state'];$sb=$resumed['state'];
foreach([&$sa,&$sb] as &$st){unset($st['config']['id']);unset($st['config_hash']);}
archeck(PaperJournal::encode($sa)===PaperJournal::encode($sb),'and the same final state');
unset($st);
$before=hash_file('sha256',$pB);$cnt=count($resumed['events']);
$r=PaperAccountReplay::run($data7,$cfgB,$pB,['plan_provider'=>provider($plans7),'chunk'=>5]);
archeck($r['processed_sessions']===0&&hash_file('sha256',$pB)===$before&&count((new PaperJournal($pB))->read()['events'])===$cnt,'running the same dates again adds no event and leaves the file byte-identical');
$rr=PaperAccountReplay::run($data7,$cfgB,$pB,['plan_provider'=>provider($plans7),'through'=>'2025-03-01']);
archeck($rr['processed_sessions']===0&&hash_file('sha256',$pB)===$before,'asking for an earlier through-date does not rewrite history');
$trades=PaperAccountReplay::trades($whole);$orders=count(evs($whole,'order'));
archeck(count($trades)===$orders&&PaperAccountReplay::reconcile($whole,$data7)['pass']&&PaperAccountReplay::reconcile($resumed,$data7)['pass'],'resumed and one-shot journals both reconcile');

// future bars cannot change anything before them
$cut=PREP+25;$through=PaperHistoryResearch::day(tsOf($cut));
$dA=mkdata(['R1.KS','R2.KS'],PREP+40,['R2.KS'=>[PREP+6=>[96,97,90,92]]]);
$dB=mkdata(['R1.KS','R2.KS'],PREP+40,['R2.KS'=>[PREP+6=>[96,97,90,92]],'R1.KS'=>[$cut+3=>[1,2,0.5,1.5],$cut+5=>[500,900,400,800]]]);
$dC=mkdata(['R1.KS','R2.KS'],$cut+1,['R2.KS'=>[PREP+6=>[96,97,90,92]]]);
$pl=['R1.KS'=>[PREP+1=>plan(PREP+1)],'R2.KS'=>[PREP+3=>plan(PREP+3)]];
$hs=[];
foreach(['A'=>$dA,'B'=>$dB,'C'=>$dC] as $k=>$dd){
    $cf=acfg();[$r,$jj]=go($dd,$cf,$pl,['through'=>$through]);
    $e=PaperAccountReplay::normalized($jj)['events'];unset($e[0]['payload']['config']['id']);
    foreach($e as &$ev)if($ev['type']==='account_started')unset($ev['payload']['research']['as_of_day'],$ev['payload']['research']['bars_sha256'],$ev['payload']['research']['dataset_files']);
    unset($ev);$hs[$k]=hash('sha256',PaperJournal::encode($e));
}
archeck($hs['A']===$hs['B']&&$hs['B']===$hs['C'],'adding or rewriting bars after the through-date leaves every earlier recommendation, order and ledger entry unchanged');
// the real engine path with a real fixture: future bars do not move the signal
$rb=recovery_bars();$sym='005930.KS';$sig=end($rb)['available_at'];$fut=$rb;
$extra=end($rb);$extra['available_at']+=86400;$extra['time']=$extra['available_at']-23400;$extra['high']=9999;$extra['low']=1;$extra['close']=5000;$fut[]=$extra;
$mk=function(array $rows)use($sym){foreach($rows as &$r){$r['available_at']=CandleClock::closeTime($r,$sym);}unset($r);
    return ['dataset'=>'fixture','dir'=>'','start_day'=>PaperHistoryResearch::day($rows[count($rows)-3]['available_at']),'as_of_day'=>PaperHistoryResearch::day(end($rows)['available_at']),
        'as_of_ts'=>end($rows)['available_at'],'symbols'=>[$sym=>['name'=>'fixture','rows'=>$rows,'bars_sha256'=>hash('sha256',PaperJournal::encode($rows))]],'excluded'=>[],'files'=>['dataset.json'=>'x','universe.json'=>'y']];};
$e1=acfg();$e2=acfg();
$r1=PaperAccountReplay::run($mk($rb),$e1,jpath($e1['id']),['chunk'=>5]);
$r2=PaperAccountReplay::run($mk($fut),$e2,jpath($e2['id']),['chunk'=>5,'through'=>PaperHistoryResearch::day($sig)]);
$g1=(new PaperJournal(jpath($e1['id'])))->read();$g2=(new PaperJournal(jpath($e2['id'])))->read();
$snap1=$g1['state']['last_snapshots'][$sym];$snap2=$g2['state']['last_snapshots'][$sym];
archeck($g1['state']['research']['plan_source']==='engine'&&$snap1['input_hash']===$snap2['input_hash']&&PaperJournal::encode($snap1['plan'])===PaperJournal::encode($snap2['plan']),'the real engine sees only bars up to the signal day (same plan and input hash with or without later bars)');

// ---- refusing a changed setup ----
$cfgC=acfg();$pC=jpath($cfgC['id']);
PaperAccountReplay::run($data7,$cfgC,$pC,['plan_provider'=>provider($plans7),'through'=>'2025-03-20']);
$changed=$data7;$changed['symbols']['R1.KS']['bars_sha256']=str_repeat('a',64);
archeck(arthrows(fn()=>PaperAccountReplay::run($changed,$cfgC,$pC,['plan_provider'=>provider($plans7)]),'Research input changed'),'a changed bars file is refused on resume');
archeck(arthrows(fn()=>PaperAccountReplay::run($data7,$cfgC,$pC,[]),'Research input changed'),'injected-test and engine runs cannot be mixed in one account');
$jc=(new PaperJournal($pC))->read();
archeck(array_keys($jc['state']['research']['code_sha256_lf'])===PaperAccountReplay::RUNTIME_CODE&&$jc['state']['research']['code_sha256_lf']===PaperAccountReplay::runtimeCodeHashes(),'the journal records the hashes of the replay code and configs it was started with');
archeck(arthrows(fn()=>PaperAccountReplay::run($data7,array_replace($cfgC,['risk_pct'=>0.02]),$pC,['plan_provider'=>provider($plans7)]),'config changed'),'a changed account config is refused');
archeck(arthrows(fn()=>PaperAccountReplay::run($data7,acfg(),jpath('hist-smoke-kr-recovery-v1-zz'),['plan_provider'=>provider($plans7),'start'=>'2000-01-01']),'precedes'),'a start before the evaluation start is refused');

// ---- only the research account is written ----
$state=$tmp.'/guard';mkdir($state.'/history-research/ds',0770,true);
file_put_contents($state.'/paper-kr-recovery-v1-forward.json','{"operational":true}');file_put_contents($state.'/history-research/ds/dataset.json','{"x":1}');
$t1=PaperAccountReplay::topLevelHash($state);$t2=PaperAccountReplay::treeHash($state.'/history-research/ds');
$cfgG=acfg();
PaperAccountReplay::run($data7,$cfgG,PaperAccountReplay::journalPath(PaperAccountReplay::accountDir($state,$research,$cfgG['id'])),['plan_provider'=>provider($plans7),'chunk'=>9]);
archeck($t1===PaperAccountReplay::topLevelHash($state)&&$t2===PaperAccountReplay::treeHash($state.'/history-research/ds'),'operational account files and the dataset folder are byte-identical after a run');
$written=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($state,FilesystemIterator::SKIP_DOTS)) as $fl)if($fl->isFile())$written[]=substr(str_replace('\\','/',$fl->getPathname()),strlen($state)+1);
sort($written);
$new=array_values(array_filter($written,fn($p)=>str_starts_with($p,'history-account/')));
archeck(count($new)>=1&&count(array_filter($new,fn($p)=>str_contains($p,$cfgG['id'])))===count($new)&&count($written)-count($new)===2,'the only new files are inside the research account folder');

// ---- the command line refuses a full run before review and unsafe combinations ----
$cli=function(string $args)use($root):array{
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/paper_account_replay.php').' '.$args.' 2>&1';
    exec($cmd,$lines,$code);return [$code,implode("\n",$lines)];
};
[$code,$msg]=$cli('run --period=prior --dataset-dir=nowhere');
archeck($code!==0&&str_contains($msg,'held until the code review'),'a full-period run is refused until the code review is confirmed');
[$code,$msg]=$cli('run --period=prior --dataset-dir=nowhere --confirm-full-run=after-code-review');
archeck($code!==0&&str_contains($msg,'sector map'),'even a confirmed full run needs an explicit sector choice');
[$code,$msg]=$cli('run --period=prior --start=2024-10-08 --dataset-dir=nowhere');
archeck($code!==0&&str_contains($msg,'only for smoke'),'a custom start is only for smoke accounts');
[$code,$msg]=$cli('run --period=prior --smoke-label=zz --dataset-dir=nowhere');
archeck($code!==0&&str_contains($msg,'--start and --through'),'smoke accounts must state their window');

// ---- the checks themselves detect errors ----
$bad=$whole;foreach($bad['events'] as &$ev)if($ev['type']==='fill'){$ev['payload']['price']+=1;break;}unset($ev);
archeck(!PaperAccountReplay::reconcile($bad,$data7)['pass'],'reconciliation fails when a fill price in the journal is altered');
$bad=$whole;$bad['state']['cash']+=500;
archeck(!PaperAccountReplay::reconcile($bad,$data7)['pass'],'reconciliation fails when the stored cash differs from the events');
$bad=$whole;foreach($bad['events'] as &$ev)if($ev['type']==='exit'){$ev['payload']['price']*=0.9;break;}unset($ev);
archeck(!PaperAccountReplay::reconcile($bad,$data7)['pass'],'reconciliation fails when an exit differs from the independent simulator');
$raw=file_get_contents($pB);$tam=preg_replace('/"price":(\d)/','"price":9$1',$raw,1,$cnt);file_put_contents($pB,$tam);
archeck($cnt===1&&arthrows(fn()=>(new PaperJournal($pB))->read(),'integrity'),'a tampered journal file fails its hash chain');
echo "ALL PASS\n";
