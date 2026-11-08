<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;

/**
 * Read-only daily path after a frozen type1 close-breakout candidate.
 * Judgment snapshot freezes the level. Later bars come from one latest verified snapshot only.
 * Equality with the level is neither breakout nor breakdown. Missing days are not interpolated.
 */
final class BreakoutPath
{
    public static function forRow(
        string $judgmentDay,
        array $row,
        array $judgmentSnapshot,
        ?array $latestSnapshot,
        ?string $latestError = null
    ): array {
        $symbol = $row['symbol'];
        $id = $judgmentDay.'|'.$symbol;
        $base = [
            'id' => $id,
            'judgment_day' => $judgmentDay,
            'symbol' => $symbol,
            'name' => $row['name'] ?? $symbol,
            'rank' => $row['rank'] ?? null,
            'judgment_close' => $row['analysis_close'] ?? ($row['classification']['analysis_close'] ?? null),
            'level' => $row['breakout20']['level'] ?? null,
            'note' => '같은 종목의 다른 판단일은 별도 관찰입니다. 연구 재진입 간격을 새로 적용하지 않습니다.',
        ];
        if (!($row['breakout20']['confirmed'] ?? false)) {
            return $base + self::uncertain('종가 돌파 확인 후보가 아닙니다.', null, 'not_confirmed');
        }
        if (!is_numeric($base['level']) || (float)$base['level'] <= 0 || !is_numeric($base['judgment_close'])) {
            return $base + self::uncertain('고정 돌파선 또는 판단 종가가 없습니다.', null, 'missing_level');
        }
        $level = (float)$base['level'];
        if ($latestError || !$latestSnapshot) {
            return $base + self::uncertain(
                $latestError ?? '이후 스냅샷이 없어 경로를 확인하지 못했습니다.',
                null,
                'latest_unavailable'
            );
        }
        $latestDay = $latestSnapshot['day'] ?? null;
        $source = [
            'judgment_snapshot_day' => $judgmentSnapshot['day'] ?? $judgmentDay,
            'latest_snapshot_day' => $latestDay,
            'policy' => '판단 스냅샷으로 기준선·종가 고정. 이후 봉은 최신 해시 검증 스냅샷 하나만 사용. 서로 다른 저장 버전을 날짜별로 섞지 않음.',
        ];
        $pricesMap = $latestSnapshot['prices'] ?? [];
        if (!is_array($pricesMap) || !array_key_exists($symbol, $pricesMap)) {
            $out = $base + self::uncertain(
                '경로에 쓰는 최신 저장본('.(string)$latestDay.')에 이 종목 일봉 키가 없습니다. 과거 저장본에 봉이 있었는지는 이 화면에서 다시 찾지 않으며, 과거에도 없었다는 뜻이 아닙니다.',
                null,
                'symbol_absent_in_latest'
            );
            $out['observation_as_of'] = $latestDay;
            $out['observed_sessions'] = 0;
            $out['source'] = $source;
            return $out;
        }
        $rawBars = $pricesMap[$symbol];
        if (!is_array($rawBars) || $rawBars === []) {
            $fetchErr = $latestSnapshot['errors'][$symbol] ?? null;
            $msg = '경로에 쓰는 최신 저장본('.(string)$latestDay.')에 이 종목 일봉 배열이 비어 있습니다.';
            if (is_string($fetchErr) && $fetchErr !== '') {
                $msg .= ' 저장 오류 표시: '.$fetchErr;
            }
            $out = $base + self::uncertain($msg, null, 'symbol_empty_in_latest');
            $out['observation_as_of'] = $latestDay;
            $out['observed_sessions'] = 0;
            $out['source'] = $source;
            return $out;
        }
        try {
            $judgeBars = Tracker::byDay($judgmentSnapshot['prices'][$symbol] ?? [], $symbol, (int)$judgmentSnapshot['as_of']);
            $latestBars = Tracker::byDay($rawBars, $symbol, (int)$latestSnapshot['as_of']);
        } catch (\Throwable $e) {
            $out = $base + self::uncertain('일봉 날짜 중복 또는 잘못된 봉: '.$e->getMessage(), null, 'bar_parse_error');
            $out['observation_as_of'] = $latestDay;
            $out['source'] = $source;
            return $out;
        }
        $conflict = self::dayConflict($judgeBars[$judgmentDay] ?? null, $latestBars[$judgmentDay] ?? null);
        $days = $latestSnapshot['days'] ?? [];
        $i = array_search($judgmentDay, $days, true);
        if ($i === false) {
            $out = $base + self::uncertain('최신 스냅샷 달력에 판단일이 없습니다.', $conflict, 'judgment_day_absent');
            $out['observation_as_of'] = $latestDay;
            $out['source'] = $source;
            return $out;
        }
        if (!isset($days[$i + 1])) {
            $out = $base + self::uncertain('정확한 다음 거래일 달력이 없습니다.', $conflict, 'next_calendar_missing');
            $out['observation_as_of'] = $latestDay;
            $out['observed_sessions'] = 0;
            $out['source'] = $source;
            return $out;
        }
        $entryDay = $days[$i + 1];
        $entryBar = $latestBars[$entryDay] ?? null;
        if ($entryBar === null) {
            // byDay skips is_complete=false; look at raw bars so missing ≠ unusable.
            try {
                $rawEntry = self::rawDayBar($rawBars, $symbol, $entryDay, (int)$latestSnapshot['as_of']);
            } catch (\Throwable $e) {
                $out = $base + self::uncertain('일봉 날짜 중복 또는 잘못된 봉: '.$e->getMessage(), $conflict, 'bar_parse_error');
                $out['entry_day'] = $entryDay;
                $out['observation_as_of'] = $latestDay;
                $out['source'] = $source;
                return $out;
            }
            if ($rawEntry === null) {
                $out = $base + self::uncertain(
                    '최신 저장본('.(string)$latestDay.')에 종목은 있으나 정확한 다음 거래일('.$entryDay.') 봉이 원본에 없어 이후 날짜로 대체하지 않았습니다.',
                    $conflict,
                    'next_bar_missing'
                );
            } else {
                $detail = (($rawEntry['is_complete'] ?? true) === false)
                    ? '미완료(is_complete=false)'
                    : '유효하지 않음';
                $out = $base + self::uncertain(
                    '최신 저장본('.(string)$latestDay.')에 다음 거래일('.$entryDay.') 봉은 있으나 '.$detail.'이라 경로에 쓰지 않으며 이후 날짜로 대체하지 않았습니다.',
                    $conflict,
                    'next_bar_invalid'
                );
            }
            $out['entry_day'] = $entryDay;
            $out['observation_as_of'] = $latestDay;
            $out['observed_sessions'] = 0;
            $out['source'] = $source;
            return $out;
        }
        if (!Tracker::valid($entryBar)) {
            $out = $base + self::uncertain(
                '최신 저장본('.(string)$latestDay.')의 다음 거래일('.$entryDay.') 봉이 유효성 검사를 통과하지 않아 이후 날짜로 대체하지 않았습니다.',
                $conflict,
                'next_bar_invalid'
            );
            $out['entry_day'] = $entryDay;
            $out['observation_as_of'] = $latestDay;
            $out['observed_sessions'] = 0;
            $out['source'] = $source;
            return $out;
        }
        $entryOpen = (float)$entryBar['open'];
        $pathDays = array_slice($days, $i + 1);
        $maxHigh = null;
        $minLow = null;
        $breachDay = null;
        $recoveryDay = null;
        $gaps = [];
        $lastClose = null;
        $lastCloseDay = null;
        foreach ($pathDays as $d) {
            $b = $latestBars[$d] ?? null;
            if (!$b || !Tracker::valid($b)) {
                $gaps[] = $d;
                continue;
            }
            $high = (float)$b['high'];
            $low = (float)$b['low'];
            $close = (float)$b['close'];
            $maxHigh = $maxHigh === null ? $high : max($maxHigh, $high);
            $minLow = $minLow === null ? $low : min($minLow, $low);
            $lastClose = $close;
            $lastCloseDay = $d;
            if ($breachDay === null && $close < $level) {
                $breachDay = $d;
            } elseif ($breachDay !== null && $recoveryDay === null && $close > $level) {
                $recoveryDay = $d;
            }
        }
        $complete = $gaps === [] && $conflict === null;
        $vs = null;
        if ($lastClose !== null) {
            $vs = $lastClose > $level ? '위' : ($lastClose < $level ? '아래' : '동일');
        }
        $gapKind = null;
        if ($conflict !== null) {
            $gapKind = 'ohlc_conflict';
        } elseif ($gaps !== []) {
            $gapKind = 'mid_bar_missing';
        }
        return $base + [
            'ok' => true,
            'reason' => $complete ? null : self::incompleteReason($gaps, $conflict),
            'gap_kind' => $gapKind,
            'conflict' => $conflict,
            'path_complete' => $complete,
            'entry_day' => $entryDay,
            'entry_open' => $entryOpen,
            'latest_close' => $lastClose,
            'latest_close_day' => $lastCloseDay,
            'change_from_entry_pct' => ($lastClose !== null && $entryOpen > 0) ? ($lastClose / $entryOpen - 1) * 100 : null,
            'max_high' => $maxHigh,
            'max_high_pct' => ($maxHigh !== null && $entryOpen > 0) ? ($maxHigh / $entryOpen - 1) * 100 : null,
            'min_low' => $minLow,
            'max_low_pct' => ($minLow !== null && $entryOpen > 0) ? ($minLow / $entryOpen - 1) * 100 : null,
            'extremes_complete' => $gaps === [] && $conflict === null,
            'first_close_below_day' => $breachDay,
            'first_close_above_after_breach_day' => $recoveryDay,
            'level_cross_complete' => $gaps === [] && $conflict === null,
            'latest_vs_level' => $vs,
            'missing_days' => $gaps,
            'observation_as_of' => $latestDay,
            'observed_sessions' => count($pathDays),
            'observed_valid_sessions' => count($pathDays) - count($gaps),
            'data_source' => '기존 스냅샷',
            'observe_status' => null,
            'data_status' => null,
            'elapsed_sessions' => null,
            'collected_bars' => null,
            'source' => $source,
        ];
    }

