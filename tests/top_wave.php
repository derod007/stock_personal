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
ck(run($peak)['status']!=='released','peak recovery alone cannot release');
$release=fixture([120,110,135,120,150,130,149,135,125,118,155,130,145]);$released=run($release);
ck($released['status']==='released'&&!$released['reduce_candidate'],'peak break followed by confirmed higher low releases');
$future=(new TopWaveReview())->analyze($release,'005930.KS',end($broken)['available_at']);
ck($future['events']===$b['events']&&$future['setup']===$b['setup'],'future appended bars cannot alter past decisions');
$more=fixture([110,104,120,110,135,120,150,130,149,135]);
ck(run($more)['setup']['high_count']===4,'four rising highs counted without exactly-three restriction');
$bad=$broken;$bad[]=$bad[65];ck(run($bad)['status']==='data_quality','duplicate price bar blocks display');
$old=(new TopWaveReview())->analyze($broken,'005930.KS',end($broken)['available_at']+5*86400);
ck($old['status']==='stale_data'&&!$old['reduce_candidate'],'stale inputs never live reduce');
$state=['active'=>['005930.KS'=>['filled'=>true],'WAIT'=>['filled'=>false]],'history'=>['005930.KS'=>$broken],'last_session'=>end($broken)['available_at']];
$before=serialize($state);$rows=paper_top_wave_rows($state,$state['last_session']);
ck(count($rows)===1&&$rows['005930.KS']['reduce_candidate'],'held only; pending order excluded');
$halted=$state;$halted['halted']=true;ck(!paper_top_wave_rows($halted,$state['last_session'])['005930.KS']['reduce_candidate'],'halted account does not show live advice');
ob_start();paper_top_wave_panel($state,$state['last_session']);$html=ob_get_clean();
ck(str_contains($html,'보유 축소 후보')&&str_contains($html,'119.00'),'panel displays status and evidence');
ck(serialize($state)===$before,'presentation cannot mutate state');
$root=sys_get_temp_dir().'/top-review-'.bin2hex(random_bytes(4));mkdir($root);
try{
 $path=$root.'/test-replay.json';(new PaperJournal($path))->transact(function(&$s,$emit)use($state){$s=$state;$emit('fixture',[]);});
 $hash=hash_file('sha256',$path);putenv('PAPER_STATE_DIR='.$root);
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/paper_top_wave.php').' --account=test --mode=replay',$output,$code);
 $json=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
 ck($code===0&&$json['rows']['005930.KS']['reduce_candidate'],'CLI reads saved held history');
 ck(hash_file('sha256',$path)===$hash,'CLI preserves operational journal bytes');
}finally{putenv('PAPER_STATE_DIR');foreach(glob($root.'/*') as $p)unlink($p);rmdir($root);}
