<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * full 라벨(노라무·수동)에서 명시 진입/손절/목표가를 모아 규칙 제안과 병기.
 * 엔진 ge70 스냅샷은 제외.
 */
final class LearnedLevels
{
    /**
     * @param list<array<string, mixed>> $entries
     * @return array{
     *   sample_count:int,
     *   with_target:int,
     *   with_stop:int,
     *   with_entry:int,
     *   target_price:?float,
     *   stop_price:?float,
     *   entry_price:?float,
     *   method:string,
     *   source_ids:list<string>
     * }|null
     */
    public function forSymbol(array $entries, string $symbol, int $limit = 12): ?array
    {
        $symbol = SymbolMap::resolveInput($symbol) ?? $symbol;
        $candidates = [];
        foreach ($entries as $e) {
            if (($e['learning_use'] ?? '') !== 'full') {
                continue;
            }
            if ($this->isEngineSnapshot($e)) {
                continue;
            }
            $esym = (string) ($e['related_underlying'] ?? $e['symbol'] ?? '');
            $resolved = SymbolMap::resolveInput($esym) ?? $esym;
            if ($resolved !== $symbol && $esym !== $symbol) {
                continue;
            }
            $candidates[] = $e;
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $a, array $b): int {
            return strcmp((string) ($b['posted_at_kst'] ?? ''), (string) ($a['posted_at_kst'] ?? ''));
        });
        $candidates = array_slice($candidates, 0, max(1, $limit));

        $targets = [];
        $stops = [];
        $entriesPx = [];
        $ids = [];
        foreach ($candidates as $e) {
            $ids[] = (string) ($e['id'] ?? '');
            if (is_numeric($e['target_price'] ?? null)) {
                $targets[] = (float) $e['target_price'];
            }
            if (is_numeric($e['stop_price'] ?? null)) {
                $stops[] = (float) $e['stop_price'];
            }
            if (is_numeric($e['entry_price'] ?? null)) {
                $entriesPx[] = (float) $e['entry_price'];
            }
        }

        return [
            'sample_count' => count($candidates),
            'with_target' => count($targets),
            'with_stop' => count($stops),
            'with_entry' => count($entriesPx),
            'target_price' => $this->median($targets),
            'stop_price' => $this->median($stops),
            'entry_price' => $this->median($entriesPx),
            'method' => 'median_recent_full_labels',
            'source_ids' => array_values(array_filter($ids, static fn(string $id): bool => $id !== '')),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function isEngineSnapshot(array $entry): bool
    {
        $tags = array_map('strval', $entry['tags'] ?? []);
        if (in_array('engine_ge70_snapshot', $tags, true) || in_array('not_noramu_post', $tags, true)) {
            return true;
        }
        $src = (string) ($entry['source'] ?? '');
        $author = (string) ($entry['author'] ?? '');

        return $src === 'engine_ge70_snapshot' || $author === 'engine_snapshot';
    }

    /**
     * @param list<float> $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return round($values[$mid], 4);
        }

        return round(($values[$mid - 1] + $values[$mid]) / 2, 4);
    }
}
