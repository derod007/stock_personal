<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Causal implementation hypothesis; broken swing support is a proxy for drawn resistance. */
final class TrendRecovery
{
    public const VERSION = 'trend_recovery_v1';
    public const GATES = [
        'support_break'=>'높아지던 저점의 종가 이탈', 'reference_low'=>'이탈 후 낮은 저점 확정',
        'resistance_reclaimed'=>'고정 저항 위 종가 돌파', 'higher_low'=>'돌파 이후 높은 저점 확정',
        'rebound_close'=>'직전봉 고점·고정 저항 위 종가', 'bullish_candle'=>'재상승 양봉',
        'fresh_confirmation'=>'새 확인 신호', 'upper_target'=>'당시 확정된 상단 저항',
        'valid_levels'=>'진입·손절·목표 순서',
    ];

    /** Recovery supplies daily structure; a falling weekly trend still requires RR >= 2. */
    public static function contextBlocked(array $context, mixed $rr): bool
    {
        return ($context['weekly'] ?? '') === 'down' && (!is_numeric($rr) || (float)$rr < 2.0);
    }

    public function analyze(array $bars): array
    {
        $p = self::emptyPlan();
        $lows = []; $highs = []; $state = null;
        // Each pivot is admitted on its second right-hand bar, never backdated as a signal.
        foreach ($bars as $i => $bar) {
            $pivot = self::pivot($bars, $i);
            if ($pivot !== null) {
                if ($pivot['kind'] === 'low') $lows[] = $pivot;
                else $highs[] = $pivot;
            }
            $highs = array_values(array_filter($highs, static fn($h) => $bar['close'] <= $h['price']));
            if ($i < 59) continue;
            if ($state !== null && $state['terminal']) {
                if (isset($p['signal_at'])) {
                    $p['ready'] = false; $p['status'] = 'expired';
                    $p['reason'] = '과거 회복 확인 신호 · 신규 주문 신호 아님';
                    $p['gates']['fresh_confirmation'] = false;
                }
            }
            if ($state === null || $state['terminal']) {
                $n = count($lows);
                if ($n < 2) continue;
                $support = $lows[$n - 1]; $prior = $lows[$n - 2];
                $ma = array_sum(array_column(array_slice($bars, $i - 19, 20), 'close')) / 20;
                if ($support['confirmed_at'] >= $bar['available_at'] || $support['price'] <= $prior['price']
                    || $bars[$i - 1]['close'] < $support['price'] || $bar['close'] >= $support['price']
                    || $bar['close'] >= $ma) continue;
                $p = self::emptyPlan();
                $p['status'] = 'await_recovery'; $p['reason'] = '상승하던 저점 종가 이탈 · 낮은 저점과 저항 회복 대기';
                $p['gates']['support_break'] = true;
                $p['evidence'] = ['resistance'=>$support, 'prior_support'=>$prior,
                    'breakdown'=>['index'=>$i, 'at'=>$bar['available_at'], 'close'=>$bar['close']]];
                $state = ['start'=>$i, 'terminal'=>false, 'low'=>null, 'breakout'=>null, 'higher'=>null];
            }
            if ($state['terminal']) continue;
            if ($i - $state['start'] > 60) {
                $state['terminal'] = true; $p['status'] = 'expired';
                $p['reason'] = '저점 이탈 이후 60봉 안에 회복 패턴 미완성'; continue;
            }
            $resistance = $p['evidence']['resistance']['price'];
            if ($state['breakout'] === null) {
                if ($pivot !== null && $pivot['kind'] === 'low' && $pivot['index'] > $state['start']
                    && $pivot['price'] < $resistance
                    && ($state['low'] === null || $pivot['price'] < $state['low']['price'])) {
                    $state['low'] = $pivot; $p['evidence']['reference_low'] = $pivot;
                    $p['gates']['reference_low'] = true;
                }
                if ($state['low'] !== null) {
                    $p['gates']['resistance_reclaimed'] = $bar['close'] > $resistance;
                    if ($bar['close'] > $resistance) {
                        $state['breakout'] = $i;
                        $p['evidence']['breakout'] = ['index'=>$i, 'at'=>$bar['available_at'], 'close'=>$bar['close']];
                        $p['status'] = 'await_higher_low';
                        $p['reason'] = '저항 위 종가 회복 · 돌파 이후 높은 눌림 저점 확정 대기';
                    }
                }
                continue;
            }
            if ($bar['close'] < $state['low']['price']) {
                $state['terminal'] = true; $p['status'] = 'invalidated';
                $p['reason'] = '회복 후 기준 저점 아래 종가 이탈 · 패턴 무효'; continue;
            }
            // A higher low formed before the resistance breakout is observation only.
            if ($state['higher'] !== null && $bar['low'] <= $state['higher']['price']) {
                $state['higher'] = null; unset($p['evidence']['higher_low']);
                $p['gates']['higher_low'] = false;
                $p['status'] = 'await_higher_low'; $p['reason'] = '눌림 저점 재접촉 · 새 높은 저점 확정 대기';
            }
            if ($pivot !== null && $pivot['kind'] === 'low' && $pivot['index'] > $state['breakout']) {
                $p['gates']['higher_low'] = $pivot['price'] > $state['low']['price'];
                if ($p['gates']['higher_low']) {
                    $state['higher'] = $pivot; $p['evidence']['higher_low'] = $pivot;
                    $p['status'] = 'await_confirmation'; $p['reason'] = '돌파 후 높은 저점 확정 · 재상승 종가 확인 대기';
                }
            }
            if ($state['higher'] === null) continue;
            if ($i - $state['higher']['index'] > 10) {
                $state['terminal'] = true; $p['status'] = 'expired';
                $p['reason'] = '높은 저점 이후 10봉 안에 재상승 확인 없음'; continue;
            }
            $p['gates']['rebound_close'] = $bar['close'] > $bars[$i - 1]['high'] && $bar['close'] > $resistance;
            $p['gates']['bullish_candle'] = $bar['close'] > $bar['open'];
            if (!$p['gates']['rebound_close'] || !$p['gates']['bullish_candle']) continue;
            $state['terminal'] = true;
            $p['gates']['fresh_confirmation'] = true;
            $p['signal_at'] = $bar['available_at'];
            $p['entry'] = PriceCandidate::trunc((float)$bar['close']);
            $p['stop'] = PriceCandidate::trunc((float)$state['higher']['price']);
            $p['evidence']['confirmation'] = ['at'=>$bar['available_at'], 'close'=>$bar['close'], 'previous_high'=>$bars[$i - 1]['high']];
            $targets = array_values(array_filter($highs, static fn($h) => PriceCandidate::trunc((float)$h['price']) > $p['entry']));
            usort($targets, static fn($a, $b) => $a['price'] <=> $b['price']);
            $p['gates']['upper_target'] = $targets !== [];
            if ($targets === []) {
                $p['status'] = 'no_upper_target'; $p['reason'] = '회복 확인 · 저장 범위에 확정된 상단 저항이 없어 추천 보류'; continue;
            }
            $p['evidence']['target_source'] = $targets[0];
            $p['target'] = PriceCandidate::trunc((float)$targets[0]['price']);
            $p['candidate'] = PriceCandidate::build($p['entry'], $p['entry'], $p['stop'], $p['target'], self::VERSION);
            $p['gates']['valid_levels'] = $p['candidate'] !== null;
            if ($p['candidate'] === null) {
                $p['status'] = 'invalid_levels'; $p['reason'] = '회복 확인 · 유효한 진입·손절·목표 가격 없음'; continue;
            }
            $rr = ($p['target'] - $p['entry']) / ($p['entry'] - $p['stop']);
            $p['reward_risk'] = $rr; // Keep exact RR for the weekly threshold; format only in the view.
            $p['ready'] = $rr >= 1.5;
            $p['status'] = $p['ready'] ? 'ready' : 'rejected_rr';
            $p['reason'] = $p['ready'] ? '저항 종가 돌파 후 높은 저점·재상승 확인'
                : '회복 확인됐지만 가장 가까운 상단 저항까지 손익비 부족';
        }
        return $p;
    }

