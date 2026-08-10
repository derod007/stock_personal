<?php

declare(strict_types=1);

namespace ChartEntryLab;

final class YahooChartClient
{
    /** 캐시가 이보다 오래되면 재조회(실패 시 오래된 캐시 사용). */
    private const CACHE_MAX_AGE_SECONDS = 14400; // 4h

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    public function __construct(
        private readonly string $cacheDir
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    /**
     * @param ?int $maxAgeSeconds null이면 기본 TTL. 0이면 항상 재조회(실패 시 캐시 폴백).
     * @return list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}>
     */
    public function fetch(
        string $symbol,
        string $range = '3mo',
        string $interval = '1d',
        bool $useCache = true,
        ?int $maxAgeSeconds = null,
    ): array {
        $cacheFile = sprintf('%s/%s_%s_%s.json', $this->cacheDir, $symbol, $range, $interval);
        $cached = null;
        if (is_file($cacheFile)) {
            /** @var list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}> $cached */
            $cached = json_decode((string) file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
            if ($useCache && is_array($cached) && $cached !== []) {
                $maxAge = $maxAgeSeconds ?? self::CACHE_MAX_AGE_SECONDS;
                $age = time() - (int) filemtime($cacheFile);
                if ($maxAge > 0 && $age < $maxAge) {
                    return $cached;
                }
            }
        }

        try {
            $rows = $this->fetchLive($symbol, $range, $interval);
            file_put_contents($cacheFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $rows;
        } catch (\Throwable $e) {
            // 네트워크/429 등: 오래된 캐시라도 있으면 현재가·점수용으로 사용
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
            throw $e;
        }
    }

    /**
     * @return list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}>
     */
    private function fetchLive(string $symbol, string $range, string $interval): array
    {
        $url = sprintf(
            'https://query1.finance.yahoo.com/v8/finance/chart/%s?range=%s&interval=%s',
            rawurlencode($symbol),
            rawurlencode($range),
            rawurlencode($interval)
        );

        $body = null;
        $code = 0;
        $lastErr = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => [
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: application/json,text/plain,*/*',
                    'Accept-Language: en-US,en;q=0.9,ko;q=0.8',
                ],
            ]);
            $body = curl_exec($ch);
            if ($body === false) {
                $lastErr = curl_error($ch);
                curl_close($ch);
                usleep(250000 * $attempt);
                continue;
            }
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 429 || $code === 503) {
                usleep(400000 * $attempt);
                continue;
            }
            break;
        }

        if ($body === false || $body === null || $body === '') {
            throw new \RuntimeException('Yahoo fetch failed: ' . ($lastErr !== '' ? $lastErr : 'empty body'));
        }
        if ($code >= 400) {
            throw new \RuntimeException("Yahoo HTTP {$code} for {$symbol}");
        }

        /** @var array<string,mixed> $json */
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $result = $json['chart']['result'][0] ?? null;
        if (!is_array($result)) {
            throw new \RuntimeException("No chart result for {$symbol}");
        }

        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];
        if (!is_array($timestamps) || !is_array($quote)) {
            throw new \RuntimeException("Malformed chart quote for {$symbol}");
        }

        $rows = [];
        foreach ($timestamps as $i => $ts) {
            if (!isset($quote['close'][$i]) || $quote['close'][$i] === null) {
                continue;
            }
            $rows[] = [
                'time' => (int) $ts,
                'time_kst' => $this->formatKst((int) $ts),
                'open' => (float) ($quote['open'][$i] ?? $quote['close'][$i]),
                'high' => (float) ($quote['high'][$i] ?? $quote['close'][$i]),
                'low' => (float) ($quote['low'][$i] ?? $quote['close'][$i]),
                'close' => (float) $quote['close'][$i],
                'volume' => (int) ($quote['volume'][$i] ?? 0),
            ];
        }

        $rows = $this->mergeRegularMarketPrice($rows, $result);
        if ($rows === []) {
            throw new \RuntimeException("No usable candles for {$symbol}");
        }

        return $rows;
    }

    /**
     * 당일 봉 close=null 이어도 meta.regularMarketPrice 로 현재가를 붙인다.
     *
     * @param list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}> $rows
     * @param array<string,mixed> $result
     * @return list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}>
     */
    private function mergeRegularMarketPrice(array $rows, array $result): array
    {
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        if (!is_numeric($meta['regularMarketPrice'] ?? null)) {
            return $rows;
        }
        $price = (float) $meta['regularMarketPrice'];
        if ($price <= 0) {
            return $rows;
        }

        $ts = is_numeric($meta['regularMarketTime'] ?? null)
            ? (int) $meta['regularMarketTime']
            : time();
        $liveDay = $this->dayKst($ts);

        if ($rows === []) {
            return [[
                'time' => $ts,
                'time_kst' => $this->formatKst($ts),
                'open' => $price,
                'high' => $price,
                'low' => $price,
                'close' => $price,
                'volume' => 0,
            ]];
        }

        $lastIdx = array_key_last($rows);
        $lastDay = $this->dayKst((int) $rows[$lastIdx]['time']);

        if ($lastDay === $liveDay) {
            $rows[$lastIdx]['close'] = $price;
            $rows[$lastIdx]['high'] = max((float) $rows[$lastIdx]['high'], $price);
            $rows[$lastIdx]['low'] = min((float) $rows[$lastIdx]['low'], $price);
            return $rows;
        }

        // 당일 OHLC가 아직 null이라 스킵된 경우 → 현재가로 당일 봉 추가
        $rows[] = [
            'time' => $ts,
            'time_kst' => $this->formatKst($ts),
            'open' => $price,
            'high' => $price,
            'low' => $price,
            'close' => $price,
            'volume' => 0,
        ];

        return $rows;
    }

    private function formatKst(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))
            ->setTimezone(new \DateTimeZone('Asia/Seoul'))
            ->format('Y-m-d H:i:s');
    }

    private function dayKst(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))
            ->setTimezone(new \DateTimeZone('Asia/Seoul'))
            ->format('Y-m-d');
    }
}
