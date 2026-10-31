<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';
foreach(['Tracker','Store','Charts','EntryReference','Page'] as $f)require __DIR__.'/bin/type1/'.$f.'.php';
use ChartEntryLab\Type1Scan\{Store,Charts};
use ChartEntryLab\Type1Breakout;
header('Cache-Control: no-store');
$report=['ok'=>true,'as_of'=>date(DATE_ATOM),'source'=>'saved','saved_day'=>null,'scope'=>($_GET['scope']??'breakout')==='all'?'all':'breakout','rows'=>[],'errors'=>[],'summary'=>['total'=>0,'evaluated'=>0,'matches'=>0,'errors'=>0],'error'=>null];
try{
    $state=Store::load(Store::folder());$day=$_GET['day']??$state['last_day'];
    if(!$day)throw new RuntimeException('아직 마감 저장 기록이 없습니다.');
    $snapshot=Charts::snapshot(Store::folder(),$state,$day);$capture=$state['captures'][$day];
    $report['saved_days']=array_keys($state['captures']);
    $report['saved_day']=$day;$report['as_of']=date(DATE_ATOM,$capture['as_of']);
    foreach($capture['rows'] as $row){
        $report['summary']['total']++;
        if($row['error']!==null){$report['errors'][]=['symbol'=>$row['symbol'],'name'=>$row['name'],'reason'=>$row['error']];continue;}
        $report['summary']['evaluated']++;$c=$row['classification'];if(!($c['matches']??false))continue;
        $report['rows'][]=array_merge($row,$c,['breakout20'=>Type1Breakout::evidence($snapshot['prices'][$row['symbol']]??[],$row['symbol'],$c,$capture['as_of'])]);
    }
    $nextError=null;$later=null;
    try{if($state['last_day']>$day)$later=Charts::snapshot(Store::folder(),$state,$state['last_day']);}
    catch(Throwable){$nextError='이후 스냅샷 검증 실패';}
    foreach($report['rows'] as &$row){
        $row['next_open']=\ChartEntryLab\Type1Scan\EntryReference::nextOpen($day,$row['symbol'],$row['breakout20'],$row['analysis_close'],$later,$nextError);
    }unset($row);
    usort($report['rows'],static fn($a,$b)=>($a['rank']<=>$b['rank'])?:strcmp($a['symbol'],$b['symbol']));
    $report['summary']['matches']=count($report['rows']);$report['summary']['errors']=count($report['errors']);
}catch(Throwable $e){$report['ok']=false;$report['error']=$e instanceof RuntimeException?$e->getMessage():'마감 기록을 확인하지 못했습니다.';}
if(($_GET['format']??'')==='json'){header('Content-Type: application/json; charset=utf-8');echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
$report['sort'] = ($_GET['sort'] ?? '') === 'near' ? 'near' : 'amount';
\ChartEntryLab\Type1Scan\render($report);
