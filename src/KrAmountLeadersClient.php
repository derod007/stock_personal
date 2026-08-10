<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 네이버 증권 거래량 상위 표에서 거래대금을 읽어
 * 코스피+코스닥을 합친 뒤 거래대금 순으로 정렬한다.
 *
 * (네이버에 거래대금 전용 순위 URL이 없어, 각 시장 거래량 상위≈100의
 *  거래대금 컬럼을 병합·재정렬하는 방식으로 TOP N을 만든다.)
 */
final class KrAmountLeadersClient
{
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
     *   amount_won:float
     * }>
     */
    public function topByAmount(int $limit = 100, bool $useCache = true): array
    {
        $limit = max(1, min(200, $limit));
        $cacheFile = $this->cacheDir . '/kr_amount_leaders_' . $limit . '.json';
        if ($useCache && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $this->cacheTtlSeconds) {
            /** @var list<array<string,mixed>> $cached */
            $cached = json_decode((string) file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
            return $cached;
        }

        $rows = array_merge(
            $this->fetchMarket(self::KOSPI_URL, 'KOSPI', '.KS'),
            $this->fetchMarket(self::KOSDAQ_URL, 'KOSDAQ', '.KQ'),
        );

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
     * @return list<array{
     *   rank:int,
     *   code:string,
     *   name:string,
     *   market:string,
     *   yahoo:string,
     *   price:?float,
     *   volume:?int,
     *   amount_million:float,
     *   amount_won:float
     * }>
     */
    private function fetchMarket(string $url, string $market, string $yahooSuffix): array
    {
        $html = $this->httpGet($url);
        $html = $this->toUtf8($html);

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
            // 현재가, 거래량, 거래대금(백만), …
            $price = isset($nums[0]) ? (float) $nums[0] : null;
            $volume = isset($nums[1]) ? (int) $nums[1] : null;
            $amountMillion = isset($nums[2]) ? (float) $nums[2] : null;
            if ($amountMillion === null || $amountMillion <= 0) {
                continue;
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
            // 전일비/등락률(한글·% 포함) 스킵
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

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . $this->userAgent,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7',
                'Referer: https://finance.naver.com/sise/',
            ],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Naver sise fetch failed: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400 || $body === '') {
            throw new \RuntimeException("Naver sise HTTP {$code}");
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
