<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/TopWavePanel.php';
use ChartEntryLab\TopWaveReview;
use ChartEntryLab\PaperJournal;
function ck(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "OK $s\n";}
function fixture(array $points):array{
 $out=[];$start=new DateTimeImmutable('2025-01-02 15:30:00',new DateTimeZone('Asia/Seoul'));
 $values=array_fill(0,60,100.0);$last=100.0;
 foreach($points as $p){for($i=1;$i<=4;$i++)$values[]=$last+($p-$last)*$i/4;$last=$p;}
 foreach($values as $i=>$c){$t=$start->modify('+'.$i.' weekdays')->getTimestamp();$out[]=['available_at'=>$t,'time'=>$t,'open'=>$c,'high'=>$c+1,'low'=>$c-1,'close'=>$c,'volume'=>1000];}
 return $out;
}
function run(array $b):array{return (new TopWaveReview())->analyze($b,'005930.KS',end($b)['available_at']);}
$bars=fixture([120,110,135,120,150,130,149,135]);$r=run($bars);
ck($r['status']==='retry_failed'&&!$r['reduce_candidate'],'failed retry alone never reduces');
ck($r['setup']['floor']===119.0,'L2 between H2 and H3 is frozen');
ck($r['setup']['high_count']===3&&$r['setup']['higher_high_breaks']===2,'three rising highs distinguished from break count');
ck($r['setup']['retry']['confirmed_at']>$r['setup']['retry']['pivot_at'],'pivot confirmation never backdated');
$pre=array_slice($bars,0,88);ck(run($pre)['setup']===null,'retest pivot not confirmed too early');
$one=fixture([150,130,149,120]);ck(run($one)['setup']===null,'one high then failure is insufficient');
$touch=$bars;$touch[count($touch)-1]['low']=118;$touch[count($touch)-1]['close']=122;$touch[count($touch)-1]['open']=123;
ck(run($touch)['status']==='support_test'&&!run($touch)['reduce_candidate'],'wick break with recovered close only support test');
$equal=$bars;$equal[count($equal)-1]=array_replace(end($equal),['open'=>120,'high'=>122,'low'=>118,'close'=>119]);
ck(!run($equal)['reduce_candidate'],'close equal L2 not breach');
$broken=fixture([120,110,135,120,150,130,149,135,125,118]);$b=run($broken);
ck($b['status']==='reduce_candidate'&&$b['reduce_candidate'],'close below L2 reduces');
ck($b['setup']['floor']===119.0,'lower prices never drag L2 down');
$recover=fixture([120,110,135,120,150,130,149,135,125,118,125]);
ck(run($recover)['status']==='recovery_watch'&&!run($recover)['reduce_candidate'],'L2 recovery only watch');
$peak=fixture([120,110,135,120,150,130,149,135,125,118,155]);
ck(run($peak)['status']==='peak_reclaimed'&&!run($peak)['reduce_candidate'],'peak recovery shown while higher low pending');
$release=fixture([120,110,135,120,150,130,149,135,125,118,155,130,145]);$released=run($release);
ck($released['status']==='released'&&!$released['reduce_candidate'],'peak break followed by confirmed higher low releases');
$future=(new TopWaveReview())->analyze($release,'005930.KS',end($broken)['available_at']);
ck($future['events']===$b['events']&&$future['setup']===$b['setup'],'future appended bars cannot alter past decisions');
$more=fixture([110,104,120,110,135,120,150,130,149,135]);
ck(run($more)['setup']['high_count']===4,'four rising highs counted without exactly-three restriction');
$bad=$broken;$bad[]=$bad[65];ck(run($bad)['status']==='data_quality','duplicate price bar blocks display');
$old=(new TopWaveReview())->analyze($broken,'005930.KS',end($broken)['available_at']+5*86400);
ck($old['status']==='reduce_candidate'&&$old['reduce_candidate']&&$old['freshness']['status']==='unverified','holiday elapsed days preserve dated chart verdict without freshness claim');

