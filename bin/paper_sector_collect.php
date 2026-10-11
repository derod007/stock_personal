<?php
declare(strict_types=1);
/**
 * Research only: collect the CURRENT Naver industry name for the replay symbols into a research folder.
 *
 * Why not SectorMap::resolve: finance.naver.com/item/main.naver now answers 302 to stock.naver.com (a script-rendered page without the
 * upjong link SectorMap parses), so the operational collector stores the placeholder "기타" for every symbol.
 * This tool reads two JSON endpoints instead and never writes an operational cache:
 *   https://m.stock.naver.com/api/stock/<code>/integration                  -> industryCode, stockName
 *   https://m.stock.naver.com/api/stocks/industry?page=1&pageSize=100       -> industry no => name
 * The bucket is SectorMap::bucketOf, the operational rule. A symbol whose lookup fails is NOT written, so it stays unconfirmed.
 * The result is a research assumption: today's industry applied to the past, not a restored historical classification.
 *
 *   php bin/paper_sector_collect.php --out-dir=<research folder> --report=<json> [--symbols-from=<sector-map.json>] [--symbols=a,b]
 */
require __DIR__.'/bootstrap.php';require __DIR__.'/paper/SectorFreeze.php';
use ChartEntryLab\{NaverDailyQuotes,SectorMap};

const UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const LIST_URL='https://m.stock.naver.com/api/stocks/industry?page=%d&pageSize=100';
const ITEM_URL='https://m.stock.naver.com/api/stock/%s/integration';

function http(string $url):array
{
    $last=['ok'=>false,'reason'=>'http_transport','detail'=>'not tried'];
    for($try=1;$try<=3;$try++){
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>15,CURLOPT_ENCODING=>'',
            CURLOPT_HTTPHEADER=>['User-Agent: '.UA,'Accept: application/json','Accept-Language: ko-KR,ko;q=0.9','Referer: https://m.stock.naver.com/']]);
        $body=curl_exec($ch);
        $err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($body===false){$last=['ok'=>false,'reason'=>'http_transport','detail'=>$err,'attempts'=>$try];}
        elseif($status>=400){$last=['ok'=>false,'reason'=>'http_status','detail'=>'HTTP '.$status,'attempts'=>$try];if($status<500&&$status!==429)return $last;}
        elseif($body===''){$last=['ok'=>false,'reason'=>'http_empty_body','detail'=>'HTTP '.$status,'attempts'=>$try];}
        else return ['ok'=>true,'body'=>$body,'status'=>$status,'attempts'=>$try];
        usleep(700000);
    }
    return $last;
}

function decode(string $body):?array
{
    if(!mb_check_encoding($body,'UTF-8'))return null;
    $j=json_decode($body,true);
    return is_array($j)?$j:null;
}

function unsafeDir(string $p):bool
{
    $n=str_replace('\\','/',$p);
    return preg_match('#/data/(ohlcv|raw|cache|paper)(/|$)#',$n)===1||str_contains($n,'/history-research/');
}

$o=[];
foreach(array_slice($argv,1) as $a){
    if(!preg_match('/^--([a-z-]+)=(.*)$/s',$a,$m))throw new InvalidArgumentException('Unknown argument '.$a);
    $o[$m[1]]=$m[2];
}
$root=dirname(__DIR__);
$outDir=rtrim(str_replace('\\','/',$o['out-dir']??''),'/');
$report=rtrim(str_replace('\\','/',$o['report']??''),'/');
if($outDir===''||$report==='')throw new InvalidArgumentException('--out-dir and --report required');
if(unsafeDir($outDir)||unsafeDir($report))throw new RuntimeException('Refusing to write into operational or dataset folders');
if(str_starts_with($outDir,str_replace('\\','/',$root).'/'))throw new RuntimeException('--out-dir must be outside the repository');

if(isset($o['symbols'])){
    $symbols=array_values(array_filter(array_map('trim',explode(',',$o['symbols']))));
}else{
    $from=$o['symbols-from']??$root.'/docs/historical-account-replay/sector-map.json';
    $listed=json_decode((string)file_get_contents($from),true,512,JSON_THROW_ON_ERROR);
    $symbols=array_keys($listed['sectors']??[]);
}
sort($symbols);
if($symbols===[])throw new RuntimeException('No symbols');

$guard=[];
foreach(['data/cache/sector','data/raw/cache/sector'] as $rel){
    $d=$root.'/'.$rel;
    $guard[$rel]=is_dir($d)?PaperSectorFreeze::fingerprint($d):null;
}

$kst=new DateTimeZone('Asia/Seoul');
$groups=[];$pages=0;$listHashes=[];$listFail=null;$total=null;
for($page=1;$page<=10;$page++){
    $r=http(sprintf(LIST_URL,$page));
    if(!$r['ok']){$listFail=$r;break;}
    $j=decode($r['body']);
    if($j===null||!is_array($j['groups']??null)||!isset($j['totalCount'])){$listFail=['reason'=>'response_format','detail'=>'industry list has no groups/totalCount'];break;}
    $pages++;$listHashes[]=hash('sha256',$r['body']);$total=(int)$j['totalCount'];
    foreach($j['groups'] as $g){
        $no=(string)($g['no']??'');$name=trim((string)($g['name']??''));
        if($no==='')continue;
        $groups[$no]=(string)(preg_replace('/\s+/u','',$name)??$name);
    }
    if(count($groups)>=$total||$j['groups']===[])break;
}
$listOk=$listFail===null&&$total!==null&&count($groups)===$total;
if(!$listOk&&$listFail===null)$listFail=['reason'=>'response_format','detail'=>'industry list is incomplete: '.count($groups).' of '.$total];
$listSha=hash('sha256',implode(',',$listHashes));

