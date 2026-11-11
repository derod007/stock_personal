<?php

declare(strict_types=1);

namespace ChartEntryLab;

/** Read-only daily diagnosis. Not a recommendation and not the author's recorded trades. */
final class SwingResearch
{
    public const VERSION = 'swing_retest_read_v2';

    public static function diagnose(array $bars, string $symbol, int $asOf): array
    {
        $done = CandleClock::completed($bars, $symbol, $asOf);
        $trend = (new TrendContext())->analyze($done, $symbol, $asOf);
        $out = [
            'version' => self::VERSION, 'symbol' => $symbol, 'judged_at' => $asOf,
            'as_of_day' => $done === [] ? null : self::day($done[count($done) - 1], $symbol),
            'breakout_level' => null, 'retest_status' => '자료 부족',
            'confirm_day' => null, 'entry' => null, 'resistance_as_of_day' => null,
            'entry_condition' => '완료된 일봉이 5개 미만입니다.', 'invalidation' => null,
            'next_resistance' => null, 'resistance_basis' => '진입 참고가가 없습니다.',
            'reward_risk' => null, 'wait_reason' => '자료 부족',
            'current_status' => null, 'current_resistance' => null,
            'stock_trend' => $trend['label'], 'market_context' => '자료 없음', 'score' => null,
        ];
        if (count($done) < 5) {
            return $out;
        }
        $pivots = [];
        for ($i = 2; $i <= count($done) - 3; $i++) {
            $high = (float) $done[$i]['high'];
            $pivot = true;
            foreach ([-2, -1, 1, 2] as $j) {
                if ($high <= (float) $done[$i + $j]['high']) {
                    $pivot = false;
                    break;
                }
            }
            if ($pivot) {
                $pivots[] = $i;
            }
        }
        $breakout = null;
        foreach ($pivots as $pivot) {
            $level = (float) $done[$pivot]['high'];
            for ($j = $pivot + 1; $j < count($done); $j++) {
                if ((float) $done[$j]['close'] > $level) {
                    $breakout = [$pivot, $j];
                    break;
                }
            }
        }
        if ($breakout === null) {
            $out['retest_status'] = '돌파 없음';
            $out['entry_condition'] = '종가가 넘은 이전 고점이 없습니다.';
            $out['wait_reason'] = '돌파 대기';
            return $out;
        }
        [$pivot, $at] = $breakout;
        $level = (float) $done[$pivot]['high'];
        $out['breakout_level'] = $level;
        $out['invalidation'] = $level;
        $retest = null;
        $confirm = null;
        $brokeAfter = false;
        for ($j = $at + 1; $j < count($done); $j++) {
            $close = (float) $done[$j]['close'];
            if ($close < $level) {
                if ($confirm === null) {
                    $out['retest_status'] = '지지 이탈';
                    $out['entry_condition'] = '돌파 기준선 아래로 종가가 마감했습니다.';
                    $out['wait_reason'] = '이번 전망은 무효';
                    return $out;
                }
                $brokeAfter = true;
                break;
            }
            if ($confirm !== null) {
                continue;
            }
            if ($retest === null && (float) $done[$j]['low'] <= $level && $close >= $level) {
                $retest = $j;
                continue;
            }
            if ($retest !== null && $close > (float) $done[$retest]['high']) {
                $confirm = $j;
            }
        }
        if ($confirm === null) {
            $out['retest_status'] = $retest === null ? '돌파만 발생' : '확인 대기';
            $out['entry_condition'] = $retest === null
                ? '재시험 전입니다. 돌파 봉의 저가 체결은 가정하지 않습니다.'
                : '재시험 봉의 고가를 종가가 넘기 전입니다.';
            $out['wait_reason'] = $retest === null ? '재시험 대기' : '지지 확인 대기';
            return $out;
        }
        $entry = (float) $done[$confirm]['close'];
        $signal = array_slice($done, 0, $confirm + 1);
        $out['entry'] = $entry;
        $out['confirm_day'] = self::day($done[$confirm], $symbol);
        $out['resistance_as_of_day'] = $out['confirm_day'];
        $out['entry_condition'] = '확인 종가 다음 거래일부터입니다. 확인 봉의 저가 체결은 가정하지 않습니다.';
        $near = self::nearest($signal, $symbol, (int) $done[$confirm]['available_at'], $entry, $level);
        if ($near === null) {
            $out['retest_status'] = '확인';
            $out['resistance_basis'] = count($signal) < 21
                ? '저항 계산에는 확인일까지 완료 일봉 21개가 필요합니다.'
                : '확인일까지 진입 참고가 위에 아직 종가로 넘지 않은 고점이 없습니다.';
            $out['wait_reason'] = '다음 저항 미확정';
        } else {
            $reward = $near - $entry;
            $risk = $entry - $level;
            $out['next_resistance'] = $near;
            $out['resistance_basis'] = '확인일까지 저장된 고점 중 진입 참고가 위에서 종가로 넘지 않은 가장 가까운 고점';
            $out['reward_risk'] = $risk > 0 ? $reward / $risk : null;
            // 1.5 is the older research cutoff. This screen waits when reward does not clear the invalidation distance.
            if ($risk <= 0 || $reward <= $risk) {
                $out['retest_status'] = '보상 여유 없음';
                $out['wait_reason'] = '손익비가 1 이하이면 대기합니다.';
            } else {
                $out['retest_status'] = '확인';
                $out['wait_reason'] = '손익비가 1보다 큽니다. 추천 점수는 아닙니다.';
            }
        }
        $live = self::nearest($done, $symbol, (int) $done[count($done) - 1]['available_at'], $entry, $level);
        $out['current_resistance'] = $live;
        $out['current_status'] = $brokeAfter ? '지지 이탈'
            : ($live === null && $out['next_resistance'] !== null ? '저항 소멸'
            : ($live != $out['next_resistance'] ? '새 저항' : '변화 없음'));
        return $out;
    }

