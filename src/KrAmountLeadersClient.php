<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 다음 금융 거래대금(accTradePrice) 순위.
 * 고가·저거래량 종목도 대금 TOP에 들어온다.
 * 다음이 막히면 네이버 거래량 상위 표의 대금 컬럼으로 폴백한다.
 */
final class KrAmountLeadersClient
{
    private const DAUM_URL = 'https://finance.daum.net/api/trend/trade_volume';
    private const KOSPI_URL = 'https://finance.naver.com/sise/sise_quant.naver?sosok=0';
    private const KOSDAQ_URL = 'https://finance.naver.com/sise/sise_quant.naver?sosok=1';

    public function __construct(
        private readonly string $cacheDir,
        private readonly int $cacheTtlSeconds = 1200,
        private readonly string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    /**
     * @return list<array{
     *   rank:int,
     *   code:string,
     *   name:string,
     *   market:string,
     *   yahoo:string,
     *   price:?float,
     *   volume:?int,
     *   amount_million:float,
     *   amount_won:float,
     *   change_pct:?float
     * }>
     */
    public function topByAmount(int $limit = 100, bool $useCache = true, string $market = 'all'): array
    {
        $limit = max(1, min(200, $limit));
        $market = strtolower($market);
        if (!in_array($market, ['all', 'kospi', 'kosdaq'], true)) {
            throw new \InvalidArgumentException('지원하지 않는 거래대금 시장: ' . $market);
        }
        $cacheFile = sprintf(
            '%s/kr_amount_leaders_v4_%s_%d.json',
            $this->cacheDir,
            $market,
            $limit
        );
        if ($useCache && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $this->cacheTtlSeconds) {
            /** @var list<array<string,mixed>> $cached */
            $cached = json_decode((string) file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
            return $cached;
        }

        $rows = $this->fetchDaum($market, $limit);
        if ($rows === []) {
            $rows = $this->fetchNaver($market);
        }

        usort($rows, static function (array $a, array $b): int {
            return $b['amount_million'] <=> $a['amount_million'];
        });

        $out = [];
        $seen = [];
        $rank = 0;
        foreach ($rows as $row) {
            $code = $row['code'];
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $rank++;
            $row['rank'] = $rank;
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        file_put_contents(
            $cacheFile,
            json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchDaum(string $market, int $limit): array
    {
        try {
            $need = $market === 'all' ? $limit : $limit;
            $rows = match ($market) {
                'kospi' => $this->fetchDaumMarket('KOSPI', $need),
                'kosdaq' => $this->fetchDaumMarket('KOSDAQ', $need),
                default => array_merge(
                    $this->fetchDaumMarket('KOSPI', $need),
                    $this->fetchDaumMarket('KOSDAQ', $need),
                ),
            };
        } catch (\Throwable) {
            return [];
        }

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchDaumMarket(string $daumMarket, int $need): array
    {
        $yahooSuffix = $daumMarket === 'KOSDAQ' ? '.KQ' : '.KS';
        $rows = [];
        $page = 1;
        $perPage = 100;
        while (count($rows) < $need) {
            $url = sprintf(
                '%s?page=%d&perPage=%d&fieldName=accTradePrice&order=desc&market=%s&pagination=true',
                self::DAUM_URL,
                $page,
                $perPage,
                rawurlencode($daumMarket)
            );
            $payload = json_decode($this->httpGet($url, [
                'Accept: application/json, text/plain, */*',
                'Referer: https://finance.daum.net/domestic/market_upper',
            ]), true);
            $chunk = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            if ($chunk === []) {
                break;
            }
            foreach ($chunk as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $mapped = $this->mapDaumRow($item, $daumMarket, $yahooSuffix);
                if ($mapped !== null) {
                    $rows[] = $mapped;
                }
            }
            if (count($chunk) < $perPage) {
                break;
            }
            $page++;
            if ($page > 3) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $item
     * @return ?array<string,mixed>
     */
    private function mapDaumRow(array $item, string $market, string $yahooSuffix): ?array
    {
        $symbol = (string) ($item['symbolCode'] ?? '');
        $code = preg_replace('/\D+/', '', $symbol) ?? '';
        if (strlen($code) !== 6) {
            $code = preg_replace('/\D+/', '', (string) ($item['code'] ?? '')) ?? '';
            if (strlen($code) > 6) {
                $code = substr($code, -6);
            }
        }
        if (strlen($code) !== 6) {
            return null;
        }

        $amountWon = (float) ($item['accTradePrice'] ?? 0);
        if ($amountWon <= 0) {
            return null;
        }

        $rate = abs((float) ($item['changeRate'] ?? 0) * 100);
        $changePct = match ((string) ($item['change'] ?? '')) {
            'FALL' => -$rate,
            'RISE' => $rate,
            default => 0.0,
        };

        $price = isset($item['tradePrice']) && is_numeric($item['tradePrice'])
            ? (float) $item['tradePrice']
            : null;
        $volume = isset($item['accTradeVolume']) && is_numeric($item['accTradeVolume'])
            ? (int) $item['accTradeVolume']
            : null;

        return [
            'rank' => 0,
            'code' => $code,
            'name' => (string) ($item['name'] ?? $code),
            'market' => $market,
            'yahoo' => $code . $yahooSuffix,
            'price' => $price,
            'volume' => $volume,
            'amount_million' => $amountWon / 1_000_000,
            'amount_won' => $amountWon,
            'change_pct' => $changePct,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchNaver(string $market): array
    {
        return match ($market) {
            'kospi' => $this->fetchNaverMarket(self::KOSPI_URL, 'KOSPI', '.KS'),
            'kosdaq' => $this->fetchNaverMarket(self::KOSDAQ_URL, 'KOSDAQ', '.KQ'),
            default => array_merge(
                $this->fetchNaverMarket(self::KOSPI_URL, 'KOSPI', '.KS'),
                $this->fetchNaverMarket(self::KOSDAQ_URL, 'KOSDAQ', '.KQ'),
            ),
        };
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchNaverMarket(string $url, string $market, string $yahooSuffix): array
    {
        $html = $this->toUtf8($this->httpGet($url, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Referer: https://finance.naver.com/sise/',
        ]));

        $rows = [];
        if (!preg_match_all(
            '#<tr>\s*<td[^>]*>\s*(\d+)\s*</td>\s*<td[^>]*>\s*<a href="/item/main\.naver\?code=(\d{6})"[^>]*>([^<]+)</a>\s*</td>(.*?)</tr>#su',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        foreach ($matches as $m) {
            $code = $m[2];
            $name = html_entity_decode(trim($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $nums = $this->extractNumbers($m[4]);
            $price = isset($nums[0]) ? (float) $nums[0] : null;
            $volume = isset($nums[1]) ? (int) $nums[1] : null;
            $amountMillion = isset($nums[2]) ? (float) $nums[2] : null;
            if ($amountMillion === null || $amountMillion <= 0) {
                continue;
            }
            $changePct = null;
            if (preg_match('/([+\-]?\d+(?:\.\d+)?)%/u', $m[4], $cm)) {
                $changePct = (float) $cm[1];
            }

            $rows[] = [
                'rank' => 0,
                'code' => $code,
                'name' => $name,
                'market' => $market,
                'yahoo' => $code . $yahooSuffix,
                'price' => $price,
                'volume' => $volume,
                'amount_million' => $amountMillion,
                'amount_won' => $amountMillion * 1_000_000,
                'change_pct' => $changePct,
            ];
        }

        return $rows;
    }

    /**
     * @return list<float>
     */
    private function extractNumbers(string $cellsHtml): array
    {
        $nums = [];
        if (!preg_match_all('#<td[^>]*>(.*?)</td>#su', $cellsHtml, $tds)) {
            return [];
        }
        foreach ($tds[1] as $raw) {
            $t = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $t = str_replace([',', "\xc2\xa0", '&nbsp;'], ['', ' ', ' '], $t);
            $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
            if ($t === '' || strcasecmp($t, 'N/A') === 0) {
                continue;
            }
            if (preg_match('/[가-힣%]/u', $t) === 1) {
                continue;
            }
            if (preg_match('/^-?\d+(?:\.\d+)?$/', $t) !== 1) {
                continue;
            }
            $nums[] = (float) $t;
        }

        return $nums;
    }

    /**
     * @param list<string> $extraHeaders
     */
    private function httpGet(string $url, array $extraHeaders = []): string
    {
        $headers = array_merge([
            'User-Agent: ' . $this->userAgent,
            'Accept-Language: ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7',
        ], $extraHeaders);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('시세 조회 실패: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400 || $body === '') {
            throw new \RuntimeException("시세 HTTP {$code}");
        }

        return $body;
    }

    private function toUtf8(string $html): string
    {
        if (preg_match('/charset\s*=\s*["\']?euc-kr/i', $html) === 1
            || preg_match('/charset\s*=\s*["\']?ks_c_5601/i', $html) === 1
        ) {
            $converted = @mb_convert_encoding($html, 'UTF-8', 'EUC-KR');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            $converted = @mb_convert_encoding($html, 'UTF-8', 'EUC-KR');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $html;
    }
}
