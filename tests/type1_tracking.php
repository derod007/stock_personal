<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';foreach(['Classifier','Tracker','Store','Collector','TrackingView'] as $f)require __DIR__.'/../bin/type1/'.$f.'.php';
use ChartEntryLab\Type1Scan\{Classifier,Tracker,Store,Collector};
$checks=0;function check($v,$label){global $checks;$checks++;if(!$v)throw new RuntimeException($label);}function rejects($fn,$label){try{$fn();}catch(Throwable $e){check(true,$label);return;}check(false,$label);}
$c=new Classifier(__DIR__.'/../data/type1/centers.json');$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/type1-synthetic.json'),true);$bars=$fixtures[1]['bars'];
$day='2026-10-23';$days=[$day];$d=new DateTimeImmutable($day);while(count($days)<23){$d=$d->modify('+1 day');if($d->format('N')<6)$days[]=$d->format('Y-m-d');}
function snap($i,$bars,$leaders=null){global $days;return ['day'=>$days[$i],'as_of'=>strtotime($days[$i].' 20:20:00 +09:00'),'days'=>array_slice($days,0,$i+1),'leaders'=>$leaders??[['yahoo'=>'000001.KS','name'=>'<script>','rank'=>1,'amount_won'=>100]],'prices'=>['000001.KS'=>$bars]];}
function candle($day,$o=100,$h=101,$l=99,$cl=100){return ['time_kst'=>$day.' 09:00:00','open'=>$o,'high'=>$h,'low'=>$l,'close'=>$cl,'volume'=>100,'is_complete'=>true];}
check($c->classify($bars,'0007C0.KQ',strtotime('2026-10-23 20:20:00 +09:00'))['matches'],'alphanumeric code');
$start=Tracker::advance(Tracker::empty(),snap(0,$bars),$c);check(count($start['trades'])===1,'new');$key=array_key_first($start['trades']);
$one=[...$bars,candle($days[1],100,116,94)];$closed=Tracker::advance($start,snap(1,$one,[]),$c);$t=$closed['trades'][$key];check($t['exit']['reason']==='stop'&&$t['same_bar_order_unknown'],'same bar');check(abs($t['net_pct']-(95*.9985/(100*1.0015)-1)*100)<1e-10,'cost');
$gap=Tracker::advance($start,snap(2,[...$bars,candle($days[1]),candle($days[2],90,92,88,90)],[]),$c);check($gap['trades'][$key]['exit']['price']===90,'gap');
$missing=Tracker::advance($start,snap(2,[...$bars,candle($days[2],100,120,99)],[]),$c);check($missing['trades'][$key]['status']==='unknown'&&$missing['trades'][$key]['entry']===null,'no missing next open shift');
$recover=Tracker::advance($missing,snap(3,[...$bars,candle($days[1]),candle($days[2],100,120,99),candle($days[3])],[]),$c);check($recover['trades'][$key]['exit']['day']===$days[2],'gap recovery');
$future=Tracker::advance(Tracker::empty(),snap(0,[...$bars,candle($days[2],100,9999,1)]),$c);check($future['trades']===$start['trades'],'future ignored');
$repeat=Tracker::advance($start,snap(1,[...$bars,candle($days[1])]),$c);check(count($repeat['trades'])===1&&$repeat['captures'][$days[1]]['rows'][0]['episode_state']==='observing','repeat');
$path=$bars;for($i=1;$i<=20;$i++)$path[]=candle($days[$i]);$expiry=Tracker::advance($start,snap(20,$path,[]),$c);check($expiry['trades'][$key]['exit']['day']===$days[20]&&$expiry['trades'][$key]['exit']['reason']==='time','T20 outside top100');
$at20=Tracker::advance($start,snap(20,$path),$c);check($at20['captures'][$days[20]]['rows'][0]['episode_state']==='observing','inclusive20');$at21=Tracker::advance($at20,snap(21,[...$path,candle($days[21])]),$c);check($at21['captures'][$days[21]]['rows'][0]['episode_state']==='new','new21');
$non=Tracker::advance(Tracker::empty(),snap(0,$fixtures[0]['bars']),$c);check(count($non['trades'])===0,'nonmatch');$later=Tracker::advance($non,snap(1,[...$bars,candle($days[1])]),$c);check(count($later['trades'])===0,'nonmatch consumes episode');
$bad=Tracker::advance(Tracker::empty(),snap(0,[]),$c);check($bad['captures'][$day]['rows'][0]['error']!==null&&isset($bad['episodes']['000001.KS']),'unavailable retained');
$pre=snap(0,$bars);$pre['as_of']=strtotime($day.' 15:29:00 +09:00');rejects(fn()=>Tracker::advance(Tracker::empty(),$pre,$c),'intraday');$changed=snap(1,$one);$changed['days']=['2026-10-22',$days[1]];rejects(fn()=>Tracker::advance($start,$changed,$c),'calendar change');
$dup=snap(0,$bars);$dup['leaders'][]=$dup['leaders'][0];rejects(fn()=>Tracker::advance(Tracker::empty(),$dup,$c),'duplicate');
$tmp=sys_get_temp_dir().'/type1-track-'.bin2hex(random_bytes(5));mkdir($tmp);try{
$r=Store::update($tmp,fn($s)=>snap(0,$bars),$c);check($r['status']==='saved','store');$hash=hash_file('sha256',$tmp.'/latest.json');$r=Store::update($tmp,fn($s)=>snap(0,$bars),$c);check($r['status']==='unchanged'&&hash_file('sha256',$tmp.'/latest.json')===$hash,'idempotent');
Store::atomic($tmp.'/snapshot-'.$days[1].'.json',snap(1,$one,[]));Store::update($tmp,fn($s)=>null,$c);check(Store::load($tmp)['last_day']===$days[1],'crash recovery');
$rev=Tracker::advance($closed,snap(2,[...$bars,candle($days[1],100,120,99)],[]),$c);check($rev['trades'][$key]===$closed['trades'][$key],'closed immutable');
ob_start();\ChartEntryLab\Type1Scan\renderTracking($start);$html=ob_get_clean();check(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'escaped');check(str_contains($html,'거래일이 없어'),'rank uncertainty');
}finally{foreach(glob($tmp.'/*') as $f)unlink($f);rmdir($tmp);}
$no=fn()=>throw new RuntimeException('must not call');check(Collector::capture(Tracker::empty(),$no,$no,$no,strtotime('2026-10-25 20:20:00 +09:00'))===null,'weekend');check(Collector::capture(Tracker::empty(),$no,$no,fn($d)=>['2026-10-22'],strtotime('2026-10-23 20:20:00 +09:00'))===null,'stale calendar');
$target=Tracker::advance($start,snap(1,[...$bars,candle($days[1],100,116,99)],[]),$c);check($target['trades'][$key]['exit']['reason']==='target','target only');
$prefix=Tracker::advance($start,snap(1,[...$bars,candle($days[1])],[]),$c);
$revision=Tracker::advance($prefix,snap(2,[...$bars,candle($days[1],100,200,1),candle($days[2])],[]),$c);
check($revision['trades'][$key]['status']==='open','later revision does not rewrite frozen bar');
$stale=$bars;array_shift($stale);array_pop($stale);check(!Tracker::advance(Tracker::empty(),snap(0,$stale),$c)['trades'],'insufficient history not replaced');
$xml=static fn($symbol,$date)=>'<chartdata symbol="'.$symbol.'" timeframe="day"><item data="'.$date.'|1|1|1|1|1"/></chartdata>';
check(Collector::calendar($xml('KOSPI','20261023'),$xml('KOSDAQ','20261023'),'2026-10-23')===['2026-10-23'],'calendar parse');
rejects(fn()=>Collector::calendar($xml('KOSPI','20261023'),$xml('KOSDAQ','20261022'),'2026-10-23'),'calendar mismatch');
echo "PASS $checks tracking checks\n";