    /**
     * Registered ids use breakout-watch bars. A missing registry keeps the snapshot path.
     * Unreadable registry or hash mismatch is not treated as unregistered.
     * $watch: folder, days, as_of_day. Read-only.
     * @param list<array> $rows
     */
    public static function forConfirmedRows(
        string $judgmentDay,
        array $rows,
        array $judgmentSnapshot,
        ?array $latestSnapshot,
        ?string $latestError = null,
        ?array $watch = null
    ): array {
        $reg = $watch === null
            ? ['status' => 'absent', 'data' => null, 'reason' => '']
            : BreakoutWatch::registryView($watch['folder']);
        $cache = [];
        $out = [];
        foreach ($rows as $row) {
            if (!($row['breakout20']['confirmed'] ?? false)) {
                continue;
            }
            $id = $judgmentDay.'|'.$row['symbol'];
            if ($reg['status'] === 'unreadable') {
                $out[] = self::watchStop($judgmentDay, $row, $reg['reason'].' 등록 여부를 알 수 없어 기존 스냅샷으로 대체하지 않았습니다.', '미확정', null);
                continue;
            }
            $entry = ($reg['status'] === 'ok') ? ($reg['data']['entries'][$id] ?? null) : null;
            $out[] = is_array($entry)
                ? self::forWatch($judgmentDay, $row, $judgmentSnapshot, $entry, $watch, $cache)
                : self::forRow($judgmentDay, $row, $judgmentSnapshot, $latestSnapshot, $latestError);
        }
        return $out;
    }

