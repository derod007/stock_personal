<?php

declare(strict_types=1);

namespace NoPullbackStudy;
use ChartEntryLab\{CandleClock,FeatureEngine,AccountPlaybook,BreakoutRetest,TrendPullback,TrendRecovery,TrendContext,PriceCandidate};

/** Shared, deterministic daily signal path for UI, scanner and replay. No author-price overrides. */
final class ChartPlanEngine
{
    public function analyze(array $bars, string $symbol, int $asOf, string $profile = 'account1', bool $useContext = true, bool $excludePullback = true): array
    {
        $bars = CandleClock::completed($bars, $symbol, $asOf);
        if (count($bars) < 40) {
            throw new \InvalidArgumentException('완료된 일봉 40개 이상 필요');
        }
        $features = (new FeatureEngine())->extract($bars);
        $decision = AccountPlaybook::forProfile($profile)->decide($features, $symbol);
        $retest = (new BreakoutRetest())->analyze($bars);
        $pullback = (new TrendPullback())->analyze($bars, $features);
        $recovery = (new TrendRecovery())->analyze($bars);
        $context = (new TrendContext())->analyze($bars, $symbol, $asOf);
        $plan = !empty($retest['ready']) ? $retest
            : (!empty($pullback['ready']) || $pullback['candidate'] !== null ? $pullback : $retest);
        if ($excludePullback) $plan = $retest; // Before context/recovery selection, never delete trades afterwards.
        // Keep an eligible legacy recommendation first; recovery is an independent alternative.
        $legacyContextBlocked = $useContext && ($context['daily'] === 'down' || ($context['weekly'] === 'down'
            && ($context['daily'] !== 'up' || (float)($plan['reward_risk'] ?? 0) < 2.0)));
        if ((empty($plan['ready']) && in_array($recovery['status'], ['ready','rejected_rr','no_upper_target','invalid_levels'], true))
            || ($legacyContextBlocked && !empty($recovery['ready'])
                && !TrendRecovery::contextBlocked($context, $recovery['reward_risk']))) {
            $plan = $recovery;
        }
        $isRecovery = $plan['version'] === TrendRecovery::VERSION;
        $plan['pattern'] = $plan['version'];
        $plan['context'] = $context;
        $plan['context_applied'] = $useContext;
        $candidate = $plan['candidate'] ?? null;
        if ($candidate === null && is_numeric($plan['entry'] ?? null)) {
            $candidate = PriceCandidate::build((float) $plan['entry'], (float) $plan['entry'],
                (float) $plan['stop'], (float) $plan['target'], $plan['version']);
        }
        if (!$isRecovery) $candidate ??= (new PriceCandidate())->fromStructure($features);
        $plan['candidate'] = $candidate;
        if ($candidate !== null && empty($plan['ready'])) {
            $plan['reason'] .= ' · 관심 가격 후보 있음, 진입 확인 전';
        }
        $contextBlocked = $isRecovery ? TrendRecovery::contextBlocked($context, $plan['reward_risk']) : $legacyContextBlocked;
        if ($useContext && $contextBlocked) {
            $plan['ready'] = false;
            $plan['status'] = 'context_wait';
            $plan['reason'] = $context['label'] . ($isRecovery ? ': 회복형은 주봉 하락 시 손익비 2 이상 필요' : ': 상위 추세·손익비 추가 확인 필요');
        }
        $latest = $bars[array_key_last($bars)]['available_at'];
        $features['asof_kst'] = (new \DateTimeImmutable('@' . $latest))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i:s');
        $plan['asof'] = $asOf;
        $plan['data_asof'] = $latest;
        // Conservative guard; not a full exchange holiday/suspension calendar.
        if ($asOf - $latest > 144 * 3600) {
            $plan = array_replace($plan, ['ready' => false, 'status' => 'stale_data', 'reason' => '완료 일봉이 6일 넘게 오래되어 신규 추천 보류']);
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
        $plan['diagnostics'] = [
            'patterns' => ['breakout_retest' => $retest, 'trend_pullback' => $pullback, 'trend_recovery' => $recovery],
            'selected_pattern' => $plan['pattern'],
            'final_status' => $plan['status'],
            'candidate_available' => $plan['candidate_available'],
            'order_ready' => $plan['ready'],
        ];
        return ['features' => $features, 'decision' => $decision, 'plan' => $plan];
    }

}
