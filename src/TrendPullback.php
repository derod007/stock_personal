<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Fixed research hypothesis: rising structure, MA20 pullback, volume contraction, separate recovery. */
final class TrendPullback
{
    public function analyze(array $bars, array $f): array
    {
        $p = ['version' => 'trend_pullback_v1', 'status' => 'no_setup', 'ready' => false,
            'reason' => '상승 추세 눌림 조건 대기', 'entry' => null, 'stop' => null, 'target' => null,
            'reward_risk' => null, 'candidate' => null, 'order_valid_bars' => 3];
        if (count($bars) < 45) { return $p; }
        $closes = array_column($bars, 'close');
        $ma = array_sum(array_slice($closes, -20)) / 20;
        $oldMa = array_sum(array_slice($closes, -25, 20)) / 20;
        $recent = array_slice($bars, -20);
        $prior = array_slice($bars, -40, 20);
        $atr = (float) ($f['atr14'] ?? 0);
        $last = $bars[count($bars)-1]; $prev = $bars[count($bars)-2];
        $up = $ma > $oldMa && min(array_column($recent, 'low')) > min(array_column($prior, 'low'))
            && max(array_column($recent, 'high')) > max(array_column($prior, 'high'));
        $p['gates'] = ['rising_structure'=>$up, 'atr_valid'=>$atr>0];
        if (!$up || $atr <= 0) { return $p; }
        $pre = array_slice($bars, -6, 5);
        $near = min(array_column($pre, 'low')) <= $ma + 0.75 * $atr;
        $stop = min(array_column(array_slice($bars, -10), 'low')) - 0.2 * $atr;
        $target = max(array_column(array_slice($bars, -21, 20), 'high'));
        $candidate = PriceCandidate::build($ma - 0.4 * $atr, $ma + 0.4 * $atr, $stop, $target, 'trend_pullback_ma20');
        $p['gates']['valid_zone'] = $candidate !== null && $last['close'] > $stop;
        if ($candidate === null || $last['close'] <= $stop) { return $p; }
        $baseVol = array_sum(array_column(array_slice($bars, -24, 20), 'volume')) / 20;
        $pullVol = array_sum(array_column(array_slice($bars, -4, 3), 'volume')) / 3;
        $dry = $baseVol > 0 && $pullVol < $baseVol * 0.85;
        $confirmed = $near && $dry && $last['close'] > $prev['high']
            && $last['close'] > $last['open'] && $last['volume'] > $prev['volume']
            && $prev['close'] <= $bars[count($bars)-3]['high'];
        $p['gates'] += ['near_ma20'=>$near, 'volume_contracted'=>$dry,
            'recovery_close'=>$last['close']>$prev['high'],
            'bullish_candle'=>$last['close']>$last['open'],
            'volume_recovery'=>$last['volume']>$prev['volume'],
            'fresh_confirmation'=>$prev['close']<=$bars[count($bars)-3]['high']];
        $p = array_replace($p, ['status' => $near ? 'await_confirmation' : 'wait_pullback',
            'reason' => !$near ? '상승 추세 유지, MA20 부근 눌림 대기'
                : (!$dry ? '눌림 도착, 거래량 감소 확인 필요' : '눌림·거래량 감소 관찰, 이후 고점 회복 확인 필요'),
            'candidate' => $candidate, 'volume_contracted' => $dry]);
        if (!$confirmed) { return $p; }
        $entry = round((float) $last['close'], 4);
        $rr = $entry > $stop ? ($target - $entry) / ($entry - $stop) : 0;
        return array_replace($p, ['status' => $rr >= 1.5 && $target > $entry ? 'ready' : 'rejected_rr',
            'ready' => $rr >= 1.5 && $target > $entry, 'reason' => $rr >= 1.5 ? '상승 추세 눌림 후 고점 회복 확인'
                : '반등 확인됐지만 확인 가격의 손익비 부족',
            'entry' => $entry, 'stop' => round($stop, 4), 'target' => round($target, 4),
            'reward_risk' => round($rr, 3), 'target_rule' => 'prior_20_bar_high',
            'signal_at' => $last['available_at']]);
    }
}
