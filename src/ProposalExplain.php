<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 제안 JSON에 사람이 읽는 한국어 설명 붙이기.
 * (과거 가격 평균이 아니라, 현재 차트에 규칙을 적용한 결과임을 분명히)
 */
final class ProposalExplain
{
    /** @var array<string, string> */
    private const ACTION_LABELS = [
        'add_on_pullback' => '내려올 때 나눠서 사기 검토',
        'watchlist_buy_zone' => '관심 — 중간 가격대 근처',
        'hold_or_trim_on_strength' => '새로 사지 말 것 · 갖고 있으면 줄이기 검토',
        'wait' => '지금은 안 삼 (지켜보기)',
        'blocked' => '차단 — 본주 티커로 다시 조회',
    ];

    /**
     * @param array<string, mixed> $proposal
     * @param array<string, mixed> $features
     * @return array{
     *   action_label:string,
     *   summary:string,
     *   price_vs_zone:string,
     *   price_vs_zone_label:string,
     *   entry_zone_note:string,
     *   invalidation_note:string,
     *   target_rule_note:string,
     *   target_learned_note:string,
     *   not_market_order:bool
     * }
     */
    public function build(array $proposal, array $features): array
    {
        $action = (string) ($proposal['action'] ?? 'wait');
        $price = is_numeric($proposal['price'] ?? null) ? (float) $proposal['price'] : null;
        $zone = is_array($proposal['entry_zone'] ?? null) ? $proposal['entry_zone'] : null;
        $invalidation = is_numeric($proposal['invalidation'] ?? null) ? (float) $proposal['invalidation'] : null;
        $zoneMid = is_array($zone) && is_numeric($zone['mid'] ?? null) ? (float) $zone['mid'] : null;
        $zoneLow = is_array($zone) && is_numeric($zone['low'] ?? null) ? (float) $zone['low'] : null;
        $zoneHigh = is_array($zone) && is_numeric($zone['high'] ?? null) ? (float) $zone['high'] : null;

        [$vsZone, $vsLabel] = $this->priceVsZone($price, $zoneLow, $zoneHigh, $invalidation);

        $newEntry = is_array($proposal['new_entry'] ?? null) ? $proposal['new_entry'] : null;
        $summary = $this->summary(
            $action,
            $vsZone,
            $price,
            $zoneLow,
            $zoneHigh,
            $zoneMid,
            $invalidation,
            is_array($proposal['underlying_proxy'] ?? null) ? $proposal['underlying_proxy'] : null,
            is_array($proposal['proxy_bias'] ?? null) ? $proposal['proxy_bias'] : null,
            $newEntry,
        );
        $lesson1 = trim((string) ($proposal['lesson1_note'] ?? ''));
        if ($lesson1 !== '') {
            $summary = trim($summary . ' ' . $lesson1);
        }
        $levelsNote = trim((string) ($proposal['levels_note'] ?? ''));
        if ($levelsNote !== '' && !str_contains($levelsNote, '없음') && !str_contains($levelsNote, '부족')) {
            $summary = trim($summary . ' ' . $levelsNote);
        }

        $actionLabel = self::ACTION_LABELS[$action] ?? $action;
        if (($newEntry['status'] ?? '') === 'structure_broken' || $vsZone === 'below_invalidation') {
            $actionLabel = '이번 그림 끝 — 새로 사지 말 것';
        }

        return [
            'action_label' => $actionLabel,
            'summary' => $summary,
            'new_entry_sentence' => is_array($newEntry) ? (string) ($newEntry['sentence'] ?? '') : '',
            'price_vs_zone' => $vsZone,
            'price_vs_zone_label' => $vsLabel,
            'entry_zone_note' => '최근 고점과 저점의 중간 가격 ±4%. “지금 시장가로 사라”가 아니라, 차트 그림이 살아 있을 때 내려오면 볼 관심 구간.',
            'invalidation_note' => ($proposal['invalidation_rule'] ?? '') === 'planned_entry_below_zone'
                ? '예정 진입가보다 아래에 있는 구조 저점·가로 지지만 손절로 사용. 현재가 기준 당일 저가가 진입가보다 높으면 제외.'
                : (str_starts_with((string) ($proposal['invalidation_rule'] ?? ''), 'horizontal_level')
                    ? '여러 번 걸린 가로 가격대(매물대). 이 아래로 깨지면 이번 매수 계획은 끝 — 손절·지켜보기 후보.'
                    : '최근 저점. 이 아래로 깨지면 이번 매수 계획은 끝 — 손절·지켜보기 후보.'),
            'target_rule_note' => $this->targetRuleNote($proposal, $features),
            'target_learned_note' => '참고: 과거 글에 적힌 목표가의 중앙값. 지금 차트의 적정가가 아님.',
            'eta_note' => '최근 14봉 하루 평균 움직임으로 가격까지 거리를 나눈 거래일. 그 날짜에 도착한다는 뜻이 아님.',
            'not_market_order' => !in_array($action, ['add_on_pullback', 'watchlist_buy_zone'], true)
                || $vsZone !== 'in_zone',
        ];
    }