    private static function emptyPlan(): array
    {
        return ['version'=>self::VERSION, 'status'=>'no_setup', 'ready'=>false,
            'reason'=>'저점 이탈 후 회복 구조 대기', 'entry'=>null, 'stop'=>null, 'target'=>null,
            'reward_risk'=>null, 'candidate'=>null, 'order_valid_bars'=>3,
            'target_rule'=>'nearest_confirmed_unbroken_pivot_high', 'stop_rule'=>'confirmed_post_breakout_low',
            'context_policy'=>'recovery_structure_weekly_rr2', 'gates'=>[], 'evidence'=>[]];
    }

    private static function pivot(array $bars, int $i): ?array
    {
        if ($i < 4) return null;
        $j = $i - 2; $low = true; $high = true;
        foreach ([-2, -1, 1, 2] as $offset) {
            $low = $low && $bars[$j]['low'] < $bars[$j + $offset]['low'];
            $high = $high && $bars[$j]['high'] > $bars[$j + $offset]['high'];
        }
        if ($low === $high) return null; // Outside bar or plateau: no invented intrabar order.
        return ['kind'=>$low ? 'low' : 'high', 'index'=>$j,
            'price'=>(float)$bars[$j][$low ? 'low' : 'high'], 'at'=>$bars[$j]['available_at'],
            'confirmed_at'=>$bars[$i]['available_at']];
    }
}
