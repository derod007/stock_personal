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

    /**
     * 본문 + 댓글 페이지(cpage) HTML을 모두 가져온다.
     *
     * @return list<string> [본문HTML, 댓글페이지1, ...]
     */
    public function fetchDocumentPages(int|string $documentSrl, bool $useCache = true, int $maxCommentPages = 15): array
    {
        $srl = (string) $documentSrl;
        $main = $this->fetchDocument($srl, $useCache);
        $pages = [$main];

        $maxPage = $this->detectCommentPageCount($main);
        $maxPage = max(1, min($maxCommentPages, $maxPage));

        for ($cpage = 1; $cpage <= $maxPage; $cpage++) {
            $url = 'https://www.fmkorea.com/index.php?' . http_build_query([
                'document_srl' => $srl,
                'mid' => 'stock',
                'cpage' => $cpage,
            ], '', '&', PHP_QUERY_RFC3986);
            try {
                $pages[] = $this->get($url, $useCache);
            } catch (\Throwable $e) {
                // 댓글 페이지만 실패하면 본문만이라도 유지
                break;
            }
        }

        return $pages;
    }

    private function detectCommentPageCount(string $html): int
    {
        $max = 1;
        // document_cpage = 현재 댓글 페이지(최대가 아님). 링크·현재값을 합쳐 상한을 잡는다.
        if (preg_match('/window\.document_cpage\s*=\s*(\d+)/', $html, $m)) {
            $max = max($max, (int) $m[1]);
        }
        // 댓글 영역 페이징만 (게시판 page= 와 구분)
        if (preg_match('/id="comment".*?<\/form>/su', $html, $section)
            || preg_match('/class="fdb_lst[^"]*".*?class="bd_pg[^"]*".*?<\/div>/su', $html, $section)
        ) {
            $chunk = $section[0];
            if (preg_match_all('/[?&]cpage=(\d+)/', $chunk, $m)) {
                foreach ($m[1] as $p) {
                    $max = max($max, (int) $p);
                }
            }
            if (preg_match('/<strong[^>]*class="[^"]*this[^"]*"[^>]*>\s*(\d+)\s*<\/strong>/u', $chunk, $m)) {
                $max = max($max, (int) $m[1]);
            }
        } elseif (preg_match_all('/[?&]cpage=(\d+)/', $html, $m)) {
            foreach ($m[1] as $p) {
                $max = max($max, (int) $p);
            }
        }
        return $max;
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
