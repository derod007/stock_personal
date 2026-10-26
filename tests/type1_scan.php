<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/type1/Classifier.php';
require __DIR__.'/../bin/type1/Scanner.php';
require __DIR__.'/../bin/type1/Page.php';
use ChartEntryLab\Type1Scan\Classifier;
use ChartEntryLab\Type1Scan\Scanner;
$checks = 0;
function check(bool $ok,string $label): void { global $checks; $checks++; if (!$ok) { throw new RuntimeException($label); } }
function rejects(callable $fn,string $label): void { try { $fn(); } catch (Throwable $e) { check(true,$label); return; } check(false,$label); }
$c = new Classifier(__DIR__.'/../data/type1/centers.json');
$fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/type1-synthetic.json'),true);
$now = strtotime('2026-10-23 20:20:00 +09:00');
foreach ($fixtures as $f) {
    $r = $c->classify($f['bars'],'000001.KS',$now);
    check($r['cluster'] === $f['cluster'],'Python label parity');
    check(abs($r['distance_squared']-$f['distance']) < 1e-12,'Python distance parity');
}
$bars = $fixtures[1]['bars'];
$future = $bars[59]; $future['time_kst'] = '2026-10-26 09:00:00'; $future['close'] = 0;
check($c->classify([...$bars,$future],'000001.KS',$now)['cluster'] === 1,'Future excluded');
$incomplete = $future; $incomplete['time_kst'] = '2026-10-23 09:00:00'; $incomplete['is_complete'] = false;
check($c->classify([...$bars,$incomplete],'000001.KS',$now)['cluster'] === 1,'Incomplete excluded');
rejects(fn()=>$c->classify(array_slice($bars,1),'000001.KS',$now),'59 bars');
foreach (['close'=>0,'volume'=>-1,'synthetic'=>true,'high'=>0] as $key=>$value) {
    $bad=$bars; $bad[59][$key]=$value;
    rejects(fn()=>$c->classify($bad,'000001.KS',$now),'Bad recent bar must not be replaced');
}
rejects(fn()=>$c->classify([...$bars,$bars[59]],'000001.KS',$now),'Duplicate');
rejects(fn()=>$c->classify($bars,'000001.KS',$now+145*3600),'Stale');
rejects(fn()=>$c->classify($bars,'bad',$now),'Symbol');
$leaders = [ ['rank'=>9,'yahoo'=>'000001.KS','name'=>'<script>'], ['rank'=>2,'yahoo'=>'000002.KQ','name'=>'two'], ['rank'=>3,'yahoo'=>'000003.KS','name'=>'other'], ['rank'=>4,'yahoo'=>'000004.KS','name'=>'failed'] ];
$s = new Scanner($c);
$prices = function($symbol) use ($fixtures,$bars) { if ($symbol==='000004.KS') { throw new RuntimeException('remote private detail'); } return $symbol==='000003.KS' ? $fixtures[0]['bars'] : $bars; };
$r = $s->scan(fn()=>$leaders,$prices,$now);
check(array_column($r['rows'],'rank') === [2,9],'Amount rank ordering');
check($r['summary'] === ['total'=>4,'evaluated'=>3,'matches'=>2,'errors'=>1],'Coverage counts');
ob_start(); \ChartEntryLab\Type1Scan\render($r); $html=ob_get_clean();
check(str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>'),'Escaping');
check(str_contains($html,'2026-10-23') && str_contains($html,'거래대금 스캔(유형1)'),'Date and tab');
check(!str_contains($html,'remote private detail'),'Provider details hidden');
check(!$s->scan(fn()=>[], $prices,$now)['ok'],'Empty ranking failure');
check(!$s->scan(fn()=>throw new RuntimeException('network'),$prices,$now)['ok'],'Ranking fetch failure');
$r=$s->scan(fn()=>[$leaders[3]],$prices,$now);
ob_start(); \ChartEntryLab\Type1Scan\render($r); $html=ob_get_clean();
check(str_contains($html,'분류 가능한 종목이 없습니다'),'All failed distinct from no match');
$tmp=tempnam(sys_get_temp_dir(),'type1');file_put_contents($tmp,'{}');
try { rejects(fn()=>new Classifier($tmp),'Center tamper'); } finally { unlink($tmp); }
$index=file_get_contents(__DIR__.'/../index.php');
check((bool)preg_match('/>거래대금 스캔\(코스피\)<\/a>\s*<a[^>]*href="type1_scan.php"/u',$index),'Adjacent main tab');
echo "PASS $checks checks\n";