    private static function forWatch(string $day, array $row, array $judgmentSnapshot, array $entry, array $watch, array &$cache): array
    {
        $symbol = $row['symbol'];
        if (($entry['judgment_day'] ?? null) !== $day || ($entry['symbol'] ?? null) !== $symbol) {
            return self::watchStop($day, $row, '명부 항목이 판단일·종목과 맞지 않습니다. 기존 스냅샷으로 대체하지 않았습니다.', '미확정', null);
        }
        try {
            $hash = hash('sha256', json_encode($judgmentSnapshot, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return self::watchStop($day, $row, '판단 스냅샷 해시를 확인하지 못했습니다. 기존 스냅샷으로 대체하지 않았습니다.', '미확정', null);
        }
        $expect = $entry['snapshot_sha256'] ?? '';
        if (!is_string($expect) || $expect === '' || !hash_equals($expect, $hash)) {
            return self::watchStop($day, $row, '명부의 판단 스냅샷 해시가 저장된 스냅샷과 다릅니다. 기존 스냅샷으로 대체하지 않았습니다.', '미확정', null);
        }
        $days = $watch['days'] ?? [];
        $asOf = (string)($watch['as_of_day'] ?? '');
        $from = array_search($day, $days, true);
        $at = array_search($asOf, $days, true);
        if ($from === false || $at === false || $at < $from) {
            return self::watchStop($day, $row, '저장 달력에서 판단일 또는 자료 기준일을 확인하지 못했습니다.', '달력 미확정', null, $asOf !== '' ? $asOf : null);
        }
        $elapsed = $at - $from;
        $observe = $elapsed === 0 ? '대기' : ($elapsed <= 20 ? '진행 중' : '기간 종료');
        $window = array_slice($days, $from + 1, min(20, $elapsed));
        if ($window === []) {
            return array_replace(self::watchStop($day, $row, '관찰 거래일이 아직 없습니다.', '확보 범위 내 정상', $observe, $asOf), [
                'ok' => true,
                'elapsed_sessions' => 0,
                'collected_bars' => 0,
                'missing_days' => [],
            ]);
        }
        $bars = [];
        $missing = [];
        $notes = [];
        $blocked = false;
        foreach ($window as $d) {
            if (!isset($cache[$d])) {
                $cache[$d] = BreakoutWatch::barFileView($watch['folder'], $d);
            }
            $file = $cache[$d];
            if ($file['status'] === 'unreadable') {
                $blocked = true;
                $missing[] = $d;
                $notes[] = $d.' '.$file['reason'];
                continue;
            }
            $slot = $file['status'] === 'ok' ? ($file['data']['symbols'][$symbol] ?? null) : null;
            $picked = self::watchBar($slot, $d, $symbol);
            if ($picked['bar']) {
                $bars[] = $picked['bar'];
            } else {
                $missing[] = $d;
                $notes[] = $picked['note'];
            }
        }
        $synth = [
            'day' => $asOf,
            'as_of' => strtotime($asOf.' 20:00:00 +0900') ?: 0,
            'days' => array_merge([$day], $window),
            'prices' => [$symbol => $bars],
        ];
        $path = self::forRow($day, $row, $judgmentSnapshot, $synth, null);
        $path['data_source'] = '독립 관찰';
        $path['observe_status'] = $observe;
        $path['data_status'] = $blocked ? '미확정' : ($missing === [] ? '확보 범위 내 정상' : '결측 있음');
        $path['elapsed_sessions'] = $elapsed;
        $path['collected_bars'] = count($bars);
        $path['missing_days'] = $missing;
        $path['observation_as_of'] = $asOf;
        $path['source'] = [
            'judgment_snapshot_day' => $judgmentSnapshot['day'] ?? $day,
            'latest_snapshot_day' => null,
            'policy' => '등록 후보의 기준선·판단 종가는 판단 스냅샷에 고정하고, 판단일 다음 1~20거래일은 일별로 처음 저장한 관찰 봉만 잇습니다. 다른 판단일이나 과거 스냅샷으로 결측을 메우지 않습니다.',
        ];
        if ($notes !== []) {
            $path['reason'] = implode(' · ', $notes);
        }
        if ($blocked || $missing !== []) {
            $path['path_complete'] = false;
            $path['extremes_complete'] = false;
            $path['level_cross_complete'] = false;
        }
        if ($blocked || $path['entry_open'] === null) {
            $path['ok'] = false;
        }
        return $path;
    }

    /** @return array{bar:?array,note:string} */
    private static function watchBar(mixed $slot, string $day, string $symbol): array
    {
        if (!is_array($slot) || !array_key_exists('bar', $slot)) {
            return ['bar' => null, 'note' => $day.' 유효 봉 없음'];
        }
        $bar = $slot['bar'];
        if ($bar === null) {
            $kind = is_array($slot['attempt'] ?? null) ? (string)($slot['attempt']['kind'] ?? '') : '';
            $labels = ['incomplete' => '미완료', 'invalid' => '무효', 'fetch_error' => '조회 실패'];
            return ['bar' => null, 'note' => $day.' '.($labels[$kind] ?? '유효 봉 없음')];
        }
        if (is_array($bar) && array_is_list($bar)) {
            return ['bar' => null, 'note' => $day.' 같은 날 봉이 중복'];
        }
        if (!is_array($bar) || (($slot['session_day'] ?? null) !== $day) || !self::barOnDay($bar, $symbol, $day)) {
            return ['bar' => null, 'note' => $day.' 날짜가 맞지 않음'];
        }
        if (($bar['is_complete'] ?? true) === false) {
            return ['bar' => null, 'note' => $day.' 미완료'];
        }
        if (!empty($bar['synthetic'])) {
            return ['bar' => null, 'note' => $day.' 합성'];
        }
        if (!Tracker::valid($bar)) {
            return ['bar' => null, 'note' => $day.' 무효'];
        }
        return ['bar' => $bar, 'note' => ''];
    }

    private static function barOnDay(array $bar, string $symbol, string $day): bool
    {
        try {
            $stamp = \ChartEntryLab\CandleClock::closeTime($bar, $symbol);
        } catch (\Throwable) {
            return false;
        }
        return (new \DateTimeImmutable('@'.$stamp))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d') === $day;
    }

    private static function watchStop(string $day, array $row, string $reason, ?string $dataStatus, ?string $observe, ?string $asOf = null): array
    {
        $symbol = $row['symbol'];
        return array_replace(self::uncertain($reason), [
            'id' => $day.'|'.$symbol,
            'judgment_day' => $day,
            'symbol' => $symbol,
            'name' => $row['name'] ?? $symbol,
            'rank' => $row['rank'] ?? null,
            'judgment_close' => $row['analysis_close'] ?? ($row['classification']['analysis_close'] ?? null),
            'level' => $row['breakout20']['level'] ?? null,
            'note' => '같은 종목의 다른 판단일은 별도 관찰입니다. 연구 재진입 간격을 새로 적용하지 않습니다.',
            'data_source' => '독립 관찰',
            'observe_status' => $observe,
            'data_status' => $dataStatus,
            'observation_as_of' => $asOf,
            'reason' => $reason,
            'gap_kind' => null,
        ]);
    }

    private static function uncertain(string $reason, ?array $conflict = null, ?string $gapKind = null): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'gap_kind' => $gapKind,
            'conflict' => $conflict,
            'path_complete' => false,
            'entry_day' => null,
            'entry_open' => null,
            'latest_close' => null,
            'latest_close_day' => null,
            'change_from_entry_pct' => null,
            'max_high' => null,
            'max_high_pct' => null,
            'min_low' => null,
            'max_low_pct' => null,
            'extremes_complete' => false,
            'first_close_below_day' => null,
            'first_close_above_after_breach_day' => null,
            'level_cross_complete' => false,
            'latest_vs_level' => null,
            'missing_days' => [],
            'observation_as_of' => null,
            'observed_sessions' => null,
            'observed_valid_sessions' => null,
            'data_source' => '기존 스냅샷',
            'observe_status' => null,
            'data_status' => null,
            'elapsed_sessions' => null,
            'collected_bars' => null,
            'source' => null,
        ];
    }

