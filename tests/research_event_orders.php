<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/docs/research/ResearchEventOrders.php';
use ChartEntryLab\PaperPortfolio;
$n=0;
function ok(bool $x,string $m):void{global $n;$n++;if(!$x)throw new RuntimeException($m);}
function rejects(callable $f,string $m):void{try{$f();}catch(InvalidArgumentException $e){ok(true,$m);return;}ok(false,$m);}
function ts(string $s):int{return (new DateTimeImmutable($s,new DateTimeZone('Asia/Seoul')))->getTimestamp();}
$cfg=['id'=>'research-event-fixture','currency'=>'KRW','initial_cash'=>10000,'max_positions'=>4,'risk_pct'=>.01,'position_pct'=>.2,'sector_pct'=>.4,'total_risk_pct'=>.03,'symbols'=>['TEST.KS'=>'test']];
$s=PaperPortfolio::start($cfg,'fixture','replay');$s['last_session']=ts('2020-01-06 15:30:00');
$s['active']=['TEST.KS'=>['filled'=>false,'reservation'=>2000.,'recorded_at'=>$s['last_session']],
 'OTHER.KS'=>['filled'=>false,'reservation'=>1000.,'recorded_at'=>$s['last_session']]];
$cut=ts('2020-01-07 08:59:00');$open=ts('2020-01-07 09:00:00');
$e=['id'=>'synthetic-1','symbol'=>'TEST.KS','kind'=>'rights_issue','confirmed'=>true,'published_at'=>ts('2020-01-06 16:00:00'),'available_at'=>ts('2020-01-06 16:05:00'),'effective_open'=>$open];
$r=ResearchEventOrders::apply($s,[$e],$cut,$open);
ok(count($r['events'])===1,'one cancellation');ok($r['released']===2000.,'reservation released');ok($r['reserved_after']===1000.,'unrelated reservation retained');ok($r['state']['cash']===10000.,'cash not credited twice');ok(isset($s['active']['TEST.KS']),'input untouched');
$r2=ResearchEventOrders::apply($r['state'],[$e],$cut,$open);ok($r2['events']===[]&&$r2['released']===0.,'idempotent');
foreach(['available_at','effective_open','confirmed'] as $field){$x=$e;$x[$field]=match($field){'available_at'=>$open,'effective_open'=>$open+86400,'confirmed'=>false};$r=ResearchEventOrders::apply($s,[$x],$cut,$open);ok($r['events']===[],$field.' does not cancel');}
$x=$e;$x['available_at']=$cut;ok(count(ResearchEventOrders::apply($s,[$x],$cut,$open)['events'])===1,'exact availability cutoff');
$x=$e;$x['published_at']=$open;$x['available_at']=$open;ok(ResearchEventOrders::apply($s,[$x],$cut,$open)['events']===[],'future publication cannot cancel');
$held=$s;$held['active']['TEST.KS']['filled']=true;$r=ResearchEventOrders::apply($held,[$e],$cut,$open);ok(!$r['can_advance']&&count($r['held_requires_handler'])===1,'held requires separate handler');ok($r['state']===$held,'held unchanged');
$x=$e;$x['id']='synthetic-2';$r=ResearchEventOrders::apply($s,[$e,$x],$cut,$open);ok(count($r['events'])===1&&count($r['events'][0]['event_ids'])===2,'multiple events one release');
rejects(fn()=>ResearchEventOrders::apply($s,[$e,$e],$cut,$open),'duplicate rejected');
$x=$e;unset($x['available_at']);rejects(fn()=>ResearchEventOrders::apply($s,[$e,$x],$cut,$open),'missing timestamp atomic reject');
$x=$e;$x['available_at']=$x['published_at']-1;rejects(fn()=>ResearchEventOrders::apply($s,[$x],$cut,$open),'availability ordering');
rejects(fn()=>ResearchEventOrders::apply($s,[$e],$open,$open),'no retrospective preopen');
$x=$s;$x['config']['id']='operating';rejects(fn()=>ResearchEventOrders::apply($x,[$e],$cut,$open),'operating blocked');
$x=$s;$x['mode']='forward';rejects(fn()=>ResearchEventOrders::apply($x,[$e],$cut,$open),'forward blocked');
// Actual engine: canceled pending order must not enter execution, with no new signal.
$r=ResearchEventOrders::apply($s,[$e],$cut,$open);$next=$r['state'];unset($next['active']['OTHER.KS']);$seen=[];
PaperPortfolio::advance($next,ts('2020-01-07 15:30:00'),[],[],function($type,$p)use(&$seen){$seen[]=$type;});
ok(!in_array('fill',$seen,true)&&$next['cash']===10000.,'engine does not fill canceled order');
echo "OK $n checks (synthetic only)\n";
