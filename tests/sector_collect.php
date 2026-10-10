<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
use ChartEntryLab\SectorMap;

function ok(bool $c,string $why):void{if(!$c)throw new RuntimeException('FAIL '.$why);echo "PASS $why\n";}
function rmrf(string $d):void{
    if(!is_dir($d)||!str_contains($d,'sector-collect-test-'))return;
    foreach(scandir($d) as $n){if($n==='.'||$n==='..')continue;$p=$d.'/'.$n;is_dir($p)?rmrf($p):unlink($p);}
    rmdir($d);
}
$root=sys_get_temp_dir().'/sector-collect-test-'.getmypid();
rmrf($root);mkdir($root,0770,true);register_shutdown_function(fn()=>rmrf($root));

$list=json_encode(['totalCount'=>2,'page'=>1,'pageSize'=>100,'groups'=>[['no'=>278,'name'=>'반도체와반도체장비'],['no'=>283,'name'=>'전기 제품']]],JSON_UNESCAPED_UNICODE);
$item=static fn(string $code,string $name,?string $industry)=>json_encode(['itemCode'=>$code,'stockName'=>$name]+($industry!==null?['industryCode'=>$industry]:[]),JSON_UNESCAPED_UNICODE);
$redirectPage='<html><head><title>Npay 증권</title></head><body>스크립트 화면</body></html>';
$legacyHtml='<html><title>삼성전자 : 네이버페이 증권</title><a href="/sise/sise_group_detail.naver?type=upjong&amp;no=278">반도체와 반도체장비</a>한글</html>';

// 1) 현재 사이트: integration의 industryCode + 업종 목록
$calls=[];
$http=function(string $url,string $accept='')use(&$calls,$list,$item,$redirectPage):string{
    $calls[]=$url;
    // The real API answers 406 to an HTML Accept header.
    if(str_contains($url,'m.stock.naver.com')&&$accept!=='application/json')throw new RuntimeException('HTTP 406');
    if(str_contains($url,'/stocks/industry'))return $list;
    if(str_contains($url,'/005930/integration'))return $item('005930','삼성전자','278');
    if(str_contains($url,'/006260/integration'))return $item('006260','LS','283');
    if(str_contains($url,'finance.naver.com'))return $redirectPage;
    throw new RuntimeException('unexpected '.$url);
};
$map=new SectorMap($root.'/a',86400,\Closure::fromCallable($http));
$r=$map->resolve('005930.KS');
ok($r['sector']==='반도체와반도체장비'&&$r['sector_bucket']==='semi'&&$r['name']==='삼성전자','the current site gives the industry name and the operational bucket');
$r2=$map->resolve('006260.KS');
ok($r2['sector']==='전기제품'&&$r2['sector_bucket']==='energy','whitespace in the industry name is removed as before');
$listCalls=count(array_filter($calls,fn($u)=>str_contains($u,'/stocks/industry')));
ok($listCalls===1,'the industry list is read once per process');
ok(!array_filter($calls,fn($u)=>str_contains($u,'finance.naver.com')),'the redirected legacy page is not requested when the JSON answers');
$cached=json_decode((string)file_get_contents($root.'/a/sector_005930.json'),true);
ok($cached['sector']==='반도체와반도체장비'&&$cached['name']==='삼성전자','the collected value is cached');

// 2) 응답 형식이 바뀌어 JSON을 못 읽으면 예전 HTML 파싱으로 되돌아간다
$http2=function(string $url)use($legacyHtml):string{
    if(str_contains($url,'m.stock.naver.com'))throw new RuntimeException('HTTP 404');
    return $legacyHtml;
};
$r=(new SectorMap($root.'/b',86400,\Closure::fromCallable($http2)))->resolve('005930.KS');
ok($r['sector']==='반도체와반도체장비'&&$r['sector_bucket']==='semi','the legacy page is the fallback when the JSON cannot be read');

// 3) 둘 다 실패하면 '기타'를 캐시에 남기지 않는다
$http3=function(string $url)use($redirectPage):string{
    if(str_contains($url,'m.stock.naver.com'))throw new RuntimeException('HTTP 503');
    return $redirectPage;
};
$m3=new SectorMap($root.'/c',86400,\Closure::fromCallable($http3));
$r=$m3->resolve('000660.KS');
ok($r['sector']==='기타'&&!is_file($root.'/c/sector_000660.json'),'a total failure is returned as 기타 but is not cached');

// 4) ETF처럼 업종코드가 없으면 업종은 비고 이름으로 분류한다
$http4=function(string $url)use($list,$item):string{
    if(str_contains($url,'/stocks/industry'))return $list;
    return $item('069500','KODEX 200',null);
};
$r=(new SectorMap($root.'/d',86400,\Closure::fromCallable($http4)))->resolve('069500.KS');
ok($r['sector_bucket']==='etf'&&$r['sector']==='기타'&&$r['name']==='KODEX 200','a symbol without an industry code is classified by its name');

// 5) 자리표시자(이름 없음) 캐시는 다시 조회한다
mkdir($root.'/e',0770,true);
file_put_contents($root.'/e/sector_005930.json',json_encode(['sector'=>'기타','sector_bucket'=>'other','sector_label'=>'기타','name'=>null],JSON_UNESCAPED_UNICODE));
$r=(new SectorMap($root.'/e',86400,\Closure::fromCallable($http)))->resolve('005930.KS');
ok($r['sector']==='반도체와반도체장비','a placeholder cache entry is refetched instead of staying 기타');
echo "SECTOR_COLLECT_PASS\n";
