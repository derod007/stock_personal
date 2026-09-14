<?php

declare(strict_types=1);

namespace ChartEntryLab;

/** Entry-post dates are evaluation anchors, never assumed author fills. */
final class EntryBacktester
{
    public const DEFAULT_HORIZONS = [5, 10, 20];

    public function __construct(private readonly ChartPlanEngine $engine = new ChartPlanEngine(),
        private readonly TradeSimulator $simulator = new TradeSimulator())
    {
    }

    public function evaluate(array $entry, array $bars, string $yahooSymbol, array $horizons = self::DEFAULT_HORIZONS): ?array
    {
        if (($entry['learning_use'] ?? '') !== 'full' || !empty($entry['exclude_price_label'])
            || (new LearnedLevels())->isEngineSnapshot($entry)) {
            return null;
        }
        if (empty($entry['posted_at_kst'])) {
            return ['entry_id' => $entry['id'] ?? null, 'error' => 'missing_post_time'];
        }
        $posted = (new \DateTimeImmutable((string) $entry['posted_at_kst'], new \DateTimeZone('Asia/Seoul')))->getTimestamp();
        $all = CandleClock::completed($bars, $yahooSymbol, time());
        $result = $this->engine->analyze($all, $yahooSymbol, $posted);
        $plan = $result['plan'];
        $score = (int) $result['features']['pullback_long_score'];
        $out = [];
        // A signal already known before publication cannot fill before this evaluation anchor.
        $plan['signal_at'] = max($posted, (int) ($plan['signal_at'] ?? $posted));
        foreach ($horizons as $n) {
            $out[(string) $n] = $this->simulator->simulate($plan, $all, $n);
        }
        return ['entry_id' => $entry['id'] ?? null, 'symbol' => $yahooSymbol,
            'posted_at_kst' => $entry['posted_at_kst'], 'evaluation_type' => 'strategy_at_post_time_not_author_performance',
            'score' => $score, 'score_band' => $this->scoreBand($score), 'plan' => $plan,
            'signal_key' => $yahooSymbol . '|' . ($result['plan']['signal_at'] ?? $plan['data_asof']),
            'levels' => ['entry_price' => $plan['entry'], 'stop' => $plan['stop'], 'target' => $plan['target']],
            'author_levels_reference_only' => array_intersect_key($entry, array_flip(['entry_price', 'stop_price', 'target_price'])),
            'horizons' => $out];
    }

    public function summarize(array $results): array
    {
        $bands = ['ge70' => [], '55_69' => [], 'lt35' => [], 'mid' => []];
        $seen = [];
        $errors = 0;
        $duplicates = 0;
        foreach ($results as $r) {
            if (isset($r['error'])) {
                $errors++;
                continue;
            }
            $key = $r['signal_key'] ?? $r['entry_id'];
            if (isset($seen[$key])) {
                $duplicates++;
                continue;
            }
            $seen[$key] = true;
            $bands[$r['score_band']][] = $r;
        }
        $summary = ['n_evaluated' => count($seen), 'n_errors' => $errors, 'n_duplicates_excluded' => $duplicates,
            'return_definition' => 'net realized return at first exit; incomplete and unfilled trades excluded', 'by_band' => []];
        foreach ($bands as $band => $rows) {
            $row = ['n' => count($rows)];
            if ($rows !== []) {
                $row['avg_score'] = round(array_sum(array_column($rows, 'score')) / count($rows), 1);
                foreach (self::DEFAULT_HORIZONS as $n) {
                    $row['h' . $n] = $this->aggregate(array_column(array_column($rows, 'horizons'), (string) $n));
                }
            }
            $summary['by_band'][$band] = $row;
        }
        return $summary;
    }

    public function aggregate(array $trades): array
    {
        $counts = [];
        $rets = [];
        $stops = $targets = 0;
        foreach ($trades as $t) {
            $counts[$t['status']] = ($counts[$t['status']] ?? 0) + 1;
            if ($t['status'] !== 'closed' || !$t['complete']) {
                continue;
            }
            $rets[] = $t['net_return_pct'];
            $stops += $t['first_exit'] === 'stop' ? 1 : 0;
            $targets += $t['first_exit'] === 'target' ? 1 : 0;
        }
        $n = count($rets);
        $avg = $n ? round(array_sum($rets) / $n, 4) : null;
        $win = $n ? round(count(array_filter($rets, static fn($r): bool => $r > 0)) / $n, 4) : null;
        return ['n' => $n, 'status_counts' => $counts, 'avg_net_return_pct' => $avg, 'win_rate' => $win,
            // Compatibility aliases for existing report consumers; semantics explicitly documented.
            'avg_ret_close_pct' => $avg, 'win_rate_close' => $win,
            'stop_rate' => $n ? $stops / $n : null, 'target_rate' => $n ? $targets / $n : null];
    }

    private function scoreBand(int $score): string
    {
        return $score >= 70 ? 'ge70' : ($score >= 55 ? '55_69' : ($score < 35 ? 'lt35' : 'mid'));
    }
}
