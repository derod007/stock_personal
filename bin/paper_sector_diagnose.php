<?php
declare(strict_types=1);
/**
 * Research only: why did the sector cache store "기타" for the replay symbols?
 * For a few symbols it fetches the same Naver page SectorMap uses (same URL, same headers) and reports which step fails:
 * transport, HTTP status, empty body, encoding, response format or industry parsing.
 * It then runs the real SectorMap::resolve into a temporary folder. No operational cache is read for writing or written.
 *
 *   php bin/paper_sector_diagnose.php --symbols=005930.KS,000660.KS --out=<json> [--tmp=<dir>] [--keep=1]
 */
require __DIR__.'/bootstrap.php';
use ChartEntryLab\NaverDailyQuotes;
use ChartEntryLab\SectorMap;

$o=[];
foreach(array_slice($argv,1) as $a){
    if(!preg_match('/^--([a-z-]+)=(.*)$/s',$a,$m))throw new InvalidArgumentException('Unknown argument '.$a);
    $o[$m[1]]=$m[2];
}
$symbols=array_values(array_filter(array_map('trim',explode(',',$o['symbols']??''))));
if($symbols===[])throw new InvalidArgumentException('--symbols required');
$out=rtrim($o['out']??'','/\\');
if($out==='')throw new InvalidArgumentException('--out required');
$norm=str_replace('\\','/',$out);
if(preg_match('#/data/(ohlcv|raw|cache|paper)#',$norm)||str_contains($norm,'/history-research/'))throw new RuntimeException('Refusing to write into operational or dataset files');

$tmp=$o['tmp']??sys_get_temp_dir().DIRECTORY_SEPARATOR.'noramu-sector-diag-'.bin2hex(random_bytes(4));
$tnorm=str_replace('\\','/',$tmp);
if(preg_match('#/data/(ohlcv|raw|cache|paper)#',$tnorm))throw new RuntimeException('Temporary folder must not be an operational folder');
if(!is_dir($tmp)&&!mkdir($tmp,0777,true))throw new RuntimeException('Cannot create '.$tmp);

const USER_AGENT='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const UPJONG_RE='#sise_group_detail\.naver\?type=upjong&(?:amp;)?no=\d+"[^>]*>([^<]{2,40})</a>#u';

function fetchRaw(string $url):array
{
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>15,CURLOPT_ENCODING=>'',
        CURLOPT_HTTPHEADER=>['User-Agent: '.USER_AGENT,'Accept: text/html,application/xhtml+xml','Accept-Language: ko-KR,ko;q=0.9,en;q=0.8','Referer: https://finance.naver.com/'],
        CURLOPT_HEADER=>true,
    ]);
    $t=microtime(true);
    $resp=curl_exec($ch);
    $r=['curl_errno'=>curl_errno($ch),'curl_error'=>curl_error($ch),'status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),
        'effective_url'=>curl_getinfo($ch,CURLINFO_EFFECTIVE_URL),'redirects'=>(int)curl_getinfo($ch,CURLINFO_REDIRECT_COUNT),
        'ms'=>(int)round((microtime(true)-$t)*1000),'headers'=>'','body'=>''];
    if(is_string($resp)){
        $size=(int)curl_getinfo($ch,CURLINFO_HEADER_SIZE);
        $r['headers']=substr($resp,0,$size);$r['body']=substr($resp,$size);
    }
    curl_close($ch);
    return $r;
}

function toUtf8Same(string $html):array
{
    if(mb_check_encoding($html,'UTF-8')&&preg_match('/[가-힣]/u',$html)===1)return ['text'=>$html,'path'=>'already_utf8'];
    $c=@mb_convert_encoding($html,'UTF-8','EUC-KR');
    if(is_string($c)&&$c!==''&&preg_match('/[가-힣]/u',$c)===1)return ['text'=>$c,'path'=>'euc_kr_converted'];
    return ['text'=>$html,'path'=>'unchanged_no_hangul'];
}

function lastHeader(string $headers,string $name):?string
{
    $v=null;
    foreach(preg_split('/\r?\n/',$headers)?:[] as $line){
        if(stripos($line,$name.':')===0)$v=trim(substr($line,strlen($name)+1));
    }
    return $v;
}

