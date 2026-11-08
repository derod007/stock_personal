<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
foreach (['Tracker', 'Store', 'Charts', 'BreakoutWatch'] as $f) {
    require __DIR__.'/../bin/type1/'.$f.'.php';
}
use ChartEntryLab\Type1Scan\{BreakoutWatch, Store, Tracker};

$n = 0;
function check($x, $m): void
{
    global $n;
    if (!$x) {
        throw new RuntimeException($m);
    }
    $n++;
}
function days(string $start, int $count): array
{
    $out = [];
    $d = new DateTimeImmutable($start);
    while (count($out) < $count) {
        if ($d->format('N') < 6) {
            $out[] = $d->format('Y-m-d');
        }
        $d = $d->modify('+1 day');
    }
    return $out;
}
function candle(string $day, float $o, float $h, float $l, float $c, bool $complete = true, bool $synthetic = false): array
{
    return ['time_kst'=>$day.' 09:00:00','open'=>$o,'high'=>$h,'low'=>$l,'close'=>$c,'volume'=>100,'is_complete'=>$complete,'synthetic'=>$synthetic];
}
function history(array $cal, int $i, float $close): array
{
    $bars = [];
    for ($k = $i - 20; $k < $i; $k++) {
        $bars[] = candle($cal[$k], 90, 100, 80, 90);
    }
    $bars[] = candle($cal[$i], 100, 120, 99, $close);
    return $bars;
}
function putSnap(string $dir, array $snap): string
{
    $bytes = json_encode($snap, JSON_THROW_ON_ERROR);
    file_put_contents($dir.'/snapshot-'.$snap['day'].'.json', $bytes);
    return hash('sha256', $bytes);
}
function row(string $symbol, string $day, float $close): array
{
    return ['symbol'=>$symbol,'name'=>$symbol,'rank'=>1,'error'=>null,'classification'=>['matches'=>true,'analysis_date'=>$day,'analysis_close'=>$close]];
}

