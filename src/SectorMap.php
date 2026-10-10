<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 종목 → 업종명·색 버킷.
 * 국장은 네이버 종목 페이지의 업종 링크, 미장은 소수의 정적 별칭.
 * 어떤 날짜도 하드코딩하지 않으며, 조회 결과는 코드별 파일 캐시(1일).
 */
final class SectorMap
{
    public const BUCKETS = [
        'semi' => '반도체·전자',
        'auto' => '자동차·조선',
        'fin' => '금융',
        'bio' => '바이오',
        'soft' => '소프트웨어',
        'energy' => '에너지·소재',
        'consumer' => '소비재',
        'indust' => '산업재',
        'trans' => '운송',
        'etf' => 'ETF',
        'other' => '기타',
    ];

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    /** @var array<string, string> Yahoo/별칭 → 버킷 */
    private const US_BUCKET = [
        'MU' => 'semi',
        'SNDK' => 'semi',
        'NVDA' => 'semi',
        'AMD' => 'semi',
        'INTC' => 'semi',
        'TSM' => 'semi',
        'AVGO' => 'semi',
        'AAPL' => 'soft',
        'MSFT' => 'soft',
        'GOOGL' => 'soft',
        'GOOG' => 'soft',
        'META' => 'soft',
        'AMZN' => 'consumer',
        'TSLA' => 'auto',
        'IONQ' => 'soft',
        'NBIS' => 'soft',
        'SKYQ' => 'soft',
        'ORCL' => 'soft',
        'STX' => 'semi',
        'EWY' => 'other',
        'SOXS' => 'semi',
        'SQQQ' => 'other',
        'SPY' => 'other',
        'CL=F' => 'energy',
    ];

