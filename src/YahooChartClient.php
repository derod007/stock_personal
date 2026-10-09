<?php

declare(strict_types=1);

namespace ChartEntryLab;

final class YahooChartClient
{
    /** 캐시가 이보다 오래되면 재조회(실패 시 오래된 캐시 사용). */
    private const CACHE_MAX_AGE_SECONDS = 14400; // 4h

    /** 국내 일봉에서 네이버 실측 고저로 덮어쓸 최근 봉 수 (네이버 1페이지 = 10거래일) */
    private const NAVER_PATCH_BARS = 10;

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    private readonly NaverDailyQuotes $naverDaily;
    private readonly NaverHistoricalClose $historicalClose;

    public function __construct(
        private readonly string $cacheDir,
        ?NaverDailyQuotes $naverDaily = null,
        private readonly ?\Closure $chartTransport = null,
        private readonly ?\Closure $clock = null,
        ?NaverHistoricalClose $historicalClose = null,
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
        $this->naverDaily = $naverDaily ?? new NaverDailyQuotes($this->cacheDir . '/naver');
        $this->historicalClose = $historicalClose ?? new NaverHistoricalClose($this->cacheDir . '/historical-close');
    }

    private function now(): int { return $this->clock !== null ? ($this->clock)() : time(); }

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
        $cacheFile = sprintf('%s/%s_%s_%s_closed_v2.json', $this->cacheDir, $symbol, $range, $interval);
        // 새로고침이면 네이버 당일 고저도 같이 새로 받는다
        $useNaverCache = $useCache && $maxAgeSeconds !== 0;

