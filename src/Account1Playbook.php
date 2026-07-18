<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 노라무식 레버리지 단타를 1번 계좌(중장기·메모리 집중)용으로 변환한 규칙.
 */
final class Account1Playbook
{
    /** @var list<string> */
    public const CORE_SYMBOLS = [
        '000660.KS', // SK하이닉스
        '005930.KS', // 삼성전자
        '084370.KQ', // 유진테크
        'MU',
        'SNDK',
    ];

    /**
     * @param array<string, float|int|bool|null> $features
     * @return array{action:string,size_hint:string,rules:list<string>,reason:string}
     */
    public function decide(array $features, string $symbol): array
    {
        $score = (int) ($features['pullback_long_score'] ?? 0);
        $higherLow = (bool) ($features['higher_low'] ?? false);
        $flush = (bool) ($features['flush_bar_recent'] ?? false);
        $distHalf = $features['dist_half_pct'] ?? null;
        $invalidation = $features['invalidation_level'] ?? null;

        $rules = [
            '레버리지·인버스·양빵 헷지 금지',
            '추격 매수 금지: 올라갈 때 사지 말고 눌림/구조 회복만',
            '진입 전 무효화 라인(최근 스윙 저점) 필수 기록',
            '한 번에 현금의 전액 투입 금지, 분할 최대 3회',
            '애매하면 관망 (노라무의 양빵은 1번 계좌에 비권장)',
        ];

        if ($score >= 70 && $higherLow && $flush) {
            return [
                'action' => 'add_on_pullback',
                'size_hint' => '가용현금의 10~20%만, 최대 3분할',
                'rules' => $rules,
                'reason' => sprintf(
                    '%s: 하방 슈팅 이후 저점 상향 + 절반대 근접(점수 %d). 무효화=%s',
                    $symbol,
                    $score,
                    (string) $invalidation
                ),
            ];
        }

        if ($score >= 55 && is_numeric($distHalf) && abs((float) $distHalf) <= 4.0) {
            return [
                'action' => 'watchlist_buy_zone',
                'size_hint' => '아직 대기. 저점 이탈 없이 한 번 더 다지며 거래량 확인 후 5~10%',
                'rules' => $rules,
                'reason' => sprintf('%s: 절반 되돌림 구간 근처(점수 %d). 지금은 관심순위만', $symbol, $score),
            ];
        }

        if ($score < 35 || !$higherLow) {
            return [
                'action' => 'hold_or_trim_on_strength',
                'size_hint' => '신규 매수 금지. 보유분이면 반등 시 일부 비중 축소 검토',
                'rules' => $rules,
                'reason' => sprintf('%s: 구조 미회복/점수 낮음(%d). FOMO 금지', $symbol, $score),
            ];
        }

        return [
            'action' => 'wait',
            'size_hint' => '현금 유지',
            'rules' => $rules,
            'reason' => sprintf('%s: 중간 점수(%d). 다음주에도 된다(포모 금지)', $symbol, $score),
        ];
    }
}
