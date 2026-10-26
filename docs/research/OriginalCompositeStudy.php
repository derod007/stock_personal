<?php
declare(strict_types=1);
// Pin the complete original score modules; only execution/data validation use current helpers.
$scoreRoot=getenv('ORIGINAL_SCORE_ROOT');
if(!$scoreRoot || !is_dir($scoreRoot))throw new RuntimeException('ORIGINAL_SCORE_ROOT must point to the frozen original scoring modules');
foreach(['AccountProfile','AccountPlaybook','HorizontalLevels','NoramuLesson1','NoramuTopPattern','SpikeDump','ThemeSmell','FeatureEngine'] as $name)require_once $scoreRoot.'/'.$name.'.php';
require_once dirname(__DIR__,2).'/bin/bootstrap.php';
use ChartEntryLab\{FeatureEngine,AccountPlaybook,CandleClock,PaperQuality,TradeSimulator};

/** Research adapter only. Original score/decision classes are called without changes. */
final class OriginalCompositeStudy
{
 public static function close(string $day):int {return (new DateTimeImmutable($day.' 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();}
 public static function assess(array $raw,string $sym,int $t,array $source):array {
  $from=(new DateTimeImmutable('@'.$t))->modify('-2 years')->getTimestamp();
  $past=array_values(array_filter($raw,fn($b)=>$b['available_at']<=$t && $b['available_at']>=$from));
  $q=PaperQuality::inspect($past,$sym,$t,$source);$valid=CandleClock::completed($past,$sym,$t);$reasons=$q['reasons'];
  if(count($valid)<120)$reasons[]='warmup_under_120';
  if($reasons)return ['history'=>count($valid),'blocked'=>array_values(array_unique($reasons)),'quality'=>$q];
  $f=(new FeatureEngine())->extract($valid);$d=AccountPlaybook::forProfile('custom')->decide($f,$sym);
  return ['history'=>count($valid),'features'=>$f,'decision'=>$d,'quality'=>$q];
 }
 public static function plan(array $decision,int $t):array {return ['ready'=>true,'entry'=>$decision['entry_zone']['mid'],'stop'=>$decision['invalidation'],'target'=>$decision['target_hint']['price'],'signal_at'=>$t,'order_valid_bars'=>3];}
 public static function execute(array $plan,array $futureDays,array $byTs,string $symbol):array {
  $sim=new TradeSimulator();$tr=$sim->simulate($plan,[],20);$future=[];$end=$plan['signal_at'];$bad=null;
  if($tr['status']!=='invalid_levels')foreach(array_slice($futureDays,0,22) as $next){
   $nt=self::close($next);$end=$nt;$bar=$byTs[$nt]??null;
   if($bar===null || CandleClock::completed([$bar],$symbol,$nt)===[] || !is_numeric($bar['volume']??null) || $bar['volume']<0){$bad='missing_or_invalid_future_bar';break;}
   $future[]=$bar;$tr=$sim->simulate($plan,$future,20);
   // TTL expires at the third session close, without needing a fourth valid bar.
   if(count($future)>=3 && !$tr['filled'] && !$tr['complete']){$tr['status']='unfilled';$tr['complete']=true;}
   if($tr['complete'])break;
  }
  if(!$tr['complete'] && $tr['status']!=='invalid_levels'){$tr['status']='unresolved';$tr['unresolved_reason']=$bad??'segment_end';}
  return ['trade'=>$tr,'end'=>$end,'future'=>$future];
 }
 public static function run(string $input,string $output):void {
  if(file_exists($output))throw new RuntimeException('Use a new output directory; no overwrite/resume of an old run');
  $manifest=json_decode(file_get_contents($input.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);mkdir($output,0777,true);
  $obs=fopen($output.'/observations.jsonl','x');$all=[];$summaries=[];
  foreach($manifest['segments'] as $seg){
   $dir=$input.'/'.$seg['directory'];if(hash_file('sha256',$dir.'/candidates.json')!==$seg['candidates_sha256'])throw new RuntimeException('Candidate hash mismatch');
   $cand=json_decode(file_get_contents($dir.'/candidates.json'),true,512,JSON_THROW_ON_ERROR);$days=$seg['days'];$bySymbol=[];
   if(array_keys($cand)!==$days)throw new RuntimeException('Calendar mismatch');
   foreach($cand as $day=>$rows){if(count($rows)!==100||count(array_unique(array_column($rows,'code')))!==100)throw new RuntimeException('Invalid TOP100');foreach($rows as $r)$bySymbol[$r['symbol']][$day]=$r;}
   $sum=['segment'=>$seg['label'],'sessions'=>count($days),'observations'=>0,'evaluated'=>0,'blocked_observations'=>0,'blocked'=>[],'actions'=>[],'candidate_signals'=>0,'attempts'=>0,'overlap_suppressed'=>0,'first_evaluated'=>null,'trade_status'=>[]];$si=0;
   foreach($bySymbol as $sym=>$dates){
    $path=$dir.'/'.$sym.'.json';$raw=is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];$hash=is_file($path)?hash_file('sha256',$path):null;
    if($hash!==($seg['bar_hashes'][$sym]??null))throw new RuntimeException('Bar hash mismatch '.$sym);
    $source=['sha256'=>$hash,'price_basis'=>$seg['basis']];$rawBy=[];
    foreach($raw as &$b){$b['available_at']=CandleClock::closeTime($b,$sym);if(isset($rawBy[$b['available_at']]))throw new RuntimeException('Duplicate timestamp '.$sym);$rawBy[$b['available_at']]=$b;}unset($b);
    ksort($rawBy);$busyUntil=0;
    foreach($dates as $day=>$r){
     $sum['observations']++;$t=self::close($day);$a=self::assess($raw,$sym,$t,$source);
     $o=['segment'=>$seg['label'],'date'=>$day,'symbol'=>$sym,'name'=>$r['name'],'rank'=>$r['rank'],'history'=>$a['history']];
     if(isset($a['blocked'])){$sum['blocked_observations']++;foreach($a['blocked'] as $v)$sum['blocked'][$v]=($sum['blocked'][$v]??0)+1;$o['blocked']=$a['blocked'];fwrite($obs,json_encode($o,JSON_UNESCAPED_UNICODE)."\n");continue;}
     $f=$a['features'];$d=$a['decision'];$sum['evaluated']++;$sum['first_evaluated']=$sum['first_evaluated']===null?$day:min($day,$sum['first_evaluated']);$sum['actions'][$d['action']]=($sum['actions'][$d['action']]??0)+1;
     $o+=['score'=>$f['pullback_long_score'],'action'=>$d['action'],'breakdown'=>$f['score_breakdown']];
     fwrite($obs,json_encode($o,JSON_UNESCAPED_UNICODE)."\n");
     if($d['action']!=='add_on_pullback')continue;$sum['candidate_signals']++;
     if($t<=$busyUntil){$sum['overlap_suppressed']++;continue;}
     $plan=self::plan($d,$t);$dayIndex=array_search($day,$days,true);$ex=self::execute($plan,array_slice($days,$dayIndex+1),$rawBy,$sym);$tr=$ex['trade'];
     $busyUntil=$tr['status']==='unresolved'?PHP_INT_MAX:($tr['status']==='invalid_levels'?$t:$ex['end']);
     $sum['attempts']++;$sum['trade_status'][$tr['status']]=($sum['trade_status'][$tr['status']]??0)+1;
     $trade=['segment'=>$seg['label'],'date'=>$day,'symbol'=>$sym,'name'=>$r['name'],'score'=>$f['pullback_long_score'],'decision'=>$d,'plan'=>$plan,'trade'=>$tr,'price_basis'=>$seg['basis'],'source_sha256'=>$hash,'quality'=>$a['quality']];
     if($tr['filled']){$qty=(int)floor(10000000/($tr['entry_fill']*1.001));$trade['quantity']=$qty;$trade['buy_cost']=$qty*$tr['entry_fill']*1.001;
      if($tr['status']==='closed'){$trade['sell_proceeds']=$qty*$tr['exit_fill']*.999;$trade['pnl_won']=$trade['sell_proceeds']-$trade['buy_cost'];
       $held=array_values(array_filter($ex['future'],fn($b)=>$b['available_at']>=$tr['entry_at']&&$b['available_at']<=$tr['exit_at']));
       $trade['holding_calendar_days']=(int)round(($tr['exit_at']-$tr['entry_at'])/86400);$trade['holding_bar_high_pct']=(max(array_column($held,'high'))/$tr['entry_fill']-1)*100;$trade['holding_bar_low_pct']=(min(array_column($held,'low'))/$tr['entry_fill']-1)*100;
      }}
     $all[]=$trade;
    }
    if(++$si%50===0)fwrite(STDERR,$seg['label'].' '.$si.'/'.count($bySymbol)." symbols\n");
   }
   $summaries[]=$sum;file_put_contents($output.'/trades.json',json_encode($all,JSON_UNESCAPED_UNICODE));file_put_contents($output.'/summary-counts.json',json_encode($summaries,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
   fwrite(STDERR,json_encode($sum,JSON_UNESCAPED_UNICODE)."\n");
  }
  fclose($obs);file_put_contents($output.'/COMPLETE','complete');
 }
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){if($argc!==3)throw new InvalidArgumentException('Usage: php OriginalCompositeStudy.php INPUT_DIR NEW_OUTPUT_DIR');OriginalCompositeStudy::run($argv[1],$argv[2]);}
