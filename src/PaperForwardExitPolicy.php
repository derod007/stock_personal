<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Explicit forward rollout; never changes an existing filled order or a signal plan. */
final class PaperForwardExitPolicy
{
    public const POLICY = 'half_risk_v1';
    public static function eligible(array $s): bool
    {
        return ($s['mode'] ?? '') === 'forward'
            && ($s['config']['id'] ?? '') === 'paper-kr-recovery-v1'
            && ($s['config']['currency'] ?? '') === 'KRW';
    }
    public static function adopt(array &$s, callable $emit, int $now): void
    {
        if (!self::eligible($s) || isset($s['forward_exit_policy'])) return;
        if (!empty($s['halted'])) throw new \RuntimeException('Halted account cannot adopt exit policy');
        $s['forward_exit_policy'] = ['policy'=>self::POLICY, 'enabled_at'=>$now,
            'scope'=>'fills_after_activation', 'trigger_r'=>1.0, 'remaining_risk_r'=>0.5];
        $emit('exit_policy_enabled', $s['forward_exit_policy'] + ['existing_positions'=>'preserved']);
    }
    public static function forNewFill(array $s, int $openTime): bool
    {
        return self::eligible($s) && ($s['forward_exit_policy']['policy'] ?? null) === self::POLICY
            && $openTime > ($s['forward_exit_policy']['enabled_at'] ?? PHP_INT_MAX);
    }
    public static function record(array &$order, array $result, string $symbol, int $session, callable $emit): void
    {
        if (!isset($result['protection'])) return;
        $p=$result['protection'];$previous=$order['protection']??[];
        foreach (['trigger_at'=>'protection_armed','active_at'=>'protection_activated'] as $field=>$event) {
            if ($p[$field] !== null && empty($previous[$field])) {
                $emit($event, ['symbol'=>$symbol,'session'=>$session] + $p);
            }
        }
        $order['protection']=$p;
    }
}
