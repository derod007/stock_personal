<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 여러 번 반복해서 닿은 가로 레벨(매물대) 탐지.
 *
 * 노라무 «대원전선 종가베팅»(fm-10194043747)의 “넓게 보면 13400 이탈만 안 하면 됩니다”처럼,
 * 스윙 저점 하나가 아니라 **같은 가격대에 몇 번 걸렸는지**로 지지·저항을 잡는다.
 *
 * 접촉 판정은 시/고/저/종이 밴드 안에 들어온 경우이며,
 * 연속 봉은 한 번의 접촉(이벤트)으로 묶어 과대 계산을 막는다.
 */
final class HorizontalLevels
{
    /** 밴드 폭(±%) */
    private const DEFAULT_TOLERANCE_PCT = 0.7;

    /** 접촉 이벤트로 인정하려면 이만큼 떨어졌다 와야 한다(봉) */
    private const EVENT_GAP_BARS = 2;

    /**
     * @param list<array{time:int,time_kst:string,open:float,high:float,low:float,close:float,volume:int}> $candles
     * @return array{
     *   levels:list<array<string,mixed>>,
     *   support:?array<string,mixed>,
     *   support_nearest:?array<string,mixed>,
     *   resistance:?array<string,mixed>,
     *   note:string
     * }
     */
    public function analyze(
        array $candles,
        ?float $price = null,
        int $minTouches = 3,
        float $tolerancePct = self::DEFAULT_TOLERANCE_PCT,
        int $lookback = 120,
        int $maxLevels = 6,
    ): array {
        $window = array_slice($candles, -max(30, $lookback));
        $n = count($window);
        if ($n < 20) {
            return $this->emptyResult('봉 부족');
        }

        $price ??= (float) $window[$n - 1]['close'];
        if ($price <= 0) {
            return $this->emptyResult('가격 없음');
        }

        $tol = max(0.1, $tolerancePct) / 100;

        $levels = [];
        foreach ($this->candidateCenters($window, $tol) as $center) {
            $touch = $this->countTouches($window, $center, $tol);
            if ($touch['touches'] < $minTouches) {
                continue;
            }
            $levels[] = $this->describe($center, $touch, $price, $window);
        }

        $levels = $this->dedupe($levels, $tol);
        usort($levels, static fn(array $a, array $b): int => $b['strength'] <=> $a['strength']);
        $levels = array_slice($levels, 0, $maxLevels);

        $supports = array_values(array_filter($levels, static fn(array $l): bool => $l['role'] === 'support'));
        $resistances = array_values(array_filter($levels, static fn(array $l): bool => $l['role'] === 'resistance'));

        // 지지는 “가장 최근에 지켜진 자리”를 먼저 본다 (노라무가 넓게 잡는 손절선)
        usort($supports, static function (array $a, array $b): int {
            $ra = $a['bars_since'] ?? 9999;
            $rb = $b['bars_since'] ?? 9999;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return $b['strength'] <=> $a['strength'];
        });
        usort($resistances, static fn(array $a, array $b): int => $a['price'] <=> $b['price']);

        $support = $supports[0] ?? null;
        $supportNearest = null;
        foreach ($supports as $lv) {
            if ($supportNearest === null || $lv['price'] > $supportNearest['price']) {
                $supportNearest = $lv;
            }
        }

        return [
            'levels' => $levels,
            'support' => $support,
            'support_nearest' => $supportNearest,
            'resistance' => $resistances[0] ?? null,
            'note' => $this->note($support, $resistances[0] ?? null),
        ];
    }

    /**
     * @return array{levels:list<array<string,mixed>>,support:null,support_nearest:null,resistance:null,note:string}
     */
    private function emptyResult(string $why): array
    {
        return [
            'levels' => [],
            'support' => null,
            'support_nearest' => null,
            'resistance' => null,
            'note' => '가로 레벨: ' . $why,
        ];
    }

    /**
     * 시/고/저/종을 각각 후보 중심으로 쓴다.
     * (구간을 미리 나누면 13,400 같은 실제 접촉가가 경계에서 갈라지므로,
     *  가격점 자체를 중심으로 놓고 접촉 수로 경쟁시킨 뒤 dedupe에서 정리한다.)
     *
     * @param list<array{open:float,high:float,low:float,close:float}> $window
     * @return list<float>
     */
    private function candidateCenters(array $window, float $tol): array
    {
        $points = [];
        foreach ($window as $bar) {
            foreach (['open', 'high', 'low', 'close'] as $key) {
                $v = (float) $bar[$key];
                if ($v > 0) {
                    $points[] = $v;
                }
            }
        }
        if ($points === []) {
            return [];
        }
        sort($points);

        // 거의 같은 값은 하나만 남겨 계산량을 줄인다
        $centers = [$points[0]];
        $count = count($points);
        for ($i = 1; $i < $count; $i++) {
            $lastKept = $centers[count($centers) - 1];
            if (($points[$i] - $lastKept) / max($lastKept, 0.0001) > $tol / 3) {
                $centers[] = $points[$i];
            }
        }

        return $centers;
    }