    /**
     * Day lookup without dropping incomplete bars. Does not change Tracker::byDay.
     * @return ?array raw bar for $day, or null if absent
     */
    private static function rawDayBar(array $bars, string $symbol, string $day, int $asOf): ?array
    {
        $found = null;
        $tz = new \DateTimeZone((str_ends_with($symbol, '.KS') || str_ends_with($symbol, '.KQ')) ? 'Asia/Seoul' : 'America/New_York');
        foreach ($bars as $b) {
            $stamp = \ChartEntryLab\CandleClock::closeTime($b, $symbol);
            if ($stamp > $asOf) {
                continue;
            }
            $d = (new \DateTimeImmutable('@'.$stamp))->setTimezone($tz)->format('Y-m-d');
            if ($d !== $day) {
                continue;
            }
            if ($found !== null) {
                throw new \RuntimeException('Duplicate price session');
            }
            $found = $b;
        }
        return $found;
    }

    private static function dayConflict(?array $a, ?array $b): ?array
    {
        if (!$a || !$b || !Tracker::valid($a) || !Tracker::valid($b)) {
            return null;
        }
        // Path metrics use OHLC only. Volume revisions alone are not a price conflict.
        foreach (['open', 'high', 'low', 'close'] as $k) {
            if ((float)$a[$k] !== (float)$b[$k]) {
                return [
                    'field' => $k,
                    'judgment' => $a[$k],
                    'latest' => $b[$k],
                    'message' => '판단 스냅샷과 최신 스냅샷의 같은 날짜 OHLC가 달라 섞지 않았습니다. 기준선은 판단 스냅샷, 이후 경로는 최신 스냅샷만 씁니다. 거래량만 다른 경우는 가격 충돌이 아닙니다.',
                ];
            }
        }
        return null;
    }

    private static function incompleteReason(array $gaps, ?array $conflict): string
    {
        $parts = [];
        if ($conflict) {
            $parts[] = '저장 버전 간 판단일 가격 충돌';
        }
        if ($gaps) {
            $parts[] = '중간 거래일 봉 누락: '.implode(', ', array_slice($gaps, 0, 5)).(count($gaps) > 5 ? ' 외' : '');
        }
        return implode(' · ', $parts) ?: '경로 미확정';
    }
}
