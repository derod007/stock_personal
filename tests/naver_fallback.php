<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/NaverSessionPatch.php';
require __DIR__.'/../bin/paper/Diagnostics.php';
function nf(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);echo "PASS $m\n";}
$tmp=sys_get_temp_dir().'/naver-fallback-'.bin2hex(random_bytes(4));mkdir($tmp,0700,true);
try{
    $now=strtotime('2026-10-06 20:23:14 +0900');$start=strtotime('2026-10-06 09:00:00 +0900')-59*86400;$bars=[];$timestamps=[];$q=[];
    for($i=0;$i<60;$i++){
        $bar=['time'=>$start+$i*86400,'open'=>48000,'high'=>48500,'low'=>47800,'close'=>48050,'volume'=>10000];$bars[]=$bar;$timestamps[]=$bar['time'];
        foreach(['open','high','low','close','volume'] as $key)$q[$key][]=$bar[$key];
    }
    $data=['chart'=>['result'=>[['meta'=>['symbol'=>'028050.KS'],'timestamp'=>$timestamps,'indicators'=>['quote'=>[$q]]]]]];
    $provider=json_encode($data);$bytes=json_encode($bars);
    $source=['symbol'=>'028050.KS','fetched_at'=>gmdate('c',$now),'sha256'=>hash('sha256',$bytes),'provider_sha256'=>hash('sha256',$provider),
        'file'=>$tmp.'/028050.KS.json','provider_file'=>$tmp.'/provider.json','price_basis'=>'provider quote OHLC; no additional adjustment applied'];
    $session=PaperYahooSessionEvidence::verify($source,$bars,$provider,$now);
    nf($session===strtotime('2026-10-06 15:30:00 +0900'),'full same-day provider candle accepted');
    foreach(['symbol','close','volume','high','duplicate'] as $case){
        $bad=$data;
        if($case==='symbol')$bad['chart']['result'][0]['meta']['symbol']='005930.KS';
        elseif($case==='duplicate')$bad['chart']['result'][0]['timestamp'][]=end($timestamps);
        else $bad['chart']['result'][0]['indicators']['quote'][0][$case][59]=null;
        $raw=json_encode($bad);$s=$source;$s['provider_sha256']=hash('sha256',$raw);
        $rejected=false;try{PaperYahooSessionEvidence::verify($s,$bars,$raw,$now);}catch(RuntimeException $e){$rejected=true;}nf($rejected,'reject '.$case);
    }
    foreach(['preclose','stale','future','old_day','hash','synthetic','edited_close'] as $case){
        $s=$source;$b=$bars;$time=$now;
        if($case==='preclose'){$time=strtotime('2026-10-06 14:00:00 +0900');$s['fetched_at']=gmdate('c',$time);}
        elseif($case==='stale')$s['fetched_at']=gmdate('c',$now-3601);
        elseif($case==='future')$s['fetched_at']=gmdate('c',$now+1);
        elseif($case==='old_day'){$time=$now+86400;$s['fetched_at']=gmdate('c',$time);}
        elseif($case==='hash')$s['provider_sha256']=str_repeat('0',64);
        elseif($case==='synthetic')$b[59]['synthetic']=true;
        else $b[59]['close']=48000;
        $rejected=false;try{PaperYahooSessionEvidence::verify($s,$b,$provider,$time);}catch(RuntimeException $e){$rejected=true;}nf($rejected,'reject '.$case);
    }
    file_put_contents($source['file'],$bytes);file_put_contents($source['provider_file'],$provider);file_put_contents($tmp.'/sources.json',json_encode([$source]));
    $naver=new ChartEntryLab\NaverDailyQuotes($tmp.'/cache');
    $r=PaperNaverSessionPatch::applyDirectory($tmp,$naver,fn($symbol)=>[],$now);
    $saved=json_decode(file_get_contents($tmp.'/sources.json'),true)[0];
    nf(count($r['fallbacks'])===1&&$r['patched']===0&&file_get_contents($source['file'])===$bytes&&$saved['sha256']===$source['sha256'],'fallback records provenance without modifying history or hash');
    file_put_contents($source['provider_file'],'corrupt');$manifest=file_get_contents($tmp.'/sources.json');
    $rejected=false;try{PaperNaverSessionPatch::applyDirectory($tmp,$naver,fn($symbol)=>[],$now);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'fallback rejected');}nf($rejected,'reason retained on blocked fallback');
    nf(file_get_contents($tmp.'/sources.json')===$manifest,'failed fallback leaves manifest unchanged');
    $runs=$tmp.'/runs';mkdir($runs);
    foreach([
        ['started_at'=>100,'status'=>'success','stage'=>'account','summary'=>['last_session'=>50]],
        ['started_at'=>200,'status'=>'success','stage'=>'scan','summary'=>['empty_universe'=>true]],
        ['started_at'=>300,'status'=>'success','stage'=>'account','summary'=>[]],
        ['started_at'=>400,'status'=>'success','stage'=>'account','summary'=>['last_session'=>60]],
    ] as $i=>$run)file_put_contents($runs.'/'.$i.'.json',json_encode($run+['run_id'=>(string)$i]));
    $d=PaperDiagnostics::runs($runs,500);$codes=array_column(array_reverse($d['recent']),'outcome');
    nf($codes===['success_first_observed','success_empty_universe','success_session_unknown','success_advanced'],'empty or unknown runs do not claim account session evaluation');
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($tmp);
}
