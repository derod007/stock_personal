<?php
declare(strict_types=1);
require __DIR__.'/../bin/paper/KrxSignalStudy.php';
use ChartEntryLab\{ChartPlanEngine,CandleClock,PaperQuality};
$n=0;
function kcheck(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);$n++;echo "PASS $label\n";}
function kthrows(callable $f,string $label):void{try{$f();}catch(Throwable $e){kcheck(true,$label);return;}throw new RuntimeException('Expected failure '.$label);}
$root=dirname(__DIR__);$p=PaperKrxSignalStudy::profile($root);
kcheck(count($p['days'])===483&&count($p['symbols'])===1248,'frozen profile and strategy/mirror checked');
kcheck(count($p['candidates'])===243,'243 actual dated candidate sessions');
kcheck(PaperKrxSignalStudy::num('1,002')===1002.0&&PaperKrxSignalStudy::num('-')===null,'KRX comma and missing marker parsing');
kthrows(fn()=>PaperKrxSignalStudy::num(INF),'nonfinite rejected');
$good=['open'=>100,'high'=>110,'low'=>90,'close'=>105,'volume'=>100];
kcheck(PaperKrxSignalStudy::status($good)==='normal_ohlcv','normal OHLCV');
foreach([null,0,-1] as $v)kcheck(PaperKrxSignalStudy::status(array_replace($good,['volume'=>$v]))==='normal_ohlc_not_ohlcv','volume not hidden by normal OHLC');
kcheck(PaperKrxSignalStudy::status(array_replace($good,['high'=>99]))==='other_abnormal','close outside high invalid');
$tmp=sys_get_temp_dir().'/krx-signals-'.bin2hex(random_bytes(5));mkdir($tmp);mkdir($tmp.'/raw');mkdir($tmp.'/bars');
register_shutdown_function(static function()use($tmp):void{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($tmp);});
$days=['2024-01-02','2024-01-03'];$sym='022100.KS';
$small=['days'=>$days,'prep_days'=>[$days[0]],'symbols'=>[$sym=>'022100','000001.KQ'=>'000001'],'raw_hashes'=>[],
    'transfers'=>['022100'=>['confirmed'=>true,'first_to_market_day'=>'2024-01-03','from_suffix'=>'kosdaq_equity','from_market'=>'KOSDAQ']]];
