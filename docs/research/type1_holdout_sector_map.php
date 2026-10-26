<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bin/bootstrap.php';
use ChartEntryLab\SectorMap;
$o=getopt('',['input:','output:']);
$d=json_decode(file_get_contents($o['input']),true,512,JSON_THROW_ON_ERROR);
$rules=new SectorMap(sys_get_temp_dir().'/type1-holdout-sector-rule');$sectors=$d['known'];$unconfirmed=[];
foreach($d['new'] as $sym=>$r){
 $sectors[$sym]=$r['status']==='confirmed_current'?$rules->bucketOf($r['industry'],$r['name']):'unclassified';
 if($r['status']!=='confirmed_current')$unconfirmed[$sym]=$r;
}
ksort($sectors);
$f=fopen($o['output'],'x');if(!$f)throw new RuntimeException('New sector output required');
fwrite($f,json_encode(['sectors'=>$sectors,'unconfirmed'=>$unconfirmed,'assumption'=>$d['assumption'],
 'evidence_sha256'=>hash_file('sha256',$o['input']),'rule_sha256'=>hash_file('sha256',dirname(__DIR__,2).'/src/SectorMap.php')],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");fclose($f);
echo json_encode(['symbols'=>count($sectors),'unconfirmed'=>count($unconfirmed)])."\n";
