<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 현재 차트 기준 “적정 신규 진입가” 문장·수치.
 * 지금 시장가 매수가가 아니라, 이 가격대면 새로 나눠서 사기를 검토한다는 레벨.
 */
final class NewEntryGuide
{
    /**
     * @param array<string, mixed> $proposal
     * @param array<string, mixed> $features
     * @return array{
     *   available:bool,
     *   buy_now:bool,
     *   status:string,
     *   price:?float,
     *   low:?float,
     *   high:?float,
     *   deep_support:?float,
     *   sentence:string,
     *   note:string
     * }
     */
    public function build(array $proposal, array $features): array
    {
        $price = is_numeric($proposal['price'] ?? null) ? (float) $proposal['price'] : null;
        $zone = is_array($proposal['entry_zone'] ?? null) ? $proposal['entry_zone'] : null;
        $low = is_array($zone) && is_numeric($zone['low'] ?? null) ? (float) $zone['low'] : null;
        $high = is_array($zone) && is_numeric($zone['high'] ?? null) ? (float) $zone['high'] : null;
        $mid = is_array($zone) && is_numeric($zone['mid'] ?? null) ? (float) $zone['mid'] : null;
        $inv = is_numeric($proposal['invalidation'] ?? null) ? (float) $proposal['invalidation'] : null;
        $action = (string) ($proposal['action'] ?? '');
        $deep = is_numeric($features['swing_low'] ?? null) ? (float) $features['swing_low'] : $inv;

        if ($action === 'blocked' || $low === null || $high === null || $mid === null || $price === null) {
            return [
                'available' => false,
                'buy_now' => false,
                'status' => 'unavailable',
                'price' => null,
                'low' => $low,
                'high' => $high,
                'deep_support' => $deep,
                'sentence' => '현재 차트로 새로 살 가격을 계산할 수 없습니다.',
                'note' => '고점·저점의 중간 가격대가 잡히면 다시 계산됩니다.',
            ];
        }

        // 레버→본주 숏 바이어스면 신규 롱 진입가 비활성
        $bias = is_array($proposal['proxy_bias'] ?? null) ? (string) ($proposal['proxy_bias']['bias'] ?? '') : '';
        if ($bias === 'short') {
            $biasNote = is_array($proposal['proxy_bias'] ?? null)
                ? (string) ($proposal['proxy_bias']['note'] ?? '숏/비중 줄이기 쪽')
                : '숏/비중 줄이기 쪽';
            return [
                'available' => false,
                'buy_now' => false,
                'status' => 'bias_short',
                'price' => $mid,
                'low' => $low,
                'high' => $high,
                'deep_support' => $deep,
                'sentence' => sprintf(
                    '현재가 %s. 숏 쪽이라 새로 롱으로 사라는 추천은 없음. (참고 중간 가격대 %s~%s)',
                    $this->n($price),
                    $this->n($low),
                    $this->n($high)
                ),
                'note' => $biasNote . ' · 저점이 다시 깨진 뒤 올리고, 중간 가격에 다시 올 때까지 새로 사기 보류.',
            ];
        }

        if ($inv !== null && $price < $inv) {
            return [
                'available' => false,
                'buy_now' => false,
                'status' => 'structure_broken',
                'price' => null,
                'low' => $low,
                'high' => $high,
                'deep_support' => $deep,
                'sentence' => sprintf(
                    '현재가 %s. 손절선(%s) 아래라 이번 그림 기준으로 새로 살 가격 없음. 저점이 올라오고 새 중간 가격이 잡힐 때까지 기다리세요.',
                    $this->n($price),
                    $this->n($inv)
                ),
                'note' => '예전 관심 구간(' . $this->n($low) . '~' . $this->n($high) . ')은 쫓아 사기·저점 줍기의 근거로 쓰지 마세요.',
            ];
        }

        if ($price > $high) {
            return [
                'available' => true,
                'buy_now' => false,
                'status' => 'wait_pullback',
                'price' => $mid,
                'low' => $low,
                'high' => $high,
                'deep_support' => $deep,
                'sentence' => sprintf(
                    '현재가 %s. %s~%s(중심 %s)이면 새로 사기 검토 가능. 지금은 쫓아 사는 자리 아님.',
                    $this->n($price),
                    $this->n($low),
                    $this->n($high),
                    $this->n($mid)
                ),
                'note' => $deep !== null && $deep < $low
                    ? sprintf('더 깊게 오면 손절선/최근 저점 %s 근처까지가 2차로 볼 자리.', $this->n($deep))
                    : '고점·저점의 중간 ±4% 구간이 1차로 새로 볼 사는 가격입니다.',
            ];
        }

        if ($price >= $low && $price <= $high) {
            $buyNow = in_array($action, ['add_on_pullback', 'watchlist_buy_zone'], true);
            return [
                'available' => true,
                'buy_now' => $buyNow,
                'status' => 'in_zone',
                'price' => $mid,
                'low' => $low,
                'high' => $high,
                'deep_support' => $deep,
                'sentence' => sprintf(
                    '현재가 %s가 새로 살 관심 구간(%s~%s) 안입니다. 중심 %s 기준으로 나눠서 사기 검토%s.',
                    $this->n($price),
                    $this->n($low),
                    $this->n($high),
                    $this->n($mid),
                    $buyNow ? '' : ' (점수가 낮으면 지켜보기 유지)'
                ),
                'note' => $inv !== null
                    ? sprintf('손절선 후보 %s. 아래로 깨지면 이번 매수 계획은 끝.', $this->n($inv))
                    : '손절선도 같이 적어 두세요.',
            ];
        }

        // 중간보다 아래지만 손절선 위: 회복 후 중간이 다시 진입가
        return [
            'available' => true,
            'buy_now' => false,
            'status' => 'below_half_wait_recover',
            'price' => $mid,
            'low' => $low,
            'high' => $high,
            'deep_support' => $deep,
            'sentence' => sprintf(
                '현재가 %s(중간 가격보다 아래). 지금 가격으로 새로 사지 않음. 차트 그림이 회복된 뒤 %s~%s이면 다시 새로 사기 검토.',
                $this->n($price),
                $this->n($low),
                $this->n($high)
            ),
            'note' => $inv !== null
                ? sprintf('그 전에 손절선 %s를 다시 지켜야 합니다.', $this->n($inv))
                : '저점이 올라온 뒤 중간 가격에 다시 오는지를 봅니다.',
        ];
    }

    private function n(float $v): string
    {
        if (abs($v) >= 10000) {
            return number_format($v, 0, '.', ',') . '원';
        }
        if (abs($v) >= 1000) {
            return number_format($v, 0, '.', ',');
        }
        if (abs($v) >= 100) {
            return number_format($v, 2, '.', ',');
        }

        return number_format($v, 4, '.', ',');
    }
}