$lower=fixture([120,110,135,120,150,110,149,135]);
ck(run($lower)['status']==='invalid_structure'&&!run($lower)['reduce_candidate'],'L3 below L2 excluded from valid structure');
$level=fixture([120,110,135,120,150,120,149,135]);
ck(run($level)['status']==='retry_failed','L3 equal L2 accepted');
$prior=$bars;$prior[88]=array_replace($prior[88],['open'=>120,'high'=>121,'low'=>117,'close'=>118]);
ck(run($prior)['status']==='preexisting_breach'&&!run($prior)['reduce_candidate'],'preconfirmation breach stays separate after recovery');
$same=$broken;$same[89]=array_replace($same[89],['open'=>120,'high'=>121,'low'=>117,'close'=>118]);
ck(run(array_slice($same,0,90))['status']==='same_day_breach','confirmation day breach separate');
ck(run($same)['status']==='same_day_breach'&&!run($same)['reduce_candidate'],'ineligible episode never promoted by later breach');
$first=$bars;$first[90]=array_replace($first[90],['open'=>120,'high'=>121,'low'=>117,'close'=>118]);
ck(run(array_slice($first,0,91))['status']==='reduce_candidate','first bar after confirmation can qualify');

$state=['active'=>['005930.KS'=>['filled'=>true],'WAIT'=>['filled'=>false]],'history'=>['005930.KS'=>$broken],'last_session'=>end($broken)['available_at']];
$before=serialize($state);$rows=paper_top_wave_rows($state,$state['last_session']);
ck(count($rows)===1&&$rows['005930.KS']['reduce_candidate'],'held only; pending order excluded');
$halted=$state;$halted['halted']=true;ck(!paper_top_wave_rows($halted,$state['last_session'])['005930.KS']['reduce_candidate'],'halted account does not show live advice');
ob_start();paper_top_wave_panel($state,$state['last_session']);$html=ob_get_clean();
ck(str_contains($html,'보유 축소 후보')&&str_contains($html,'119.00'),'panel displays status and evidence');
ck(serialize($state)===$before,'presentation cannot mutate state');

$gap=$state;$gap['history']['000660.KS']=$recover;
$gapRows=paper_top_wave_rows($gap,end($recover)['available_at']);
ck($gapRows['005930.KS']['freshness']['status']==='missing_observed_session'&&$gapRows['005930.KS']['status']==='reduce_candidate','missing observed market session separate from chart verdict');
$other=$state;$other['history']['AAPL']=$recover;
ck(paper_top_wave_rows($other,end($recover)['available_at'])['005930.KS']['freshness']['status']==='unverified','US observation cannot mark KR data missing');
ck(paper_top_wave_rows($gap,$state['last_session'])['005930.KS']['freshness']['status']==='unverified','future observations excluded from historical freshness');
$peakState=$state;$peakState['history']['005930.KS']=$peak;
ob_start();paper_top_wave_panel($peakState,end($peak)['available_at']);$peakHtml=ob_get_clean();
ck(str_contains(explode('<details>',$peakHtml)[0],'전고점 회복 · 높은 저점 확인 대기'),'peak recovery visible outside evidence details');

$root=sys_get_temp_dir().'/top-review-'.bin2hex(random_bytes(4));mkdir($root);
try{
 $path=$root.'/test-replay.json';(new PaperJournal($path))->transact(function(&$s,$emit)use($state){$s=$state;$emit('fixture',[]);});
 $hash=hash_file('sha256',$path);putenv('PAPER_STATE_DIR='.$root);
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/paper_top_wave.php').' --account=test --mode=replay',$output,$code);
 $json=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
 ck($code===0&&$json['rows']['005930.KS']['reduce_candidate'],'CLI reads saved held history');
 ck(hash_file('sha256',$path)===$hash,'CLI preserves operational journal bytes');
}finally{putenv('PAPER_STATE_DIR');foreach(glob($root.'/*') as $p)unlink($p);rmdir($root);}
