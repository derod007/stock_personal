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

    public function __construct(
        private readonly KrAmountLeadersClient $leaders,
        private readonly ProposalService $service,
        private readonly string $cacheDir,
        /** 스캔 결과(점수·신규진입 문장) 캐시. 현재가가 빨리 식어서 짧게 둔다. */
        private readonly int $cacheTtlSeconds = 300,
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    /**
     * @return array{
     *   ok:bool,
     *   profile:string,
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
    ): array {
        $limit = max(1, min(200, $limit));
        $profileId = $this->service->profile()->id;
        // v2: 현재가·신규진입 문장이 시세와 같이 갱신되도록 캐시 키 분리
        $cacheFile = sprintf(
            '%s/kr_amount_scan_v2_%s_%d.json',
            $this->cacheDir,
            $profileId,
            $limit
        );

        if ($useCache && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $this->cacheTtlSeconds) {
            /** @var array<string,mixed> $cached */
            $cached = json_decode((string) file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
            return $cached;
        }

        try {
            $leaders = $this->leaders->topByAmount($limit, useCache: $useCache);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'profile' => $profileId,
                'limit' => $limit,
                'fetched_at' => $this->nowKst(),
                'source' => 'naver_sise_quant_amount_merge',
                'error' => $e->getMessage(),
                'rows' => [],
                'summary' => ['total' => 0, 'scored' => 0, 'recommend' => 0, 'errors' => 0],
            ];
        }

        $rows = [];
        $scored = 0;
        $recommend = 0;
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
            $row = [
                'amount_rank' => $leader['rank'],
                'code' => $leader['code'],
                'name' => $leader['name'],
                'market' => $leader['market'],
                'yahoo' => $yahoo,
                'amount_million' => $leader['amount_million'],
                'amount_won' => $leader['amount_won'],
                'naver_price' => $leader['price'],
                'ok' => $result['ok'],
                'error' => $result['error'] ?? null,
                'score' => null,
                'action' => null,
                'action_label' => null,
                'entry_recommend' => false,
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
            $explain = is_array($proposal['explain'] ?? null) ? $proposal['explain'] : [];
            $newEntry = is_array($proposal['new_entry'] ?? null) ? $proposal['new_entry'] : [];
            $action = (string) ($proposal['action'] ?? '');
            $score = isset($proposal['score']) && is_numeric($proposal['score'])
                ? (int) $proposal['score']
                : null;
            $entryRecommend = in_array($action, self::ENTRY_ACTIONS, true)
                && !empty($newEntry['available']);

            $row['ok'] = true;
            $row['score'] = $score;
            $row['action'] = $action;
            $row['action_label'] = (string) ($explain['action_label'] ?? $action);
            $row['entry_recommend'] = $entryRecommend;
            $row['new_entry_sentence'] = isset($newEntry['sentence']) ? (string) $newEntry['sentence'] : null;
            $row['price'] = $proposal['price'] ?? $leader['price'];
            $row['reason'] = isset($proposal['reason']) ? (string) $proposal['reason'] : null;
            $scored++;
            if ($entryRecommend) {
                $recommend++;
            }
            $rows[] = $row;
        }

        // 진입 추천·점수 높은 순으로 보기 좋게 (거래대금 순위는 amount_rank에 유지)
        usort($rows, static function (array $a, array $b): int {
            if (($a['entry_recommend'] ?? false) !== ($b['entry_recommend'] ?? false)) {
                return ($b['entry_recommend'] ?? false) <=> ($a['entry_recommend'] ?? false);
            }
            $sa = is_int($a['score'] ?? null) ? $a['score'] : -1;
            $sb = is_int($b['score'] ?? null) ? $b['score'] : -1;
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            return ($a['amount_rank'] ?? 999) <=> ($b['amount_rank'] ?? 999);
        });

        $payload = [
            'ok' => true,
            'profile' => $profileId,
            'limit' => $limit,
            'fetched_at' => $this->nowKst(),
            'source' => 'naver_sise_quant_amount_merge',
            'error' => null,
            'rows' => $rows,
            'summary' => [
                'total' => $total,
                'scored' => $scored,
                'recommend' => $recommend,
                'errors' => $errors,
            ],
        ];

        file_put_contents(
            $cacheFile,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $payload;
    }

    private function nowKst(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Seoul')))->format('Y-m-d H:i:s');
    }
}
