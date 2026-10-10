<?php
declare(strict_types=1);
require_once __DIR__.'/../bin/paper/PullbackSegmentPilot.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}

// Synthetic bars: a low at index 10 that is the strict minimum of its 3+3 neighbours, a high at index 20.
$bars=[];
for($i=0;$i<30;$i++){
    $p=100+$i;$bars[]=['date'=>sprintf('2026-01-%02d',$i+1),'available_at'=>1000+$i,'open'=>$p,'high'=>$p+2,'low'=>$p-2,'close'=>$p+1,'volume'=>1000+$i,'ohlc_invalid'=>false,'ma20'=>null,'ma60'=>null];
}
$bars[10]['low']=50;$bars[20]['high']=300;
$p=PaperPullbackSegmentPilot::pivot($bars,10,'low');
check($p['confirmed']&&$p['confirmed_at']===1013,'a low that is lower than 3 bars on each side is confirmed at the third right bar');
$p=PaperPullbackSegmentPilot::pivot($bars,28,'low');
check(!$p['confirmed']&&$p['confirmed_at']===null,'a bar with fewer than 3 completed bars after it is not confirmed');
$tie=$bars;$tie[12]['low']=50;
check(!PaperPullbackSegmentPilot::pivot($tie,10,'low')['confirmed'],'an equal low within 3 bars blocks confirmation');
$inv=$bars;$inv[11]['ohlc_invalid']=true;
check(!PaperPullbackSegmentPilot::pivot($inv,10,'low')['confirmed'],'an invalid candle among the compared bars blocks confirmation');
check(PaperPullbackSegmentPilot::pivot($bars,20,'high')['confirmed'],'a high is tested on the high field');

// Selection: fixed order, one symbol per period, status slots, no filling from elsewhere.
$pool=[];
foreach(['A','B','C','D','E'] as $k=>$s)foreach(['wait_pullback','await_confirmation'] as $st)$pool[]=['case_id'=>hash('sha256',$s.$st),'symbol'=>$s.'.KS','month'=>'2026-0'.(($k%3)+1),'status'=>$st];
$u1=[];$m1=[];$r1=PaperPullbackSegmentPilot::pick($pool,3,['wait_pullback','await_confirmation','wait_pullback'],$u1,$m1,'d','waiting');
$u2=[];$m2=[];$r2=PaperPullbackSegmentPilot::pick(array_reverse($pool),3,['wait_pullback','await_confirmation','wait_pullback'],$u2,$m2,'d','waiting');
check(array_column($r1['picked'],'case_id')===array_column($r2['picked'],'case_id'),'the pick does not depend on the input order');
check(count(array_unique(array_column($r1['picked'],'symbol')))===3,'a symbol is used once');
check(array_column($r1['picked'],'status')===['wait_pullback','await_confirmation','wait_pullback'],'status slots are filled in the stated order');
$u3=[];$m3=[];$r3=PaperPullbackSegmentPilot::pick(array_slice($pool,0,4),6,null,$u3,$m3,'d','waiting');
check($r3['shortfall']>0&&count($r3['picked'])<6,'a short category is reported, not filled');

// Overlay: markers sit on the wick end of the named bar, and no overlay leaves the chart byte-identical.
$view=PaperPullbackSegmentPilot::viewOf($bars,30);
$plain=PaperReviewCharts::svg($view,[],'t',true,'005930.KS');
check($plain===PaperReviewCharts::svg($view,[],'t',true,'005930.KS',null),'a null overlay does not change the chart');
$overlay=['marks'=>[
    ['at'=>1020,'price'=>300.0,'field'=>'high','code'=>'H','main'=>true,'confirmed'=>true,'color'=>'#b03a2e','date'=>'2026-01-21'],
    ['at'=>1010,'price'=>50.0,'field'=>'low','code'=>'L','main'=>true,'confirmed'=>false,'color'=>'#1f618d','date'=>'2026-01-11'],
],'legend'=>[['text'=>'H test','color'=>'#b03a2e']]];
$svg=PaperReviewCharts::svg($view,[],'t',true,'005930.KS',$overlay);
preg_match_all('/<circle cx="([\d.]+)" cy="([\d.]+)"[^>]*data-field="(high|low)"/',$svg,$m,PREG_SET_ORDER);
check(count($m)===2,'both marks are drawn');
preg_match_all('/<line x1="([\d.]+)" y1="([\d.]+)" x2="\1" y2="([\d.]+)" stroke="#(?:c0392b|2471a3)"\/>/',$svg,$cl,PREG_SET_ORDER);
$on=true;
foreach($m as $x){
    $line=null;foreach($cl as $c)if(abs((float)$c[1]-(float)$x[1])<0.06)$line=$c;
    if($line===null||abs((float)$x[2]-(float)$line[$x[3]==='high'?2:3])>0.15)$on=false;
}
check($on,'each marker centre equals the wick end of its bar');
check(str_contains($svg,'data-confirmed="1"')&&str_contains($svg,'data-confirmed="0"'),'confirmed and observed-only marks are distinguishable');
check(str_contains($svg,'H test'),'the legend line is written');
// Freeze check: line endings must not matter, content must.
$src=__DIR__.'/../docs/pullback-segment-pilot';
$tmp=sys_get_temp_dir().'/pspf_'.bin2hex(random_bytes(4));mkdir($tmp.'/charts',0777,true);
$copy=function(string $mode) use($src,$tmp):void{
    $names=array_merge(['a-freeze.json','a-freeze-lf.json','annotations-a.src.json','annotations-a.json','protocol.md','sample-manifest.json'],array_map(fn($f)=>'charts/'.basename($f),glob($src.'/charts/*.svg')));
    foreach($names as $n){
        $raw=file_get_contents($src.'/'.$n);$lf=str_replace("\r\n","\n",$raw);
        $out=$mode==='lf'?$lf:($mode==='crlf'?str_replace("\n","\r\n",$lf):$raw);
        if(in_array($n,['a-freeze.json','a-freeze-lf.json'],true))$out=$raw;
        file_put_contents($tmp.'/'.$n,$out);
    }
};
$copy('lf');$r=PaperPullbackSegmentPilot::freezeCheck($tmp);
check($r['ok']&&$r['files']['protocol.md']['status']==='line_ending_only'&&$r['files']['protocol.md']['frozen_hash_reproduced_from_lf_content'],'an LF checkout of the frozen files passes and is reported as line-ending only');
$copy('crlf');$r=PaperPullbackSegmentPilot::freezeCheck($tmp);
check($r['ok']&&$r['files']['annotations-a.json']['status']==='line_ending_only','an all-CRLF checkout also passes');
$copy('raw');$r=PaperPullbackSegmentPilot::freezeCheck($tmp);
check($r['ok']&&$r['charts']['content_changed']===[],'the files as stored pass');
file_put_contents($tmp.'/protocol.md',str_replace('A','B',(string)file_get_contents($tmp.'/protocol.md')));
$r=PaperPullbackSegmentPilot::freezeCheck($tmp);
check(!$r['ok']&&$r['files']['protocol.md']['status']==='content_changed','a real content change fails even if line endings are untouched');
$copy('lf');$c=$tmp.'/charts/'.basename(glob($src.'/charts/*.svg')[0]);file_put_contents($c,str_replace('<svg','<svg ',(string)file_get_contents($c)));
check(!PaperPullbackSegmentPilot::freezeCheck($tmp)['ok'],'a changed chart fails');
foreach(glob($tmp.'/charts/*')as $f)unlink($f);foreach(glob($tmp.'/*.*')as $f)unlink($f);rmdir($tmp.'/charts');rmdir($tmp);
echo "OK\n";
