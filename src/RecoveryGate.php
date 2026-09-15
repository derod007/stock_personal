<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Research-only single-condition entry alternative. Input must contain past completed bars only. */
final class RecoveryGate
{
    public static function passes(array $bars): bool
    {
        $n=count($bars);
        return $n>=2 && $bars[$n-1]['close']>$bars[$n-2]['high'];
    }
}
