<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 국장 거래대금 상위 종목에 노라무 점수·진입 추천을 붙인다.
 */
final class KrAmountScanner
{
    /** @var list<string> */
    private const ENTRY_ACTIONS = ['add_on_pullback', 'watchlist_buy_zone'];

    private readonly SectorMap $sectors;

    public function __construct(
        private readonly KrAmountLeadersClient $leaders,
        private readonly ProposalService $service,
        private readonly string $cacheDir,
        /** 스캔 결과(점수·신규진입 문장) 캐시. 현재가가 빨리 식어서 짧게 둔다. */
        private readonly int $cacheTtlSeconds = 300,
        ?SectorMap $sectors = null,
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
        $this->sectors = $sectors ?? new SectorMap($this->cacheDir . '/sector');
    }

    /**
     * @return array{
     *   ok:bool,
     *   profile:string,
     *   market:string,
     *   limit:int,
     *   fetched_at:string,
     *   source:string,
     *   error:?string,
     *   rows:list<array<string,mixed>>,
     *   summary:array{total:int,scored:int,recommend:int,errors:int}
     * }
     */
    public function scan(
        int $limit = 100,
        bool $useCache = true,
        bool $useYahooCache = true,
        ?callable $onProgress = null,
        /** Yahoo 일봉 캐시 허용 나이(초). 스캔 재계산 시 기본 10분. 새로고침은 0. */
        ?int $yahooMaxAgeSeconds = 600,
        /** all=코스피+코스닥, kospi=코스피만 */
        string $market = 'all',
    ): array {
        $limit = max(1, min(200, $limit));
        $market = strtolower($market);
        if (!in_array($market, ['all', 'kospi'], true)) {
            throw new \InvalidArgumentException('지원하지 않는 스캔 시장: ' . $market);
        }
        $profileId = $this->service->profile()->id;
        // v17: 다음 금융 거래대금 순위
        $cacheFile = sprintf(
            '%s/kr_amount_scan_v17_%s_%s_%d.json',
            $this->cacheDir,
            $market,
            $profileId,
            $limit
        );

        if ($useCache && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $this->cacheTtlSeconds) {
            /** @var array<string,mixed> $cached */
            $cached = json_decode((string) file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
            return $cached;
        }

        try {
            $leaders = $this->leaders->topByAmount($limit, useCache: $useCache, market: $market);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'profile' => $profileId,
                'market' => $market,
                'limit' => $limit,
                'fetched_at' => $this->nowKst(),
                'source' => $market === 'kospi'
                    ? 'daum_acc_trade_price_kospi'
                    : 'daum_acc_trade_price_merge',
                'error' => $e->getMessage(),
                'rows' => [],
                'summary' => ['total' => 0, 'scored' => 0, 'recommend' => 0, 'errors' => 0],
            ];
        }

        $rows = [];
        $scored = 0;
        $recommend = 0;
        $smellCount = 0;
        $spikeDumpCount = 0;
        $errors = 0;
        $total = count($leaders);
        $yahooAge = $useYahooCache ? ($yahooMaxAgeSeconds ?? 600) : 0;

        foreach ($leaders as $i => $leader) {
            $yahoo = (string) $leader['yahoo'];
            if ($onProgress !== null) {
                $onProgress($i + 1, $total, $yahoo, (string) $leader['name']);
            }

            $result = $this->service->propose(
                $yahoo,
                useCache: $useYahooCache,
                cacheMaxAgeSeconds: $yahooAge,
            );
            $sector = $this->sectors->resolve($yahoo, useCache: $useCache);
            $row = [
                'amount_rank' => $leader['rank'],
                'code' => $leader['code'],
                'name' => $leader['name'],
                'market' => $leader['market'],
                'yahoo' => $yahoo,
                'amount_million' => $leader['amount_million'],
                'amount_won' => $leader['amount_won'],
                'naver_price' => $leader['price'],
                'change_pct' => $leader['change_pct'] ?? null,
                'sector' => $sector['sector'],
                'sector_bucket' => $sector['sector_bucket'],
                'sector_label' => $sector['sector_label'],
                'ok' => $result['ok'],
                'error' => $result['error'] ?? null,
                'score' => null,
                'action' => null,
                'action_label' => null,
                'entry_recommend' => false,
                'buy_now' => false,
                'entry_status' => 'unavailable',
                'lesson1_hit' => false,
                'lesson1_note' => null,
                'top_pattern_status' => 'none',
                'top_pattern_note' => null,
                'inverse_bottom_status' => 'none',
                'inverse_bottom_phase' => 'none',
                'theme_smell_status' => 'none',
                'theme_smell_label' => '',
                'theme_smell_note' => null,
                'spike_dump_status' => 'none',
                'spike_dump_label' => '',
                'spike_dump_note' => null,
                'new_entry_sentence' => null,
                'price' => null,
                'reason' => null,
                'tradingview_url' => $result['tradingview_url'] ?? null,
            ];

            if (!$result['ok']) {
                // Yahoo 실패해도 네이버 현재가는 표에 남긴다
                $row['price'] = $leader['price'];
                $errors++;
                $rows[] = $row;
                continue;
            }

            $proposal = is_array($result['proposal'] ?? null) ? $result['proposal'] : [];
            $features = is_array($result['features'] ?? null) ? $result['features'] : [];
            $explain = is_array($proposal['explain'] ?? null) ? $proposal['explain'] : [];
            $newEntry = is_array($proposal['new_entry'] ?? null) ? $proposal['new_entry'] : [];
            $action = (string) ($proposal['action'] ?? '');
            // 목록 정렬·표시는 차트 구조 점수(작성자 관점으로 덮이기 전)를 우선
            $score = null;
            if (isset($proposal['chart_score']) && is_numeric($proposal['chart_score'])) {
                $score = (int) $proposal['chart_score'];
            } elseif (isset($features['pullback_long_score']) && is_numeric($features['pullback_long_score'])) {
                $score = (int) $features['pullback_long_score'];
            } elseif (isset($proposal['score']) && is_numeric($proposal['score'])) {
                $score = (int) $proposal['score'];
            }
            $entryRecommend = in_array($action, self::ENTRY_ACTIONS, true)
                && !empty($newEntry['available']);

            $lesson1Hit = !empty($proposal['lesson1_candle_recipe'])
                || !empty($proposal['lesson1_upper_box'])
                || !empty($proposal['lesson1_breakout_no_box'])
                || !empty($features['lesson1_candle_recipe'])
                || !empty($features['lesson1_upper_box'])
                || !empty($features['lesson1_breakout_no_box']);
            $lesson1Note = (string) ($proposal['lesson1_note'] ?? $features['lesson1_note'] ?? '');
            if (!$lesson1Hit && $lesson1Note !== '' && !str_contains($lesson1Note, '해당 패턴 없음')) {
                $lesson1Hit = true;
            }

            $row['ok'] = true;
            $row['score'] = $score;
            $row['action'] = $action;
            $row['action_label'] = (string) ($explain['action_label'] ?? $action);
            $row['entry_recommend'] = $entryRecommend;
            $row['buy_now'] = !empty($newEntry['buy_now']);
            $row['entry_status'] = (string) ($newEntry['status'] ?? 'unavailable');
            $row['lesson1_hit'] = $lesson1Hit;
            $row['lesson1_note'] = $lesson1Note !== '' ? $lesson1Note : null;
            $row['top_pattern_status'] = (string) ($proposal['top_pattern_status'] ?? $features['top_pattern_status'] ?? 'none');
            $row['top_pattern_note'] = $proposal['top_pattern_note'] ?? $features['top_pattern_note'] ?? null;
            $row['inverse_bottom_status'] = (string) ($proposal['inverse_bottom_status'] ?? $features['inverse_bottom_status'] ?? 'none');
            $row['inverse_bottom_phase'] = (string) ($proposal['inverse_bottom_phase'] ?? $features['inverse_bottom_phase'] ?? 'none');
            $smellStatus = (string) ($proposal['theme_smell_status'] ?? $features['theme_smell_status'] ?? 'none');
            if ($this->isEtfName((string) ($leader['name'] ?? ''))) {
                $smellStatus = 'none';
            }
            $row['theme_smell_status'] = $smellStatus;
            $row['theme_smell_label'] = $smellStatus === 'none'
                ? ''
                : (string) ($proposal['theme_smell_label'] ?? $features['theme_smell_label'] ?? '');
            $row['theme_smell_note'] = $smellStatus === 'none'
                ? null
                : ($proposal['theme_smell_note'] ?? $features['theme_smell_note'] ?? null);
            $spikeDumpStatus = (string) ($proposal['spike_dump_status'] ?? $features['spike_dump_status'] ?? 'none');
            $row['spike_dump_status'] = $spikeDumpStatus;
            $row['spike_dump_label'] = $spikeDumpStatus === 'none'
                ? ''
                : (string) ($proposal['spike_dump_label'] ?? $features['spike_dump_label'] ?? '급등후급락');
            $row['spike_dump_note'] = $spikeDumpStatus === 'none'
                ? null
                : ($proposal['spike_dump_note'] ?? $features['spike_dump_note'] ?? null);
            $row['new_entry_sentence'] = isset($newEntry['sentence']) ? (string) $newEntry['sentence'] : null;
            $row['price'] = $proposal['price'] ?? $leader['price'];
            $row['reason'] = isset($proposal['reason']) ? (string) $proposal['reason'] : null;
            $scored++;
            if ($entryRecommend) {
                $recommend++;
            }
            if ($smellStatus !== 'none') {
                $smellCount++;
            }
            if ($spikeDumpStatus !== 'none') {
                $spikeDumpCount++;
            }
            $rows[] = $row;
        }

        // 기본은 구조 점수 순. «지금 진입순»은 화면에서 다시 정렬한다.
        usort($rows, function (array $a, array $b): int {
            $sa = is_int($a['score'] ?? null) ? $a['score'] : -1;
            $sb = is_int($b['score'] ?? null) ? $b['score'] : -1;
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            if (($a['lesson1_hit'] ?? false) !== ($b['lesson1_hit'] ?? false)) {
                return ($b['lesson1_hit'] ?? false) <=> ($a['lesson1_hit'] ?? false);
            }
            $smellA = $this->smellRank((string) ($a['theme_smell_status'] ?? 'none'));
            $smellB = $this->smellRank((string) ($b['theme_smell_status'] ?? 'none'));
            if ($smellA !== $smellB) {
                return $smellB <=> $smellA;
            }
            if (($a['buy_now'] ?? false) !== ($b['buy_now'] ?? false)) {
                return ($b['buy_now'] ?? false) <=> ($a['buy_now'] ?? false);
            }
            if (($a['entry_recommend'] ?? false) !== ($b['entry_recommend'] ?? false)) {
                return ($b['entry_recommend'] ?? false) <=> ($a['entry_recommend'] ?? false);
            }

            return ($a['amount_rank'] ?? 999) <=> ($b['amount_rank'] ?? 999);
        });

        $flow = $this->attachSmellContext($this->moneyFlow($leaders, $useCache), $rows);
        $rows = $this->tagLaggingThemeRows($rows, $flow);
        $laggingCount = 0;
        foreach ($rows as $tagged) {
            if (!empty($tagged['lagging_theme'])) {
                $laggingCount++;
            }
        }

        $payload = [
            'ok' => true,
            'profile' => $profileId,
            'market' => $market,
            'limit' => $limit,
            'fetched_at' => $this->nowKst(),
            'source' => $market === 'kospi'
                ? 'daum_acc_trade_price_kospi'
                : 'daum_acc_trade_price_merge',
            'error' => null,
            'rows' => $rows,
            'money_flow' => $flow,
            'summary' => [
                'total' => $total,
                'scored' => $scored,
                'recommend' => $recommend,
                'smell' => $smellCount,
                'lagging' => $laggingCount,
                'spike_dump' => $spikeDumpCount,
                'errors' => $errors,
            ],
        ];

        file_put_contents(
            $cacheFile,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $payload;
    }

    /**
     * @param list<array<string,mixed>> $leaders
     * @return array<string,mixed>
     */
    private function moneyFlow(array $leaders, bool $useCache): array
    {
        try {
            $themes = (new NaverThemeClient($this->cacheDir))->topByChange(6, $useCache);
            return (new MoneyFlow())->build($themes, $leaders);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'sentence' => '',
                'themes' => [],
                'amount_spikes' => [],
                'smell_themes' => [],
                'smell_clusters' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 같은 업종에 냄새 종목이 2개 이상이면 테마 구경 후보로 묶는다.
     *
     * @param array<string,mixed> $flow
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function attachSmellContext(array $flow, array $rows): array
    {
        $byBucket = [];
        foreach ($rows as $row) {
            if (($row['theme_smell_status'] ?? 'none') === 'none') {
                continue;
            }
            $bucket = (string) ($row['sector_bucket'] ?? 'other');
            if (!isset($byBucket[$bucket])) {
                $byBucket[$bucket] = [
                    'sector_bucket' => $bucket,
                    'sector_label' => (string) ($row['sector_label'] ?? SectorMap::BUCKETS[$bucket] ?? '기타'),
                    'names' => [],
                    'statuses' => [],
                ];
            }
            $byBucket[$bucket]['names'][] = (string) ($row['name'] ?? $row['code'] ?? '');
            $byBucket[$bucket]['statuses'][] = (string) $row['theme_smell_status'];
        }

        $clusters = [];
        foreach ($byBucket as $cluster) {
            if (count($cluster['names']) < 2) {
                continue;
            }
            $clusters[] = [
                'sector_bucket' => $cluster['sector_bucket'],
                'sector_label' => $cluster['sector_label'],
                'count' => count($cluster['names']),
                'names' => array_slice($cluster['names'], 0, 4),
                'note' => $cluster['sector_label'] . ' 쪽 대금이 한꺼번에 터짐. 구경만, 추격 금지.',
            ];
        }

        $flow['smell_clusters'] = $clusters;
        $flow['smell_themes'] = is_array($flow['smell_themes'] ?? null) ? $flow['smell_themes'] : [];

        return $flow;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $flow
     * @return list<array<string,mixed>>
     */
    private function tagLaggingThemeRows(array $rows, array $flow): array
    {
        $codes = [];
        foreach (is_array($flow['smell_themes'] ?? null) ? $flow['smell_themes'] : [] as $theme) {
            $themeName = (string) ($theme['name'] ?? '');
            foreach (is_array($theme['in_amount_top'] ?? null) ? $theme['in_amount_top'] : [] as $hit) {
                $code = preg_replace('/\D+/', '', (string) ($hit['code'] ?? '')) ?? '';
                if (strlen($code) === 6) {
                    $codes[$code] = $themeName;
                }
            }
        }

        foreach ($rows as &$row) {
            $code = preg_replace('/\D+/', '', (string) ($row['code'] ?? $row['yahoo'] ?? '')) ?? '';
            $row['lagging_theme'] = $code !== '' && isset($codes[$code]);
            $row['lagging_theme_name'] = $codes[$code] ?? null;
        }
        unset($row);

        return $rows;
    }

    private function smellRank(string $status): int
    {
        return match ($status) {
            'ignite' => 3,
            'poke_fail' => 2,
            'poke' => 1,
            default => 0,
        };
    }

    private function isEtfName(string $name): bool
    {
        $u = strtoupper($name);
        foreach (['KODEX', 'TIGER', 'KOSEF', 'ACE', 'SOL ', 'PLUS', 'RISE', 'HANARO', 'ARIRANG', 'KBSTAR', 'TIMEFOLIO', 'WON ', 'KIWOOM'] as $p) {
            if (str_starts_with($u, $p)) {
                return true;
            }
        }

        return preg_match('/인버스|레버리지|커버드콜|선물|ETF/u', $name) === 1;
    }

    private function nowKst(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
    }
}
