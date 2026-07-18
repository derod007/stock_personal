<?php

declare(strict_types=1);

namespace ChartEntryLab;

final class YahooChartClient
{
    public function __construct(
        private readonly string $cacheDir
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    /**
     * @return list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}>
     */
    public function fetch(string $symbol, string $range = '3mo', string $interval = '1d', bool $useCache = true): array
    {
        $cacheFile = sprintf('%s/%s_%s_%s.json', $this->cacheDir, $symbol, $range, $interval);
        if ($useCache && is_file($cacheFile)) {
            /** @var list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}> $cached */
            $cached = json_decode((string) file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
            return $cached;
        }

        $url = sprintf(
            'https://query1.finance.yahoo.com/v8/finance/chart/%s?range=%s&interval=%s',
            rawurlencode($symbol),
            rawurlencode($range),
            rawurlencode($interval)
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 chart-entry-lab',
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            throw new \RuntimeException('Yahoo fetch failed: ' . curl_error($ch));
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
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
        $rows = [];
        foreach ($timestamps as $i => $ts) {
            if (!isset($quote['close'][$i]) || $quote['close'][$i] === null) {
                continue;
            }
            $rows[] = [
                'time' => (int) $ts,
                'time_kst' => (new \DateTimeImmutable('@' . $ts))
                    ->setTimezone(new \DateTimeZone('Asia/Seoul'))
                    ->format('Y-m-d H:i:s'),
                'open' => (float) $quote['open'][$i],
                'high' => (float) $quote['high'][$i],
                'low' => (float) $quote['low'][$i],
                'close' => (float) $quote['close'][$i],
                'volume' => (int) ($quote['volume'][$i] ?? 0),
            ];
        }

        file_put_contents($cacheFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $rows;
    }
}