$tablesByDay=[];
foreach($days as $day){
    $compact=str_replace('-','',$day);$suffix=$day===$days[0]?'kosdaq_equity':'kospi_equity';
    $raw=['BAS_DD'=>$compact,'ISU_CD'=>'022100','MKT_NM'=>$suffix==='kospi_equity'?'KOSPI':'KOSDAQ',
        'TDD_OPNPRC'=>'100','TDD_HGPRC'=>'110','TDD_LWPRC'=>'90','TDD_CLSPRC'=>'105','ACC_TRDVOL'=>'1,000','ACC_TRDVAL'=>'105000'];
    foreach(['kospi_equity','kosdaq_equity'] as $s){
        $other=array_replace($raw,['ISU_CD'=>'999999']);$block=[$s===$suffix?$raw:$other];
        $file=$compact.'_'.$s.'.json';file_put_contents($tmp.'/raw/'.$file,json_encode(['OutBlock_1'=>$block]));
        $small['raw_hashes'][$file]=hash_file('sha256',$tmp.'/raw/'.$file);
        $tablesByDay[$day][$s]=array_column($block,null,'ISU_CD');
    }
}
$all=[];foreach($small['symbols'] as $s=>$code){
    $rows=[];foreach($days as $day)$rows[]=PaperKrxSignalStudy::expected($s,$code,$day,$day===$days[0],$tablesByDay[$day],$small);
    $all[$s]=$rows;file_put_contents($tmp.'/bars/'.$s.'.jsonl',implode("\n",array_map(fn($r)=>PaperKrxSignalStudy::encode($r),$rows))."\n");
}
$v=PaperKrxSignalStudy::validate($small,$tmp.'/bars',$tmp.'/raw',$tmp.'/raw');
kcheck($v['raw_value_comparison_pass']&&$v['status_counts']['no_row']===2,'every JSONL row independently matches original or absence');
kcheck($all[$sym][0]['lookup']==='linked_prior_market'&&$all[$sym][1]['lookup']==='assigned','confirmed market boundary mapped');
kcheck(PaperKrxSignalStudy::bar($all['000001.KQ'][0])===null,'absent source never makes a bar');
$file=$tmp.'/bars/'.$sym.'.jsonl';$saved=file_get_contents($file);$changed=$all[$sym];$changed[0]['close']=104;
file_put_contents($file,implode("\n",array_map(fn($r)=>PaperKrxSignalStudy::encode($r),$changed)));
kthrows(fn()=>PaperKrxSignalStudy::validate($small,$tmp.'/bars',$tmp.'/raw',$tmp.'/raw'),'valid-looking altered OHLCV fails raw comparison');
file_put_contents($file,$saved);$changed=$all[$sym];$changed[0]['file_sha256']=str_repeat('0',64);
file_put_contents($file,implode("\n",array_map(fn($r)=>PaperKrxSignalStudy::encode($r),$changed)));
kthrows(fn()=>PaperKrxSignalStudy::validate($small,$tmp.'/bars',$tmp.'/raw',$tmp.'/raw'),'forged provenance hash rejected');
file_put_contents($file,$saved);$rawfile=$tmp.'/raw/20240102_kospi_equity.json';$rawsave=file_get_contents($rawfile);
file_put_contents($rawfile,$rawsave.' ');
kthrows(fn()=>PaperKrxSignalStudy::validate($small,$tmp.'/bars',$tmp.'/raw',$tmp.'/raw'),'changed raw bytes rejected');
file_put_contents($rawfile,$rawsave);
$duplicate=json_decode($rawsave,true);$duplicate['OutBlock_1'][]=$duplicate['OutBlock_1'][0];file_put_contents($rawfile,json_encode($duplicate));
$bad=$small;$bad['raw_hashes'][basename($rawfile)]=hash_file('sha256',$rawfile);
kthrows(fn()=>PaperKrxSignalStudy::validate($bad,$tmp.'/bars',$tmp.'/raw',$tmp.'/raw'),'duplicate code rejected even with matching file hash');
file_put_contents($rawfile,$rawsave);
$bad=$small;unset($bad['transfers']['022100']);
kthrows(fn()=>PaperKrxSignalStudy::validate($bad,$tmp.'/bars',$tmp.'/raw',$tmp.'/raw'),'unapproved market link rejected');

