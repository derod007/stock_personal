<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/DatedTop100Prep.php';

function pcheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('FAIL '.$why);echo "PASS $why\n";}

$root=dirname(__DIR__);
pcheck(PaperDatedTop100Prep::EVAL_START==='2025-10-08'&&PaperDatedTop100Prep::AS_OF==='2026-10-08','evaluation window is the requested range');
pcheck(PaperHistoryResearch::PREP_BARS===240&&PaperHistoryResearch::MIN_BARS===60,'prep window is MA240 / quality 60 from engine code');
pcheck(PaperDatedTop100Prep::DATASET!==PaperDatedTop100Replay::DATASET,'year dataset id does not overwrite Oct 2025');

$tmp=sys_get_temp_dir().'/dated-top100-prep-'.getmypid();
@mkdir($tmp,0770,true);
$rows=[];
for($i=1;$i<=100;$i++)$rows[]=['rank'=>$i,'code'=>sprintf('%06d',$i),'symbol'=>sprintf('%06d.KS',$i),'name'=>'n'.$i,'market'=>'KOSPI','amount_won'=>1000-$i];
$payload=['schema'=>1,'kind'=>'dated_krx_amount_top100_candidates_v1','days'=>['2025-10-10','2026-10-08'],
    'dates'=>['2025-10-10'=>['n'=>100,'rows'=>$rows],'2026-10-08'=>['n'=>100,'rows'=>$rows]]];
$path=$tmp.'/candidates.json';
file_put_contents($path,json_encode($payload));
$c=PaperDatedTop100Prep::loadCandidates($path);
pcheck(count($c['days'])===2&&count($c['union'])===100,'prep loader accepts a range that is not 18/305');
pcheck($c['by_session'][PaperDatedTop100Prep::sessionClose('2025-10-10')]['symbols'][0]==='000001.KS','session map uses that day TOP100');

echo "OK dated top100 prep tests\n";
