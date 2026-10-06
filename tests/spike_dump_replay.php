<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/TargetTracking.php';
$root=dirname(__DIR__).'/docs/paper-kr-5d-source';
$obs=PaperFollowup::observations($root.'/rr-audit/paper-kr');
$now=1790794963;
$r=PaperTargetTracking::register(['rows'=>[],'errors'=>[],'added'=>0],$obs,$now,PaperStrategyVersion::current(),PaperTargetTracking::executionVersion());
$mismatches=array_filter($r['errors'],fn($e)=>($e['error']??'')==='Original replay mismatch');
if(!$mismatches)throw new RuntimeException('changed risk rule must quarantine differing historical decisions');
foreach($mismatches as $e){
    if(isset($r['rows'][$e['symbol'].'@'.$e['session']]))throw new RuntimeException('mismatch silently registered under new rule');
}
echo "SPIKE_REPLAY_PASS historical disagreements remain quarantined: ".count($mismatches)."\n";
