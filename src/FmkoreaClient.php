<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 에펨코리아 HTML 수집. DDoS/레이트리밋에 대비해 캐시·딜레이·재시도를 둔다.
 */
final class FmkoreaClient
{
    private int $blockCount = 0;

    public function __construct(
        private readonly string $cacheDir,
        private readonly float $delaySeconds = 2.5,
        private readonly string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    public function cachePathForUrl(string $url): string
    {
        return $this->cacheDir . '/' . sha1($url) . '.html';
    }

    public function putCache(string $url, string $html): void
    {
        file_put_contents($this->cachePathForUrl($url), $html);
    }

    public function searchByNick(string $nick, int $page = 1, bool $useCache = true): string
    {
        $url = 'https://www.fmkorea.com/search.php?' . http_build_query([
            'mid' => 'stock',
            'search_keyword' => $nick,
            'search_target' => 'nick_name',
            'page' => $page,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->get($url, $useCache);
    }

    public function fetchDocument(int|string $documentSrl, bool $useCache = true): string
    {
        $url = 'https://www.fmkorea.com/' . $documentSrl;
        return $this->get($url, $useCache);
    }

    public function get(string $url, bool $useCache = true): string
    {
        $cacheKey = sha1($url) . '.html';
        $cacheFile = $this->cacheDir . '/' . $cacheKey;
        if ($useCache && is_file($cacheFile) && filesize($cacheFile) > 500) {
            return (string) file_get_contents($cacheFile);
        }

        usleep((int) ($this->delaySeconds * 1_000_000));

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
                'Referer: https://www.fmkorea.com/stock',
            ],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('FMKorea fetch failed: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 430 || str_contains($body, '보안 시스템') || str_contains($body, 'ddosCheckOnly')) {
            $this->blockCount++;
            // 차단 시 지수 백오프
            sleep(min(60, 5 * $this->blockCount));
            throw new \RuntimeException("FMKorea blocked request (HTTP {$code}). Wait and retry, or use bin/import_html_dir.php.");
        }
        $this->blockCount = 0;
        if ($code >= 400) {
            throw new \RuntimeException("FMKorea HTTP {$code} for {$url}");
        }

        file_put_contents($cacheFile, $body);
        return $body;
    }
}