    /**
     * @param array<string, mixed> $proposal
     * @param array<string, mixed> $features
     */
    private function targetRuleNote(array $proposal, array $features): string
    {
        $wideRule = (string) ($proposal['target_wide_rule'] ?? $features['target_wide_rule'] ?? '');
        if (str_starts_with($wideRule, 'horizontal_resistance')) {
            return '규칙: 1차=직전 스윙 고점, 2차=고점과 측정이동 사이 가로 저항. 과거 글 평균이 아님.';
        }
        if ($wideRule === 'measured_move_1x') {
            return '규칙: 1차=직전 스윙 고점, 2차=같은 스윙 폭을 고점 위로 한 번 더. 과거 글 평균이 아님.';
        }

        return '규칙: 1차=직전 스윙 고점. 2차는 같은 폭을 위로 한 번 더(또는 그 사이 가로 저항). 과거 글 평균이 아님.';
    }

    /**
     * @return array{0:string,1:string}
     */
    private function priceVsZone(?float $price, ?float $low, ?float $high, ?float $invalidation): array
    {
        if ($price === null || $low === null || $high === null) {
            return ['unknown', '구간 비교 불가'];
        }
        if ($invalidation !== null && $price < $invalidation) {
            return ['below_invalidation', '손절선(최근 저점) 아래 — 차트 그림이 깨진 쪽'];
        }
        if ($price < $low) {
            return ['below_zone', '관심 사는 구간보다 아래'];
        }
        if ($price > $high) {
            return ['above_zone', '관심 사는 구간보다 위 (쫓아 사는 구간)'];
        }

        return ['in_zone', '관심 사는 구간 안'];
    }

    /**
     * @param array<string, mixed>|null $proxy
     * @param array<string, mixed>|null $proxyBias
     * @param array<string, mixed>|null $newEntry
     */
    private function summary(
        string $action,
        string $vsZone,
        ?float $price,
        ?float $zoneLow,
        ?float $zoneHigh,
        ?float $zoneMid,
        ?float $invalidation,
        ?array $proxy,
        ?array $proxyBias,
        ?array $newEntry,
    ): string {
        if ($action === 'blocked') {
            return '레버 상품 직접 제안은 막혀 있습니다. SOXS→MU, 코루→삼전처럼 본주로 바꿔 보세요.';
        }

        $bits = [];
        if ($newEntry !== null && ($newEntry['sentence'] ?? '') !== '') {
            $bits[] = (string) $newEntry['sentence'];
            if (($newEntry['note'] ?? '') !== '') {
                $bits[] = (string) $newEntry['note'];
            }
        }
        if ($proxy !== null) {
            $bits[] = sprintf(
                '입력 %s → 본주 %s 차트로 대응%s.',
                (string) ($proxy['source_instrument'] ?? ''),
                (string) ($proxy['spot'] ?? ''),
                !empty($proxy['inverse']) ? ' (인버스·방향 반대)' : ''
            );
        }
        if ($proxyBias !== null && isset($proxyBias['note'])) {
            $bits[] = (string) $proxyBias['note'];
        }

        if (($newEntry['status'] ?? '') !== 'structure_broken') {
            $bits[] = match ($action) {
                'add_on_pullback' => '점수·차트 그림이 맞아, 내려올 때 나눠서 사기를 검토할 수 있는 상태입니다.',
                'watchlist_buy_zone' => '관심만 — 지금 바로 전부 사라는 뜻이 아닙니다.',
                'hold_or_trim_on_strength' => '새로 사기보다 지켜보기·비중 줄이기가 우선입니다.',
                'wait' => '지금은 안 삼: 현금 유지.',
                default => '',
            };
        }

        return trim(implode(' ', array_filter($bits)));
    }

    private function n(float $v): string
    {
        if (abs($v) >= 1000) {
            return number_format($v, 0, '.', ',');
        }
        if (abs($v) >= 100) {
            return number_format($v, 2, '.', ',');
        }

        return number_format($v, 4, '.', ',');
    }
}
