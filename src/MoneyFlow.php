<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 오늘 돈이 어디로 몰렸는지. 점수와 무관.
 * - 네이버 테마 전일대비 (원전·식품·보험 등 테마 단위)
 * - 거래대금 TOP 안에서 ETF를 뺀 급등 종목
 * - 아직 안 올랐는데 대금 TOP에 선도주가 보이는 테마(냄새)
 */
final class MoneyFlow
{
    /** @var list<string> */
    private const ETF_PREFIX = [
        'KODEX', 'TIGER', 'KOSEF', 'ACE ', 'ACE', 'SOL ', 'PLUS', 'RISE',
        'HANARO', 'ARIRANG', 'KBSTAR', 'TIMEFOLIO', 'WON ', '1Q ', 'KIWOOM',
        'SMART', 'TREX', 'KTOP',
    ];

    /**
     * @param list<array<string,mixed>> $themes
     * @param list<array<string,mixed>> $leaders
     * @return array{
     *   ok:bool,
     *   sentence:string,
     *   themes:list<array<string,mixed>>,
     *   amount_spikes:list<array<string,mixed>>,
     *   smell_themes:list<array<string,mixed>>,
     *   error:?string
     * }
     */
    public function build(array $themes, array $leaders, int $themeLimit = 8, float $minThemePct = 1.2): array
    {
        $byCode = [];
        foreach ($leaders as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code !== '') {
                $byCode[$code] = $row;
            }
        }

        $hot = [];
        foreach ($themes as $theme) {
            $chg = (float) ($theme['change_pct'] ?? 0);
            if ($chg < $minThemePct) {
                continue;
            }
            $leaderNames = [];
            $inAmount = [];
            foreach (is_array($theme['leaders'] ?? null) ? $theme['leaders'] : [] as $ld) {
                $name = rtrim((string) ($ld['name'] ?? ''), '.');
                if ($name !== '') {
                    $leaderNames[] = $name;
                }
                $code = (string) ($ld['code'] ?? '');
                if ($code !== '' && isset($byCode[$code])) {
                    $inAmount[] = [
                        'name' => (string) ($byCode[$code]['name'] ?? $name),
                        'rank' => $byCode[$code]['rank'] ?? $byCode[$code]['amount_rank'] ?? null,
                    ];
                }
            }
            $hot[] = [
                'no' => $theme['no'] ?? null,
                'name' => (string) ($theme['name'] ?? ''),
                'change_pct' => $chg,
                'up' => (int) ($theme['up'] ?? 0),
                'leaders' => $leaderNames,
                'in_amount_top' => $inAmount,
            ];
            if (count($hot) >= $themeLimit) {
                break;
            }
        }

        $spikes = [];
        foreach ($leaders as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '' || $this->isEtf($name)) {
                continue;
            }
            $chg = $row['change_pct'] ?? null;
            if (!is_numeric($chg) || (float) $chg < 5.0) {
                continue;
            }
            $spikes[] = [
                'code' => (string) ($row['code'] ?? ''),
                'name' => $name,
                'yahoo' => (string) ($row['yahoo'] ?? ''),
                'change_pct' => (float) $chg,
                'amount_rank' => $row['rank'] ?? $row['amount_rank'] ?? null,
            ];
        }
        usort($spikes, static fn(array $a, array $b): int => $b['change_pct'] <=> $a['change_pct']);
        $spikes = array_slice($spikes, 0, 8);

        $smellThemes = [];
        foreach ($themes as $theme) {
            $chg = (float) ($theme['change_pct'] ?? 0);
            if ($chg >= $minThemePct || $chg < -8.0) {
                continue;
            }
            $leaderNames = [];
            $inAmount = [];
            foreach (is_array($theme['leaders'] ?? null) ? $theme['leaders'] : [] as $ld) {
                $name = rtrim((string) ($ld['name'] ?? ''), '.');
                if ($name !== '') {
                    $leaderNames[] = $name;
                }
                $code = (string) ($ld['code'] ?? '');
                if ($code !== '' && isset($byCode[$code])) {
                    $inAmount[] = [
                        'code' => $code,
                        'name' => (string) ($byCode[$code]['name'] ?? $name),
                        'rank' => $byCode[$code]['rank'] ?? $byCode[$code]['amount_rank'] ?? null,
                    ];
                }
            }
            if ($inAmount === []) {
                continue;
            }
            $smellThemes[] = [
                'no' => $theme['no'] ?? null,
                'name' => (string) ($theme['name'] ?? ''),
                'change_pct' => $chg,
                'up' => (int) ($theme['up'] ?? 0),
                'down' => (int) ($theme['down'] ?? 0),
                'leaders' => $leaderNames,
                'in_amount_top' => $inAmount,
            ];
        }
        usort($smellThemes, static function (array $a, array $b): int {
            $na = count($a['in_amount_top'] ?? []);
            $nb = count($b['in_amount_top'] ?? []);
            if ($na !== $nb) {
                return $nb <=> $na;
            }

            return abs((float) ($a['change_pct'] ?? 0)) <=> abs((float) ($b['change_pct'] ?? 0));
        });
        $smellThemes = array_slice($smellThemes, 0, 8);

        $bits = [];
        foreach ($hot as $t) {
            $lead = $t['leaders'] !== [] ? implode('·', array_slice($t['leaders'], 0, 2)) : '';
            $bits[] = $lead !== ''
                ? sprintf('%s %+.1f%% (%s)', $t['name'], $t['change_pct'], $lead)
                : sprintf('%s %+.1f%%', $t['name'], $t['change_pct']);
        }

        return [
            'ok' => $hot !== [] || $spikes !== [] || $smellThemes !== [],
            'sentence' => $bits !== [] ? implode(' · ', $bits) : '',
            'themes' => $hot,
            'amount_spikes' => $spikes,
            'smell_themes' => $smellThemes,
            'error' => null,
        ];
    }

    private function isEtf(string $name): bool
    {
        $u = strtoupper($name);
        foreach (self::ETF_PREFIX as $p) {
            if (str_starts_with($u, strtoupper($p))) {
                return true;
            }
        }

        return preg_match('/인버스|레버리지|커버드콜|선물|ETF/u', $name) === 1;
    }
}
