<?php
declare(strict_types=1);
namespace ChartEntryLab;

final class PriceCandidate
{
    public static function build(float $low, float $high, float $stop, float $target, string $source): ?array
    {
        foreach ([$low, $high, $stop, $target] as $v) {
            if (!is_finite($v) || $v <= 0) { return null; }
        }
        $low = round($low, 4); $high = round($high, 4);
        $stop = round($stop, 4); $target = round($target, 4);
        if (!($stop < $low && $low <= $high && $high < $target)) { return null; }
        $mid = round(($low + $high) / 2, 4);
        $rr = static fn(float $e): float => round(($target - $e) / ($e - $stop), 3);
        return ['low' => $low, 'mid' => $mid, 'high' => $high, 'stop' => $stop, 'target' => $target,
            'source' => $source, 'reward_risk' => ['low' => $rr($low), 'mid' => $rr($mid), 'high' => $rr($high)],
            'rr_basis' => 'before_costs'];
    }

    public function fromStructure(array $f): ?array
    {
        $atr = (float) ($f['atr14'] ?? 0);
        $price = (float) ($f['price'] ?? 0);
        $swingLow = (float) ($f['swing_low'] ?? 0);
        $mid = (float) ($f['half_retrace'] ?? 0);
        if ($atr <= 0 || $swingLow <= 0 || $price <= $swingLow) { return null; }
        $support = (float) ($f['level_support'] ?? 0);
        if ($support > $swingLow && $support <= $price && abs($support - $mid) <= 2 * $atr) {
            $mid = $support;
        }
        $width = min($atr * 0.4, $mid * 0.04);
        $target = (float) ($f['swing_high'] ?? 0);
        $resistance = (float) ($f['level_resistance'] ?? 0);
        if ($resistance > $mid + $width && $resistance < $target) { $target = $resistance; }
        return self::build($mid - $width, $mid + $width, $swingLow - 0.2 * $atr, $target, 'structure_support');
    }
}
