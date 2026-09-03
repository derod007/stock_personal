<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 네이버 «테마별 시세». 전일대비 등락 큰 테마 = 오늘 돈이 몰린 곳.
 * 날짜·테마 목록은 하드코딩하지 않고 조회 시점 페이지를 그대로 읽는다.
 */
final class NaverThemeClient
{
    private const LIST_URL = 'https://finance.naver.com/sise/theme.naver?field=change_rate&ordering=desc';

    public function __construct(
        private readonly string $cacheDir,
        private readonly int $cacheTtlSeconds = 300,
        private readonly string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    /**
     * @return list<array{
     *   no:int,
     *   name:string,
     *   change_pct:float,
     *   change_3d_pct:?float,
     *   up:int,
     *   flat:int,
     *   down:int,
     *   leaders:list<array{code:string,name:string}>
     * }>
     */
    public function topByChange(int $pages = 1, bool $useCache = true): array
    {
        $pages = max(1, min(8, $pages));
        $cacheFile = $this->cacheDir . '/naver_themes_chg_' . $pages . '.json';
        if ($useCache && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $this->cacheTtlSeconds) {
            /** @var list<array<string,mixed>> $cached */
            $cached = json_decode((string) file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
            return $cached;
        }

        $out = [];
        $seen = [];
        for ($page = 1; $page <= $pages; $page++) {
            $url = self::LIST_URL . '&page=' . $page;
            $html = $this->toUtf8($this->httpGet($url));
            foreach ($this->parse($html) as $row) {
                $no = $row['no'];
                if (isset($seen[$no])) {
                    continue;
                }
                $seen[$no] = true;
                $out[] = $row;
            }
        }

        usort($out, static fn(array $a, array $b): int => $b['change_pct'] <=> $a['change_pct']);

        file_put_contents(
            $cacheFile,
            json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $out;
    }

    /**
     * @return list<array{
     *   no:int,
     *   name:string,
     *   change_pct:float,
     *   change_3d_pct:?float,
     *   up:int,
     *   flat:int,
     *   down:int,
     *   leaders:list<array{code:string,name:string}>
     * }>
     */
    private function parse(string $html): array
    {
        $rows = [];
        if (!preg_match_all(
            '#<td class="col_type1"><a href="/sise/sise_group_detail\.naver\?type=theme&no=(\d+)">([^<]+)</a></td>(.*?)</tr>#su',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        foreach ($matches as $m) {
            $chunk = $m[3];
            $pcts = [];
            if (preg_match_all('/([+\-]?[0-9]+(?:\.[0-9]+)?)%/u', $chunk, $pm)) {
                foreach ($pm[1] as $p) {
                    $pcts[] = (float) $p;
                }
            }
            $counts = [];
            if (preg_match_all('/<td class="number col_type4">\s*(\d+)\s*<\/td>/u', $chunk, $cm)) {
                $counts = array_map('intval', $cm[1]);
            }
            $leaders = [];
            if (preg_match_all('/item\/main\.naver\?code=(\d{6})"[^>]*>([^<]+)</u', $chunk, $lm, PREG_SET_ORDER)) {
                foreach ($lm as $hit) {
                    $leaders[] = [
                        'code' => $hit[1],
                        'name' => html_entity_decode(trim($hit[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    ];
                }
            }

            $rows[] = [
                'no' => (int) $m[1],
                'name' => html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'change_pct' => $pcts[0] ?? 0.0,
                'change_3d_pct' => $pcts[1] ?? null,
                'up' => $counts[0] ?? 0,
                'flat' => $counts[1] ?? 0,
                'down' => $counts[2] ?? 0,
                'leaders' => $leaders,
            ];
        }

        return $rows;
    }

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . $this->userAgent,
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: ko-KR,ko;q=0.9,en;q=0.8',
                'Referer: https://finance.naver.com/sise/',
            ],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Naver theme fetch failed: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 400 || $body === '') {
            throw new \RuntimeException("Naver theme HTTP {$status}");
        }

        return $body;
    }

    private function toUtf8(string $html): string
    {
        if (mb_check_encoding($html, 'UTF-8') && preg_match('/[가-힣]/u', $html) === 1) {
            return $html;
        }
        $converted = @mb_convert_encoding($html, 'UTF-8', 'EUC-KR');
        if (is_string($converted) && $converted !== '' && preg_match('/[가-힣]/u', $converted) === 1) {
            return $converted;
        }

        return $html;
    }
}
