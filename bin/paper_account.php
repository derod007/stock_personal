<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use ChartEntryLab\PaperJournal;
use ChartEntryLab\PaperPortfolio;
use ChartEntryLab\PaperQuality;
use ChartEntryLab\CandleClock;
use ChartEntryLab\ChartPlanEngine;

$o=getopt('',['config:','data:','mode:']);
if(empty($o['config']) || empty($o['data'])) throw new InvalidArgumentException('--config and --data required');
$config=json_decode(file_get_contents($o['config']),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/^[a-z0-9_-]{1,64}$/',$config['id']??'')) throw new InvalidArgumentException('Invalid account ID');
$mode=$o['mode']??'forward';
if(!in_array($mode,['forward','replay'],true)) throw new InvalidArgumentException('Invalid mode');
$directory=getenv('PAPER_STATE_DIR')?:dirname(__DIR__,2).'/stock-personal-paper';
$path=$directory.'/'.$config['id'].'-'.$mode.'.json';
$data=rtrim($o['data'],'/');$now=time();
$manifest=json_decode(file_get_contents($data.'/sources.json'),true,512,JSON_THROW_ON_ERROR);
$sources=[];foreach($manifest as $source)$sources[$source['symbol']]=$source;
$raw=[];$sessions=[];$completed=[];$cutoffs=[];$inputFiles=[];
foreach($config['symbols'] as $symbol=>$sector) {
    if(!preg_match('/^[A-Z0-9][A-Z0-9.=-]{0,24}$/',$symbol)) throw new InvalidArgumentException('Invalid symbol');
    $file=$data.'/'.$symbol.'.json';
    if(!isset($sources[$symbol]['sha256']) || !is_file($file)) {$raw[$symbol]=[];$completed[$symbol]=[];continue;}
    if(hash_file('sha256',$file)!==$sources[$symbol]['sha256']) throw new RuntimeException('Source hash mismatch: '.$symbol);
    $bytes=file_get_contents($file);
    $inputFiles[$sources[$symbol]['sha256']]=$bytes;
    $sources[$symbol]['archive_hash']=$sources[$symbol]['sha256'];
    if(isset($sources[$symbol]['provider_file'],$sources[$symbol]['provider_sha256'])) {
        $provider=$data.'/'.basename($sources[$symbol]['provider_file']);
        if(!is_file($provider) || hash_file('sha256',$provider)!==$sources[$symbol]['provider_sha256']) throw new RuntimeException('Provider response hash mismatch');
        $inputFiles[$sources[$symbol]['provider_sha256']]=file_get_contents($provider);
    }
    $raw[$symbol]=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
    foreach($raw[$symbol] as &$b) $b['available_at']=CandleClock::closeTime($b,$symbol);
    unset($b);
    $captured=strtotime($sources[$symbol]['fetched_at']??'');
    if($captured===false) throw new RuntimeException('Source capture timestamp required: '.$symbol);
    $cutoffs[$symbol]=min($now,$captured);
    $completed[$symbol]=CandleClock::completed($raw[$symbol],$symbol,$cutoffs[$symbol]);
    // Include invalid current sessions so missing/bad candles cannot silently shift execution.
    foreach($raw[$symbol] as $b) {
        $t=CandleClock::closeTime($b,$symbol);
        if($t<=$cutoffs[$symbol]) $sessions[$t]=true;
    }
}
if($sessions===[]) throw new RuntimeException('No completed market sessions in input');
$dates=array_keys($sessions);sort($dates);$latest=end($dates);
$conflicts=is_file($data.'/price-crosscheck.json')?(json_decode(file_get_contents($data.'/price-crosscheck.json'),true,512,JSON_THROW_ON_ERROR)['cases']??[]):[];
$versionInputs=[];
foreach(glob(dirname(__DIR__).'/src/*.php') as $file) $versionInputs[basename($file)]=hash_file('sha256',$file);
$version=hash('sha256',PaperJournal::encode($versionInputs));
$configHash=hash('sha256',PaperJournal::encode($config));
$journal=new PaperJournal($path);
$result=$journal->transact(function(&$s,$emit)use($config,$mode,$version,$configHash,$dates,$latest,$raw,$sources,$completed,$now,$conflicts,$cutoffs,$inputFiles,$directory) {
    if($s===null) {
        $s=PaperPortfolio::start($config,$version,$mode);$s['config_hash']=$configHash;
        $emit('account_started',['config'=>$config,'mode'=>$mode,'version'=>$version]);
    }
    if($s['version']!==$version || $s['config_hash']!==$configHash || $s['mode']!==$mode) throw new RuntimeException('Pinned version/config changed; use a new account ID');
    if(!empty($s['halted'])) throw new RuntimeException('Account halted after a data revision or unavailable position price; review journal and use a new account ID');
    foreach($inputFiles as $hash=>$bytes) PaperJournal::archive($directory.'/inputs',$hash,$bytes);
    // Keep the original history when a provider's rolling window drops its oldest bars.
    foreach($config['symbols'] as $symbol=>$sector) {
        $merged=[];
        foreach(($s['history'][$symbol]??[]) as $b) $merged[$b['available_at']]=$b;
        foreach($raw[$symbol] as $b) {
            if($b['available_at']<=($cutoffs[$symbol]??0)) $merged[$b['available_at']]=$b;
        }
        ksort($merged,SORT_NUMERIC);$raw[$symbol]=array_values($merged);
        if($raw[$symbol]!==[]) $cutoffs[$symbol]=max($cutoffs[$symbol]??0,end($raw[$symbol])['available_at']);
        $completed[$symbol]=CandleClock::completed($raw[$symbol],$symbol,$cutoffs[$symbol]??0);
    }
    $pastRaw=function(string $symbol,int $date)use($raw,$cutoffs):array {
        return array_values(array_filter($raw[$symbol],fn($b)=>CandleClock::closeTime($b,$symbol)<=min($date,$cutoffs[$symbol]??0)));
    };
    foreach($s['frozen'] as $key=>$frozen) {
        [$symbol,$date]=explode(':',$key);
        $hash=hash('sha256',PaperJournal::encode($pastRaw($symbol,(int)$date)));
        if($hash!==$frozen['input_hash']) {
            $s['halted']=true;$s['halt_reason']='historical_revision';
            $emit('data_revision',['snapshot'=>$key,'original_hash'=>$frozen['input_hash'],'new_hash'=>$hash,'action'=>'halt_account_without_rewriting_history']);
            return;
        }
    }
    $s['history']=$raw;
    $first=$s['last_session']===0;
    foreach($dates as $date) {
        if($date<=$s['last_session'] || ($first && $mode==='forward' && $date!==$latest)) continue;
        $barSet=[];$snapshots=[];$enough=false;
        foreach($config['symbols'] as $symbol=>$sector) {
            $past=$pastRaw($symbol,$date);$valid=array_values(array_filter($completed[$symbol],fn($b)=>$b['available_at']<=$date));
            if(count($valid)>=60)$enough=true;
            $quality=PaperQuality::inspect($past,$symbol,$date,$sources[$symbol]??['error'=>'missing_source'],$conflicts);
            if($mode==='forward' && $date===$latest && $now-$date>4*86400) {
                $quality['can_simulate']=false;$quality['status']='blocked';$quality['reasons'][]='stale_latest_session';
            }
            $plan=['ready'=>false,'status'=>'data_quality','reason'=>'데이터 품질 확인 필요'];
            if(count($valid)>=40) $plan=(new ChartPlanEngine())->analyze($valid,$symbol,$date)['plan'];
            if($valid!==[] && end($valid)['available_at']===$date)$barSet[$symbol]=end($valid);
            $snapshots[$symbol]=['symbol'=>$symbol,'session'=>$date,'recorded_at'=>$now,
                'origin'=>$mode==='replay'?'replay':($date===$latest?'forward':'catchup'),
                'version'=>$version,'input_hash'=>hash('sha256',PaperJournal::encode($past)),
                'plan'=>$plan,'quality'=>$quality];
        }
        if(!$enough && $mode==='replay') continue;
        PaperPortfolio::advance($s,$date,$barSet,$snapshots,$emit);
    }
});
$s=$result['state'];
echo PaperJournal::encode(['account'=>$config['id'],'mode'=>$mode,'cash'=>$s['cash'],
    'equity'=>PaperPortfolio::equity($s),'reserved_cash'=>PaperPortfolio::reserved($s),'closed_trades'=>$s['closed_trades'],
    'realized'=>$s['realized'],'max_close_drawdown'=>$s['max_drawdown'],'last_session'=>$s['last_session'],
    'active_count'=>count($s['active']),'halted'=>$s['halted']??false,'events'=>count($result['events'])]).PHP_EOL;
