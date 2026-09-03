<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 디깅온유식 규칙 → 현재 차트 피처에 적용.
 * 투매 플러시 저 · 하이라이트 지지 «밟으면 매수» · 반등 분할매도.
 * (과거 글 절대가 복붙이 아님)
 *
 * @see docs/digingonyou-playbook.md
 */
final class DigingonyouMethod
{
    /**
     * @param array<string, mixed> $features FeatureEngine 출력
     * @return array{
     *   ok:bool,
     *   method:string,
     *   method_label:string,
     *   score:int,
     *   action:string,
     *   action_label:string,
     *   entry_zone:?array{low:float,high:float,mid:float,rule:string},
     *   invalidation:?float,
     *   target_hint:?array{price:float,rule:string},
     *   size_hint:string,
     *   reason:string,
     *   near_flush_buy:bool,
     *   extended_from_flush:bool
     * }
     */
    public function analyze(array $features): array
    {
        $price = is_numeric($features['price'] ?? null) ? (float) $features['price'] : null;
        $flushLow = is_numeric($features['flush_low'] ?? null) ? (float) $features['flush_low'] : null;
        $swingLow = is_numeric($features['swing_low'] ?? null) ? (float) $features['swing_low'] : null;
        $swingHigh = is_numeric($features['swing_high'] ?? null) ? (float) $features['swing_high'] : null;
        $flushRecent = (bool) ($features['flush_bar_recent'] ?? false);
        $higherLow = (bool) ($features['higher_low'] ?? false);
        // 1차 하이라이트 지지 ≈ 플러시저 우선, 없으면 스윙저 («밟으면 매수»)
        $structLow = $flushLow ?? $swingLow;
        $supportMode = $flushLow !== null ? 'flush' : 'highlight_swing';

        if ($price === null || $structLow === null || $structLow <= 0) {
            return [
                'ok' => false,
                'method' => 'digingonyou_support_reentry',
                'method_label' => '기타: 지지선 밟고 다시 사기 (데이터 부족)',
                'score' => 0,
                'action' => 'wait',
                'action_label' => '지금은 안 삼',
                'entry_zone' => null,
                'invalidation' => null,
                'target_hint' => null,
                'size_hint' => '차트 피처 부족',
                'reason' => '이 규칙 적용에 필요한 저점/가격이 없습니다.',
                'near_flush_buy' => false,
                'extended_from_flush' => false,
            ];
        }

        // 관심구간: 1차 지지 ~ 지지×1.08
        $zoneLow = round($structLow, 4);
        $zoneHigh = round($structLow * 1.08, 4);
        $zoneMid = round(($zoneLow + $zoneHigh) / 2, 4);
        $entryZone = [
            'low' => $zoneLow,
            'high' => $zoneHigh,
            'mid' => $zoneMid,
            'rule' => $supportMode === 'flush'
                ? 'digingonyou_flush_low_to_+8%'
                : 'digingonyou_highlight_support_to_+8%',
        ];

        $invalidation = round($structLow * 0.995, 4);
        $secondSupport = round($structLow * 0.90, 4);

        $targetFromFlush = $structLow * 1.25;
        $targetPrice = $swingHigh !== null ? min($targetFromFlush, (float) $swingHigh) : $targetFromFlush;
        if ($targetPrice <= $zoneHigh) {
            $targetPrice = $structLow * 1.20;
        }
        $targetHint = [
            'price' => round($targetPrice, 4),
            'rule' => 'digingonyou_bounce_scale_out(~+20~25%_or_swing_high)',
        ];

        $distToZoneMidPct = (($price - $zoneMid) / $zoneMid) * 100.0;
        $rallyFromLowPct = (($price - $structLow) / $structLow) * 100.0;
        $nearSupportBuy = $price <= $zoneHigh * 1.02 && $price >= $zoneLow * 0.98;
        $extended = $rallyFromLowPct >= 18.0;

        $score = 25;
        if ($flushRecent || $flushLow !== null) {
            $score += 20;
        }
        if ($nearSupportBuy) {
            $score += 25;
        }
        if ($higherLow) {
            $score += 15;
        }
        // 플러시 없어도 스윙저 근처면 «하이라이트 밟기» 가산
        if ($supportMode === 'highlight_swing' && $nearSupportBuy) {
            $score += 10;
        }
        if ($price < $invalidation) {
            $score = min($score, 20);
        }
        if ($extended && !$nearSupportBuy) {
            $score -= 15;
        }
        if ($distToZoneMidPct > 12) {
            $score -= 10;
        }
        $score = max(0, min(100, $score));

        if ($price < $invalidation) {
            $action = 'wait';
            $actionLabel = '지금은 안 삼 — 1차 지지선 깨짐';
            $size = '새로 사기 보류 · 2차 지지선(' . $this->n($secondSupport) . ') 확인 전 현금';
            $reason = sprintf(
                '기타: 현재가 %s가 1차 지지선/손절선(%s) 아래. 다음 아래(약 %s) 열린 구간 — 새로 사지 말 것.',
                $this->n($price),
                $this->n($invalidation),
                $this->n($secondSupport)
            );
        } elseif ($nearSupportBuy && ($flushRecent || $higherLow || $score >= 55)) {
            $action = 'add_on_pullback';
            $actionLabel = '지지선 밟고 나눠서 사기 검토';
            $size = '가진 현금 나눠서 (한 번에 몰빵 금지)';
            $reason = sprintf(
                '기타: %s(%s) 근처 «밟으면 매수» 구간 %s~%s. 프로그램이 누르면 나눠서 사기 후보(점수 %d).',
                $supportMode === 'flush' ? '급락 저점' : '표시해 둔 지지선',
                $this->n($structLow),
                $this->n($zoneLow),
                $this->n($zoneHigh),
                $score
            );
        } elseif ($extended) {
            $action = 'hold_or_trim_on_strength';
            $actionLabel = '새로 사지 말 것 · 올라오면 나눠 팔기 검토';
            $size = '새로 사지 말 것. 갖고 있으면 올라오는 구간에서 일부 줄이기';
            $reason = sprintf(
                '기타: 1차 지지선 대비 +%.1f%% 반등. 나눠 팔고 다시 살 자리 대기(점수 %d).',
                $rallyFromLowPct,
                $score
            );
        } elseif ($score >= 50) {
            $action = 'watchlist_buy_zone';
            $actionLabel = '관심 — 지지선 근처 대기';
            $size = '대기 후 지지선 밟을 때 나눠서';
            $reason = sprintf(
                '기타: 관심 구간 %s~%s 대기(점수 %d). 쫓아 사기 아님. 깨지면 2차≈%s.',
                $this->n($zoneLow),
                $this->n($zoneHigh),
                $score,
                $this->n($secondSupport)
            );
        } else {
            $action = 'wait';
            $actionLabel = '지금은 안 삼';
            $size = '현금 유지';
            $reason = sprintf(
                '기타: 지지선 다시 사기 조건 미충족(점수 %d). 관심 구간 %s~%s.',
                $score,
                $this->n($zoneLow),
                $this->n($zoneHigh)
            );
        }

        return [
            'ok' => true,
            'method' => 'digingonyou_support_reentry',
            'method_label' => '기타: 급락/표시 지지선~+8% 밟고 사기 · 올라오면 나눠 팔기 · 손절선=1차 저점',
            'score' => $score,
            'action' => $action,
            'action_label' => $actionLabel,
            'entry_zone' => $entryZone,
            'invalidation' => $invalidation,
            'target_hint' => $targetHint,
            'size_hint' => $size,
            'reason' => $reason,
            'near_flush_buy' => $nearSupportBuy,
            'extended_from_flush' => $extended,
            'second_support' => $secondSupport,
        ];
    }

    private function n(float $v): string
    {
        if (abs($v) >= 1000) {
            return number_format($v, 0, '.', ',');
        }
        return number_format($v, 2, '.', ',');
    }
}
