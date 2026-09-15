<?php
declare(strict_types=1);
require __DIR__.'/v7.php';
require __DIR__.'/../bin/paper/Diagnostics.php';
function dcheck(bool $ok,string $name): void {if(!$ok)throw new RuntimeException($name);echo "diagnostics OK: $name\n";}
$events=[];
$add=function($type,$p)use(&$events){$events[]=['type'=>$type,'payload'=>$p];};
foreach(['forward','catchup','replay'] as $i=>$origin) {
    $p=['symbol'=>'MU','session'=>10000000+$i,'origin'=>$origin,'plan'=>['reason'=>'돌파 확인 대기'],'quality'=>['reasons'=>['bad_bar','bad_bar']]];
    $add('snapshot',$p);$add('decision',['symbol'=>'MU','session'=>$p['session'],'reason'=>'signal_not_confirmed']);
}
$add('fill',['symbol'=>'MU','session'=>10000001]);
$add('snapshot',['symbol'=>'OLD','session'=>1,'origin'=>'forward']);
$r=PaperDiagnostics::summarize($events);
dcheck($r['origins']['forward']['counts']['snapshot']===1,'window excludes old snapshots');
dcheck($r['origins']['catchup']['reasons']['decision:signal_not_confirmed']===1,'catchup separate');
dcheck($r['origins']['replay']['plan_reasons']['돌파 확인 대기']===1,'preserved plan reason');
dcheck($r['origins']['forward']['quality_reasons']['bad_bar']===1,'quality deduplicated per snapshot');
dcheck($r['origins']['execution']['counts']['fill']===1,'execution separate from recommendations');
$dir=sys_get_temp_dir().'/diagnostics-'.bin2hex(random_bytes(5));mkdir($dir);
try {
    foreach([[100,'success',10],[200,'success',10],[300,'success',11],[400,'failed',0],[500,'running',0]] as $i=>[$t,$status,$session]) {
        file_put_contents($dir.'/'.$i.'.json',json_encode(['run_id'=>(string)$i,'started_at'=>$t,'finished_at'=>null,'status'=>$status,'stage'=>'account','summary'=>['last_session'=>$session]]));
    }
    file_put_contents($dir.'/bad.json','{');
    $r=PaperDiagnostics::runs($dir,5000);
    dcheck($r['latest']['outcome']==='unfinished','interrupted run not success');
    dcheck($r['recent'][1]['outcome']==='failed','failure remains failure');
    dcheck($r['recent'][2]['outcome']==='success_advanced','new session');
    dcheck($r['recent'][3]['outcome']==='success_no_new_session','normal no-op');
    dcheck($r['invalid_files']===1,'corrupt operational log visible');
} finally {foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
echo "v8: 10 diagnostic checks passed\n";