    public function __construct(
        private readonly string $cacheDir,
        private readonly int $cacheTtlSeconds = 86400,
        /** @var (\Closure(string,string):string)|null 테스트용 HTTP 대체(URL, Accept) */
        private readonly ?\Closure $http = null,
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    /**
     * @return array{sector:string, sector_bucket:string, sector_label:string, name:?string}
     */
    public function resolve(string $symbol, bool $useCache = true): array
    {
        $symbol = trim($symbol);
        $code = NaverDailyQuotes::codeOf($symbol);
        if ($code !== null) {
            $out = $this->resolveKr($code, $useCache);
            if (($out['name'] ?? null) === null || $out['name'] === '') {
                $out['name'] = SymbolMap::koreanName($symbol);
            }

            return $out;
        }

        $upper = strtoupper($symbol);
        $bucket = self::US_BUCKET[$upper] ?? 'other';
        $sector = $bucket === 'other' ? ($upper !== '' ? $upper : '기타') : self::BUCKETS[$bucket];

        return [
            'sector' => $sector,
            'sector_bucket' => $bucket,
            'sector_label' => self::BUCKETS[$bucket] ?? '기타',
            'name' => SymbolMap::koreanName($symbol),
        ];
    }

    /**
     * @return array{sector:string, sector_bucket:string, sector_label:string, name:?string}
     */
    private function resolveKr(string $code, bool $useCache): array
    {
        $cacheFile = $this->cacheDir . '/sector_' . $code . '.json';
        if (
            $useCache
            && is_file($cacheFile)
            && (time() - (int) filemtime($cacheFile)) < $this->cacheTtlSeconds
        ) {
            /** @var array{sector?:string,sector_bucket?:string,sector_label?:string,name?:?string} $cached */
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['sector_bucket'], $cached['sector'])) {
                $name = isset($cached['name']) ? trim((string) $cached['name']) : '';
                $sectorName = (string) $cached['sector'];
                $bucket = $this->bucketOf($sectorName, $name);
                $out = [
                    'sector' => $sectorName !== '' ? $sectorName : '기타',
                    'sector_bucket' => $bucket,
                    'sector_label' => self::BUCKETS[$bucket] ?? '기타',
                    'name' => $name !== '' ? $name : null,
                ];
                if (
                    $out['sector_bucket'] !== (string) $cached['sector_bucket']
                    || (string) ($cached['sector_label'] ?? '') !== $out['sector_label']
                ) {
                    file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
                if ($name !== '') {
                    return $out;
                }
            }
        }

        $meta = $this->fetchNaverMeta($code);
        $sectorName = $meta['upjong'];
        $bucket = $this->bucketOf($sectorName, $meta['name']);
        $out = [
            'sector' => $sectorName !== '' ? $sectorName : '기타',
            'sector_bucket' => $bucket,
            'sector_label' => self::BUCKETS[$bucket] ?? '기타',
            'name' => $meta['name'] !== '' ? $meta['name'] : null,
        ];
        // 이름도 업종도 못 읽은 조회 실패는 캐시하지 않는다(자리표시자 '기타'가 하루 동안 굳는 것을 막는다).
        if ($meta['name'] !== '' || $meta['upjong'] !== '') {
            file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        return $out;
    }

    /** @var array<string,string>|null 네이버 업종 번호 → 업종명(프로세스당 한 번만 조회) */
    private ?array $industryNames = null;

    /**
     * finance.naver.com/item/main.naver 는 업종 링크가 없는 새 사이트로 이동한다.
     * 그래서 같은 사이트의 JSON(종목 integration의 industryCode, 업종 목록)에서 먼저 읽고,
     * 그 응답 자체를 못 읽을 때만 예전 HTML 파싱으로 되돌아간다.
     *
     * @return array{name:string, upjong:string}
     */
    private function fetchNaverMeta(string $code): array
    {
        $mobile = $this->fetchMobileMeta($code);

        return $mobile ?? $this->fetchLegacyMeta($code);
    }

    /**
     * @return array{name:string, upjong:string}|null null = 응답을 못 읽음
     */
    private function fetchMobileMeta(string $code): ?array
    {
        try {
            $item = json_decode($this->httpGet('https://m.stock.naver.com/api/stock/' . rawurlencode($code) . '/integration', 'application/json'), true);
            if (!is_array($item) || (string) ($item['itemCode'] ?? '') !== $code) {
                return null;
            }
            $name = trim((string) ($item['stockName'] ?? ''));
            $no = (string) ($item['industryCode'] ?? '');
            $upjong = '';
            if ($no !== '') {
                $names = $this->industryNames();
                if ($names === []) {
                    return null;
                }
                $upjong = $names[$no] ?? '';
            }

            return ['name' => $name, 'upjong' => $upjong];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,string>
     */
    private function industryNames(): array
    {
        if ($this->industryNames !== null) {
            return $this->industryNames;
        }
        $names = [];
        try {
            for ($page = 1; $page <= 5; $page++) {
                $j = json_decode($this->httpGet('https://m.stock.naver.com/api/stocks/industry?page=' . $page . '&pageSize=100', 'application/json'), true);
                if (!is_array($j) || !is_array($j['groups'] ?? null)) {
                    $names = [];
                    break;
                }
                foreach ($j['groups'] as $g) {
                    $no = (string) ($g['no'] ?? '');
                    $label = preg_replace('/\s+/u', '', trim((string) ($g['name'] ?? ''))) ?? '';
                    if ($no !== '' && $label !== '') {
                        $names[$no] = $label;
                    }
                }
                if ($j['groups'] === [] || count($names) >= (int) ($j['totalCount'] ?? 0)) {
                    break;
                }
            }
        } catch (\Throwable) {
            $names = [];
        }

        return $this->industryNames = $names;
    }

    /**
     * @return array{name:string, upjong:string}
     */
    private function fetchLegacyMeta(string $code): array
    {
        $url = 'https://finance.naver.com/item/main.naver?code=' . rawurlencode($code);
        try {
            $html = $this->toUtf8($this->httpGet($url));
        } catch (\Throwable) {
            return ['name' => '', 'upjong' => ''];
        }

        $name = '';
        if (preg_match('#<title>\s*([^<:]+)\s*[:：]#u', $html, $m) === 1) {
            $name = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($name === '' && preg_match('#class="wrap_company"[^>]*>.*?<h2[^>]*>\s*([^<]+)#su', $html, $m) === 1) {
            $name = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $upjong = '';
        if (preg_match(
            '#sise_group_detail\.naver\?type=upjong&(?:amp;)?no=\d+"[^>]*>([^<]{2,40})</a>#u',
            $html,
            $m
        ) === 1) {
            $upjong = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $upjong = preg_replace('/\s+/u', '', $upjong) ?? $upjong;
        }

        return ['name' => $name, 'upjong' => $upjong];
    }

    public function bucketOf(string $sectorName, string $name = ''): string
    {
        $s = preg_replace('/\s+/u', '', $sectorName) ?? $sectorName;
        $n = preg_replace('/\s+/u', '', $name) ?? $name;

        if ($this->isEtfName($n) || $this->isEtfName($s)) {
            return 'etf';
        }
        if ($s === '') {
            return 'other';
        }

        if (preg_match('/제약|바이오|헬스케어|의료|생명과학|생물공학|건강관리/u', $s) === 1) {
            return 'bio';
        }
        if (preg_match('/은행|증권|보험|금융|카드|저축|창업투자/u', $s) === 1) {
            return 'fin';
        }
        if (preg_match('/소프트웨어|인터넷|게임|통신|미디어|엔터|IT서비스|방송/iu', $s) === 1
            && preg_match('/금융서비스/u', $s) !== 1
        ) {
            return 'soft';
        }
        if (preg_match('/반도체|전자부품|디스플레이|전기전자|IT하드웨어|하드웨어|핸드셋|전자장비|전자기기/iu', $s) === 1) {
            return 'semi';
        }
        if (preg_match('/전자제품/u', $s) === 1) {
            return 'consumer';
        }
        if (preg_match('/전기제품|전기장비|전선|2차전지|배터리/u', $s) === 1) {
            return 'energy';
        }
        if (preg_match('/에너지|화학|소재|철강|금속|광업|석유|가스|유틸리티|전력|원자력|원전|포장재/u', $s) === 1) {
            return 'energy';
        }
        if (preg_match('/해운|도로와철도|육운|운송사|물류/u', $s) === 1) {
            return 'trans';
        }
        if (preg_match('/자동차|운송장비|타이어|조선|항공/u', $s) === 1) {
            return 'auto';
        }
        if (preg_match('/기계|건설|건축|산업재|상업서비스|방산|방위/u', $s) === 1) {
            return 'indust';
        }
        if (preg_match('/유통|식품|음료|의류|화장품|필수소비|임의소비|소매|백화점|호텔|레저/u', $s) === 1) {
            return 'consumer';
        }

        return 'other';
    }

    private function isEtfName(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        $u = strtoupper($name);
        foreach ([
            'KODEX', 'TIGER', 'KOSEF', 'ACE', 'SOL', 'PLUS', 'RISE', 'HANARO',
            'ARIRANG', 'KBSTAR', 'TIMEFOLIO', 'WON', 'KIWOOM', 'KINDEX', 'KOACT',
        ] as $p) {
            if (str_starts_with($u, $p)) {
                return true;
            }
        }

        return preg_match('/인버스|레버리지|커버드콜|ETF|액티브|맥쿼리인프라/u', $name) === 1;
    }

    private function httpGet(string $url, string $accept = 'text/html,application/xhtml+xml'): string
    {
        if ($this->http !== null) {
            return ($this->http)($url, $accept);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::USER_AGENT,
                'Accept: ' . $accept,
                'Accept-Language: ko-KR,ko;q=0.9,en;q=0.8',
                'Referer: https://finance.naver.com/',
            ],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Naver sector fetch failed: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 400 || $body === '') {
            throw new \RuntimeException("Naver sector HTTP {$status}");
        }

        return $body;
    }

    private function toUtf8(string $html): string
    {
        // 이미 UTF-8이면 건드리지 않는다 (메타 charset=euc-kr 이어도 본문이 UTF-8인 경우 있음)
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