    /**
     * 밴드 접촉 봉을 찾되 연속 봉은 한 이벤트로 묶는다.
     *
     * @param list<array{time_kst:string,open:float,high:float,low:float,close:float}> $window
     * @return array{touches:int,first_idx:?int,last_idx:?int,as_resistance:int,as_support:int,first_role:?string,last_role:?string}
     */
    private function countTouches(array $window, float $center, float $tol): array
    {
        $low = $center * (1 - $tol);
        $high = $center * (1 + $tol);

        $touches = 0;
        $firstIdx = null;
        $lastIdx = null;
        $prevIdx = null;
        $asResistance = 0;
        $asSupport = 0;
        $firstRole = null;
        $lastRole = null;

        foreach ($window as $i => $bar) {
            $hit = false;
            foreach (['open', 'high', 'low', 'close'] as $key) {
                $v = (float) $bar[$key];
                if ($v >= $low && $v <= $high) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }

            $isNewEvent = $prevIdx === null || ($i - $prevIdx) > self::EVENT_GAP_BARS;
            $prevIdx = $i;
            $lastIdx = $i;
            if (!$isNewEvent) {
                continue;
            }

            $touches++;
            $firstIdx ??= $i;

            // 해당 봉이 이 레벨을 위에서 막혔는지(저항) 아래에서 지켰는지(지지)
            $role = (float) $bar['close'] < $center ? 'resistance' : 'support';
            if ($role === 'resistance') {
                $asResistance++;
            } else {
                $asSupport++;
            }
            $firstRole ??= $role;
            $lastRole = $role;
        }

        return [
            'touches' => $touches,
            'first_idx' => $firstIdx,
            'last_idx' => $lastIdx,
            'as_resistance' => $asResistance,
            'as_support' => $asSupport,
            'first_role' => $firstRole,
            'last_role' => $lastRole,
        ];
    }

    /**
     * @param array{touches:int,first_idx:?int,last_idx:?int,as_resistance:int,as_support:int,first_role:?string,last_role:?string} $touch
     * @return array{price:float,touches:int,role:string,flip:bool,first_kst:?string,last_kst:?string,bars_since:?int,strength:float}
     */
    private function describe(float $center, array $touch, float $price, array $window): array
    {
        $n = count($window);
        $barsSince = $touch['last_idx'] !== null ? ($n - 1 - $touch['last_idx']) : null;
        $role = $center < $price * 0.999
            ? 'support'
            : ($center > $price * 1.001 ? 'resistance' : 'at_price');

        // 저항으로 여러 번 막히다 나중에 지지로 바뀐 자리 = 역할 전환
        $flip = $touch['as_resistance'] >= 1
            && $touch['first_role'] === 'resistance'
            && $touch['last_role'] === 'support';

        $strength = (float) $touch['touches'];
        if ($flip) {
            $strength += 1.5;
        }
        if ($barsSince !== null && $barsSince <= 10) {
            $strength += 1.0;
        }
        $distPct = abs(($price - $center) / $price) * 100;
        if ($distPct <= 10.0) {
            $strength += 0.5;
        }

        return [
            'price' => round($center, 4),
            'touches' => $touch['touches'],
            'role' => $role,
            'flip' => $flip,
            'first_kst' => $touch['first_idx'] !== null
                ? substr((string) ($window[$touch['first_idx']]['time_kst'] ?? ''), 0, 10)
                : null,
            'last_kst' => $touch['last_idx'] !== null
                ? substr((string) ($window[$touch['last_idx']]['time_kst'] ?? ''), 0, 10)
                : null,
            'bars_since' => $barsSince,
            'strength' => round($strength, 2),
        ];
    }

    /**
     * @param list<array{price:float,touches:int,role:string,flip:bool,first_kst:?string,last_kst:?string,bars_since:?int,strength:float}> $levels
     * @return list<array{price:float,touches:int,role:string,flip:bool,first_kst:?string,last_kst:?string,bars_since:?int,strength:float}>
     */
    private function dedupe(array $levels, float $tol): array
    {
        usort($levels, static fn(array $a, array $b): int => $b['strength'] <=> $a['strength']);
        $out = [];
        foreach ($levels as $lv) {
            $dup = false;
            foreach ($out as $kept) {
                if (abs($lv['price'] - $kept['price']) / max($kept['price'], 0.0001) <= $tol * 2) {
                    $dup = true;
                    break;
                }
            }
            if (!$dup) {
                $out[] = $lv;
            }
        }

        return $out;
    }

    /**
     * @param array{price:float,touches:int,flip:bool}|null $support
     * @param array{price:float,touches:int,flip:bool}|null $resistance
     */
    private function note(?array $support, ?array $resistance): string
    {
        $bits = [];
        if ($support !== null) {
            $bits[] = sprintf(
                '지지 %s (%d회 접촉%s)',
                $this->fmt($support['price']),
                $support['touches'],
                $support['flip'] ? '·저항→지지 전환' : ''
            );
        }
        if ($resistance !== null) {
            $bits[] = sprintf('저항 %s (%d회 접촉)', $this->fmt($resistance['price']), $resistance['touches']);
        }

        return $bits === [] ? '가로 레벨: 반복 접촉 자리 없음' : '가로 레벨: ' . implode(' · ', $bits);
    }

    private function fmt(float $v): string
    {
        if (abs($v) >= 1000) {
            return number_format($v, 0, '.', ',');
        }

        return number_format($v, 2, '.', ',');
    }

    /**
     * @param list<float> $values
     */
    private function median(array $values): float
    {
        sort($values);
        $c = count($values);
        if ($c === 0) {
            return 0.0;
        }
        $mid = intdiv($c, 2);

        return $c % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
