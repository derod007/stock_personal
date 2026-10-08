<?php
declare(strict_types=1);
function recovery_bars(float $target = 130): array
{
    $bars = []; $start = new DateTimeImmutable('2026-01-05', new DateTimeZone('Asia/Seoul'));
    for ($i = 0; $i < 70; $i++) {
        $day = $start->modify('+'.$i.' weekdays')->format('Y-m-d');
        $at = (new DateTimeImmutable($day.' 15:30:00', new DateTimeZone('Asia/Seoul')))->getTimestamp();
        $bars[] = ['time'=>$at, 'time_kst'=>$day.' 15:30:00', 'available_at'=>$at,
            'open'=>107.0, 'high'=>110.0, 'low'=>106.0, 'close'=>108.0, 'volume'=>100000];
    }
    $bars[35]['high'] = $target;
    $bars[45]['low'] = 90.0; $bars[53]['low'] = 100.0;
    $tail = [
        [107,108,98,99], [99,100,94,96], [96,97,90,93], [93,98,93,95], [95,99,95,97],
        [99,104,98,103], [103,106,102,104], [103,104,100,102], [102,103,98,101],
        [101,104,100,102], [102,107,101,106],
    ];
    foreach ($tail as $j=>$ohlc) foreach (['open','high','low','close'] as $k=>$key) $bars[$j+59][$key]=(float)$ohlc[$k];
    return $bars;
}
