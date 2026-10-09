<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Repair only a contradictory historical close backed by matching independent OHLCV. */
final class NaverHistoricalClose
{
    public function __construct(private readonly string $cacheDir, private readonly ?\Closure $transport = null) {}

    public static function parse(string $body, string $code): array
    {
        // Numeric attributes/data are ASCII even when the document declares EUC-KR.
        if (!preg_match('/<chartdata\b([^>]*)>/', $body, $chart)
            || !preg_match('/\bsymbol="'.preg_quote($code, '/').'"/', $chart[1])
            || !preg_match('/\btimeframe="day"/', $chart[1])) {
            throw new \RuntimeException('Historical quote identity mismatch');
        }
        preg_match_all('/<item\s+data="([^"]+)"\s*\/?\s*>/', $body, $items);
        $quotes = [];
        foreach ($items[1] as $data) {
            $cells = explode('|', $data);
            if (count($cells) !== 6 || !preg_match('/^\d{8}$/D', $cells[0])) continue;
            $day = substr($cells[0], 0, 4).'-'.substr($cells[0], 4, 2).'-'.substr($cells[0], 6, 2);
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
            if (!$date || $date->format('Y-m-d') !== $day || isset($quotes[$day])) {
                throw new \RuntimeException('Invalid or duplicate historical session');
            }
            $q = [];
            foreach (['open','high','low','close','volume'] as $i => $key) {
                if (!is_numeric($cells[$i+1])) throw new \RuntimeException('Invalid historical number');
                $q[$key] = (float)$cells[$i+1];
            }
            $quotes[$day] = $q;
        }
        if (!$quotes) throw new \RuntimeException('No historical quotes');
        return $quotes;
    }

    private static function day(array $row): string
    {
        return (new \DateTimeImmutable('@'.(int)$row['time']))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d');
    }

    private static function numbers(array $q): bool
    {
        foreach (['open','high','low','close','volume'] as $k) {
            $v = $q[$k] ?? null;
            if (!is_numeric($v) || !is_finite((float)$v) || ($k === 'volume' ? $v < 0 : $v <= 0)) return false;
        }
        return $q['low'] <= $q['open'] && $q['open'] <= $q['high'];
    }

    private static function valid(array $q): bool
    {
        return self::numbers($q) && $q['low'] <= $q['close'] && $q['close'] <= $q['high'];
    }

    private static function matches(array $a, array $b, array $keys): bool
    {
        foreach ($keys as $k) if ((float)$a[$k] !== (float)$b[$k]) return false;
        return true;
    }

    /** Pure merge; never changes a valid candle or guesses missing prices. */
    public static function merge(array $rows, array $quotes, string $symbol, int $fetchedAt, int $asOf, string $hash): array
    {
        if (!preg_match('/^\d{6}\.(KS|KQ)$/D', $symbol) || $fetchedAt > $asOf || $fetchedAt <= 0
            || !preg_match('/^[a-f0-9]{64}$/D', $hash)) return $rows;
        $days = array_map(fn($r) => self::day($r), $rows);
        $counts = array_count_values($days);
        $out = $rows;
        foreach ($rows as $i => $r) {
            if ($i === 0 || !isset($rows[$i+1]) || $counts[$days[$i]] !== 1
                || !self::candidate($r, $symbol, $asOf)) continue;
            $q = $quotes[$days[$i]] ?? [];
            if (!self::valid($q) || !self::matches($r, $q, ['open','high','low','volume'])) continue;
            // Both neighboring sessions must agree in all five fields. Do not mix adjusted bases.
            foreach ([$i-1,$i+1] as $j) {
                $neighbor = $rows[$j]; $other = $quotes[$days[$j]] ?? [];
                if ($counts[$days[$j]] !== 1 || !self::valid($neighbor) || !self::valid($other)
                    || !empty($neighbor['synthetic']) || ($neighbor['is_complete'] ?? true) === false
                    || CandleClock::closeTime($neighbor, $symbol) > min($fetchedAt, $asOf)
                    || !self::matches($neighbor, $other, ['open','high','low','close','volume'])) continue 2;
            }
            if (!($days[$i-1] < $days[$i] && $days[$i] < $days[$i+1])) continue;
            $out[$i]['close'] = (float)$q['close'];
            $out[$i]['close_source'] = 'naver_historical_verified';
            $out[$i]['close_observed_at'] = $fetchedAt;
            $out[$i]['historical_close_repair'] = [
                'policy' => 'matching_ohlv_and_neighbors_v1', 'date' => $days[$i],
                'original' => $r, 'source_sha256' => $hash, 'fetched_at' => $fetchedAt,
            ];
        }
        return $out;
    }