        $cached = null;
        if (is_file($cacheFile)) {
            /** @var list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}> $cached */
            $cached = json_decode((string) file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
            if ($useCache && is_array($cached) && $cached !== []) {
                $maxAge = $maxAgeSeconds ?? self::CACHE_MAX_AGE_SECONDS;
                $age = $this->now() - (int) filemtime($cacheFile);
                if ($maxAge > 0 && $age < $maxAge) {
                    // 일봉 캐시가 살아 있어도 당일 고저는 장중 계속 바뀐다
                    return $this->mergeNaverDaily($cached, $symbol, $interval, $useNaverCache);
                }
            }
        }

        try {
            $rows = $this->fetchLive($symbol, $range, $interval);
            file_put_contents($cacheFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $this->mergeNaverDaily($rows, $symbol, $interval, $useNaverCache);
        } catch (\Throwable $e) {
            // 네트워크/429 등: 오래된 캐시라도 있으면 현재가·점수용으로 사용
            if (is_array($cached) && $cached !== []) {
                return $this->mergeNaverDaily($cached, $symbol, $interval, $useNaverCache);
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
        if ($this->chartTransport !== null) {
            $body = json_encode(($this->chartTransport)($url), JSON_THROW_ON_ERROR);
            $code = 200;
        }
        for ($attempt = 1; $this->chartTransport === null && $attempt <= 3; $attempt++) {
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
            $close = $quote['close'][$i] ?? null;
            $repaired = false;
            if ($close === null && $interval === '1d') {
                $close = $this->confirmedMissingClose($quote, $i, (int)$ts, $result['meta'] ?? [], $symbol);
                $repaired = $close !== null;
            }
            if ($close === null) continue;
            $rows[] = [
                'time' => (int) $ts,
                'time_kst' => $this->formatKst((int) $ts),
                'open' => (float) ($quote['open'][$i] ?? $close),
                'high' => (float) ($quote['high'][$i] ?? $close),
                'low' => (float) ($quote['low'][$i] ?? $close),
                'close' => (float) $close,
                'volume' => (int) ($quote['volume'][$i] ?? 0),
                'synthetic' => !isset($quote['open'][$i], $quote['high'][$i], $quote['low'][$i]),
                'close_source' => $repaired ? 'yahoo_regular_market_final' : 'yahoo_daily',
                'close_observed_at' => $repaired ? (int)$result['meta']['regularMarketTime'] : null,
            ];
        }

        // Live quote substitution corrupts historical/intraday OHLC. Daily incomplete bars are excluded downstream.
        if ($interval === '1d') {
            $rows = $this->mergeRegularMarketPrice($rows, $result);
            foreach ($rows as &$row) {
                $row['is_complete'] = CandleClock::closeTime($row, $symbol) <= $this->now();
            }
            unset($row);
        }
        $rows = $this->sortByTime($rows);
        if ($rows === []) {
            throw new \RuntimeException("No usable candles for {$symbol}");
        }

        return $rows;
    }

    /** Fill only a missing close using same-session, post-close regular metadata and real OHLCV. */
    private function confirmedMissingClose(array $quote,int $i,int $ts,array $meta,string $symbol): ?float
    {
        if(strtoupper((string)($meta['symbol']??''))!==strtoupper($symbol))return null;
        $at=$meta['regularMarketTime']??null;$price=$meta['regularMarketPrice']??null;
        if(!is_numeric($at)||!is_numeric($price)||!is_finite((float)$price)||$price<=0)return null;
        $close=CandleClock::closeTime(['time'=>$ts],$symbol);
        if($at<$close||$at>$this->now()||$this->now()<$close)return null;
        $tz=new \DateTimeZone(preg_match('/\.(KS|KQ)$/i',$symbol)?'Asia/Seoul':'America/New_York');
        $day=static fn(int $t)=>(new \DateTimeImmutable('@'.$t))->setTimezone($tz)->format('Y-m-d');
        if($day((int)$at)!==$day($ts))return null;
        foreach(['open','high','low','volume'] as $k){
            $v=$quote[$k][$i]??null;
            if(!is_numeric($v)||!is_finite((float)$v)||($k==='volume'?$v<0:$v<=0))return null;
        }
        if($quote['low'][$i]>min($quote['open'][$i],$price)||$quote['high'][$i]<max($quote['open'][$i],$price))return null;
        return (float)$price;
    }

    /**
     * 국내 종목 일봉의 최근 며칠을 네이버 «일별 시세»의 실제 고가·저가로 덮어쓴다.
     *
     * Yahoo는 장중 당일 봉의 OHLC를 null로 주는 경우가 있어,
     * regularMarketPrice로 만든 합성 봉은 고가=저가=현재가가 된다.
     * 그러면 “오늘 저가 이탈” 같은 타이트 손절선이 현재가로 잘못 잡힌다.
     *
     * 날짜는 고정하지 않는다. 네이버가 주는 최근 거래일과 봉의 KST 날짜를 맞춰 붙일 뿐이다.
     *
     * @param list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}> $rows
     * @return list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}>
     */
    private function mergeNaverDaily(
        array $rows,
        string $symbol,
        string $interval,
        bool $useNaverCache = true,
    ): array {
        if ($rows === [] || $interval !== '1d' || NaverDailyQuotes::codeOf($symbol) === null) {
            return $rows;
        }

        $rows = $this->historicalClose->repair($rows, $symbol, $interval, $this->now());
        $quotes = $this->naverDaily->recent($symbol, useCache: $useNaverCache);
        if ($quotes === []) {
            return $rows;
        }

        return DailyEvidenceMerge::merge($rows, $quotes, $symbol, $this->naverDaily->evidenceFetchedAt(), $this->now());
    }

    /** 해당 KST 거래일의 정규장 마감(15:30) 타임스탬프 */
    private function closeTsKst(string $day): int
    {
        return (new \DateTimeImmutable($day . ' 15:30:00', new \DateTimeZone('Asia/Seoul')))
            ->getTimestamp();
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
            : $this->now();
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
                'synthetic' => true,
            ]];
        }

        $lastIdx = array_key_last($rows);
        $lastDay = $this->dayKst((int) $rows[$lastIdx]['time']);

        // Yahoo meta.regularMarketTime 이 몇 년 전 값으로 남는 경우가 있다.
        // 그때 봉을 뒤에 붙이면 날짜가 거꾸로 흘러 최근 종가가 옛 날짜로 보인다.
        if ($liveDay < $lastDay) {
            return $rows;
        }

        if ($lastDay === $liveDay) {
            $rows[$lastIdx]['close'] = $price;
            $rows[$lastIdx]['high'] = max((float) $rows[$lastIdx]['high'], $price);
            $rows[$lastIdx]['low'] = min((float) $rows[$lastIdx]['low'], $price);
            return $rows;
        }

        $lastTs = (int) $rows[$lastIdx]['time'];
        if ($ts - $lastTs > 10 * 86400) {
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
            'synthetic' => true,
        ];

        return $rows;
    }

    /**
     * @param list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}> $rows
     * @return list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}>
     */
    private function sortByTime(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);
        return array_values($rows);
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
