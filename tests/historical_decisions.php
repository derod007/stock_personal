<?php
declare(strict_types=1);
require __DIR__.'/../bin/paper/HistoricalDecisionEvidence.php';
function hdcheck(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);echo 'OK '.$m."\n";}
$bars=[];$start=new DateTimeImmutable('2025-01-02',new DateTimeZone('Asia/Seoul'));
for($i=0;$i<80;$i++){
 $t=$start->modify('+'.$i.' weekdays')->setTime(15,30)->getTimestamp();$c=1000+$i*2;
 $bars[]=['time'=>$t,'time_kst'=>date('Y-m-d H:i:s',$t),'available_at'=>$t,'open'=>$c-1,'high'=>$c+4,'low'=>$c-4,'close'=>$c,'volume'=>1000,'is_complete'=>true];
}
$at=end($bars)['available_at'];$info=['rows'=>$bars,'bars_sha256'=>'frozen-source','name'=>'fixture'];
$a=PaperHistoricalDecisionEvidence::inspect($info,'005930.KS',$at,'test');
$future=$info;$future['rows'][]=['time'=>$at+86400,'available_at'=>$at+86400,'open'=>0,'high'=>0,'low'=>0,'close'=>0,'volume'=>0];
hdcheck($a===PaperHistoricalDecisionEvidence::inspect($future,'005930.KS',$at,'test'),'future invalid candle cannot change diagnosis or input hash');
hdcheck($a['last_input_close']===$at&&$a['input_bars']===80,'as-of input boundary recorded');
$short=$info;$short['rows']=array_slice($bars,-45);
$b=PaperHistoricalDecisionEvidence::inspect($short,'005930.KS',$at,'test');
hdcheck($b['analysis_ran']&&!$b['recommendation_eligible']&&!$b['quality']['can_simulate'],'40-bar engine and 60-bar quality remain separate');
$empty=$info;$empty['rows']=[];$e=PaperHistoricalDecisionEvidence::inspect($empty,'X.KS',$at,'test');
hdcheck(!$e['analysis_ran']&&$e['patterns']===[]&&$e['data_asof']===null,'missing input has no invented gates or data date');
$signal=['symbol'=>'X','recommendation_eligible'=>true,'signal_plan'=>['pattern'=>'p','entry'=>100,'stop'=>90,'target'=>120,'reward_risk'=>2,'signal_at'=>123,'order_valid_bars'=>3]];
$want=['symbol'=>'X']+$signal['signal_plan'];PaperHistoricalDecisionEvidence::verifySignals([$signal],[$want]);
hdcheck(true,'known signal exact fields verify');
$bad=$want;$bad['entry']=101;$thrown=false;try{PaperHistoricalDecisionEvidence::verifySignals([$signal],[$bad]);}catch(RuntimeException $ex){$thrown=true;}
hdcheck($thrown,'signal level mismatch fails closed');
$thrown=false;try{PaperHistoricalDecisionEvidence::verifySignals([],[$want]);}catch(RuntimeException $ex){$thrown=true;}
hdcheck($thrown,'signal membership mismatch fails closed');
hdcheck($a['account_outcome']==='not_observed_here'&&$a['origin']==='historical_recomputed_not_contemporaneous','does not invent account state or contemporaneous evidence');
