<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';
foreach(['Tracker','Store','TrackingView','Charts'] as $f)require __DIR__.'/bin/type1/'.$f.'.php';
use ChartEntryLab\Type1Scan\{Store,Charts};
header('Cache-Control: no-store');
$detail=null;$quotes=[];$chartError=null;$scope=($_GET['scope']??'new')==='all'?'all':'new';
try{$state=Store::load(Store::folder());$error=null;}
catch(Throwable $e){$state=null;$error='저장 기록을 읽지 못했습니다. 서버 상태를 확인해 주세요.';http_response_code(503);}
if($state && (isset($_GET['day']) || isset($_GET['symbol']))){
    try{
        if(!is_string($_GET['day']??null)||!is_string($_GET['symbol']??null))throw new InvalidArgumentException('판단일과 종목을 함께 지정해 주세요.');
        $snapshot=Charts::snapshot(Store::folder(),$state,$_GET['day']);
        $detail=Charts::detail($state,$snapshot,$_GET['symbol']);
    }catch(Throwable $e){$chartError=$e instanceof InvalidArgumentException?$e->getMessage():'당시 저장 근거를 확인할 수 없어 차트를 표시하지 않습니다.';http_response_code($e instanceof InvalidArgumentException?400:503);}
}elseif($state && $state['last_day'] && ($_GET['format']??'')!=='json'){
    try{$quotes=Charts::latestQuotes($state,Charts::snapshot(Store::folder(),$state,$state['last_day']));}
    catch(Throwable $e){$chartError='최근 가격 스냅샷을 확인하지 못했습니다. 후보 기록은 유지하며 가격 비교는 미확정으로 표시합니다.';}
}
if(($_GET['format']??'')==='json'){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(isset($_GET['day'])||isset($_GET['symbol'])?['detail'=>$detail,'error'=>$error??$chartError]:['state'=>$state,'error'=>$error],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
?><!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>유형1 고정 관찰</title>
<style>body{background:#0b1220;color:#e7edf8;font-family:sans-serif;margin:30px auto;max-width:1280px;padding:0 20px}a{color:#95bfff}p{line-height:1.7}.table-wrap{overflow:auto}table{border-collapse:collapse;width:100%;white-space:nowrap}th,td{padding:12px;border-bottom:1px solid #35435a;text-align:right}small{display:block;color:#a6b5cb}.notice{padding:16px;background:#192b45}.filters{display:flex;gap:12px;margin:20px 0}.filters a,summary{padding:12px;border:1px solid #35435a;border-radius:8px}.filters [aria-current]{background:#244473;color:white}details{margin:24px 0}summary{cursor:pointer}.candles{display:block;width:100%;min-width:680px;background:#111e30;border-radius:12px}.candles text{fill:#bdcce0;font-size:11px}.chart-detail{overflow-x:auto}.after{border-top:2px solid #60718d;padding-top:20px}a:focus-visible,summary:focus-visible{outline:2px solid #fff;outline-offset:4px}</style></head><body><main>
<nav><a href="type1_intraday.php">장중 조정·반등 관찰</a> · <a href="type1_scan.php">거래대금 스캔(유형1)</a> · <a href="type1_tracking.php">고정 신규 후보</a></nav>
<?php
if($chartError)echo '<p class="notice" role="alert">'.htmlspecialchars($chartError,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>';
if($detail)Charts::render($detail);
elseif(!isset($_GET['day'])&&!isset($_GET['symbol']))\ChartEntryLab\Type1Scan\renderTracking($state,$error,$scope,$quotes);
elseif($error)echo '<p role="alert">'.htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>';
?></main></body></html>
