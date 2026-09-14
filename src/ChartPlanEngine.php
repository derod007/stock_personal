<?php

declare(strict_types=1);

namespace ChartEntryLab;

/** Shared, deterministic daily signal path for UI, scanner and replay. No author-price overrides. */
final class ChartPlanEngine
{
    public function analyze(array $bars, string $symbol, int $asOf, string $profile = 'account1', bool $useContext = true): array
    {
        $bars = CandleClock::completed($bars, $symbol, $asOf);
        if (count($bars) < 40) {
            throw new \InvalidArgumentException('완료된 일봉 40개 이상 필요');
        }
        $features = (new FeatureEngine())->extract($bars);
        $decision = AccountPlaybook::forProfile($profile)->decide($features, $symbol);
        $retest = (new BreakoutRetest())->analyze($bars);
        $pullback = (new TrendPullback())->analyze($bars, $features);
        $context = (new TrendContext())->analyze($bars, $symbol, $asOf);
        $plan = !empty($retest['ready']) ? $retest
            : (!empty($pullback['ready']) || $pullback['candidate'] !== null ? $pullback : $retest);
        $plan['pattern'] = $plan['version'];
        $plan['context'] = $context;
        $plan['context_applied'] = $useContext;
        $candidate = $plan['candidate'] ?? null;
        if ($candidate === null && is_numeric($plan['entry'] ?? null)) {
            $candidate = PriceCandidate::build((float) $plan['entry'], (float) $plan['entry'],
                (float) $plan['stop'], (float) $plan['target'], $plan['version']);
        }
        $candidate ??= (new PriceCandidate())->fromStructure($features);
        $plan['candidate'] = $candidate;
        if ($candidate !== null && empty($plan['ready'])) {
            $plan['reason'] .= ' · 관심 가격 후보 있음, 진입 확인 전';
        }
        if ($useContext && ($context['daily'] === 'down' || ($context['weekly'] === 'down'
            && ($context['daily'] !== 'up' || (float) ($plan['reward_risk'] ?? 0) < 2.0)))) {
            $plan['ready'] = false;
            $plan['status'] = 'context_wait';
            $plan['reason'] = $context['label'] . ': 상위 추세·손익비 추가 확인 필요';
        }
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
        if (in_array($plan['status'], ['stale_data', 'blocked'], true)) {
            $plan['candidate'] = null;
        }
        $plan['candidate_available'] = $plan['candidate'] !== null;
        $plan['confirmation_status'] = $plan['ready'] ? 'confirmed' : 'waiting';
        return ['features' => $features, 'decision' => $decision, 'plan' => $plan];
    }

    public function apply(array $proposal, array $plan): array
    {
        $ready = !empty($plan['ready']);
        $candidate = $plan['candidate'] ?? null;
        // Never revive a hidden candidate from legacy numbers for stale or blocked data.
        if (in_array($plan['status'], ['stale_data', 'blocked'], true)) { $candidate = null; }
        $available = is_array($candidate);
        $proposal['trade_plan'] = $plan;
        $proposal['price_candidate'] = $candidate;
        $proposal['trend_context'] = $plan['context'] ?? null;
        $proposal['rules'] = ['완료 일봉 기준', '관심 가격과 주문 확인 분리', '구조 손절·첫 저항 기준 손익비',
            '확인 후 다음 3개 거래봉 지정가', '큰 추세 하락은 추가 확인'];
        unset($proposal['digingonyou_method']);
        $proposal['action'] = $plan['status'] === 'blocked' ? 'blocked' : ($ready ? 'watchlist_buy_zone' : 'wait');
        $proposal['entry_zone'] = $available ? ['low' => $candidate['low'], 'high' => $candidate['high'],
            'mid' => $candidate['mid'], 'rule' => $candidate['source']] : null;
        foreach (['invalidation', 'invalidation_tight', 'invalidation_wide', 'invalidation_structural'] as $k) {
            $proposal[$k] = $available ? $candidate['stop'] : null;
        }
        $proposal['target_hint'] = $available ? ['price' => $candidate['target'], 'rule' => 'candidate_structural_target', 'wide' => null] : null;
        $proposal['target_tight'] = $available ? $candidate['target'] : null;
        $proposal['target_wide'] = null;
        $proposal['target_wide_rule'] = 'none';
        $proposal['eta'] = null;
        $proposal['invalidation_rule'] = 'candidate_structure_atr_buffer';
        $proposal['level_method'] = 'multi_pattern_v2';
        $proposal['level_method_label'] = '가격 후보 / 진입 확인 / 큰 추세 분리';
        $proposal['reason'] = $plan['reason'];
        $proposal['size_hint'] = $ready ? '패턴 확인 완료: 다음 거래봉 지정가 검토' : '관심 가격은 주문 지시가 아님';
        $sentence = ($available ? '관심 ' . $candidate['low'] . '~' . $candidate['high'] . ' / 손절 후보 '
            . $candidate['stop'] . ' / 목표 후보 ' . $candidate['target'] . ' · ' : '') . $plan['reason'];
        $proposal['new_entry'] = ['available' => $available, 'candidate_available' => $available,
            'buy_now' => false, 'order_ready' => $ready, 'status' => $plan['status'],
            'price' => $available ? $candidate['mid'] : null, 'low' => $candidate['low'] ?? null,
            'high' => $candidate['high'] ?? null, 'deep_support' => $candidate['stop'] ?? null,
            'sentence' => $sentence, 'note' => $ready
                ? '확인 지정가 ' . $plan['entry'] . ' / 손익비 ' . $plan['reward_risk']
                : '후보 가격에 도달해도 패턴 확인 전에는 주문하지 않음'];
        return $proposal;
    }
}
