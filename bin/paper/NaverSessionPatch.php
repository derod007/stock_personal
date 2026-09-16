<?php

declare(strict_types=1);

use ChartEntryLab\NaverDailyQuotes;
use ChartEntryLab\PaperJournal;

/**
 * Yahoo 일봉의 한국 종목을 네이버 정규장(일별 시세) 시/고/저/종·거래량으로 덮는다.
 */
final class PaperNaverSessionPatch
{
    /**
     * @param list<array<string,mixed>> $bars
     * @param array<string, array{date?:string,open:float,high:float,low:float,close:float,volume:int}> $quotes
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    public static function patchBars(array $bars, array $quotes): array
    {
        $overlayed = 0;
        foreach ($bars as &$bar) {
            if (!is_array($bar)) {
                continue;
            }
            $q = self::quoteForBar($bar, $quotes);
            if ($q === null) {
                continue;
            }
            $day = (string) ($q['date'] ?? self::barDay($bar));
            $session = new DateTimeImmutable($day . ' 15:30:00', new DateTimeZone('Asia/Seoul'));
            $bar['open'] = (float) $q['open'];
            $bar['high'] = (float) $q['high'];
            $bar['low'] = (float) $q['low'];
            $bar['close'] = (float) $q['close'];
            if ((int) $q['volume'] > 0) {
                $bar['volume'] = (int) $q['volume'];
            }
            $bar['time'] = $session->getTimestamp();
            $bar['time_kst'] = $session->format('Y-m-d H:i:s');
            $bar['session'] = 'krx_regular';
            $overlayed++;
        }
        unset($bar);

        return [$bars, $overlayed];
    }

    /**
     * @return array{patched:int,skipped:int,overlayed:int}
     */
    public static function applyDirectory(string $dir, NaverDailyQuotes $naver): array
    {
        $sourcesFile = $dir . '/sources.json';
        if (!is_file($sourcesFile)) {
            throw new RuntimeException('sources.json missing');
        }
        /** @var list<array<string,mixed>> $sources */
        $sources = json_decode((string) file_get_contents($sourcesFile), true, 512, JSON_THROW_ON_ERROR);
        $patched = 0;
        $skipped = 0;
        $overlayed = 0;
        foreach ($sources as &$source) {
            $symbol = (string) ($source['symbol'] ?? '');
            if (NaverDailyQuotes::codeOf($symbol) === null) {
                $skipped++;
                continue;
            }
            $file = (string) ($source['file'] ?? '');
            if ($file === '' || !is_file($file)) {
                throw new RuntimeException('Korean history file missing for ' . $symbol);
            }
            /** @var list<array<string,mixed>> $bars */
            $bars = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $quotes = $naver->recent($symbol, 2, false);
            if ($quotes === []) {
                throw new RuntimeException('Naver regular-session quotes unavailable for ' . $symbol);
            }
            [$bars, $count] = self::patchBars($bars, $quotes);
            if ($count === 0) {
                throw new RuntimeException('Naver regular-session overlay matched no bars for ' . $symbol);
            }
            $bytes = PaperJournal::encode($bars);
            file_put_contents($file, $bytes);
            $source['sha256'] = hash('sha256', $bytes);
            $source['bars'] = count($bars);
            $source['price_basis'] = 'Naver sise_day regular session OHLC overlay on Yahoo history';
            $patched++;
            $overlayed += $count;
        }
        unset($source);
        file_put_contents($sourcesFile, json_encode($sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['patched' => $patched, 'skipped' => $skipped, 'overlayed' => $overlayed];
    }

    /**
     * @param array<string,mixed> $bar
     * @param array<string, array{date?:string,open:float,high:float,low:float,close:float,volume:int}> $quotes
     * @return array{date?:string,open:float,high:float,low:float,close:float,volume:int}|null
     */
    public static function quoteForBar(array $bar, array $quotes): ?array
    {
        $day = self::barDay($bar);
        if ($day !== null && isset($quotes[$day])) {
            return $quotes[$day];
        }
        if (!isset($bar['time']) || !is_numeric($bar['time'])) {
            return null;
        }
        $dt = (new DateTimeImmutable('@' . (int) $bar['time']))->setTimezone(new DateTimeZone('Asia/Seoul'));
        if ((int) $dt->format('G') >= 9) {
            return null;
        }
        $prev = $dt->modify('-1 day')->format('Y-m-d');

        return $quotes[$prev] ?? null;
    }

    /** @param array<string,mixed> $bar */
    public static function barDay(array $bar): ?string
    {
        $kst = (string) ($bar['time_kst'] ?? '');
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $kst, $m) === 1) {
            return $m[1];
        }
        if (!isset($bar['time']) || !is_numeric($bar['time'])) {
            return null;
        }

        return (new DateTimeImmutable('@' . (int) $bar['time']))
            ->setTimezone(new DateTimeZone('Asia/Seoul'))
            ->format('Y-m-d');
    }
}
