<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/DatedTop100Prep.php';
use ChartEntryLab\NaverDailyQuotes;

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
pcheck($cov['days'][0]['price_jump_warning']===0,'later jump is not applied to the earlier session');
pcheck($cov['days'][1]['price_jump_warning']===1,'jump warning is tagged on the session that already contains it');
pcheck($cov['partition']['analyzable']+$cov['partition']['blocked']===6&&$cov['partition']['sum_matches']===true,'each name-session is analyzable or blocked once');
pcheck($cov['later_jump_does_not_wipe_earlier']['symbols_with_later_jump_and_earlier_clear']===1,'earlier clear sessions stay distinct from a later jump');
pcheck($cov['potential_holding_or_pending_bar_gaps']['final_dropout']['checked']===0,'no final dropout when the name stays in both sessions');

$gapRows=[
    ['rank'=>1,'code'=>'000001','symbol'=>'000001.KS','name'=>'n1','market'=>'KOSPI','amount_won'=>100],
];
$d1=PaperDatedTop100Prep::sessionClose('2025-10-10');
$d2=PaperDatedTop100Prep::sessionClose('2025-10-13');
$d3=PaperDatedTop100Prep::sessionClose('2025-10-14');
$reentry=['days'=>['2025-10-10','2025-10-13','2025-10-14'],'by_session'=>[
    $d1=>['day'=>'2025-10-10','rows'=>$gapRows],
    $d2=>['day'=>'2025-10-13','rows'=>[['rank'=>1,'code'=>'000099','symbol'=>'000099.KS','name'=>'other','market'=>'KOSPI','amount_won'=>1]]],
    $d3=>['day'=>'2025-10-14','rows'=>$gapRows],
]];
$reState=['000001.KS'=>['status'=>'included','state'=>'done','rows'=>$rows,'bars_sha256'=>str_repeat('a',64),'name'=>'n1']];
$g=PaperDatedTop100Prep::sessionCoverage($reentry,$reState);
pcheck($g['potential_holding_or_pending_bar_gaps']['intermediate_dropout']['checked']===1,'re-entry after an intermediate drop is a checked gap');
pcheck($g['potential_holding_or_pending_bar_gaps']['intermediate_dropout']['potential_missing_bars']===0,'a bar on the intermediate session is not a potential miss');
$missingMid=$rows;
$reStateMiss=['000001.KS'=>['status'=>'included','state'=>'done','rows'=>array_values(array_filter($missingMid,fn($b)=>PaperHistoryResearch::day($b['available_at'])!=='2025-10-13')),'bars_sha256'=>str_repeat('a',64),'name'=>'n1']];
$gm=PaperDatedTop100Prep::sessionCoverage($reentry,$reStateMiss);
pcheck($gm['potential_holding_or_pending_bar_gaps']['intermediate_dropout']['potential_missing_bars']===1,'missing bar in the intermediate drop is a potential holding/pending gap');
pcheck($gm['potential_holding_or_pending_bar_gaps']['intermediate_dropout']['unique_symbols']===1,'the re-entered name is the missing-bar symbol');
pcheck($gm['potential_holding_or_pending_bar_gaps']['not_account_halt_count']===true,'potential gaps are not account halt counts');

$id=PaperDatedTop100Prep::yahooIdentityOk(
    ['symbol'=>'0001A0.KQ','market'=>'KOSDAQ'],
    ['parsed'=>['ok'=>true,'symbol'=>'0001A0.KQ','exchange'=>'KOE','currency'=>'KRW','bar_count'=>168]]
);
pcheck($id['ok']===true&&$id['need_5y']===true&&$id['one_year_covers_eval_prep']===false,'Yahoo identity matches KRX listing and 1y is not the prep window');
pcheck(PaperDatedTop100Prep::yahooIdentityOk(['symbol'=>'0001A0.KQ','market'=>'KOSDAQ'],['parsed'=>['symbol'=>'999999.KQ','exchange'=>'KOE','currency'=>'KRW','bar_count'=>10]])['ok']===false,'mismatched Yahoo symbol is not admitted');
pcheck(PaperDatedTop100Prep::DATASET_V2!==PaperDatedTop100Prep::DATASET,'v2 dataset id does not overwrite v1');

$ev=PaperDatedTop100Prep::yahooRequestEvidence($tmp);
pcheck(($ev['confirmed']['included_symbols']??0)===0&&$ev['no_recollect']===true,'yahoo evidence does not recollect');

$copySrc=$tmp.'/v1src';$copyDst=$tmp.'/v2dst';
@mkdir($copySrc.'/bars',0770,true);@mkdir($copySrc.'/status',0770,true);
$payload=PaperHistoryResearch::encode(['schema'=>1,'symbol'=>'000001.KS','rows'=>[]],true);
$sha=hash('sha256',$payload);
PaperHistoryResearch::writeFile($copySrc.'/bars/000001.KS.json',$payload);
PaperHistoryResearch::writeFile($copySrc.'/status/000001.KS.json',PaperHistoryResearch::encode([
    'schema'=>1,'symbol'=>'000001.KS','state'=>'done','bars_path'=>'bars/000001.KS.json','bars_sha256'=>$sha,'requests'=>[]],true));
