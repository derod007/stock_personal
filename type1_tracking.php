<?php
declare(strict_types=1);
require __DIR__.'/bin/bootstrap.php';foreach(['Tracker','Store','TrackingView'] as $f)require __DIR__.'/bin/type1/'.$f.'.php';
header('Cache-Control: no-store');
try{$state=\ChartEntryLab\Type1Scan\Store::load(\ChartEntryLab\Type1Scan\Store::folder());$error=null;}
catch(Throwable $e){$state=null;$error='저장 기록을 읽지 못했습니다. 서버 상태를 확인해 주세요.';http_response_code(503);}
if(($_GET['format']??'')==='json'){header('Content-Type: application/json; charset=utf-8');echo json_encode(['state'=>$state,'error'=>$error],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
?><!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>유형1 고정 관찰</title><style>body{background:#0b1220;color:#e7edf8;font-family:sans-serif;margin:30px auto;max-width:1280px;padding:0 20px}a{color:#95bfff}p{line-height:1.7}.table-wrap{overflow:auto}table{border-collapse:collapse;width:100%;white-space:nowrap}th,td{padding:12px;border-bottom:1px solid #35435a;text-align:right}small{display:block;color:#a6b5cb}.notice{padding:16px;background:#192b45}</style></head><body><main><a href="type1_scan.php">거래대금 스캔(유형1)으로</a><?php \ChartEntryLab\Type1Scan\renderTracking($state,$error); ?></main></body></html>
