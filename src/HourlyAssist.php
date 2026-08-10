<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 1시간봉으로 “이상 거래량·단기 확인” 보조 신호.
 * 일봉 점수를 대체하지 않고 가산/메모만 제공.
 */
final class HourlyAssist
{
    public function __construct(
        private readonly YahooChartClient $client,
    ) {
    }

    /**
     * @return array{
     *   ok:bool,
     *   bars:?int,
     *   vol_spike_1h:?float,
     *   unusual_volume:bool,
     *   last_1h_kst:?string,
     *   note:string,
     *   error:?string
     * }
     */
    public function analyze(string $yahooSymbol): array
    {
        try {
            $bars = $this->client->fetch($yahooSymbol, '5d', '1h', useCache: true);
            if (count($bars) < 10) {
                return [
                    'ok' => false,
                    'bars' => count($bars),
                    'vol_spike_1h' => null,
                    'unusual_volume' => false,
                    'last_1h_kst' => null,
                    'note' => '1h 봉 부족',
                    'error' => null,
                ];
            }
            $volumes = array_column($bars, 'volume');
            $last = $bars[array_key_last($bars)];
            $avg = array_sum(array_slice($volumes, 0, -1)) / max(1, count($volumes) - 1);
            $spike = $avg > 0 ? $last['volume'] / $avg : null;
            $unusual = $spike !== null && $spike >= 1.8;

            return [
                'ok' => true,
                'bars' => count($bars),
                'vol_spike_1h' => $spike !== null ? round($spike, 3) : null,
                'unusual_volume' => $unusual,
                'last_1h_kst' => $last['time_kst'] ?? null,
                'note' => $unusual
                    ? '1h 이상 거래량 — 일봉 눌림 자리와 겹치면 확인 가산'
                    : '1h 거래량 평범 — 일봉 구조만으로 판단',
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'bars' => null,
                'vol_spike_1h' => null,
                'unusual_volume' => false,
                'last_1h_kst' => null,
                'note' => '1h 조회 실패',
                'error' => $e->getMessage(),
            ];
        }
    }
}
