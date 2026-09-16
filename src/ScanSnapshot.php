<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 거래대금 스캔 일별 스냅샷. 다음날 등락을 어제 스캔가와 비교한다.
 */
final class ScanSnapshot
{
    public function __construct(
        private readonly string $dir,
    ) {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    /**
     * @param array<string,mixed> $report
     */
    public function save(array $report, bool $overwrite = true): void
    {
        if (empty($report['ok'])) {
            return;
        }
        $day = $this->kstDay(isset($report['fetched_at']) ? (string) $report['fetched_at'] : null);
        $file = $this->fileFor($day);
        if (!$overwrite && is_file($file)) {
            return;
        }

        $rows = [];
        foreach (is_array($report['rows'] ?? null) ? $report['rows'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = preg_replace('/\D+/', '', (string) ($row['code'] ?? $row['yahoo'] ?? '')) ?? '';
            if (strlen($code) !== 6) {
                continue;
            }
            $selected = self::comparisonPrice($row);
            $price = $selected['price'];
            $rows[] = [
                'code' => $code,
                'yahoo' => (string) ($row['yahoo'] ?? ''),
                'name' => (string) ($row['name'] ?? $code),
                'score' => is_numeric($row['score'] ?? null) ? (int) $row['score'] : null,
                'buy_now' => !empty($row['buy_now']),
                'entry_recommend' => !empty($row['entry_recommend']),
                'entry_status' => (string) ($row['entry_status'] ?? ''),
                'theme_smell_status' => (string) ($row['theme_smell_status'] ?? 'none'),
                'lagging_theme' => !empty($row['lagging_theme']),
                'spike_dump_status' => (string) ($row['spike_dump_status'] ?? 'none'),
                'price' => $price,
                'price_source' => $selected['source'],
                'price_observed_at' => (string) ($report['fetched_at'] ?? ''),
                'change_pct' => is_numeric($row['change_pct'] ?? null) ? (float) $row['change_pct'] : null,
                'amount_rank' => $row['amount_rank'] ?? null,
            ];
        }

        file_put_contents(
            $file,
            json_encode([
                'date' => $day,
                'fetched_at' => (string) ($report['fetched_at'] ?? ''),
                'market' => (string) ($report['market'] ?? ''),
                'profile' => (string) ($report['profile'] ?? ''),
                'limit' => $report['limit'] ?? null,
                'rows' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param list<array<string,mixed>> $todayRows
     * @return ?array{date:string,fetched_at:string,rows:list<array<string,mixed>>}
     */
    public function reviewAgainst(array $todayRows, ?string $todayFetchedAt = null): ?array
    {
        $today = $this->kstDay($todayFetchedAt);
        $prev = $this->loadLatestBefore($today);
        if ($prev === null) {
            return null;
        }

        $byCode = [];
        foreach ($todayRows as $row) {
            $code = preg_replace('/\D+/', '', (string) ($row['code'] ?? $row['yahoo'] ?? '')) ?? '';
            if (strlen($code) === 6) {
                $byCode[$code] = $row;
            }
        }

        $out = [];
        foreach (is_array($prev['rows'] ?? null) ? $prev['rows'] : [] as $old) {
            if (!is_array($old)) {
                continue;
            }
            $code = (string) ($old['code'] ?? '');
            $now = $byCode[$code] ?? null;
            $oldPx = is_numeric($old['price'] ?? null) ? (float) $old['price'] : null;
            $newPx = null;
            if (is_array($now)) {
                $selected = self::comparisonPrice($now);
                $newPx = $selected['price'];
            }
            $since = ($oldPx !== null && $newPx !== null && $oldPx > 0)
                ? (($newPx - $oldPx) / $oldPx) * 100
                : null;
            $out[] = [
                'code' => $code,
                'yahoo' => (string) ($old['yahoo'] ?? ''),
                'name' => (string) ($old['name'] ?? $code),
                'score' => $old['score'] ?? null,
                'buy_now' => !empty($old['buy_now']),
                'entry_recommend' => !empty($old['entry_recommend']),
                'entry_status' => (string) ($old['entry_status'] ?? ''),
                'theme_smell_status' => (string) ($old['theme_smell_status'] ?? 'none'),
                'lagging_theme' => !empty($old['lagging_theme']),
                'spike_dump_status' => (string) ($old['spike_dump_status'] ?? 'none'),
                'yesterday_price' => $oldPx,
                'today_price' => $newPx,
                'yesterday_price_source' => $old['price_source'] ?? 'legacy_unknown',
                'yesterday_price_observed_at' => $old['price_observed_at'] ?? ($prev['fetched_at'] ?? ''),
                'today_price_source' => is_array($now) ? $selected['source'] : 'unavailable',
                'today_price_observed_at' => $todayFetchedAt ?? '',
                'since_scan_pct' => $since !== null ? round($since, 2) : null,
                'still_in_scan' => $now !== null,
            ];
        }

        usort($out, static function (array $a, array $b): int {
            $pa = $a['buy_now'] || $a['entry_recommend'];
            $pb = $b['buy_now'] || $b['entry_recommend'];
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }
            $sa = $a['since_scan_pct'];
            $sb = $b['since_scan_pct'];
            if ($sa === null && $sb === null) {
                return 0;
            }
            if ($sa === null) {
                return 1;
            }
            if ($sb === null) {
                return -1;
            }

            return abs((float) $sb) <=> abs((float) $sa);
        });

        return [
            'date' => (string) ($prev['date'] ?? ''),
            'fetched_at' => (string) ($prev['fetched_at'] ?? ''),
            'rows' => $out,
        ];
    }

    /**
     * @return ?array<string,mixed>
     */
    private function loadLatestBefore(string $today): ?array
    {
        $files = glob($this->dir . '/20*.json') ?: [];
        rsort($files, SORT_STRING);
        foreach ($files as $file) {
            $base = basename($file, '.json');
            if ($base >= $today) {
                continue;
            }
            try {
                /** @var array<string,mixed> $data */
                $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (($data['rows'] ?? []) === []) {
                continue;
            }

            return $data;
        }

        return null;
    }

    /** Scan observation time is not the exchange quote timestamp. */
    private static function comparisonPrice(array $row): array
    {
        foreach (['naver_price' => 'naver_scan', 'price' => 'row_price_fallback'] as $key => $source) {
            $value = $row[$key] ?? null;
            if (is_numeric($value) && is_finite((float) $value) && (float) $value > 0) {
                return ['price' => (float) $value, 'source' => $source];
            }
        }
        return ['price' => null, 'source' => 'unavailable'];
    }

    private function fileFor(string $day): string
    {
        return $this->dir . '/' . $day . '.json';
    }

    private function kstDay(?string $fetchedAt): string
    {
        if ($fetchedAt !== null && preg_match('/^(\d{4}-\d{2}-\d{2})/', $fetchedAt, $m) === 1) {
            return $m[1];
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Seoul')))->format('Y-m-d');
    }
}