$rows=[];$first=true;
foreach($symbols as $symbol){
    $code=NaverDailyQuotes::codeOf($symbol);
    if($code===null)throw new InvalidArgumentException('Not a Korean symbol: '.$symbol);
    if(!$first)sleep(1);
    $first=false;
    $url='https://finance.naver.com/item/main.naver?code='.rawurlencode($code);
    $r=fetchRaw($url);
    $row=['symbol'=>$symbol,'url'=>$url,'curl_errno'=>$r['curl_errno'],'curl_error'=>$r['curl_error'],'status'=>$r['status'],
        'effective_url'=>$r['effective_url'],'redirects'=>$r['redirects'],'ms'=>$r['ms'],
        'content_type'=>lastHeader($r['headers'],'Content-Type'),'content_encoding'=>lastHeader($r['headers'],'Content-Encoding'),
        'body_bytes'=>strlen($r['body'])];
    if($r['curl_errno']!==0||$r['body']===''&&$r['status']===0){
        $row['cause']='http_failure_transport';
    }elseif($r['status']>=400){
        $row['cause']='http_failure_status';
        $row['body_head']=mb_substr(preg_replace('/\s+/u',' ',strip_tags($r['body']))??'',0,160);
    }elseif($r['body']===''){
        $row['cause']='http_failure_empty_body';
    }else{
        $conv=toUtf8Same($r['body']);$html=$conv['text'];
        $row['encoding_path']=$conv['path'];
        $row['body_valid_utf8']=mb_check_encoding($r['body'],'UTF-8');
        $row['meta_charset']=preg_match('/charset=["\']?([A-Za-z0-9_-]+)/i',$r['body'],$m)===1?strtolower($m[1]):null;
        $row['has_hangul_after_decode']=preg_match('/[가-힣]/u',$html)===1;
        $title=preg_match('#<title>\s*([^<]*)</title>#u',$html,$m)===1?trim(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8')):null;
        $row['title']=$title;
        $row['name_parsed']=preg_match('#<title>\s*([^<:]+)\s*[:：]#u',$html,$m)===1?trim($m[1]):null;
        $row['upjong_anchor_count']=preg_match_all('#sise_group_detail\.naver\?type=upjong#u',$html)?:0;
        $row['upjong_parsed']=preg_match(UPJONG_RE,$html,$m)===1?trim((string)preg_replace('/\s+/u','',html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8'))):null;
        $row['has_finance_markers']=str_contains($html,'wrap_company')||str_contains($html,'tab_con1');
        if($row['encoding_path']==='unchanged_no_hangul'||$row['has_hangul_after_decode']===false){
            $row['cause']='encoding_failure';
        }elseif($row['upjong_parsed']!==null&&$row['name_parsed']!==null){
            $row['cause']='ok';
        }elseif($row['upjong_anchor_count']===0&&!$row['has_finance_markers']){
            $row['cause']='response_format_changed_or_blocked_page';
            $row['body_head']=mb_substr(preg_replace('/\s+/u',' ',strip_tags($html))??'',0,160);
        }elseif($row['upjong_parsed']===null){
            $row['cause']='industry_parse_failure';
            $row['upjong_context']=preg_match('#.{0,120}upjong.{0,160}#su',$html,$m)===1?preg_replace('/\s+/u',' ',$m[0]):null;
        }else{
            $row['cause']='name_parse_failure';
        }
    }
    // The real collection path, into a temporary folder.
    $sec=new SectorMap($tmp.'/sector');
    $res=$sec->resolve($symbol,false);
    $row['sector_map_result']=['sector'=>$res['sector'],'sector_bucket'=>$res['sector_bucket'],'name'=>$res['name']];
    $row['sector_map_stored_placeholder']=$res['sector']==='기타'&&$res['name']===null;
    $rows[]=$row;
}

$causes=[];
foreach($rows as $r)$causes[$r['cause']]=($causes[$r['cause']]??0)+1;
ksort($causes);
$report=['schema'=>1,'kind'=>'sector_collection_diagnosis_v1','checked_at'=>gmdate('c'),'php'=>PHP_VERSION,
    'collector'=>'ChartEntryLab\\SectorMap::resolve (useCache=false) into a temporary folder; raw fetch repeats its URL and headers',
    'symbols'=>$symbols,'cause_counts'=>$causes,'rows'=>$rows];
$json=json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
$dest=str_ends_with(strtolower($out),'.json')?$out:$out.'/sector-collection-diagnosis.json';
if(!is_dir(dirname($dest)))mkdir(dirname($dest),0777,true);
file_put_contents($dest,str_replace("\r\n","\n",$json));

if(($o['keep']??'')!=='1'){
    foreach(glob($tmp.'/sector/*')?:[] as $f)@unlink($f);
    @rmdir($tmp.'/sector');@rmdir($tmp);
}
echo $json;
