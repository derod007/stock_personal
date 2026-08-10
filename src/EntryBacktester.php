<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 진입(글 시각) 이후 N일 성과 라벨.
 * - 미래 누수 없이 as-of 피처는 posted_at 이전 봉만 사용
 * - 포워드 구간은 그 이후 봉으로만 평가
 */
final class EntryBacktester
{
    /** @var list<int> */
    public const DEFAULT_HORIZONS = [5, 10, 20];

    public function __construct(
        private readonly FeatureEngine $engine = new FeatureEngine(),
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}> $bars
     * @param list<int> $horizons
     * @return array<string, mixed>|null
     */
    public function evaluate(array $entry, array $bars, string $yahooSymbol, array $horizons = self::DEFAULT_HORIZONS): ?array
    {
        if (($entry['learning_use'] ?? '') !== 'full' || !empty($entry['exclude_price_label'])) {
            return null;
        }

        $tags = array_map('strval', $entry['tags'] ?? []);
        if (
            in_array('engine_ge70_snapshot', $tags, true)
            || in_array('not_noramu_post', $tags, true)
            || ($entry['source'] ?? '') === 'engine_ge70_snapshot'
            || ($entry['author'] ?? '') === 'engine_snapshot'
        ) {
            return null;
        }

        $postedRaw = $entry['posted_at_kst'] ?? null;
        if ($postedRaw === null) {
            return null;
        }

        $tz = new \DateTimeZone('Asia/Seoul');
        $posted = new \DateTimeImmutable((string) $postedRaw);

        $asOfBars = [];
        $forwardBars = [];
        foreach ($bars as $bar) {
            $t = new \DateTimeImmutable($bar['time_kst'], $tz);
            if ($t <= $posted) {
                $asOfBars[] = $bar;
            } else {
                $forwardBars[] = $bar;
            }
        }

        if (count($asOfBars) < 30) {
            return [
                'entry_id' => $entry['id'] ?? null,
                'symbol' => $yahooSymbol,
                'error' => 'insufficient_asof_bars',
                'bars_asof' => count($asOfBars),
            ];
        }

        $features = $this->engine->extract($asOfBars);
        $entryPrice = is_numeric($entry['entry_price'] ?? null)
            ? (float) $entry['entry_price']
            : (float) $features['price'];
        $stop = is_numeric($entry['stop_price'] ?? null)
            ? (float) $entry['stop_price']
            : (float) ($features['invalidation_level'] ?? 0);
        $target = is_numeric($entry['target_price'] ?? null)
            ? (float) $entry['target_price']
            : (float) ($features['suggested_target_half_to_high'] ?? 0);

        $score = (int) ($features['pullback_long_score'] ?? 0);
        $band = $this->scoreBand($score);

        $horizonsOut = [];
        foreach ($horizons as $n) {
            $slice = array_slice($forwardBars, 0, $n);
            $horizonsOut[(string) $n] = $this->horizonStats($slice, $entryPrice, $stop, $target);
        }

        return [
            'entry_id' => $entry['id'] ?? null,
            'symbol' => $yahooSymbol,
            'posted_at_kst' => $entry['posted_at_kst'] ?? null,
            'learning_use' => $entry['learning_use'] ?? null,
            'exit_reason_label' => $entry['exit_reason'] ?? null,
            'score' => $score,
            'score_band' => $band,
            'features_asof' => [
                'price' => $features['price'] ?? null,
                'half_retrace' => $features['half_retrace'] ?? null,
                'dist_half_pct' => $features['dist_half_pct'] ?? null,
                'swing_method' => $features['swing_method'] ?? null,
                'swing_high' => $features['swing_high'] ?? null,
                'swing_low' => $features['swing_low'] ?? null,
                'invalidation_level' => $features['invalidation_level'] ?? null,
                'flush_bar_recent' => $features['flush_bar_recent'] ?? null,
                'higher_low' => $features['higher_low'] ?? null,
            ],
            'levels' => [
                'entry_price' => $entryPrice,
                'stop' => $stop,
                'target' => $target,
            ],
            'horizons' => $horizonsOut,
        ];
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return array<string, mixed>
     */
    public function summarize(array $results): array
    {
        $usable = array_values(array_filter(
            $results,
            static fn(array $r): bool => !isset($r['error']) && isset($r['horizons'])
        ));

        $bands = ['ge70' => [], '55_69' => [], 'lt35' => [], 'mid' => []];
        foreach ($usable as $r) {
            $bands[$r['score_band']][] = $r;
        }

        $summary = [
            'n_evaluated' => count($usable),
            'n_errors' => count($results) - count($usable),
            'by_band' => [],
        ];

        foreach ($bands as $band => $rows) {
            if ($rows === []) {
                $summary['by_band'][$band] = ['n' => 0];
                continue;
            }
            $summary['by_band'][$band] = [
                'n' => count($rows),
                'avg_score' => round(array_sum(array_column($rows, 'score')) / count($rows), 1),
                'h5' => $this->aggHorizon($rows, '5'),
                'h10' => $this->aggHorizon($rows, '10'),
                'h20' => $this->aggHorizon($rows, '20'),
            ];
        }

        return $summary;
    }

    /**
     * @param list<array{high:float,low:float,close:float}> $bars
     * @return array<string, mixed>
     */
    private function horizonStats(array $bars, float $entry, float $stop, float $target): array
    {
        if ($bars === []) {
            return [
                'bars' => 0,
                'ret_close_pct' => null,
                'mfe_pct' => null,
                'mae_pct' => null,
                'hit_stop' => null,
                'hit_target' => null,
                'first_exit' => null,
            ];
        }

        $mfe = null;
        $mae = null;
        $hitStop = false;
        $hitTarget = false;
        $firstExit = null;

        foreach ($bars as $bar) {
            $high = (float) $bar['high'];
            $low = (float) $bar['low'];
            $up = $entry > 0 ? (($high - $entry) / $entry) * 100 : null;
            $down = $entry > 0 ? (($low - $entry) / $entry) * 100 : null;
            if ($up !== null) {
                $mfe = $mfe === null ? $up : max($mfe, $up);
            }
            if ($down !== null) {
                $mae = $mae === null ? $down : min($mae, $down);
            }

            $stopHitNow = $stop > 0 && $low <= $stop;
            $targetHitNow = $target > 0 && $high >= $target;
            if ($firstExit === null) {
                if ($stopHitNow && $targetHitNow) {
                    // 같은 봉이면 보수적으로 stop 우선
                    $firstExit = 'stop';
                    $hitStop = true;
                } elseif ($stopHitNow) {
                    $firstExit = 'stop';
                    $hitStop = true;
                } elseif ($targetHitNow) {
                    $firstExit = 'target';
                    $hitTarget = true;
                }
            } else {
                if ($stopHitNow) {
                    $hitStop = true;
                }
                if ($targetHitNow) {
                    $hitTarget = true;
                }
            }
        }

        $lastClose = (float) $bars[array_key_last($bars)]['close'];
        $ret = $entry > 0 ? (($lastClose - $entry) / $entry) * 100 : null;

        return [
            'bars' => count($bars),
            'ret_close_pct' => $ret !== null ? round($ret, 3) : null,
            'mfe_pct' => $mfe !== null ? round($mfe, 3) : null,
            'mae_pct' => $mae !== null ? round($mae, 3) : null,
            'hit_stop' => $hitStop,
            'hit_target' => $hitTarget,
            'first_exit' => $firstExit,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function aggHorizon(array $rows, string $key): array
    {
        $rets = [];
        $stops = 0;
        $targets = 0;
        $n = 0;
        foreach ($rows as $r) {
            $h = $r['horizons'][$key] ?? null;
            if (!is_array($h) || ($h['bars'] ?? 0) === 0) {
                continue;
            }
            $n++;
            if (is_numeric($h['ret_close_pct'] ?? null)) {
                $rets[] = (float) $h['ret_close_pct'];
            }
            if (!empty($h['hit_stop'])) {
                $stops++;
            }
            if (!empty($h['hit_target'])) {
                $targets++;
            }
        }
        return [
            'n' => $n,
            'avg_ret_close_pct' => $rets !== [] ? round(array_sum($rets) / count($rets), 3) : null,
            'win_rate_close' => $rets !== []
                ? round(count(array_filter($rets, static fn(float $x): bool => $x > 0)) / count($rets), 3)
                : null,
            'stop_rate' => $n > 0 ? round($stops / $n, 3) : null,
            'target_rate' => $n > 0 ? round($targets / $n, 3) : null,
        ];
    }

    private function scoreBand(int $score): string
    {
        if ($score >= 70) {
            return 'ge70';
        }
        if ($score >= 55) {
            return '55_69';
        }
        if ($score < 35) {
            return 'lt35';
        }
        return 'mid';
    }
}
