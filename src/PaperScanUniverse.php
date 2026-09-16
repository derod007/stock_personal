<?php

declare(strict_types=1);

namespace ChartEntryLab;

final class PaperScanUniverse
{
    /**
     * Daily scan names must not pin the account. Limits and universe stay hashed.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function pinned(array $config): array
    {
        if (($config['universe'] ?? '') === 'kr_amount_scan') {
            $config['symbols'] = [];
        }

        return $config;
    }

    /**
     * @param array<string,mixed> $report
     * @param list<string> $held
     * @param array<string,string> $heldSectors
     * @return array<string,string>
     */
    public static function symbols(array $report, array $held = [], array $heldSectors = []): array
    {
        $out = [];
        foreach ($held as $symbol) {
            if (!is_string($symbol) || $symbol === '') {
                continue;
            }
            $sector = $heldSectors[$symbol] ?? 'held';
            $out[$symbol] = $sector === '' ? 'held' : $sector;
        }
        foreach (is_array($report['rows'] ?? null) ? $report['rows'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (empty($row['entry_recommend']) && empty($row['buy_now'])) {
                continue;
            }
            $yahoo = (string) ($row['yahoo'] ?? '');
            if ($yahoo === '' || !preg_match('/^[A-Z0-9][A-Z0-9.=-]{0,24}$/', $yahoo)) {
                continue;
            }
            $sector = (string) ($row['sector_bucket'] ?? $row['sector'] ?? 'unclassified');
            $out[$yahoo] = $sector === '' ? 'unclassified' : $sector;
        }
        ksort($out);

        return $out;
    }
}
