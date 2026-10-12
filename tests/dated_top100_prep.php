<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/DatedTop100Prep.php';

function pcheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('FAIL '.$why);echo "PASS $why\n";}

$root=dirname(__DIR__);
pcheck(PaperDatedTop100Prep::classifyKrxCode('005930')==='six_digit','six-digit codes stay six_digit');
pcheck(PaperDatedTop100Prep::classifyKrxCode('00088K')==='preferred_or_class_letter_suffix','preferred letter suffix is classified without calling it invalid');
pcheck(PaperDatedTop100Prep::classifyKrxCode('0001A0')==='alphanumeric_short_code','alphanumeric short codes are not treated as invalid listings');
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

$kst=new DateTimeZone('Asia/Seoul');
$bar=static function(string $day,float $close)use($kst):array{
    $ts=(new DateTimeImmutable($day.' 15:30:00',$kst))->getTimestamp();
    return ['time'=>$ts,'open'=>$close,'high'=>$close,'low'=>$close,'close'=>$close,'volume'=>1000,'available_at'=>$ts];
};
$pre=[];
$d=new DateTimeImmutable('2025-10-09',$kst);
while(count($pre)<80){
    if((int)$d->format('N')<6)$pre[]=$bar($d->format('Y-m-d'),1000.0);
    $d=$d->modify('-1 day');
}
$rows=array_reverse($pre);
$rows[]=$bar('2025-10-10',1000.0);
$rows[]=$bar('2025-10-13',2000.0); // +100% jump on the next session, calendar gap under 10 days
$state=[
    '000001.KS'=>['status'=>'included','state'=>'done','rows'=>$rows,'bars_sha256'=>str_repeat('a',64),'name'=>'n1'],
    '000002.KS'=>['status'=>'provider_symbol_unmapped','state'=>'missing','rows'=>[],'bars_sha256'=>null,'name'=>'letter'],
    '000003.KS'=>['status'=>'included','state'=>'failed','rows'=>[],'bars_sha256'=>null,'name'=>'gone'],
];
$covRows=[
    ['rank'=>1,'code'=>'000001','symbol'=>'000001.KS','name'=>'n1','market'=>'KOSPI','amount_won'=>100],
    ['rank'=>2,'code'=>'000002','symbol'=>'000002.KS','name'=>'letter','market'=>'KOSPI','amount_won'=>90],
    ['rank'=>3,'code'=>'000003','symbol'=>'000003.KS','name'=>'gone','market'=>'KOSPI','amount_won'=>80],
];
$cand=['days'=>['2025-10-10','2025-10-13'],'by_session'=>[
    PaperDatedTop100Prep::sessionClose('2025-10-10')=>['day'=>'2025-10-10','rows'=>$covRows],
    PaperDatedTop100Prep::sessionClose('2025-10-13')=>['day'=>'2025-10-13','rows'=>$covRows],
]];
$cov=PaperDatedTop100Prep::sessionCoverage($cand,$state);
pcheck($cov['days'][0]['unmapped']===1&&$cov['days'][0]['no_bars']===1,'unmapped and missing bars are counted per session');
pcheck($cov['days'][0]['price_jump']===0,'later jump is not applied to the earlier session');
pcheck($cov['days'][1]['price_jump']===1,'jump is tagged on the session that already contains it');
pcheck($cov['later_jump_does_not_wipe_earlier']['symbols_with_later_jump_and_earlier_clear']===1,'earlier clear sessions stay distinct from a later jump');
pcheck($cov['post_dropout']['symbol_sessions_checked']===0,'no dropout gap on symbols still in both sessions');

$ev=PaperDatedTop100Prep::yahooRequestEvidence($tmp);
pcheck(($ev['confirmed']['included_symbols']??0)===0&&$ev['no_recollect']===true,'yahoo evidence does not recollect');

echo "OK dated top100 prep tests\n";
