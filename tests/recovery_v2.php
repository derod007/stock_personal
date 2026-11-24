<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/StrategyVersion.php';
use ChartEntryLab\{PaperPortfolio, PaperForwardExitPolicy, PaperJournal, PaperScanUniverse};
function checkV2(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException($why);
    echo "PASS $why\n";
}
$root=dirname(__DIR__);
$v1=json_decode(file_get_contents($root.'/config/paper-kr-recovery-v1.json'),true,512,JSON_THROW_ON_ERROR);
$v2=json_decode(file_get_contents($root.'/config/paper-kr-recovery-v2.json'),true,512,JSON_THROW_ON_ERROR);
$expected=$v1;$expected['id']='paper-kr-recovery-v2';
checkV2($v2===$expected, 'only account identity changes; cash and limits preserved');
$current=PaperStrategyVersion::current();
$prior='2303a1b6c59353e3e53f5b49d011adb8e82a184e8cd9b31d86ed541c712b1c3f';
$events=[];$emit=function($type,$payload)use(&$events){$events[]=[$type,$payload];};
$states=[];
foreach ([$v1,$v2] as $config) {
    $config['symbols']=['TEST.KS'=>'fixture'];
    $s=PaperPortfolio::start($config,$current,'forward');
    PaperForwardExitPolicy::adopt($s,$emit,100);
    checkV2($s['forward_exit_policy']===['policy'=>'half_risk_v1','enabled_at'=>100,'scope'=>'fills_after_activation','trigger_r'=>1.0,'remaining_risk_r'=>0.5],$config['id'].' exact policy');
    checkV2(!PaperForwardExitPolicy::forNewFill($s,100)&&PaperForwardExitPolicy::forNewFill($s,101),'activation boundary');
    for($day=1;$day<=3;$day++) {
        $at=(new DateTimeImmutable('2026-01-0'.$day.' 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();
        $bar=['available_at'=>$at,'open'=>100,'high'=>$day===2?114:111,'low'=>$day===2?90:93,'close'=>$day===2?113:100,'volume'=>1000];
        $snapshot=['session'=>$at,'recorded_at'=>$at+60,'origin'=>'forward','input_hash'=>'fixture-'.$day,
            'plan'=>['ready'=>$day===1,'signal_at'=>$at,'entry'=>100,'stop'=>88,'target'=>130,'order_valid_bars'=>3],'quality'=>['can_simulate'=>true]];
        PaperPortfolio::advance($s,$at,['TEST.KS'=>$bar],['TEST.KS'=>$snapshot],$emit);
        if($day===2) checkV2($s['active']['TEST.KS']['exit_policy']==='half_risk_v1'&&$s['active']['TEST.KS']['protection']['state']==='armed','new fill arms protection');
    }
    $states[]=$s;
}
$exits=array_values(array_filter($events,fn($e)=>$e[0]==='exit'));
checkV2(count($exits)===2&&$exits[0][1]===$exits[1][1]&&abs($exits[1][1]['price']-94*.9995)<1e-6,'v2 executes identical protected exit including slippage');
foreach(['replay','unrelated','USD'] as $case) {
    $s=PaperPortfolio::start($v2,$current,$case==='replay'?'replay':'forward');
    if($case==='unrelated')$s['config']['id']='paper-kr-recovery-v3';
    if($case==='USD')$s['config']['currency']='USD';
    PaperForwardExitPolicy::adopt($s,$emit,100);
    checkV2(!isset($s['forward_exit_policy']),'policy excluded '.$case);
}
$s=$states[0];$s['version']=$prior;$s['strategy_fingerprint']=$prior;$before=$s;
PaperStrategyVersion::verify($s,$current);
checkV2($s===$before,'version check read-only');
PaperStrategyVersion::adopt($s,$current,$emit);
$expected=$before;$expected['version']=$current;$expected['strategy_fingerprint']=$current;
checkV2($s===$expected,'declared upgrade preserves all portfolio and policy fields');
foreach(['halted','replay','unknown'] as $case) {
    $s=$before;
    if($case==='halted')$s['halted']=true;
    if($case==='replay')$s['mode']='replay';
    if($case==='unknown')$s['version']=str_repeat('0',64);
    $copy=$s;$rejected=false;
    try{PaperStrategyVersion::adopt($s,$current,$emit);}catch(RuntimeException $e){$rejected=true;}
    checkV2($rejected&&$s===$copy,'upgrade refuses '.$case.' without mutation');
}
$tmp=sys_get_temp_dir().'/recovery-v2-'.bin2hex(random_bytes(6));mkdir($tmp);
$previous=getenv('PAPER_STATE_DIR');
try {
    putenv('PAPER_STATE_DIR='.$tmp);
    $path=$tmp.'/'.$v1['id'].'-forward.json';
    (new PaperJournal($path))->transact(function(&$s,$emit)use($v1,$prior){
        $s=PaperPortfolio::start($v1,$prior,'forward');$s['strategy_fingerprint']=$prior;
        $s['config_hash']=hash('sha256',PaperJournal::encode(PaperScanUniverse::pinned($v1)));
        $s['halted']=true;$s['halt_reason']='historical_revision';$emit('fixture',[]);
    });
    $bytes=file_get_contents($path);
    foreach([$v1,$v2] as $cfg) {
        $lines=[];$cmd=escapeshellarg(PHP_BINARY).' -n '.escapeshellarg($root.'/bin/paper_account_preflight.php').' --config='.escapeshellarg($root.'/config/'.$cfg['id'].'.json').' 2>&1';
        exec($cmd,$lines,$code);$output=implode("\n",$lines);
        if($cfg['id']===$v1['id'])checkV2($code!==0&&str_contains($output,'Account halted; review existing journal'),'old halted account still refused');
        else {$result=json_decode($output,true);checkV2($code===0&&($result['status']??null)==='new_account'&&$result['writes']===false,'v2 preflight is new account and read-only');}
    }
    checkV2(file_get_contents($path)===$bytes,'old journal bytes unchanged');
    checkV2(!file_exists($tmp.'/'.$v2['id'].'-forward.json'),'preflight does not initialize v2');
} finally {
    putenv($previous===false?'PAPER_STATE_DIR':'PAPER_STATE_DIR='.$previous);
    foreach(glob($tmp.'/*') as $file)if(is_file($file))unlink($file);
    rmdir($tmp);
}
checkV2(str_contains(file_get_contents($root.'/bin/run_paper_daily.cmd'),'paper-kr-recovery-v1.json'),'scheduled runner remains on v1');
echo "RECOVERY_V2_PASS\n";
