<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/EntryJourney.php';
function ej(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$dir=sys_get_temp_dir().'/journey-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);
function cleanJourney(string $dir):void{foreach(scandir($dir) as $f){if($f==='.'||$f==='..')continue;$p=$dir.'/'.$f;is_dir($p)?cleanJourney($p):unlink($p);}rmdir($dir);}
try{
    $plan=['ready'=>true,'status'=>'ready','confirmation_status'=>'confirmed','entry'=>100,'stop'=>90,'target'=>120,'reward_risk'=>2,'signal_at'=>strtotime('2026-10-06 15:30:00 +0900'),'order_valid_bars'=>3];
    $scan=['ok'=>true,'profile'=>'account1','market'=>'all','fetched_at'=>'2026-10-07 10:00:00','rows'=>[
        ['yahoo'=>'123456.KS','name'=>'test','analysis_mode'=>'completed','order_plan'=>$plan,'entry_status'=>'ready'],
        ['yahoo'=>'654321.KQ','name'=>'live','analysis_mode'=>'intraday','order_plan'=>['ready'=>false,'status'=>'intraday_preview'],
            'entry_status'=>'intraday_preview','entry_candidate'=>['low'=>90,'high'=>100]],
    ]];
    ej(PaperEntryJourney::scanTime($scan['fetched_at'])===strtotime('2026-10-07 10:00:00 +0900'),'scanner timestamp is KST regardless of PHP default timezone');
    $folder=$dir.'/entry-observations/manual';
    $a=PaperEntryJourney::saveManual($folder,$scan);$b=PaperEntryJourney::saveManual($folder,$scan);
    ej($a['status']==='saved'&&$b['status']==='existing'&&count(glob($folder.'/*.json'))===1,'cached rerun does not duplicate observation');
    $scan['fetched_at']='2026-10-07 11:00:00';$scan['rows'][0]['order_plan']['entry']=101;
    PaperEntryJourney::saveManual($folder,$scan);
    $r=PaperEntryJourney::read($dir,'paper-kr','account1');ej(count($r['rows'])===4,'new observation retained with intraday provisional candidate');
    $first=array_values(array_filter($r['rows'],fn($x)=>($x['plan']['entry']??null)===100));ej(count($first)===1,'original plan remains frozen after new scan');
    foreach($r['rows'] as $row)ej($row['outcome']===null&&$row['link']==='manual_observation_only','manual never inherits completed simulation');
    ej(count(PaperEntryJourney::read($dir,'paper-kr','isa')['rows'])===0,'manual profiles isolated');
    $record=['symbol'=>'123456.KS','session'=>$plan['signal_at'],'source_file'=>'exact.json','observation_hash'=>str_repeat('a',64)];
    $f=$record+['tracking_policy'=>PaperTrackingInput::POLICY,'trades'=>[['kind'=>'limit','outcome'=>['status'=>'closed','net_return_pct'=>99]],['kind'=>'baseline','outcome'=>['status'=>'closed','net_return_pct'=>-2]]]];
    ej(PaperEntryJourney::linkedOutcome($record,$f)['outcome']['net_return_pct']===-2,'only baseline, never alternative limit results');
    foreach(['symbol','session','source_file','observation_hash','tracking_policy'] as $key){$bad=$f;$bad[$key]='wrong';ej(PaperEntryJourney::linkedOutcome($record,$bad)['outcome']===null,'mismatch refused: '.$key);}
    $intraday=strtotime('2026-10-07 10:00:00 +0900');$close=strtotime('2026-10-07 15:30:00 +0900');
    $cr=[['session'=>$close,'recorded_at'=>$close+3600,'status'=>'risk_blocked','ready'=>false]];
    ej(PaperEntryJourney::closeComparison($intraday,'intraday',$cr)['status']==='risk_blocked','lost intraday candidate visible at close');
    ej(PaperEntryJourney::closeComparison($intraday,'completed_fallback',$cr)===null,'fallback not classified as intraday');
    ej(PaperEntryJourney::closeComparison($intraday-86400,'intraday',$cr)===null,'next day not paired as same-day close');
    file_put_contents($folder.'/'.$a['id'].'.json','{}');
    ej(count(PaperEntryJourney::read($dir,'paper-kr','account1')['errors'])===1,'corrupted manual evidence surfaced');
    // End-to-end real audit format, exact observation hash and saved baseline result.
    mkdir($dir.'/rr-audit/paper-kr',0770,true);$file='20261006-112300-abcdef012345.json';
    $audit=['symbol'=>'123456.KS','name'=>'audit','session'=>$plan['signal_at'],'status'=>'evaluated','analysis'=>['plan'=>$plan],'bars'=>[],'input_hash'=>'test'];
    $captured=$plan['signal_at']+3600;
    file_put_contents($dir.'/rr-audit/paper-kr/'.$file,json_encode(['version'=>PaperRrAudit::VERSION,'membership'=>'observed_scan_only','recorded_at'=>$captured,'records'=>[$audit]]));
    $audit['source_file']=$file;$audit['captured_at']=$captured;$hash=hash('sha256',PaperRrAudit::encode($audit));
    $follow=['symbol'=>$audit['symbol'],'session'=>$audit['session'],'source_file'=>$file,'observation_hash'=>$hash,'tracking_policy'=>PaperTrackingInput::POLICY,
        'rejected'=>false,'reasons'=>[],'status'=>'pending','as_of'=>$captured+86400,'trades'=>[['kind'=>'baseline','outcome'=>['status'=>'incomplete','filled'=>true,'entry_at'=>$captured+86400,'entry_fill'=>99]]]];
    mkdir($dir.'/followup/paper-kr',0770,true);file_put_contents($dir.'/followup/paper-kr/latest.json',json_encode(['schema'=>1,'as_of'=>$captured+86400,'rows'=>[$audit['symbol'].'@'.$audit['session']=>$follow]]));
    $read=PaperEntryJourney::read($dir,'paper-kr','isa');ej(count($read['rows'])===1&&$read['rows'][0]['link']==='linked'&&$read['rows'][0]['outcome']['entry_fill']===99,'real file path links frozen plan through saved followup');
    // Render the page with the fixture to catch template/runtime errors, not just syntax.
    putenv('PAPER_STATE_DIR='.$dir);$_GET=['account'=>'paper-kr','profile'=>'isa'];ob_start();require __DIR__.'/../paper_journey.php';$html=ob_get_clean();
    ej(str_contains($html,'99.000')&&str_contains($html,'후속 결과 연결됨'),'page renders linked fill and status');
    file_put_contents($dir.'/followup/paper-kr/latest.json','{broken');
    $partial=PaperEntryJourney::read($dir,'paper-kr','account1');
    ej(count($partial['rows'])===3&&count($partial['errors'])===2,'broken followup keeps observations visible with explicit error');
}finally{cleanJourney($dir);}