$srcBefore=hash_file('sha256',$copySrc.'/bars/000001.KS.json');
pcheck(PaperDatedTop100Prep::copyVerifiedBars($copyDst,$copySrc,['symbol'=>'000001.KS'])==='copied','hash-verified bars are copied into v2');
pcheck(hash_file('sha256',$copyDst.'/bars/000001.KS.json')===$srcBefore,'copied bars match the source bytes');
pcheck(hash_file('sha256',$copySrc.'/bars/000001.KS.json')===$srcBefore,'source bars are not rewritten');

pcheck(NaverDailyQuotes::codeOf('005930.KS')==='005930'&&PaperHistoryResearch::krCode('005930.KS')==='005930','six-digit codes stay the same in research and operations');
pcheck(NaverDailyQuotes::codeOf('0001A0.KQ')===null,'operations still reject alphanumeric listing codes');
pcheck(PaperHistoryResearch::krCode('0001A0.KQ')==='0001A0','research krCode accepts alphanumeric listings');

$union=['AAA.KS','BBB.KS','CCC.KS'];
$baseCol=['symbols'=>[
    'AAA.KS'=>['status'=>'secured','bucket'=>'semi'],
    'BBB.KS'=>['status'=>'unconfirmed','reason'=>'not_a_kr_symbol'],
    'CCC.KS'=>['status'=>'unconfirmed','reason'=>'http_status'],
]];
$admitMap=['symbols'=>['BBB.KS'],'sectors'=>['BBB.KS'=>'bio']];
$merged=PaperDatedTop100Prep::mergeUnionSectorMap($union,$baseCol,$admitMap);
pcheck($merged['counts']['secured']===2&&$merged['counts']['unconfirmed']===1,'union merge keeps base names and admits the extra listing');
pcheck($merged['sectors']['CCC.KS']==='unclassified'&&count($merged['unconfirmed'])===1,'unconfirmed names share unclassified');
PaperSectorFreeze::assertCoversUnion($union,$merged);
pcheck((static function()use($union,$admitMap){
    try{PaperSectorFreeze::assertCoversUnion($union,['kind'=>PaperSectorFreeze::KIND_V2,'assumption'=>'당시 업종을 복원한 것이 아니다.','unclassified_bucket'=>'unclassified','unclassified_is_one_shared_bucket'=>true,'sectors'=>$admitMap['sectors'],'unconfirmed'=>[]]);return false;}
    catch(Throwable){return true;}
})(),'an admitted-only partial map is rejected for execution');
pcheck((static function()use($union,$baseCol){
    try{PaperDatedTop100Prep::mergeUnionSectorMap($union,$baseCol,['sectors'=>['BBB.KS'=>'fin','AAA.KS'=>'auto']]);return false;}
    catch(Throwable){return true;}
})(),'a bucket conflict is not overwritten');

$docs=dirname(__DIR__).'/docs/historical-top100-20251008-20261008';
if(is_file($docs.'/sector-map-v2.json')&&is_file($docs.'/sector-collection-v2.json')){
    $am=json_decode((string)file_get_contents($docs.'/sector-map-v2.json'),true,512,JSON_THROW_ON_ERROR);
    $ac=json_decode((string)file_get_contents($docs.'/sector-collection-v2.json'),true,512,JSON_THROW_ON_ERROR);
    $off=PaperDatedTop100Prep::verifyAdmittedOffline($am,$ac);
    pcheck($off['pass']===true&&$off['symbols']===31&&$off['recollected']===false,'stored 31-name codes and sectors still match without a recollect');
}
if(is_file($docs.'/sector-map-full-v2.json')&&is_file($docs.'/candidates.json')){
    $full=json_decode((string)file_get_contents($docs.'/sector-map-full-v2.json'),true,512,JSON_THROW_ON_ERROR);
    $real=PaperDatedTop100Prep::loadCandidates($docs.'/candidates.json');
    PaperSectorFreeze::assertCoversUnion($real['union'],$full);
    pcheck(($full['counts']['secured']??0)===926&&($full['counts']['unconfirmed']??0)===7&&count($full['sectors'])===933,'full map lists 933 names with 7 unclassified');
    pcheck((static function()use($real,$am){
        $partial=['kind'=>PaperSectorFreeze::KIND_V2,'assumption'=>'당시 업종을 복원한 것이 아니다.','unclassified_bucket'=>'unclassified','unclassified_is_one_shared_bucket'=>true,'sectors'=>$am['sectors']??[],'unconfirmed'=>[]];
        try{PaperSectorFreeze::assertCoversUnion($real['union'],$partial);return false;}catch(Throwable){return true;}
    })(),'the 31-name map is refused against the 933-name union');
}

echo "OK dated top100 prep tests\n";
