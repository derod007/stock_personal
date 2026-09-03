<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 네이버 증권 «일별 시세»(sise_day)에서 최근 거래일의 시/고/저/종·거래량을 읽는다.
 *
 * Yahoo는 장중 국내 종목의 당일 봉 OHLC를 null로 주고 meta.regularMarketPrice만 채우는
 * 경우가 있어, 당일 고가·저가가 현재가로 눌려 버린다.
 * 그러면 “오늘 저가 이탈”류의 타이트 손절선이 실제 저가가 아니라 현재가가 된다.
 * 이 클래스는 그 구멍을 실제 장중 고저로 메운다.
 *
 * 어떤 날짜도 하드코딩하지 않는다. 네이버가 주는 최근 N거래일을 그대로 쓰고,
 * 호출 시점의 거래일과 맞춰 붙인다.
 */
final class NaverDailyQuotes
{
    private const URL = 'https://finance.naver.com/item/sise_day.naver?code=%s&page=%d';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    public function __construct(
        private readonly string $cacheDir,
        /** 장중에도 당일 고저가 갱신되도록 짧게 잡는다. */
        private readonly int $cacheTtlSeconds = 300,
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    /**
     * '006340.KS' / '006340.KQ' / '006340' → '006340'. 국내 종목이 아니면 null.
     */
    public static function codeOf(string $symbol): ?string
    {
        if (preg_match('/^(\d{6})(?:\.(?:KS|KQ))?$/i', trim($symbol), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * 최근 거래일 시세를 날짜(Y-m-d) 키로 반환. 실패하면 빈 배열.
     *
     * @return array<string, array{date:string,open:float,high:float,low:float,close:float,volume:int}>
     */
    public function recent(string $symbol, int $pages = 1, bool $useCache = true): array
    {
        $code = self::codeOf($symbol);
        if ($code === null) {
            return [];
        }

        $pages = max(1, min(4, $pages));
        $cacheFile = sprintf('%s/naver_day_%s_%d.json', $this->cacheDir, $code, $pages);
        if (
            $useCache
            && is_file($cacheFile)
            && (time() - (int) filemtime($cacheFile)) < $this->cacheTtlSeconds
        ) {
            /** @var array<string, array{date:string,open:float,high:float,low:float,close:float,volume:int}> $cached */
            $cached = json_decode((string) file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $out = [];
        try {
            for ($page = 1; $page <= $pages; $page++) {
                $html = $this->toUtf8($this->httpGet(sprintf(self::URL, $code, $page), $code));
                foreach ($this->parse($html) as $date => $row) {
                    $out[$date] = $row;
                }
            }
        } catch (\Throwable) {
            // 네이버가 막히면 Yahoo 데이터를 그대로 쓴다
            return is_file($cacheFile)
                ? (array) json_decode((string) file_get_contents($cacheFile), true)
                : [];
        }

        if ($out !== []) {
            krsort($out);
            file_put_contents($cacheFile, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $out;
    }

    /**
     * 일별 시세 표: 날짜 · 종가 · 전일비 · 시가 · 고가 · 저가 · 거래량
     *
     * @return array<string, array{date:string,open:float,high:float,low:float,close:float,volume:int}>
     */
    private function parse(string $html): array
    {
        if (preg_match_all('#<tr[^>]*>(.*?)</tr>#su', $html, $trs) === false || ($trs[1] ?? []) === []) {
            return [];
        }

        $out = [];
        foreach ($trs[1] as $tr) {
            $found = preg_match_all(
                '#<span[^>]*class="[^"]*tah[^"]*"[^>]*>(.*?)</span>#su',
                $tr,
                $spans
            );
            if ($found === false || $found < 7) {
                continue;
            }
            $cells = [];
            foreach ($spans[1] as $raw) {
                $t = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $t = str_replace([',', "\xc2\xa0", '&nbsp;'], ['', ' ', ' '], $t);
                $cells[] = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
            }
            if (count($cells) < 7) {
                continue;
            }
            if (preg_match('/^(\d{4})\.(\d{2})\.(\d{2})$/', $cells[0], $d) !== 1) {
                continue;
            }

            $date = sprintf('%s-%s-%s', $d[1], $d[2], $d[3]);
            $close = $this->num($cells[1]);
            $open = $this->num($cells[3]);
            $high = $this->num($cells[4]);
            $low = $this->num($cells[5]);
            $volume = $this->num($cells[6]);
            if ($close <= 0 || $high <= 0 || $low <= 0) {
                continue;
            }

            $out[$date] = [
                'date' => $date,
                'open' => $open > 0 ? $open : $close,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => (int) $volume,
            ];
        }

        return $out;
    }

    private function num(string $raw): float
    {
        $t = preg_replace('/[^0-9.\-]/', '', $raw) ?? '';

        return $t === '' ? 0.0 : (float) $t;
    }

    private function httpGet(string $url, string $code): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::USER_AGENT,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: ko-KR,ko;q=0.9,en;q=0.8',
                'Referer: https://finance.naver.com/item/sise.naver?code=' . $code,
            ],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Naver sise_day fetch failed: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 400 || $body === '') {
            throw new \RuntimeException("Naver sise_day HTTP {$status}");
        }

        return $body;
    }

    private function toUtf8(string $html): string
    {
        if (
            preg_match('/charset\s*=\s*["\']?euc-kr/i', $html) === 1
            || preg_match('/charset\s*=\s*["\']?ks_c_5601/i', $html) === 1
            || !mb_check_encoding($html, 'UTF-8')
        ) {
            $converted = @mb_convert_encoding($html, 'UTF-8', 'EUC-KR');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $html;
    }
}