if(!is_dir($outDir.'/sector')&&!mkdir($outDir.'/sector',0777,true))throw new RuntimeException('Cannot create '.$outDir);
$ruleDir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'noramu-sector-rule-'.bin2hex(random_bytes(4));
$rules=new SectorMap($ruleDir);

$rows=[];$failed=[];$secured=0;$first=true;
foreach($symbols as $symbol){
    $code=NaverDailyQuotes::codeOf($symbol);
    $row=['symbol'=>$symbol];
    $file=$code!==null?$outDir.'/sector/sector_'.$code.'.json':null;
    $fail=static function(string $reason,string $detail='')use(&$rows,&$failed,$symbol,$file):void{
        // Stale file from an earlier run must not be mistaken for a collected value.
        if($file!==null&&is_file($file))unlink($file);
        $failed[]=['symbol'=>$symbol,'reason'=>$reason,'detail'=>$detail];
        $rows[$symbol]=['status'=>'unconfirmed','reason'=>$reason,'detail'=>$detail];
    };
    if($code===null){$fail('not_a_kr_symbol');continue;}
    if(!$listOk){$fail('industry_list_unavailable',(string)($listFail['reason']??'').' '.(string)($listFail['detail']??''));continue;}
    if(!$first)usleep(300000);
    $first=false;
    $url=sprintf(ITEM_URL,rawurlencode($code));
    $r=http($url);
    if(!$r['ok']){$fail($r['reason'],(string)$r['detail']);continue;}
    $j=decode($r['body']);
    if($j===null){$fail('encoding_or_json_invalid');continue;}
    if((string)($j['itemCode']??'')!==$code){$fail('response_format','itemCode does not match');continue;}
    $industryCode=(string)($j['industryCode']??'');
    if($industryCode===''){$fail('industry_code_missing');continue;}
    $industry=$groups[$industryCode]??null;
    if($industry===null||$industry===''){$fail('industry_code_unknown','industryCode '.$industryCode);continue;}
    if($industry===PaperSectorFreeze::PLACEHOLDER_SECTOR){$fail('industry_placeholder','industryCode '.$industryCode);continue;}
    $stockName=trim((string)($j['stockName']??''));
    $bucket=$rules->bucketOf($industry,$stockName);
    $entry=['sector'=>$industry,'sector_bucket'=>$bucket,'sector_label'=>SectorMap::BUCKETS[$bucket]??'기타',
        'name'=>$stockName!==''?$stockName:null,
        'source_url'=>$url,'industry_code'=>$industryCode,'stock_name_source'=>'integration.stockName',
        'fetched_at'=>(new DateTimeImmutable('now',$kst))->format('c'),
        'response_sha256'=>hash('sha256',$r['body']),'industry_list_sha256'=>$listSha];
    file_put_contents($file,json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n");
    $rows[$symbol]=['status'=>'secured','sector_name'=>$industry,'bucket'=>$bucket,'industry_code'=>$industryCode,'response_sha256'=>$entry['response_sha256'],'attempts'=>$r['attempts']];
    $secured++;
}
if(is_dir($ruleDir)&&str_contains($ruleDir,'noramu-sector-rule-'))@rmdir($ruleDir);

$after=[];
foreach($guard as $rel=>$before){
    $d=$root.'/'.$rel;
    $after[$rel]=is_dir($d)?PaperSectorFreeze::fingerprint($d):null;
    if($after[$rel]!==$before)throw new RuntimeException('Operational sector cache changed: '.$rel);
}
$reasons=[];foreach($failed as $f)$reasons[$f['reason']]=($reasons[$f['reason']]??0)+1;ksort($reasons);
$buckets=[];foreach($rows as $r)if(($r['status']??'')==='secured')$buckets[$r['bucket']]=($buckets[$r['bucket']]??0)+1;ksort($buckets);
$doc=['schema'=>1,'kind'=>'sector_collection_v2','assumption'=>PaperSectorFreeze::ASSUMPTION,
    'collected_at'=>(new DateTimeImmutable('now',$kst))->format('c'),
    'why_not_sector_map'=>'finance.naver.com/item/main.naver answers 302 to stock.naver.com, which has no upjong link. SectorMap stores "기타" and a null name for every symbol.',
    'endpoints'=>['industry_list'=>LIST_URL,'stock'=>ITEM_URL],
    'industry_list'=>['groups'=>count($groups),'total_count'=>$total,'pages'=>$pages,'sha256'=>$listSha,'ok'=>$listOk,'failure'=>$listFail],
    'research_cache_dir'=>$outDir,
    'counts'=>['symbols'=>count($symbols),'secured'=>$secured,'unconfirmed'=>count($failed)],
    'failure_reasons'=>$reasons,'bucket_counts'=>$buckets,
    'operational_caches_unchanged'=>$guard===$after,'operational_cache_fingerprints'=>$after,
    'symbols'=>$rows];
$json=json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
if(!is_dir(dirname($report))&&!mkdir(dirname($report),0777,true))throw new RuntimeException('Cannot create '.dirname($report));
file_put_contents($report,$json);
echo json_encode(['report'=>$report,'counts'=>$doc['counts'],'failure_reasons'=>$reasons,'bucket_counts'=>$buckets,'operational_caches_unchanged'=>$doc['operational_caches_unchanged']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
