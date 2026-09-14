<?php

declare(strict_types=1);

namespace ChartEntryLab;

/** Shared, deterministic daily signal path for UI, scanner and replay. No author-price overrides. */
final class ChartPlanEngine
{
    public function analyze(array $bars, string $symbol, int $asOf, string $profile = 'account1'): array
    {
        $bars = CandleClock::completed($bars, $symbol, $asOf);
        if (count($bars) < 40) {
            throw new \InvalidArgumentException('완료된 일봉 40개 이상 필요');
        }
        $features = (new FeatureEngine())->extract($bars);
        $decision = AccountPlaybook::forProfile($profile)->decide($features, $symbol);
        $plan = (new BreakoutRetest())->analyze($bars);
        $latest = $bars[array_key_last($bars)]['available_at'];
        $features['asof_kst'] = (new \DateTimeImmutable('@' . $latest))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i:s');
        $plan['asof'] = $asOf;
        $plan['data_asof'] = $latest;
        // Conservative guard; not a full exchange holiday/suspension calendar.
        if ($asOf - $latest > 4 * 86400) {
            $plan = array_replace($plan, ['ready' => false, 'status' => 'stale_data', 'reason' => '완료 일봉이 4일 넘게 오래되어 신규 추천 보류']);
        } elseif ($decision['action'] === 'blocked') {
            $plan = array_replace($plan, ['ready' => false, 'status' => 'blocked', 'reason' => $decision['reason']]);
        } elseif (($features['spike_dump_status'] ?? 'none') !== 'none'
            || (in_array($features['top_pattern_status'] ?? 'none', ['warning', 'confirmed'], true)
                && ($features['top_pattern_phase'] ?? '') !== 'bounce_confirmed')) {
            $plan = array_replace($plan, ['ready' => false, 'status' => 'risk_blocked', 'reason' => '급등 후 급락 또는 고점 붕괴 경고로 신규 진입 보류']);
        }
        return ['features' => $features, 'decision' => $decision, 'plan' => $plan];
    }

    public function apply(array $proposal, array $plan): array
    {
        $ready = !empty($plan['ready']);
        $entry = $ready ? $plan['entry'] : null;
        $stop = $ready ? $plan['stop'] : null;
        $target = $ready ? $plan['target'] : null;
        $proposal['trade_plan'] = $plan;
        $proposal['legacy_rules_reference'] = $proposal['rules'] ?? [];
        $proposal['rules'] = ['완료 일봉만 사용', '재지지 후 별도 확인 봉 필요', '손절 < 진입 < 목표', '최소 손익비 1.5', '확인 다음 거래봉부터 3봉 지정가 유효'];
        $proposal['legacy_digingonyou_method'] = $proposal['digingonyou_method'] ?? null;
        unset($proposal['digingonyou_method']);
        $proposal['action'] = $plan['status'] === 'blocked' ? 'blocked' : ($ready ? 'watchlist_buy_zone' : 'wait');
        $proposal['entry_zone'] = $ready ? ['low' => $entry, 'high' => $entry, 'mid' => $entry, 'rule' => 'confirmed_retest_limit'] : null;
        foreach (['invalidation', 'invalidation_tight', 'invalidation_wide', 'invalidation_structural'] as $k) {
            $proposal[$k] = $stop;
        }
        $proposal['target_hint'] = $ready ? ['price' => $target, 'rule' => $plan['target_rule'], 'wide' => null, 'wide_rule' => 'none'] : null;
        $proposal['target_tight'] = $target;
        $proposal['target_wide'] = null;
        $proposal['target_wide_rule'] = 'none';
        $proposal['eta'] = null;
        $proposal['invalidation_rule'] = 'retest_low_minus_atr_buffer';
        $proposal['level_method'] = BreakoutRetest::VERSION;
        $proposal['level_method_label'] = '완료 일봉 돌파·재지지 확인 / 구조 손절 / 손익비';
        $proposal['reason'] = $plan['reason'];
        $proposal['size_hint'] = $ready ? '다음 거래봉부터 지정가 검토. 현재 종가에 체결된 것으로 간주하지 않음.' : '신규 진입 보류';
        $proposal['new_entry'] = ['available' => $ready, 'buy_now' => false, 'order_ready' => $ready,
            'status' => $plan['status'], 'price' => $entry, 'low' => $entry, 'high' => $entry,
            'deep_support' => $stop, 'sentence' => $plan['reason'],
            'note' => $ready ? '진입 ' . $entry . ' / 손절 ' . $stop . ' / 목표 ' . $target . ' / 손익비 ' . $plan['reward_risk']
                : '점수·과거 글 가격만으로 신규 주문을 활성화하지 않습니다.'];
        return $proposal;
    }
}
