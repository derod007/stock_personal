<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
foreach (['Tracker', 'BreakoutWatch', 'BreakoutPath', 'Page'] as $f) {
    require __DIR__.'/../bin/type1/'.$f.'.php';
}
use ChartEntryLab\Type1Scan\BreakoutPath;

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
function row(string $day, string $symbol, string $name, float $level, float $close): array
{
    return ['symbol'=>$symbol,'name'=>$name,'rank'=>4,'analysis_close'=>$close,'breakout20'=>['confirmed'=>true,'level'=>$level,'day'=>$day]];
}
function snap(string $day, array $cal, array $prices): array
{
    return ['day'=>$day,'as_of'=>strtotime($day.' 20:00:00 +0900'),'days'=>$cal,'prices'=>$prices];
}
function finger(string $root): array
{
    if (!is_dir($root)) {
        return ['__dir' => false];
    }
    $out = ['__dir' => true];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $out[$f->getPathname()] = hash_file('sha256', $f->getPathname());
        }
    }
    ksort($out);
    return $out;
}
function wipe(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}
function put(string $path, array $data): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0770, true);
    }
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

$tmp = sys_get_temp_dir().'/bw-path-'.bin2hex(random_bytes(4));
mkdir($tmp, 0770);
$cal = days('2026-10-21', 25);
$day = $cal[0];
$sym = '425420.KQ';
$other = '144960.KQ';
$judge = snap($day, $cal, [$sym => [candle($day, 100, 106, 99, 105)]]);
$hash = hash('sha256', json_encode($judge, JSON_THROW_ON_ERROR));
$tempt = snap($cal[5], $cal, [$sym => [
    candle($day, 100, 106, 99, 105),
    candle($cal[1], 999, 1000, 990, 999),
    candle($cal[2], 999, 1000, 990, 999),
    candle($cal[3], 999, 1000, 990, 999),
]]);
$watchOf = static fn(string $asOf): array => ['folder'=>$tmp,'days'=>$cal,'as_of_day'=>$asOf];
$same = static function (callable $read) use ($tmp): array {
    $before = finger($tmp);
    $got = $read();
    check(finger($tmp) === $before, 'watch files unchanged by read');
    return $got;
};
try {
    $gone = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '미등록', 100, 105)], $judge, $tempt, null, $watchOf($cal[3])));
    check(!is_dir($tmp.'/breakout-watch'), 'missing registry creates nothing');
    check($gone[0]['data_source'] === '기존 스냅샷' && $gone[0]['entry_open'] === 999.0, 'no registry keeps snapshot path');

    put($tmp.'/breakout-watch/registry.json', ['version'=>1,'start_day'=>$day,'entries'=>[
        $day.'|'.$other => ['judgment_day'=>$day,'symbol'=>$other,'name'=>'다른','level'=>1,'judgment_close'=>1,'snapshot_sha256'=>$hash,'enrolled_at'=>'x'],
    ]]);
    $plain = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '미등록', 100, 105)], $judge, $tempt, null, $watchOf($cal[3])));
    check($plain[0]['data_source'] === '기존 스냅샷' && $plain[0]['entry_open'] === 999.0, 'unlisted id keeps snapshot path');

    $entry = static fn(string $d, string $symbol, string $sha): array => [
        'judgment_day'=>$d,'symbol'=>$symbol,'name'=>$symbol,'level'=>1,'judgment_close'=>1,'snapshot_sha256'=>$sha,'enrolled_at'=>'x',
    ];
    $kept = static fn(string $d, array $bar): array => ['session_day'=>$d,'collected_at'=>'x','source'=>'watch_fetch','snapshot_sha256'=>null,'bar'=>$bar,'attempt'=>null];
    put($tmp.'/breakout-watch/registry.json', ['version'=>1,'start_day'=>$day,'entries'=>[
        $day.'|'.$sym => $entry($day, $sym, $hash),
        $day.'|'.$other => $entry($day, $other, $hash),
    ]]);
    foreach ([$cal[1], $cal[2], $cal[3]] as $i => $d) {
        $open = $d === $cal[1] ? 106.0 : 110.0 + $i;
        put($tmp.'/breakout-watch/bars/'.$d.'.json', ['day'=>$d,'symbols'=>[
            $sym => $kept($d, candle($d, $open, $open + 4, $open - 2, $open + 2)),
            $other => $kept($d, candle($d, 50, 55, 49, 52)),
        ]]);
    }
    $live = $same(fn() => BreakoutPath::forConfirmedRows($day, [
        row($day, $sym, '등록', 100, 105),
        row($day, $other, '다른종목', 40, 52),
    ], $judge, $tempt, null, $watchOf($cal[3])));
    check($live[0]['data_source'] === '독립 관찰' && $live[0]['entry_open'] === 106.0, 'registered row uses watch open');
    check($live[0]['entry_open'] !== 999.0 && $live[0]['level'] === 100.0 && $live[0]['judgment_close'] === 105.0, 'level stays on judgment snapshot');
    check($live[0]['observe_status'] === '진행 중' && $live[0]['data_status'] === '확보 범위 내 정상', 'in progress and complete are separate labels');
    check($live[0]['elapsed_sessions'] === 3 && $live[0]['collected_bars'] === 3 && $live[0]['observation_as_of'] === $cal[3], 'elapsed and as-of');
    check($live[1]['entry_open'] === 50.0 && $live[1]['level'] === 40.0, 'other symbol is not mixed');
    check(str_contains((string)$live[0]['source']['policy'], '처음 저장한 관찰 봉'), 'source says first stored bars');

    $stale = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '등록', 100, 105)], $judge, $tempt, null, $watchOf($cal[2])));
    check($stale[0]['observation_as_of'] === $cal[2] && !in_array($cal[10], $stale[0]['missing_days'], true), 'later calendar days are not missing');
    check($stale[0]['data_status'] === '확보 범위 내 정상' && $stale[0]['observe_status'] === '진행 중', 'stale as-of is not described as finished');

    $wait = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '등록', 100, 105)], $judge, $tempt, null, $watchOf($day)));
    check($wait[0]['observe_status'] === '대기' && $wait[0]['data_status'] === '확보 범위 내 정상' && $wait[0]['elapsed_sessions'] === 0, 'waiting is not a data gap');
    check($wait[0]['missing_days'] === [] && $wait[0]['entry_open'] === null, 'waiting has no substituted open');

    unlink($tmp.'/breakout-watch/bars/'.$cal[2].'.json');
    $mid = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '등록', 100, 105)], $judge, $tempt, null, $watchOf($cal[3])));
    check($mid[0]['missing_days'] === [$cal[2]] && $mid[0]['entry_open'] === 106.0, 'middle gap keeps the real next open');
    check($mid[0]['data_status'] === '결측 있음' && !$mid[0]['extremes_complete'] && !$mid[0]['level_cross_complete'], 'middle gap is not a complete extreme');

    unlink($tmp.'/breakout-watch/bars/'.$cal[1].'.json');
    $next = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '등록', 100, 105)], $judge, $tempt, null, $watchOf($cal[3])));
    check($next[0]['entry_open'] === null && in_array($cal[1], $next[0]['missing_days'], true), 'missing next open is not taken from a later bar');
    check(!str_contains((string)$next[0]['reason'], '999'), 'later snapshot open is not copied into the reason');

    put($tmp.'/breakout-watch/bars/'.$cal[1].'.json', ['day'=>$cal[1],'symbols'=>[
        $sym => $kept($cal[1], candle($cal[1], 106, 110, 104, 108, false)),
    ]]);
    $inc = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '등록', 100, 105)], $judge, $tempt, null, $watchOf($cal[2])));
    check($inc[0]['entry_open'] === null && str_contains((string)$inc[0]['reason'], '미완료'), 'incomplete bar is excluded');

    put($tmp.'/breakout-watch/bars/'.$cal[1].'.json', ['day'=>$cal[1],'symbols'=>[
        $sym => $kept($cal[1], [candle($cal[1], 106, 110, 104, 108), candle($cal[1], 107, 111, 105, 109)]),
    ]]);
    $dup = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '등록', 100, 105)], $judge, $tempt, null, $watchOf($cal[1])));
    check($dup[0]['entry_open'] === null && str_contains((string)$dup[0]['reason'], '중복'), 'duplicate day is excluded');

    put($tmp.'/breakout-watch/bars/'.$cal[1].'.json', ['day'=>$cal[1],'symbols'=>[
        $sym => $kept($cal[1], candle($cal[2], 106, 110, 104, 108)),
    ]]);
    $wrong = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '등록', 100, 105)], $judge, $tempt, null, $watchOf($cal[1])));
    check($wrong[0]['entry_open'] === null && str_contains((string)$wrong[0]['reason'], '날짜가 맞지 않음'), 'wrong bar date is excluded');

    put($tmp.'/breakout-watch/bars/'.$cal[1].'.json', ['day'=>'1999-01-04','symbols'=>[]]);
    $badDay = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '등록', 100, 105)], $judge, $tempt, null, $watchOf($cal[1])));
    check($badDay[0]['data_status'] === '미확정' && $badDay[0]['entry_open'] === null && $badDay[0]['data_source'] === '독립 관찰', 'date mismatch is not a snapshot fallback');

    $ended = [];
    for ($i = 1; $i <= 20; $i++) {
        if ($i === 4) {
            continue;
        }
        $d = $cal[$i];
        $ended[$d] = candle($d, 106, 112, 100, 108);
        put($tmp.'/breakout-watch/bars/'.$d.'.json', ['day'=>$d,'symbols'=>[$sym => $kept($d, $ended[$d])]]);
    }
    $done = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '등록', 100, 105)], $judge, $tempt, null, $watchOf($cal[21])));
    check($done[0]['observe_status'] === '기간 종료' && $done[0]['data_status'] === '결측 있음', 'period end is not completeness');
    check($done[0]['elapsed_sessions'] === 21 && $done[0]['collected_bars'] === 19, 'day 21 is elapsed but not a collected slot');
    check(in_array($cal[4], $done[0]['missing_days'], true) && !in_array($cal[21], $done[0]['missing_days'], true), 'only the missed day inside the 20 is listed');

    file_put_contents($tmp.'/breakout-watch/registry.json', '{');
    $broken = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '등록', 100, 105)], $judge, $tempt, null, $watchOf($cal[3])));
    check($broken[0]['data_source'] === '독립 관찰' && $broken[0]['entry_open'] === null, 'damaged registry is not treated as unregistered');
    check(str_contains((string)$broken[0]['reason'], '대체하지 않았습니다'), 'damaged registry says it did not fall back');

    unlink($tmp.'/breakout-watch/registry.json');
    file_put_contents($tmp.'/breakout-watch/registry.json.bak', json_encode(['version'=>1,'entries'=>[]], JSON_THROW_ON_ERROR));
    $half = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '등록', 100, 105)], $judge, $tempt, null, $watchOf($cal[3])));
    check(!is_file($tmp.'/breakout-watch/registry.json') && is_file($tmp.'/breakout-watch/registry.json.bak'), 'read does not restore a backup');
    check($half[0]['data_source'] === '독립 관찰' && $half[0]['entry_open'] === null, 'backup-only registry is not a fresh install');

    put($tmp.'/breakout-watch/registry.json', ['version'=>1,'start_day'=>$day,'entries'=>[
        $day.'|'.$sym => $entry($day, $sym, 'deadbeef'),
    ]]);
    $badHash = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '등록', 100, 105)], $judge, $tempt, null, $watchOf($cal[3])));
    check($badHash[0]['data_source'] === '독립 관찰' && $badHash[0]['entry_open'] === null, 'hash mismatch does not use the snapshot');
    check(str_contains((string)$badHash[0]['reason'], '해시'), 'hash mismatch is named');

    $dayB = $cal[1];
    $judgeB = snap($dayB, $cal, [$sym => [candle($dayB, 120, 130, 119, 128)]]);
    $hashB = hash('sha256', json_encode($judgeB, JSON_THROW_ON_ERROR));
    put($tmp.'/breakout-watch/registry.json', ['version'=>1,'start_day'=>$day,'entries'=>[
        $day.'|'.$sym => $entry($day, $sym, $hash),
        $dayB.'|'.$sym => $entry($dayB, $sym, $hashB),
    ]]);
    put($tmp.'/breakout-watch/bars/'.$cal[1].'.json', ['day'=>$cal[1],'symbols'=>[$sym => $kept($cal[1], candle($cal[1], 106, 110, 104, 108))]]);
    put($tmp.'/breakout-watch/bars/'.$cal[2].'.json', ['day'=>$cal[2],'symbols'=>[$sym => $kept($cal[2], candle($cal[2], 140, 146, 139, 144))]]);
    $pathA = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '첫째', 100, 105)], $judge, $tempt, null, $watchOf($cal[2])));
    $pathB = $same(fn() => BreakoutPath::forConfirmedRows($dayB, [row($dayB, $sym, '둘째', 200, 128)], $judgeB, $tempt, null, $watchOf($cal[2])));
    check($pathA[0]['id'] !== $pathB[0]['id'] && $pathA[0]['level'] === 100.0 && $pathB[0]['level'] === 200.0, 'same symbol on two judgment days stays separate');
    check($pathA[0]['entry_open'] === 106.0 && $pathB[0]['entry_open'] === 140.0, 'each judgment uses its own next session');

    $unknown = $same(fn() => BreakoutPath::forConfirmedRows($day, [row($day, $sym, '등록', 100, 105)], $judge, $tempt, null, $watchOf('1999-01-04')));
    check($unknown[0]['data_status'] === '달력 미확정' && $unknown[0]['entry_open'] === null, 'unknown as-of does not borrow snapshot bars');

    $report = [
        'ok'=>true,'as_of'=>date(DATE_ATOM),'source'=>'saved','saved_day'=>$day,'scope'=>'breakout','sort'=>'amount',
        'rows'=>[array_merge(row($day, $sym, '등록', 100, 105), ['market'=>'KQ','price'=>105,'change_pct'=>1,'amount_won'=>1,'analysis_date'=>$day,'breakout20'=>['confirmed'=>true,'level'=>100,'distance_pct'=>5,'status'=>'종가 돌파 확인']])],
        'errors'=>[],'summary'=>['total'=>1,'evaluated'=>1,'matches'=>1,'errors'=>0],
        'recent'=>[['day'=>$day,'confirmed'=>1,'unevaluable'=>0,'recent_candidate'=>true,'observation'=>['state'=>'대기','label'=>'다음']]],
        'saved_days'=>[$day],'post_paths'=>[$pathA[0], $gone[0]],'error'=>null,
    ];
    ob_start();
    \ChartEntryLab\Type1Scan\render($report);
    $html = ob_get_clean();
    check(str_contains($html, '독립 관찰') && str_contains($html, '기존 스냅샷'), 'screen names both sources');
    check(str_contains($html, '진행 중') && str_contains($html, '자료 기준일 '.$cal[2]), 'screen shows status and as-of');
    check(str_contains($html, '처음 저장한 관찰 봉') && !str_contains($html, 'next_bar_missing') && !str_contains($html, 'watch_fetch'), 'screen hides internal codes');
    check(!str_contains($html, '수익 가능성'), 'no profit ranking');
    file_put_contents(sys_get_temp_dir().'/watch-path.html', $html);
} finally {
    wipe($tmp);
}

echo "$n checks passed\n";