    private static function candidate(array $r, string $symbol, int $asOf): bool
    {
        return !empty($r['is_complete']) && empty($r['synthetic'])
            && ($r['close_source'] ?? null) === 'yahoo_daily'
            && CandleClock::closeTime($r, $symbol) <= $asOf
            && self::numbers($r) && !self::valid($r);
    }

    public function repair(array $rows, string $symbol, string $interval, int $now): array
    {
        if ($interval !== '1d' || !preg_match('/^(\d{6})\.(KS|KQ)$/D', $symbol, $m)
            || !array_filter($rows, fn($r) => self::candidate($r, $symbol, $now))) return $rows;
        try {
            [$quotes, $at, $hash] = $this->evidence($m[1], $now);
            return self::merge($rows, $quotes, $symbol, $at, $now, $hash);
        } catch (\Throwable) {
            // An unavailable, corrupt or disagreeing source leaves the original quality block intact.
            return $rows;
        }
    }

    private function evidence(string $code, int $now): array
    {
        $index = $this->cacheDir.'/'.$code.'.json';
        if (is_file($index)) {
            try {
                $saved = json_decode(file_get_contents($index), true, 512, JSON_THROW_ON_ERROR);
                $hash = $saved['sha256'] ?? ''; $at = $saved['fetched_at'] ?? 0;
                if (preg_match('/^[a-f0-9]{64}$/D', $hash) && is_int($at) && $at > 0 && $at <= $now && $now-$at < 86400) {
                    $rawPath = $this->cacheDir.'/'.$hash.'.xml';
                    $body = is_file($rawPath) ? file_get_contents($rawPath) : false;
                    if ($body !== false && hash_equals($hash, hash('sha256', $body))) {
                        return [self::parse($body, $code), $at, $hash];
                    }
                }
            } catch (\Throwable) { /* Re-fetch without trusting damaged cache metadata. */ }
        }
        $url = 'https://fchart.stock.naver.com/sise.nhn?symbol='.$code.'&timeframe=day&count=600&requestType=0';
        if ($this->transport !== null) {
            $body = ($this->transport)($url);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15,
                CURLOPT_HTTPHEADER=>['User-Agent: Mozilla/5.0', 'Accept: application/xml,text/xml,*/*']]);
            $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($status !== 200 || !is_string($body)) throw new \RuntimeException('Historical quote fetch failed');
        }
        $quotes = self::parse($body, $code); $hash = hash('sha256', $body);
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0770, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException('Cannot preserve historical evidence');
        }
        // Publish immutable raw evidence before any repaired candle can be returned.
        $path = $this->cacheDir.'/'.$hash.'.xml';
        if (!is_file($path) && file_put_contents($path, $body, LOCK_EX) !== strlen($body)) {
            throw new \RuntimeException('Cannot preserve historical evidence');
        }
        if (!hash_equals($hash, hash_file('sha256', $path))) throw new \RuntimeException('Historical evidence checksum mismatch');
        $temp = tempnam($this->cacheDir, 'index-');
        try {
            $bytes = json_encode(['sha256'=>$hash,'fetched_at'=>$now], JSON_THROW_ON_ERROR);
            if (file_put_contents($temp, $bytes) !== strlen($bytes) || !rename($temp, $index)) {
                throw new \RuntimeException('Cannot publish historical evidence');
            }
        } finally { if (is_file($temp)) unlink($temp); }
        return [$quotes, $now, $hash];
    }
}
