<?php

declare(strict_types=1);

$market = null;
$force = false;
$nowRaw = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
        continue;
    }
    if (str_starts_with($arg, '--now=')) {
        $nowRaw = substr($arg, 6);
        continue;
    }
    if ($arg === 'kr' || $arg === 'us') {
        $market = $arg;
    }
}
if ($market === null) {
    fwrite(STDERR, "market kr|us required\n");
    exit(1);
}
if ($force) {
    echo "RUN force {$market}\n";
    exit(0);
}

$tz = new DateTimeZone('Asia/Seoul');
$now = $nowRaw === null
    ? new DateTimeImmutable('now', $tz)
    : (new DateTimeImmutable($nowRaw))->setTimezone($tz);
$weekday = (int) $now->format('N');
$hi = $now->format('Hi');
if ($weekday >= 6) {
    echo "SKIP {$market} weekend {$hi}\n";
    exit(10);
}
$ok = $market === 'kr'
    ? ($hi >= '2020' && $hi <= '2359')
    : ($hi >= '0620' && $hi <= '1159');
if ($ok) {
    echo "RUN {$market} {$hi}\n";
    exit(0);
}
echo "SKIP {$market} {$hi}\n";
exit(10);