    private static function nearest(array $bars, string $symbol, int $session, float $entry, float $stop): ?float
    {
        require_once dirname(__DIR__) . '/bin/paper/TargetResearch.php';
        $found = \PaperTargetResearch::inspect($bars, $symbol, $session, $entry, $stop, $entry + 1000.0);
        $price = $found['nearest_upper_resistance']['price'] ?? null;
        return is_numeric($price) ? (float) $price : null;
    }

    private static function day(array $bar, string $symbol): string
    {
        $kr = str_ends_with($symbol, '.KS') || str_ends_with($symbol, '.KQ');
        $tz = new \DateTimeZone($kr ? 'Asia/Seoul' : 'America/New_York');
        return (new \DateTimeImmutable('@' . $bar['available_at']))->setTimezone($tz)->format('Y-m-d');
    }

    public static function page(?array $diagnosis): string
    {
        $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $rows = '';
        if ($diagnosis !== null) {
            $fields = [
                '종목' => $diagnosis['symbol'], '판단 시각' => (string) $diagnosis['judged_at'],
                '자료 기준일' => (string) ($diagnosis['as_of_day'] ?? '없음'),
                '돌파 기준선' => self::num($diagnosis['breakout_level']),
                '재시험 상태' => $diagnosis['retest_status'],
                '확인일' => (string) ($diagnosis['confirm_day'] ?? '없음'),
                '진입 조건' => $diagnosis['entry_condition'],
                '진입 참고가' => self::num($diagnosis['entry']),
                '무효화 가격' => self::num($diagnosis['invalidation']),
                '저항 계산 기준일' => (string) ($diagnosis['resistance_as_of_day'] ?? '없음'),
                '신호 당시 저항' => self::num($diagnosis['next_resistance']),
                '저항 근거' => $diagnosis['resistance_basis'],
                '신호 당시 손익비' => $diagnosis['reward_risk'] === null ? '없음' : (string) round($diagnosis['reward_risk'], 3),
                '대기 사유' => $diagnosis['wait_reason'], '개별 추세' => $diagnosis['stock_trend'],
                '시장 지수' => $diagnosis['market_context'],
            ];
            foreach ($fields as $name => $value) {
                $rows .= '<tr><th scope="row">' . $h($name) . '</th><td>' . $h((string) $value) . '</td></tr>';
            }
            if ($diagnosis['confirm_day'] !== null) {
                $rows .= '</tbody></table><h2>현재 상태</h2><table><tbody>';
                foreach ([
                    '현재 상태' => (string) ($diagnosis['current_status'] ?? '없음'),
                    '현재 저항' => self::num($diagnosis['current_resistance']),
                ] as $name => $value) {
                    $rows .= '<tr><th scope="row">' . $h($name) . '</th><td>' . $h($value) . '</td></tr>';
                }
            }
        }
        $body = $diagnosis === null
            ? '<p class="notice">가격 자료가 없습니다. 개별 일봉, 시장 지수, 외국인 선물, 옵션 미결제 자료는 이 화면에 연결되어 있지 않습니다. 없는 값은 미확정이며, 이 조회는 파일을 만들지 않습니다.</p>'
            : ($diagnosis['confirm_day'] !== null ? '<h2>신호 당시</h2>' : '') . '<table><tbody>' . $rows . '</tbody></table>';
        return '<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8"><title>스윙 연구</title>'
            . '<link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/readability.css">'
            . '</head><body><nav class="tabs" aria-label="화면"><a class="tabs__link" href="index.php">차트</a>'
            . '<a class="tabs__link" href="type1_scan.php">거래대금 스캔(유형1)</a>'
            . '<a class="tabs__link is-active" href="swing_research.php" aria-current="page">스윙 연구</a>'
            . '<a class="tabs__link" href="paper.php">모의 계좌</a></nav>'
            . '<h1>스윙 연구</h1><p>연구 단계입니다. 돌파 뒤 재시험과 지지 확인만 진단합니다. 신호 당시 손익비는 확인일까지 알려진 자료만 사용하고, 손익비가 1 이하이면 대기합니다. 확인 이후의 지지 이탈과 새 저항은 현재 상태에만 표시합니다. 추천 점수나 자동 주문이 아닙니다.</p>'
            . $body . '</body></html>';
    }

    private static function num(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '없음';
    }
}
