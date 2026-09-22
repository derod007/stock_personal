<?php
declare(strict_types=1);
require_once __DIR__ . '/RrAudit.php';

/** Read-only presentation of saved RR audit bundles. Does not create orders. */
final class PaperRrView
{
    public static function files(string $dir): array
    {
        $out = [];
        foreach (glob(rtrim($dir, '/\\') . '/*.json') ?: [] as $path) {
            $base = basename($path);
            if (preg_match('/^\d{8}-\d{6}-[a-f0-9]{12}\.json$/', $base) !== 1) {
                continue;
            }
            $out[] = $base;
        }
        rsort($out, SORT_STRING);

        return $out;
    }

    public static function load(string $path): array
    {
        $bundle = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($bundle) || ($bundle['version'] ?? null) !== PaperRrAudit::VERSION || !is_array($bundle['records'] ?? null)) {
            throw new RuntimeException('지원하지 않는 감사 로그');
        }

        return $bundle;
    }

    /** @return list<array<string,mixed>> */
    public static function rows(array $bundle): array
    {
        $out = [];
        foreach ($bundle['records'] ?? [] as $record) {
            if (!is_array($record)) {
                continue;
            }
            $base = [
                'symbol' => (string) ($record['symbol'] ?? ''),
                'name' => (string) ($record['name'] ?? $record['symbol'] ?? ''),
                'rank' => $record['amount_rank'] ?? null,
                'session' => $record['session'] ?? null,
            ];
            if (($record['status'] ?? '') !== 'evaluated') {
                $out[] = $base + [
                    'pattern' => '—',
                    'raw_status' => (string) ($record['reason'] ?? 'unavailable'),
                    'final_status' => '—',
                    'missing' => (string) ($record['detail'] ?? ''),
                    'blockers' => '',
                    'entry' => null,
                    'rr' => null,
                    'limit' => null,
                    'limit_rr' => null,
                    'included' => false,
                    'why' => (string) ($record['detail'] ?? $record['reason'] ?? ''),
                ];
                continue;
            }
            $patterns = is_array($record['patterns'] ?? null) ? $record['patterns'] : [];
            if ($patterns === []) {
                $out[] = $base + self::blank('패턴 없음');
                continue;
            }
            foreach ($patterns as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $limit = is_array($p['hypothetical_limit'] ?? null) ? $p['hypothetical_limit'] : (is_array($p['candidate'] ?? null) ? $p['candidate'] : null);
                $original = is_array($p['original'] ?? null) ? $p['original'] : [];
                $missing = array_values($p['missing_conditions'] ?? []);
                $out[] = $base + [
                    'pattern' => (string) ($p['pattern'] ?? ''),
                    'raw_status' => (string) ($p['raw_status'] ?? ''),
                    'final_status' => (string) ($p['final_status'] ?? ''),
                    'missing' => implode(', ', $missing),
                    'blockers' => implode(', ', $p['exclusion_reason_labels'] ?? []),
                    'entry' => $original['entry'] ?? null,
                    'rr' => $original['reward_risk'] ?? null,
                    'limit' => $limit['entry'] ?? null,
                    'limit_rr' => $limit['reward_risk'] ?? null,
                    'included' => ($p['status'] ?? '') === 'added',
                    'why' => (string) (($p['addition_reason'] ?? '') !== '' ? $p['addition_reason'] : ($p['raw_reason'] ?? '')),
                    'measurements' => $record['measurements'] ?? [],
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string,list<array<string,mixed>>> $prices symbol => raw bars
     * @return array{baseline:array<string,mixed>,limit:array<string,mixed>,rows:list<array<string,mixed>>}
     */
    public static function performance(array $records, array $prices, int $asOf): array
    {
        $rows = [];
        foreach ($records as $record) {
            if (!is_array($record) || ($record['status'] ?? '') !== 'evaluated' || !isset($record['bars'])) {
                continue;
            }
            $symbol = (string) $record['symbol'];
            $raw = $prices[$symbol] ?? $record['bars'];
            $plan = $record['analysis']['plan'] ?? null;
            if (is_array($plan) && !empty($plan['ready'])) {
                $rows[] = self::tradeRow($record, '기존 추천', $plan, $raw, $asOf);
            }
            foreach ($record['patterns'] ?? [] as $p) {
                if (!is_array($p) || ($p['status'] ?? '') !== 'added' || !is_array($p['candidate'] ?? null)) {
                    continue;
                }
                $rows[] = self::tradeRow($record, '지정가 후보', $p['candidate'], $raw, $asOf);
            }
        }

        return [
            'baseline' => self::stats($rows, '기존 추천'),
            'limit' => self::stats($rows, '지정가 후보'),
            'rows' => $rows,
        ];
    }

    private static function tradeRow(array $record, string $kind, array $plan, array $raw, int $asOf): array
    {
        $outcome = PaperRrAudit::outcome($record, $plan, $raw, $asOf);

        return [
            'kind' => $kind,
            'name' => (string) ($record['name'] ?? $record['symbol']),
            'symbol' => (string) $record['symbol'],
            'entry' => $plan['entry'] ?? null,
            'status' => (string) ($outcome['status'] ?? ''),
            'filled' => !empty($outcome['filled']),
            'hit_stop' => !empty($outcome['hit_stop']),
            'hit_target' => !empty($outcome['hit_target']),
            'net_return_pct' => $outcome['net_return_pct'] ?? null,
            'entry_bar_stop_touch' => !empty($outcome['entry_bar_stop_touch']),
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private static function stats(array $rows, string $kind): array
    {
        $mine = array_values(array_filter($rows, static fn(array $r): bool => $r['kind'] === $kind));
        $closed = array_values(array_filter($mine, static fn(array $r): bool => $r['status'] === 'closed' && is_numeric($r['net_return_pct'])));
        $sum = 0.0;
        foreach ($closed as $r) {
            $sum += (float) $r['net_return_pct'];
        }

        return [
            'signals' => count($mine),
            'filled' => count(array_filter($mine, static fn(array $r): bool => $r['filled'])),
            'closed' => count($closed),
            'stops' => count(array_filter($mine, static fn(array $r): bool => $r['hit_stop'])),
            'targets' => count(array_filter($mine, static fn(array $r): bool => $r['hit_target'])),
            'unfilled' => count(array_filter($mine, static fn(array $r): bool => $r['status'] === 'unfilled')),
            'mean_net_pct' => $closed === [] ? null : round($sum / count($closed), 3),
        ];
    }

    /** @return array<string,mixed> */
    private static function blank(string $why): array
    {
        return [
            'pattern' => '—', 'raw_status' => '—', 'final_status' => '—', 'missing' => '',
            'blockers' => '', 'entry' => null, 'rr' => null, 'limit' => null, 'limit_rr' => null,
            'included' => false, 'why' => $why,
        ];
    }
}
