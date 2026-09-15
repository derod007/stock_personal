<?php

declare(strict_types=1);

namespace ChartEntryLab;

/** Research hypothesis v1, not a claim about the author's actual trades. Long-only daily setup. */
final class BreakoutRetest
{
    public const VERSION = 'breakout_retest_v1';
    public const MIN_RR = 1.5;
    public const ORDER_VALID_BARS = 3;

    public function analyze(array $bars): array
    {
        $n = count($bars);
        $base = ['version' => self::VERSION, 'status' => 'no_setup', 'ready' => false,
            'reason' => '20봉 박스 돌파 후 재지지 패턴 대기', 'entry' => null, 'stop' => null,
            'target' => null, 'reward_risk' => null, 'order_valid_bars' => self::ORDER_VALID_BARS];
        if ($n < 40) {
            return array_replace($base, ['status' => 'insufficient_data', 'reason' => '완료된 일봉 40개 이상 필요']);
        }
        // Find the most recent independent breakout. All thresholds are fixed before forward evaluation.
        for ($i = $n - 1; $i >= max(20, $n - 16); $i--) {
            $box = array_slice($bars, $i - 20, 20);
            $level = (float) max(array_column($box, 'high'));
            $floor = (float) min(array_column($box, 'low'));
            $atr = $this->atr(array_slice($bars, 0, $i));
            if ($atr <= 0 || $level - $floor > 8 * $atr || $level - $floor < 2 * $atr) {
                continue;
            }
            $avgVol = array_sum(array_column($box, 'volume')) / 20;
            if ($bars[$i]['close'] <= $level + 0.1 * $atr || $bars[$i]['close'] <= $bars[$i]['open']
                || $avgVol <= 0 || $bars[$i]['volume'] < $avgVol * 1.2) {
                continue;
            }
            $plan = array_replace($base, ['status' => 'await_retest', 'reason' => '돌파 확인, 이후 재지지 봉 대기',
                'breakout_at' => $bars[$i]['available_at'], 'level' => $level, 'atr' => $atr]);
            $retest = null;
            $retestLow = null;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($bars[$j]['close'] < $level - 0.5 * $atr) {
                    return array_replace($plan, ['status' => 'invalidated', 'reason' => '돌파 가격 아래로 종가 이탈, 이번 패턴 무효']);
                }
                if ($j - $i > 10) {
                    return array_replace($plan, ['status' => 'expired', 'reason' => '돌파 후 10봉 안에 확인되지 않아 만료']);
                }
                if ($retest !== null) {
                    $retestLow = min($retestLow, (float) $bars[$j]['low']);
                    if ($bars[$j]['close'] > $bars[$retest]['high']
                        && $bars[$j]['close'] > $bars[$j]['open']
                        && $bars[$j]['volume'] >= $bars[$retest]['volume']) {
                        if ($j !== $n - 1) {
                            return array_replace($plan, ['status' => 'expired', 'reason' => '이미 지난 확인 신호: 새로 추격 진입하지 않음']);
                        }
                        // Limit buy after the confirmation close; fill only on a subsequent session.
                        $entry = (float) $bars[$j]['close'];
                        $stop = $retestLow - 0.2 * $atr;
                        $target = $level + ($level - $floor);
                        // Any known overhead high takes precedence over the measured-move projection.
                        foreach (array_slice($bars, 0, $j) as $old) {
                            if ($old['high'] > $entry + 0.1 * $atr && $old['high'] < $target) {
                                $target = (float) $old['high'];
                            }
                        }
                        $entry = PriceCandidate::trunc($entry);
                        $stop = PriceCandidate::trunc($stop);
                        $target = PriceCandidate::trunc($target);
                        $rr = $entry > $stop ? ($target - $entry) / ($entry - $stop) : 0.0;
                        $valid = $stop > 0 && $stop < $entry && $entry < $target && $rr >= self::MIN_RR;
                        return array_replace($plan, ['status' => $valid ? 'ready' : 'rejected_rr', 'ready' => $valid,
                            'reason' => $valid ? '재지지 후 고점 회복 확인. 다음 3개 거래봉 지정가 계획, 손익비 ' . round($rr, 2)
                                : '재지지 확인됐지만 첫 저항까지 손익비 부족: 진입 보류',
                            'entry' => $entry, 'stop' => $stop, 'target' => $target,
                            'reward_risk' => round($rr, 3), 'target_rule' => 'nearest_known_high_or_box_measured_move',
                            'signal_at' => $bars[$j]['available_at'], 'retest_at' => $bars[$retest]['available_at']]);
                    }
                }
                if ($retest === null && $bars[$j]['low'] <= $level + 0.35 * $atr
                    && $bars[$j]['close'] >= $level && $bars[$j]['volume'] < $bars[$i]['volume']) {
                    $retest = $j;
                    $retestLow = (float) $bars[$j]['low'];
                    $plan['status'] = 'await_confirmation';
                    $plan['reason'] = '재지지 관찰, 이후 봉의 재지지 봉 고점 회복 대기';
                }
            }
            return $plan;
        }
        return $base;
    }

    private function atr(array $bars): float
    {
        $tr = [];
        for ($i = max(1, count($bars) - 14); $i < count($bars); $i++) {
            $b = $bars[$i];
            $p = $bars[$i - 1]['close'];
            $tr[] = max($b['high'] - $b['low'], abs($b['high'] - $p), abs($b['low'] - $p));
        }
        return $tr === [] ? 0.0 : array_sum($tr) / count($tr);
    }
}