$cal = days('2026-01-05', 45);
$j = 22;
$tmp = sys_get_temp_dir().'/breakout-watch-'.bin2hex(random_bytes(4));
mkdir($tmp);
try {
    $symbol = '425420.KQ';
    $other = '144960.KQ';
    $before = $cal[$j - 1];
    $start = $cal[$j];
    $snapBefore = ['day'=>$before,'as_of'=>strtotime($before.' 20:20:00 +0900'),'days'=>array_slice($cal, 0, $j),'leaders'=>[['yahoo'=>$symbol,'name'=>'이전','rank'=>1]],'prices'=>[$symbol=>history($cal, $j - 1, 110)]];
    $hashBefore = putSnap($tmp, $snapBefore);
    $snap = ['day'=>$start,'as_of'=>strtotime($start.' 20:20:00 +0900'),'days'=>array_slice($cal, 0, $j + 1),'leaders'=>[['yahoo'=>$symbol,'name'=>'시행','rank'=>1]],'prices'=>[$symbol=>history($cal, $j, 110)]];
    $hash = putSnap($tmp, $snap);
    $state = ['version'=>Tracker::VERSION,'days'=>array_slice($cal, 0, $j + 1),'last_day'=>$start,'episodes'=>[],'trades'=>[$start.'|'.$symbol=>['symbol'=>$symbol,'status'=>'closed']],'captures'=>[
        $before=>['as_of'=>$snapBefore['as_of'],'rows'=>[row($symbol, $before, 110)],'snapshot_sha256'=>$hashBefore],
        $start=>['as_of'=>$snap['as_of'],'rows'=>[row($symbol, $start, 110)],'snapshot_sha256'=>$hash],
    ]];
    file_put_contents($tmp.'/latest.json', json_encode($state, JSON_THROW_ON_ERROR));
    $latestHash = hash_file('sha256', $tmp.'/latest.json');
    $snapHash = hash_file('sha256', $tmp.'/snapshot-'.$start.'.json');
    $called = 0;
    $r = BreakoutWatch::record($tmp, $state, $start, function () use (&$called) {
        $called++;
        throw new RuntimeException('판단일에는 조회하지 않습니다.');
    });
    check($r['registry_initialized'] === true && $r['enrolled'] === 1 && $r['fetched'] === 0 && $called === 0, 'start day inits registry without a bar');
    check(!is_file($tmp.'/breakout-watch/bars/'.$start.'.json'), 'judgment day stores no bar');
    $reg = json_decode(file_get_contents($tmp.'/breakout-watch/registry.json'), true, 512, JSON_THROW_ON_ERROR);
    check($reg['start_day'] === $start && isset($reg['entries'][$start.'|'.$symbol]) && !isset($reg['entries'][$before.'|'.$symbol]), 'enroll start day only');
    check($reg['entries'][$start.'|'.$symbol]['snapshot_sha256'] === $hash, 'snapshot hash copied');
    check((float)$reg['entries'][$start.'|'.$symbol]['level'] === 100.0, 'frozen level '.$reg['entries'][$start.'|'.$symbol]['level']);
    $level = $reg['entries'][$start.'|'.$symbol]['level'];
    $r = BreakoutWatch::record($tmp, $state, $start, fn() => throw new RuntimeException('no'));
    $reg2 = json_decode(file_get_contents($tmp.'/breakout-watch/registry.json'), true, 512, JSON_THROW_ON_ERROR);
    check($r['enrolled'] === 0 && $reg2['entries'][$start.'|'.$symbol]['level'] === $level, 'rerun does not duplicate or overwrite');
    check(hash_file('sha256', $tmp.'/latest.json') === $latestHash && hash_file('sha256', $tmp.'/snapshot-'.$start.'.json') === $snapHash, 'snapshot and ledger unchanged');

    $bad = $cal[0];
    $r = BreakoutWatch::record($tmp, $state, '2026-01-01', fn() => throw new RuntimeException('no'));
    check($r['status'] === '달력 미확정' && $r['fetched'] === 0, 'unconfirmed session is not estimated');

    $next = $cal[$j + 1];
    $nextSnap = ['day'=>$next,'as_of'=>strtotime($next.' 20:20:00 +0900'),'days'=>array_slice($cal, 0, $j + 2),'leaders'=>[],'prices'=>[],'errors'=>[$symbol=>'일봉 조회 실패']];
    $nextHash = putSnap($tmp, $nextSnap);
    $state['days'] = $nextSnap['days'];
    $state['last_day'] = $next;
    $state['captures'][$next] = ['as_of'=>$nextSnap['as_of'],'rows'=>[],'snapshot_sha256'=>$nextHash];
    $calls = [];
    $r = BreakoutWatch::record($tmp, $state, $next, function ($s) use (&$calls, $next) {
        $calls[] = $s;
        throw new RuntimeException('일시 실패');
    });
    check($calls === [$symbol] && $r['failed'] === 1 && $r['reused'] === 0, 'empty snapshot failure is not reused');
    $bars = json_decode(file_get_contents($tmp.'/breakout-watch/bars/'.$next.'.json'), true, 512, JSON_THROW_ON_ERROR);
    check($bars['symbols'][$symbol]['bar'] === null && $bars['symbols'][$symbol]['attempt']['kind'] === 'fetch_error', 'failure kept apart from a valid bar');
    unlink($tmp.'/breakout-watch/bars/'.$next.'.json');
    $calls = [];
    $r = BreakoutWatch::record($tmp, $state, $next, function ($s) use (&$calls, $next) {
        $calls[] = $s;
        return [candle($next, 110, 112, 100, 111)];
    });
    check($calls === [$symbol] && $r['fetched'] === 1, 'missing attempt still retries');
    $bars = json_decode(file_get_contents($tmp.'/breakout-watch/bars/'.$next.'.json'), true, 512, JSON_THROW_ON_ERROR);
    check($bars['symbols'][$symbol]['bar']['close'] === 111 && $bars['symbols'][$symbol]['source'] === 'watch_fetch', 'valid bar stored');
    $calls = [];
    $r = BreakoutWatch::record($tmp, $state, $next, function () use (&$calls) {
        $calls[] = 1;
        return [candle('2026-01-06', 1, 1, 1, 1)];
    });
    check($calls === [] && $r['fetched'] === 0, 'saved valid bar is not requested again');
    $again = json_decode(file_get_contents($tmp.'/breakout-watch/bars/'.$next.'.json'), true, 512, JSON_THROW_ON_ERROR);
    check($again['symbols'][$symbol]['bar']['close'] === 111, 'valid bar not overwritten');
    $regFile = $tmp.'/breakout-watch/registry.json';
    $barFile = $tmp.'/breakout-watch/bars/'.$next.'.json';
    rename($regFile, $regFile.'.bak');
    check(!is_file($regFile) && is_file($regFile.'.bak'), 'registry crash leaves only bak');
    $calls = [];
    $r = BreakoutWatch::record($tmp, $state, $next, function () use (&$calls) {
        $calls[] = 1;
        return [candle($next, 9, 9, 9, 9)];
    });
    $restored = json_decode(file_get_contents($regFile), true, 512, JSON_THROW_ON_ERROR);
    check($r['registry_initialized'] === false && $restored['start_day'] === $start && isset($restored['entries'][$start.'|'.$symbol]), 'registry bak is not a new install');
    check($calls === [], 'restored bar is not fetched again');
    rename($barFile, $barFile.'.bak');
    $calls = [];
    BreakoutWatch::record($tmp, $state, $next, function () use (&$calls) {
        $calls[] = 1;
        return [candle($next, 8, 8, 8, 8)];
    });
    $restoredBar = json_decode(file_get_contents($barFile), true, 512, JSON_THROW_ON_ERROR);
    check($calls === [] && $restoredBar['symbols'][$symbol]['bar']['close'] === 111 && !is_file($barFile.'.bak'), 'bar bak restores the valid bar');

    $day20 = $cal[$j + 20];
    $day21 = $cal[$j + 21];
    foreach ([$day20, $day21] as $day) {
        $i = array_search($day, $cal, true);
        $s = ['day'=>$day,'as_of'=>strtotime($day.' 20:20:00 +0900'),'days'=>array_slice($cal, 0, $i + 1),'leaders'=>[],'prices'=>[]];
        $h = putSnap($tmp, $s);
        $state['days'] = $s['days'];
        $state['last_day'] = $day;
        $state['captures'][$day] = ['as_of'=>$s['as_of'],'rows'=>[],'snapshot_sha256'=>$h];
    }
    $state['days'] = array_slice($cal, 0, $j + 21);
    $state['last_day'] = $day20;
    $calls = [];
    $r = BreakoutWatch::record($tmp, $state, $day20, function ($s) use (&$calls, $day20) {
        $calls[] = $s;
        return [candle($day20, 1, 2, 1, 2)];
    });
    check($calls === [$symbol] && $r['fetched'] === 1, 'day 20 still collects');
    $state['days'] = array_slice($cal, 0, $j + 22);
    $state['last_day'] = $day21;
    $calls = [];
    $r = BreakoutWatch::record($tmp, $state, $day21, function () use (&$calls) {
        $calls[] = 1;
        return [];
    });
    check($calls === [] && $r['fetched'] === 0, 'day 21 stops that candidate');

    $second = $cal[$j + 2];
    $secSnap = ['day'=>$second,'as_of'=>strtotime($second.' 20:20:00 +0900'),'days'=>array_slice($cal, 0, $j + 3),'leaders'=>[['yahoo'=>$symbol,'name'=>'반복','rank'=>2]],'prices'=>[$symbol=>history($cal, $j + 2, 110)]];
    $secHash = putSnap($tmp, $secSnap);
    $state['days'] = $secSnap['days'];
    $state['last_day'] = $second;
    $state['captures'][$second] = ['as_of'=>$secSnap['as_of'],'rows'=>[row($symbol, $second, 110)],'snapshot_sha256'=>$secHash];
    BreakoutWatch::record($tmp, $state, $second, fn() => throw new RuntimeException('elapsed 2 but bar comes from snapshot'));
    $reg = json_decode(file_get_contents($tmp.'/breakout-watch/registry.json'), true, 512, JSON_THROW_ON_ERROR);
    check(isset($reg['entries'][$second.'|'.$symbol]) && (float)$reg['entries'][$start.'|'.$symbol]['level'] === 100.0, 'second judgment is separate and first level stays');
    $later = $cal[$j + 3];
    $laterSnap = ['day'=>$later,'as_of'=>strtotime($later.' 20:20:00 +0900'),'days'=>array_slice($cal, 0, $j + 4),'leaders'=>[],'prices'=>[$other=>[candle($later, 3, 4, 2, 3)]]];
    $laterHash = putSnap($tmp, $laterSnap);
    $state['days'] = $laterSnap['days'];
    $state['captures'][$later] = ['as_of'=>$laterSnap['as_of'],'rows'=>[row($other, $later, 3)],'snapshot_sha256'=>$laterHash];
    $reg = json_decode(file_get_contents($tmp.'/breakout-watch/registry.json'), true, 512, JSON_THROW_ON_ERROR);
    $reg['entries'][$start.'|'.$other] = ['judgment_day'=>$start,'symbol'=>$other,'name'=>$other,'level'=>1,'judgment_close'=>1,'snapshot_sha256'=>$hash,'enrolled_at'=>'x'];
    Store::atomic($tmp.'/breakout-watch/registry.json', $reg);
    $calls = [];
    $r = BreakoutWatch::record($tmp, $state, $later, function ($s) use (&$calls, $later) {
        $calls[] = $s;
        return [candle($later, 5, 6, 4, 5)];
    });
    check($calls === [$symbol] && $r['reused'] === 1 && $r['fetched'] === 1, 'one fetch per symbol and snapshot bar reused');
    $file = json_decode(file_get_contents($tmp.'/breakout-watch/bars/'.$later.'.json'), true, 512, JSON_THROW_ON_ERROR);
    check($file['symbols'][$other]['source'] === 'snapshot_reuse' && $file['symbols'][$symbol]['source'] === 'watch_fetch', 'sources stay distinct');

    $gap = $cal[$j + 4];
    $gapSnap = ['day'=>$gap,'as_of'=>strtotime($gap.' 20:20:00 +0900'),'days'=>array_slice($cal, 0, $j + 5),'leaders'=>[],'prices'=>[]];
    $gapHash = putSnap($tmp, $gapSnap);
    $state['days'] = $gapSnap['days'];
    $state['captures'][$gap] = ['as_of'=>$gapSnap['as_of'],'rows'=>[],'snapshot_sha256'=>$gapHash];
    $r = BreakoutWatch::record($tmp, $state, $gap, fn($s) => $s === $symbol ? [candle($gap, 1, 1, 1, 1, false)] : [candle($cal[$j + 5], 2, 3, 1, 2)]);
    $file = json_decode(file_get_contents($tmp.'/breakout-watch/bars/'.$gap.'.json'), true, 512, JSON_THROW_ON_ERROR);
    check($file['symbols'][$symbol]['attempt']['kind'] === 'incomplete' && $file['symbols'][$symbol]['bar'] === null, 'incomplete is not stored or replaced by a later day');
    check($file['symbols'][$other]['attempt']['kind'] === 'missing', 'wrong session is not substituted');
    $badBar = $cal[$j + 6];
    $badSnap = ['day'=>$badBar,'as_of'=>strtotime($badBar.' 20:20:00 +0900'),'days'=>array_slice($cal, 0, $j + 7),'leaders'=>[],'prices'=>[]];
    $badHash = putSnap($tmp, $badSnap);
    $state['days'] = $badSnap['days'];
    $state['captures'][$badBar] = ['as_of'=>$badSnap['as_of'],'rows'=>[],'snapshot_sha256'=>$badHash];
    BreakoutWatch::record($tmp, $state, $badBar, fn() => [candle($badBar, 1, 1, 1, 1, true, true)]);
    $file = json_decode(file_get_contents($tmp.'/breakout-watch/bars/'.$badBar.'.json'), true, 512, JSON_THROW_ON_ERROR);
    check($file['symbols'][$symbol]['attempt']['kind'] === 'invalid', 'invalid bar is not kept as a price');
    check(hash_file('sha256', $tmp.'/latest.json') === $latestHash, 'ledger still unchanged after failures');
    $wrong = $tmp.'/breakout-watch/bars/'.$badBar.'.json';
    $decoded = json_decode(file_get_contents($wrong), true, 512, JSON_THROW_ON_ERROR);
    $decoded['day'] = '1999-01-04';
    file_put_contents($wrong, json_encode($decoded, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $wrongHash = hash_file('sha256', $wrong);
    $calls = [];
    $r = BreakoutWatch::record($tmp, $state, $badBar, function () use (&$calls) {
        $calls[] = 1;
        return [];
    });
    check($r['status'] === '일봉 날짜 불일치' && $calls === [] && hash_file('sha256', $wrong) === $wrongHash, 'mismatched bar file is kept and not replaced');
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($tmp);
}
echo "$n checks passed\n";
