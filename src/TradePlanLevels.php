<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 미래 눌림 진입가와 현재가 기준 손절선이 뒤섞이지 않게 정리한다.
 */
final class TradePlanLevels
{
    /**
     * @param array<string, mixed> $proposal
     * @param array<string, mixed> $features
     * @return array<string, mixed>
     */
    public function alignStops(array $proposal, array $features): array
    {
        $zone = is_array($proposal['entry_zone'] ?? null) ? $proposal['entry_zone'] : null;
        $entryLow = is_array($zone) && is_numeric($zone['low'] ?? null)
            ? (float) $zone['low']
            : null;
        if ($entryLow === null || $entryLow <= 0) {
            return $proposal;
        }

        $tight = is_numeric($proposal['invalidation_tight'] ?? null)
            ? (float) $proposal['invalidation_tight']
            : null;
        $wide = is_numeric($proposal['invalidation_wide'] ?? null)
            ? (float) $proposal['invalidation_wide']
            : null;

        // 두 손절선이 모두 가장 낮은 예정 진입가 아래면 이미 모순이 없다.
        if ($this->belowEntry($tight, $entryLow) && $this->belowEntry($wide, $entryLow)) {
            return $proposal;
        }

        $structural = is_numeric($features['swing_low'] ?? null)
            ? (float) $features['swing_low']
            : null;
        $horizontal = [];
        foreach (is_array($features['levels'] ?? null) ? $features['levels'] : [] as $level) {
            if (!is_array($level) || !is_numeric($level['price'] ?? null)) {
                continue;
            }
            $price = (float) $level['price'];
            if ($this->belowEntry($price, $entryLow)) {
                $horizontal[] = $price;
            }
        }
        if ($this->belowEntry($structural, $entryLow)) {
            $horizontal[] = $structural;
        }
        rsort($horizontal, SORT_NUMERIC);
        $horizontal = $this->dedupe($horizontal);

        $newTight = $horizontal[0] ?? null;
        if ($newTight === null) {
            // 유효한 손절이 없으면 잘못된 숫자를 보여 주지 않는다.
            $proposal['current_invalidation_tight'] = $tight;
            $proposal['current_invalidation_wide'] = $wide;
            $proposal['invalidation_tight'] = null;
            $proposal['invalidation_wide'] = null;
            $proposal['invalidation'] = null;
            $proposal['invalidation_rule'] = 'unavailable_below_entry';
            $proposal['eta'] = $this->replaceStopEta($proposal, null, null);
            return $proposal;
        }

        $newWide = null;
        foreach ($horizontal as $candidate) {
            if ($this->belowEntry($candidate, $newTight)) {
                $newWide = $candidate;
                break;
            }
        }
        $newWide ??= $newTight;

        $proposal['current_invalidation_tight'] = $tight;
        $proposal['current_invalidation_wide'] = $wide;
        $proposal['invalidation_tight'] = round($newTight, 4);
        $proposal['invalidation_wide'] = round($newWide, 4);
        $proposal['invalidation'] = round($newWide, 4);
        $proposal['invalidation_rule'] = 'planned_entry_below_zone';
        $proposal['stop_plan_adjusted'] = true;
        $proposal['eta'] = $this->replaceStopEta($proposal, $newTight, $newWide);

        return $proposal;
    }

    private function belowEntry(?float $price, float $entry): bool
    {
        return $price !== null && $price > 0 && $price < $entry * 0.999;
    }

    /**
     * @param list<float> $prices
     * @return list<float>
     */
    private function dedupe(array $prices): array
    {
        $out = [];
        foreach ($prices as $price) {
            foreach ($out as $existing) {
                if (abs($price - $existing) / max($existing, 0.0001) < 0.003) {
                    continue 2;
                }
            }
            $out[] = $price;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function replaceStopEta(array $proposal, ?float $tight, ?float $wide): array
    {
        $eta = is_array($proposal['eta'] ?? null) ? $proposal['eta'] : [];
        $price = is_numeric($proposal['price'] ?? null) ? (float) $proposal['price'] : null;
        $atr = is_numeric($proposal['atr14'] ?? null) ? (float) $proposal['atr14'] : null;
        $eta['stop_tight'] = $this->estimate($price, $tight, $atr);
        $eta['stop_wide'] = $this->estimate($price, $wide, $atr);
        return $eta;
    }

    /**
     * @return array{status:string,days_lo:int,days_hi:int,label:string}|null
     */
    private function estimate(?float $price, ?float $level, ?float $atr): ?array
    {
        if ($price === null || $level === null || $atr === null || $price <= 0 || $atr <= 0) {
            return null;
        }
        $distance = $price - $level;
        if ($distance <= 0) {
            return ['status' => 'passed', 'days_lo' => 0, 'days_hi' => 0, 'label' => '이미 아래'];
        }
        $raw = $distance / $atr;
        if ($raw < 0.4) {
            return ['status' => 'near', 'days_lo' => 1, 'days_hi' => 1, 'label' => '오늘~1일'];
        }
        $lo = max(1, (int) round($raw * 0.7));
        $hi = max($lo + ($raw >= 1.5 ? 1 : 0), (int) round($raw * 1.6));
        $hi = min(40, $hi);
        $lo = min($lo, $hi);
        $label = $lo === $hi ? '약 ' . $lo . '거래일' : '약 ' . $lo . '~' . $hi . '거래일';
        if ($lo >= 15) {
            $label = '3주 이상';
        } elseif ($lo >= 10) {
            $label = '약 2~3주';
        }
        return ['status' => 'ahead', 'days_lo' => $lo, 'days_hi' => $hi, 'label' => $label];
    }
}
