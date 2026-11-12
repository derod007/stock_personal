<?php

declare(strict_types=1);

namespace ChartEntryLab;

/** Read-only daily diagnosis. Not a recommendation and not the author's recorded trades. */
final class SwingResearch
{
    public const VERSION = 'swing_retest_read_v2';

    public static function diagnose(array $bars, string $symbol, int $asOf, ?int $currentAsOf = null): array
    {
        $done = CandleClock::completed($bars, $symbol, $asOf);
        $later = $currentAsOf !== null && $currentAsOf > $asOf ? CandleClock::completed($bars, $symbol, $currentAsOf) : $done;
        $trend = (new TrendContext())->analyze($done, $symbol, $asOf);
        $out = [
            'version' => self::VERSION, 'symbol' => $symbol, 'judged_at' => $asOf,
            'as_of_day' => $done === [] ? null : self::day($done[count($done) - 1], $symbol),
            'current_as_of_day' => $later === [] ? null : self::day($later[count($later) - 1], $symbol),
            'breakout_level' => null, 'breakout_day' => null, 'retest_day' => null, 'break_day' => null,
            'retest_status' => '자료 부족',
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
        $out['breakout_day'] = self::day($done[$at], $symbol);
        $out['invalidation'] = $level;
        $retest = null;
        $confirm = null;
        $brokeAfter = false;
        for ($j = $at + 1; $j < count($done); $j++) {
            $close = (float) $done[$j]['close'];
            if ($close < $level) {
                if ($confirm === null) {
                    if ($retest !== null) {
                        $out['retest_day'] = self::day($done[$retest], $symbol);
                    }
                    $out['break_day'] = self::day($done[$j], $symbol);
                    $out['retest_status'] = '지지 이탈';
                    $out['entry_condition'] = '돌파 기준선 아래로 종가가 마감했습니다.';
                    $out['wait_reason'] = '이번 전망은 무효';
                    return $out;
                }
                $brokeAfter = true;
                $out['break_day'] = self::day($done[$j], $symbol);
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
        if ($retest !== null) {
            $out['retest_day'] = self::day($done[$retest], $symbol);
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
        if ($later !== $done) {
            $end = (int) $done[count($done) - 1]['available_at'];
            foreach ($later as $bar) {
                if ((int) $bar['available_at'] <= $end) {
                    continue;
                }
                if ((float) $bar['close'] < $level) {
                    $brokeAfter = true;
                    $out['break_day'] ??= self::day($bar, $symbol);
                    break;
                }
            }
        }
        $cursor = $later[count($later) - 1];
        $live = self::nearest($later, $symbol, (int) $cursor['available_at'], $entry, $level);
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

    /**
     * Each session uses only bars closed by that session. Same breakout day and level stay one signal.
     *
     * @return array{error:?string,basis:string,identity:string,signals:list<array<string,mixed>>,days:list<array<string,mixed>>}
     */
    public static function replay(array $bars, string $symbol, string $from, string $to): array
    {
        $basis = '현재 캐시로 과거 거래일을 다시 계산한 결과입니다. 당시 저장했던 판단 기록이 아닙니다.';
        $identity = '신호는 돌파 기준선과 그 선을 종가가 처음 넘은 날로 구분합니다. 같은 기준선이 같은 돌파일로 이어지면 하루마다 다시 세지 않습니다.';
        $empty = ['error' => '시작일과 종료일을 확인해 주세요.', 'basis' => $basis, 'identity' => $identity, 'signals' => [], 'days' => []];
        if (!self::realDay($from) || !self::realDay($to) || $from > $to) {
            return $empty;
        }
        $kr = str_ends_with($symbol, '.KS') || str_ends_with($symbol, '.KQ');
        $tz = new \DateTimeZone($kr ? 'Asia/Seoul' : 'America/New_York');
        $cursor = new \DateTimeImmutable($from . ' 12:00:00', $tz);
        $end = new \DateTimeImmutable($to . ' 12:00:00', $tz);
        $signals = [];
        $index = [];
        $days = [];
        while ($cursor <= $end) {
            $day = $cursor->format('Y-m-d');
            $week = (int) $cursor->format('N');
            $cursor = $cursor->modify('+1 day');
            if ($week >= 6) {
                continue;
            }
            $asOf = self::closeOf($day, $symbol);
            $done = CandleClock::completed($bars, $symbol, $asOf);
            $present = false;
            foreach ($done as $bar) {
                if (self::day($bar, $symbol) === $day) {
                    $present = true;
                    break;
                }
            }
            if (!$present) {
                $days[] = ['day' => $day, 'status' => '미확정', 'diagnosis' => null];
                continue;
            }
            $diagnosis = self::diagnose($bars, $symbol, $asOf);
            $days[] = ['day' => $day, 'status' => $diagnosis['retest_status'], 'diagnosis' => $diagnosis];
            if ($diagnosis['breakout_level'] !== null && $diagnosis['breakout_day'] !== null) {
                $id = $diagnosis['breakout_level'] . '|' . $diagnosis['breakout_day'];
                if (!isset($index[$id])) {
                    $index[$id] = count($signals);
                    $signals[] = [
                        'id' => $id, 'breakout_day' => $diagnosis['breakout_day'], 'breakout_level' => $diagnosis['breakout_level'],
                        'retest_day' => null, 'retest_price' => null, 'confirm_day' => null, 'entry' => null,
                        'resistance' => null, 'reward_risk' => null, 'resistance_as_of_day' => null,
                        'break_day' => null, 'break_price' => null, 'locked' => false,
                    ];
                }
                $slot = &$signals[$index[$id]];
                if ($slot['retest_day'] === null && $diagnosis['retest_day'] !== null) {
                    $slot['retest_day'] = $diagnosis['retest_day'];
                    $slot['retest_price'] = $diagnosis['breakout_level'];
                }
                if ($slot['locked'] === false && $diagnosis['confirm_day'] !== null) {
                    $slot['locked'] = true;
                    $slot['confirm_day'] = $diagnosis['confirm_day'];
                    $slot['entry'] = $diagnosis['entry'];
                    $slot['resistance'] = $diagnosis['next_resistance'];
                    $slot['reward_risk'] = $diagnosis['reward_risk'];
                    $slot['resistance_as_of_day'] = $diagnosis['resistance_as_of_day'];
                }
                if ($slot['break_day'] === null && $diagnosis['break_day'] !== null) {
                    $slot['break_day'] = $diagnosis['break_day'];
                    $slot['break_price'] = $diagnosis['invalidation'];
                }
                unset($slot);
            }
            foreach ($signals as &$open) {
                if ($open['break_day'] !== null) {
                    continue;
                }
                foreach ($done as $bar) {
                    if (self::day($bar, $symbol) <= $open['breakout_day']) {
                        continue;
                    }
                    if ((float) $bar['close'] < (float) $open['breakout_level']) {
                        $open['break_day'] = self::day($bar, $symbol);
                        $open['break_price'] = $open['breakout_level'];
                        break;
                    }
                }
            }
            unset($open);
        }
        return ['error' => null, 'basis' => $basis, 'identity' => $identity, 'signals' => $signals, 'days' => $days];
    }

    /** Stored Yahoo daily cache only. fetch() writes and can fill from another source. */
    public static function view(string $dir, array $query): string
    {
        $symbols = self::symbols($dir);
        $symbol = strtoupper(trim((string) ($query['symbol'] ?? '')));
        $day = trim((string) ($query['day'] ?? ''));
        $mode = (string) ($query['mode'] ?? '');
        $form = ['symbols' => $symbols, 'symbol' => $symbol, 'day' => $day, 'from' => trim((string) ($query['from'] ?? '')), 'to' => trim((string) ($query['to'] ?? '')), 'mode' => $mode, 'note' => null, 'replay' => null];
        if ($symbol === '') {
            return self::page(null, $form);
        }
        if (!in_array($symbol, $symbols, true)) {
            $form['note'] = '저장 일봉이 없습니다. 없는 종목은 보충하지 않습니다.';
            return self::page(null, $form);
        }
        $loaded = self::readStored($dir, $symbol);
        if ($loaded['error'] !== null) {
            $form['note'] = $loaded['error'];
            return self::page(null, $form);
        }
        $dropped = $loaded['dropped'] > 0
            ? '저장 봉 중 완료로 쓰지 않은 봉이 ' . $loaded['dropped'] . '개입니다. 보충하지 않습니다.'
            : null;
        if ($mode === 'replay') {
            if (!self::realDay($form['from']) || !self::realDay($form['to'])) {
                $form['note'] = '재생은 시작일과 종료일이 필요합니다.';
                return self::page(null, $form);
            }
            $form['note'] = $dropped;
            $form['replay'] = self::replay($loaded['bars'], $symbol, $form['from'], $form['to']);
            return self::page(null, $form);
        }
        if (!self::realDay($day)) {
            $form['note'] = '판단일을 고르면 그 날까지 알려진 봉만으로 진단합니다. 저장 기간 ' . $loaded['from'] . '~' . $loaded['to'] . '.';
            return self::page(null, $form);
        }
        $asOf = self::closeOf($day, $symbol);
        $last = CandleClock::closeTime($loaded['bars'][count($loaded['bars']) - 1], $symbol);
        $form['note'] = $dropped;
        return self::page(self::diagnose($loaded['bars'], $symbol, $asOf, $last), $form);
    }

    public static function page(?array $diagnosis, array $form = []): string
    {
        $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $rows = '';
        if ($diagnosis !== null) {
            $fields = [
                '종목' => $diagnosis['symbol'], '판단 시각' => (string) $diagnosis['judged_at'],
                '자료 기준일' => (string) ($diagnosis['as_of_day'] ?? '없음'),
                '현재 자료 기준일' => (string) ($diagnosis['current_as_of_day'] ?? '없음'),
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
            ? (is_array($form['replay'] ?? null) ? '' : '<p class="notice">가격 자료가 없습니다. 개별 일봉, 시장 지수, 외국인 선물, 옵션 미결제 자료는 이 화면에 연결되어 있지 않습니다. 없는 값은 미확정이며, 이 조회는 파일을 만들지 않습니다.</p>')
            : ($diagnosis['confirm_day'] !== null ? '<h2>신호 당시</h2>' : '') . '<table><tbody>' . $rows . '</tbody></table>';
        $chooser = '';
        if (isset($form['symbols'])) {
            $options = '<option value="">선택</option>';
            foreach ($form['symbols'] as $name) {
                $options .= '<option' . ($name === ($form['symbol'] ?? '') ? ' selected' : '') . '>' . $h($name) . '</option>';
            }
            $chooser = '<form method="get"><label>종목 <select name="symbol">' . $options . '</select></label> '
                . '<label>판단일 <input type="date" name="day" value="' . $h((string) ($form['day'] ?? '')) . '"></label> '
                . '<button type="submit" name="mode" value="diagnose">진단</button> '
                . '<label>시작일 <input type="date" name="from" value="' . $h((string) ($form['from'] ?? '')) . '"></label> '
                . '<label>종료일 <input type="date" name="to" value="' . $h((string) ($form['to'] ?? '')) . '"></label> '
                . '<button type="submit" name="mode" value="replay">재생</button></form>';
            if (($form['mode'] ?? '') === 'replay') {
                $chooser .= '<p>실행: 재생. 각 날짜는 그 날 마감까지의 봉만 사용합니다.</p>';
            } elseif (($form['mode'] ?? '') === 'diagnose') {
                $chooser .= '<p>실행: 진단. 판단일까지의 신호와, 저장 마지막 봉까지의 현재 상태를 보여 줍니다.</p>';
            }
            if (($form['note'] ?? null) !== null) {
                $chooser .= '<p class="notice">' . $h((string) $form['note']) . '</p>';
            }
            if (is_array($form['replay'] ?? null)) {
                $chooser .= self::replayHtml($form['replay'], $h);
            }
        }
        return '<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8"><title>스윙 연구</title>'
            . '<link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/readability.css">'
            . '</head><body><nav class="tabs" aria-label="화면"><a class="tabs__link" href="index.php">차트</a>'
            . '<a class="tabs__link" href="type1_scan.php">거래대금 스캔(유형1)</a>'
            . '<a class="tabs__link is-active" href="swing_research.php" aria-current="page">스윙 연구</a>'
            . '<a class="tabs__link" href="paper.php">모의 계좌</a></nav>'
            . '<h1>스윙 연구</h1><p>연구 단계입니다. 돌파 뒤 재시험과 지지 확인만 진단합니다. 신호 당시 손익비는 확인일까지 알려진 자료만 사용하고, 손익비가 1 이하이면 대기합니다. 확인 이후의 지지 이탈과 새 저항은 현재 상태에만 표시합니다. 추천 점수나 자동 주문이 아닙니다.</p>'
            . $chooser . $body . '</body></html>';
    }

    /** @param callable(string): string $h */
    private static function replayHtml(array $replay, callable $h): string
    {
        $html = '<h2>날짜순 재생</h2><p>' . $h((string) $replay['basis']) . '</p><p>' . $h((string) $replay['identity']) . '</p>';
        if ($replay['error'] !== null) {
            return $html . '<p class="notice">' . $h((string) $replay['error']) . '</p>';
        }
        $rows = '';
        foreach ($replay['signals'] as $signal) {
            $rows .= '<tr><td>' . $h((string) $signal['breakout_day']) . '</td><td>' . $h(self::num($signal['breakout_level'])) . '</td>'
                . '<td>' . $h((string) ($signal['retest_day'] ?? '없음')) . '</td><td>' . $h(self::num($signal['retest_price'])) . '</td>'
                . '<td>' . $h((string) ($signal['confirm_day'] ?? '없음')) . '</td><td>' . $h(self::num($signal['entry'])) . '</td>'
                . '<td>' . $h(self::num($signal['resistance'])) . '</td><td>' . $h($signal['reward_risk'] === null ? '없음' : (string) round((float) $signal['reward_risk'], 3)) . '</td>'
                . '<td>' . $h((string) ($signal['break_day'] ?? '없음')) . '</td><td>' . $h(self::num($signal['break_price'])) . '</td></tr>';
        }
        $html .= '<table><thead><tr><th>돌파일</th><th>돌파 기준선</th><th>재시험일</th><th>재시험 기준가</th><th>확인일</th><th>진입 참고가</th><th>당시 저항</th><th>당시 손익비</th><th>이탈일</th><th>이탈 기준가</th></tr></thead><tbody>'
            . ($rows === '' ? '<tr><td colspan="10">이 구간의 돌파 신호가 없습니다.</td></tr>' : $rows) . '</tbody></table>';
        $open = [];
        foreach ($replay['days'] as $row) {
            if ($row['status'] === '미확정') {
                $open[] = $row['day'];
            }
        }
        return $html . '<p>거래일인지 알 수 없는 평일: ' . ($open === [] ? '없음' : $h(implode(', ', $open))) . '. 미확정이며 보충하지 않습니다.</p>';
    }

    /** @return list<string> */
    private static function symbols(string $dir): array
    {
        $out = [];
        foreach (glob($dir . '/*_2y_1d_closed_v2.json') ?: [] as $file) {
            if (preg_match('/^([A-Z0-9.^=-]+)_2y_1d_closed_v2\.json$/', basename($file), $m)) {
                $out[] = $m[1];
            }
        }
        sort($out);
        return $out;
    }

    /** @return array{error:?string,bars:list<array<string,mixed>>,from:?string,to:?string,dropped:int} */
    private static function readStored(string $dir, string $symbol): array
    {
        $empty = ['error' => '저장 일봉이 없습니다. 없는 종목은 보충하지 않습니다.', 'bars' => [], 'from' => null, 'to' => null, 'dropped' => 0];
        $root = realpath($dir);
        $file = realpath($dir . '/' . $symbol . '_2y_1d_closed_v2.json');
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            return $empty;
        }
        try {
            $raw = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['error' => '저장 일봉이 손상되었습니다. 보충하지 않습니다.', 'bars' => [], 'from' => null, 'to' => null, 'dropped' => 0];
        }
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            return ['error' => '저장 일봉이 손상되었습니다. 보충하지 않습니다.', 'bars' => [], 'from' => null, 'to' => null, 'dropped' => 0];
        }
        foreach ($raw as $bar) {
            if (!is_array($bar) || !is_numeric($bar['time'] ?? null) || !is_numeric($bar['open'] ?? null)
                || !is_numeric($bar['high'] ?? null) || !is_numeric($bar['low'] ?? null) || !is_numeric($bar['close'] ?? null)) {
                return ['error' => '저장 일봉이 손상되었습니다. 보충하지 않습니다.', 'bars' => [], 'from' => null, 'to' => null, 'dropped' => 0];
            }
        }
        $last = CandleClock::closeTime($raw[count($raw) - 1], $symbol);
        $kept = CandleClock::completed($raw, $symbol, $last);
        $tz = new \DateTimeZone(str_ends_with($symbol, '.KS') || str_ends_with($symbol, '.KQ') ? 'Asia/Seoul' : 'America/New_York');
        $fmt = static fn (int $t): string => (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d');
        return ['error' => null, 'bars' => $raw, 'from' => $fmt((int) $raw[0]['time']), 'to' => $fmt($last), 'dropped' => count($raw) - count($kept)];
    }

    private static function realDay(string $day): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        return $parsed !== false && $parsed->format('Y-m-d') === $day;
    }

    private static function closeOf(string $day, string $symbol): int
    {
        $kr = str_ends_with($symbol, '.KS') || str_ends_with($symbol, '.KQ');
        $tz = new \DateTimeZone($kr ? 'Asia/Seoul' : 'America/New_York');
        return (new \DateTimeImmutable($day . ($kr ? ' 15:30:00' : ' 16:00:00'), $tz))->getTimestamp();
    }

    private static function num(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '없음';
    }
}