// An actual engine fixture from the recovery-close study, with more than 240 prior bars.
$rows=[];$start=new DateTimeImmutable('2023-01-02');
for($i=0;$i<300;$i++){
    $j=$i-240;$close=$j<30?50+$j:80+($j-30)*.4;if($j>=55)$close=[88,86,84.5,84,85.5][$j-55];$close+=1000;
    $open=$j===59?1084.2:$close-.2;$day=$start->modify('+'.$i.' weekdays')->format('Y-m-d');
    $rows[]=['day'=>$day,'status'=>'normal_ohlcv','open'=>$open,'high'=>$close+.8,'low'=>min($open,$close)-.4,'close'=>$close,
        'volume'=>$j>=56&&$j<=58?500:($j===59?1400:1000)];
}
$day=end($rows)['day'];$at=PaperDatedTop100Replay::sessionClose($day);
$e=PaperKrxSignalStudy::evaluate($rows,'005930.KS',$day,[]);
kcheck($e['past_count']===300&&$e['valid_count']===300,'no arbitrary 240-bar truncation');
kcheck($e['baseline_mirror_checked'],'actual operating/mirror-off plans match');
$direct=(new ChartPlanEngine())->analyze(array_map(fn($r)=>PaperKrxSignalStudy::bar($r),$rows),'005930.KS',$at,'account1',true)['plan'];
kcheck(PaperKrxSignalStudy::compact($direct)===$e['baseline'],'adapter plans equal direct operating engine');
$future=$rows;$future[]=array_replace(end($rows),['day'=>'2030-01-01','close'=>1]);
$futureBreak=['from'=>$day,'to'=>'2030-01-01','ratio'=>.1];
kcheck(PaperKrxSignalStudy::evaluate($future,'005930.KS',$day,[$futureBreak])===$e,'future bars and future events cannot change prior diagnostics');
$broken=$rows;$broken[298]=array_replace($broken[298],['open'=>0,'high'=>0,'low'=>0,'volume'=>0,'status'=>'ohl_zero']);
$z=PaperKrxSignalStudy::evaluate($broken,'005930.KS',$day,[]);
kcheck($z['past_count']===300&&$z['valid_count']===299,'zero row kept for quality but not chart calculation');
kcheck(in_array('invalid_recent_ohlcv',$z['quality']['reasons'],true)&&!$z['baseline_quality_eligible']&&!$z['variant_quality_eligible'],'recent zero blocks both arms through unchanged quality');
$old=$rows;$old[1]=array_replace($old[1],['open'=>0,'high'=>0,'low'=>0,'volume'=>0,'status'=>'ohl_zero']);
$z=PaperKrxSignalStudy::evaluate($old,'005930.KS',$day,[]);
kcheck(!in_array('invalid_recent_ohlcv',$z['quality']['reasons'],true)&&count($z['input_warnings']['ohl_zero_days'])===1,'old zero warning retained without invented permanent exclusion');
$gap=$rows;$gap[0]['status']='no_row';$gap[120]['status']='no_row';
$g=PaperKrxSignalStudy::evaluate($gap,'005930.KS',$day,[]);
kcheck(count($g['input_warnings']['leading_no_row_days'])===1&&count($g['input_warnings']['internal_no_row_days'])===1,'leading absence distinguished from middle gap without inferring cause');
kcheck($g['valid_count']===298&&!in_array('missing_session',$g['quality']['reasons'],true),'middle gap not silently converted into synthetic candle or new filter');
$event=['from'=>$rows[100]['day'],'to'=>$rows[101]['day'],'ratio'=>.5];
$warn=PaperKrxSignalStudy::evaluate($rows,'005930.KS',$day,[$event]);
kcheck($warn['baseline']===$e['baseline']&&$warn['variant']===$e['variant']&&count($warn['input_warnings']['breaks_in_history'])===1,'break warning is not an automatic rule change');
$short=PaperKrxSignalStudy::evaluate(array_slice($rows,-10),'005930.KS',$day,[]);
kcheck(!$short['baseline_mirror_checked']&&!$short['baseline_quality_eligible'],'insufficient history is explicit');
$summary=PaperKrxSignalStudy::summary([$e,$z],[$day]);
kcheck($summary['pairs']===2&&$summary['baseline_mirror_checked']===2,'summary counts sessions-symbol observations not trades');
$path=$tmp.'/result.json';$result=[$day=>$e];
PaperKrxSignalStudy::save($path,['inputs_sha256'=>'abc','symbol'=>'005930.KS','results'=>$result,'results_sha256'=>PaperKrxSignalStudy::digest($result)]);
kcheck(PaperKrxSignalStudy::readResult($path,'abc','005930.KS')===$result,'checkpoint roundtrip preserves numeric types');
kthrows(fn()=>PaperKrxSignalStudy::readResult($path,'changed','005930.KS'),'changed input identity refuses resume');
$r=PaperKrxSignalStudy::json($path);$r['results'][$day]['baseline']['entry']=123;PaperKrxSignalStudy::save($path,$r);
kthrows(fn()=>PaperKrxSignalStudy::readResult($path,'abc','005930.KS'),'modified stored result rejected');
$miss=$rows;$miss[298]['high']=1086;
$m=PaperKrxSignalStudy::evaluate($miss,'005930.KS',$day,[]);
kcheck(!$m['baseline']['diagnostics']['patterns']['trend_pullback']['ready']&&$m['variant']['diagnostics']['patterns']['trend_pullback']['ready'],'single-failure fixture actually changes independent pullback result');
kcheck($m['variant']['diagnostics']['patterns']['trend_pullback']['gates']['recovery_close']===false,'primitive failure remains visible in variant evidence');
$wanted=[$rows[298]['day'],$day];
$one=PaperKrxSignalStudy::extend($rows,'005930.KS',$wanted,[]);
$part=PaperKrxSignalStudy::extend($rows,'005930.KS',[$wanted[0]],[]);
$two=PaperKrxSignalStudy::extend($rows,'005930.KS',$wanted,[],$part['results']);
kcheck($one['results']===$two['results']&&$two['processed']===1,'split resume matches all-at-once actual engine results');
$repeat=PaperKrxSignalStudy::extend($rows,'005930.KS',$wanted,[],$two['results']);
kcheck($repeat['processed']===0&&$repeat['results']===$two['results'],'repeat produces zero new pairs and identical payload');
kthrows(fn()=>PaperKrxSignalStudy::extend($rows,'005930.KS',[$wanted[0]],[],$two['results']),'shorter resume cannot erase completed sessions');
echo "OK $n checks\n";
