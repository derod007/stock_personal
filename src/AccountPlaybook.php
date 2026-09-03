<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 프로필별 규칙으로 노라무식 구조를 진입 제안으로 변환.
 */
final class AccountPlaybook
{
    private readonly AccountProfile $profile;

    public function __construct(?AccountProfile $profile = null)
    {
        $this->profile = $profile ?? AccountProfile::account1();
    }

    public static function forProfile(string $profileId): self
    {
        return new self(AccountProfile::fromId($profileId));
    }

    public function profile(): AccountProfile
    {
        return $this->profile;
    }

    /**
     * @param array<string, float|int|bool|string|null> $features
     * @return array{
     *   action:string,
     *   size_hint:string,
     *   rules:list<string>,
     *   reason:string,
     *   entry_zone:?array{low:float,high:float,mid:float,rule:string},
     *   invalidation:?float,
     *   target_hint:?array{price:float,rule:string},
     *   blocked:bool,
     *   profile:string
     * }
     */
    public function decide(array $features, string $symbol): array
    {
        $p = $this->profile;
        $rules = [
            $p->blockLeverage
                ? '레버/인버스는 직접 사지 않고 본주 방향·차트 그림으로만 바꿔 대응'
                : '레버 허용(주의)',
            '쫓아 사기 금지: 올라갈 때 사지 말고 내려올 때/그림 회복만',
            '사기 전에 손절선(최근 저점) 필수 기록',
            sprintf('한 번에 현금 전액 금지, 나눠서 최대 %d회', $p->maxSplits),
            '애매하면 지금은 안 삼',
            '불법과외1: 수렴 돌파만 보고 추격 금지 — 돌파 후 윗구간 박스 확인',
            '불법과외1: 선호캔들(장대양→도지→거래량없는장대음→장대양)은 매물대 돌파 후에만',
            '고점판독: 돌파 실패→고점 하향→파동 중심·하단 시험→상승 추세선 이탈이면 신규 매수 금지',
            '손절은 2단으로: 타이트=당일 저가, 넓게=여러 번 걸린 가로 매물대',
            '익절은 2단으로: 1차=직전 고점, 2차=같은 폭을 위로 한 번 더(또는 그 사이 가로 저항)',
        ];
        if ($p->id === 'isa') {
            $rules[] = 'ISA/연금: 회전율 낮게, 점수 문턱 상향';
        }

        $levels = $this->buildLevels($features);
        $lastPx = is_numeric($features['price'] ?? null) ? (float) $features['price'] : null;
        $invPx = is_numeric($levels['invalidation'] ?? null) ? (float) $levels['invalidation'] : null;
        $structureBroken = $lastPx !== null && $invPx !== null && $lastPx < $invPx * 0.999;

        if ($p->isBlocked($symbol)) {
            return [
                'action' => 'blocked',
                'size_hint' => '0%',
                'rules' => $rules,
                'reason' => sprintf(
                    '%s: 레버 티커 직접 제안 차단. SOXS/코루 등은 본주(MU·삼전 등)로 치환해 다시 조회하세요.',
                    $symbol
                ),
                'entry_zone' => null,
                'invalidation' => null,
                'target_hint' => null,
                'blocked' => true,
                'profile' => $p->id,
            ];
        }

        if ($structureBroken) {
            return [
                'action' => 'hold_or_trim_on_strength',
                'size_hint' => '새로 사지 말 것 — 이번 그림 손절선 이탈',
                'rules' => $rules,
                'reason' => sprintf(
                    '%s [%s]: 현재가가 손절선 %s 아래. 이번 그림은 끝. 예전 중간 가격대로 새로 사지 말 것.',
                    $symbol,
                    $p->id,
                    (string) $invPx
                ),
                'entry_zone' => $levels['entry_zone'],
                'invalidation' => $levels['invalidation'],
                'target_hint' => $levels['target_hint'],
                'blocked' => false,
                'profile' => $p->id,
            ];
        }

        $score = (int) ($features['pullback_long_score'] ?? 0);
        $higherLow = (bool) ($features['higher_low'] ?? false);
        $flush = (bool) ($features['flush_bar_recent'] ?? false);
        $distHalf = $features['dist_half_pct'] ?? null;
        $lessonNote = (string) ($features['lesson1_note'] ?? '');
        $upperBox = (bool) ($features['lesson1_upper_box'] ?? false);
        $breakoutNoBox = (bool) ($features['lesson1_breakout_no_box'] ?? false);
        $recipeDecline = ($features['lesson1_candle_context'] ?? null) === 'in_decline'
            && !empty($features['lesson1_candle_recipe']);
        $recipeBreak = ($features['lesson1_candle_context'] ?? null) === 'after_supply_break'
            && !empty($features['lesson1_candle_recipe']);
        $spikeDumpStatus = (string) ($features['spike_dump_status'] ?? 'none');
        $spikeDumpNote = (string) ($features['spike_dump_note'] ?? '');

        // 1~3일 급등 후 급락 → 중간 반등은 허공. 신규 롱 보류
        if ($spikeDumpStatus !== 'none') {
            return [
                'action' => 'wait',
                'size_hint' => '현금 유지 — 급등 후 급락, 중간가 추격 금지',
                'rules' => $rules,
                'reason' => sprintf(
                    '%s [%s]: 급등 후 급락(%s, 점수 %d). %s',
                    $symbol,
                    $p->id,
                    $spikeDumpStatus,
                    $score,
                    $spikeDumpNote !== '' ? $spikeDumpNote : '급등 전 가로가 지지되는지 보기'
                ),
                'entry_zone' => $levels['entry_zone'],
                'invalidation' => $levels['invalidation'],
                'target_hint' => $levels['target_hint'],
                'blocked' => false,
                'profile' => $p->id,
            ];
        }

        // 돌파만 있고 윗박스 없음 → 추격 롱 금지 (점수 높아도 관심/대기로)
        if ($breakoutNoBox && !$upperBox && !$recipeBreak) {
            return [
                'action' => 'wait',
                'size_hint' => '현금 유지 — 돌파 추격 금지, 윗구간 박스 나올 때까지',
                'rules' => $rules,
                'reason' => sprintf(
                    '%s [%s]: 불법과외1 — 수렴·돌파만 있고 윗박스 없음(점수 %d). %s',
                    $symbol,
                    $p->id,
                    $score,
                    $lessonNote
                ),
                'entry_zone' => $levels['entry_zone'],
                'invalidation' => $levels['invalidation'],
                'target_hint' => $levels['target_hint'],
                'blocked' => false,
                'profile' => $p->id,
            ];
        }

        // 하락 중 선호캔들 → 신규 매수 후보에서 제외
        if ($recipeDecline && $score >= $p->watchScoreThreshold) {
            return [
                'action' => 'hold_or_trim_on_strength',
                'size_hint' => '새로 사지 말 것 — 하락 중 선호캔들은 승률 낮음',
                'rules' => $rules,
                'reason' => sprintf(
                    '%s [%s]: 불법과외1 — 빠지는 중 선호캔들(점수 %d). %s',
                    $symbol,
                    $p->id,
                    $score,
                    $lessonNote
                ),
                'entry_zone' => $levels['entry_zone'],
                'invalidation' => $levels['invalidation'],
                'target_hint' => $levels['target_hint'],
                'blocked' => false,
                'profile' => $p->id,
            ];
        }

        if ($score >= $p->addScoreThreshold && $higherLow && $flush) {
            $extra = $upperBox || $recipeBreak ? ' · ' . $lessonNote : '';
            return [
                'action' => 'add_on_pullback',
                'size_hint' => $p->sizeHintAdd(),
                'rules' => $rules,
                'reason' => sprintf(
                    '%s [%s]: 급락 후 저점 올림 + 중간 가격대 근접(점수 %d). 내려올 때 나눠서 사기 후보. 손절선=%s%s',
                    $symbol,
                    $p->id,
                    $score,
                    (string) ($levels['invalidation'] ?? ''),
                    $extra
                ),
                'entry_zone' => $levels['entry_zone'],
                'invalidation' => $levels['invalidation'],
                'target_hint' => $levels['target_hint'],
                'blocked' => false,
                'profile' => $p->id,
            ];
        }

        // 절반 구간 + 불법과외1 가산(윗박스·돌파후 선호캔들)이면 관심 구간으로
        if (
            ($upperBox || $recipeBreak)
            && $score >= max(40, $p->watchScoreThreshold - 10)
            && is_numeric($distHalf)
            && abs((float) $distHalf) <= 6.0
        ) {
            return [
                'action' => 'watchlist_buy_zone',
                'size_hint' => '불법과외1 패턴 확인 — 아직 대기, 내려올 때 나눠서',
                'rules' => $rules,
                'reason' => sprintf(
                    '%s [%s]: 불법과외1 신호 + 중간대 근처(점수 %d). %s',
                    $symbol,
                    $p->id,
                    $score,
                    $lessonNote
                ),
                'entry_zone' => $levels['entry_zone'],
                'invalidation' => $levels['invalidation'],
                'target_hint' => $levels['target_hint'],
                'blocked' => false,
                'profile' => $p->id,
            ];
        }

        if ($score >= $p->watchScoreThreshold && is_numeric($distHalf) && abs((float) $distHalf) <= 4.0) {
            $watchSize = sprintf('대기 후 %.0f~%.0f%%', $p->addSizeMinPct / 2, $p->addSizeMinPct);
            return [
                'action' => 'watchlist_buy_zone',
                'size_hint' => '아직 대기. 저점 안 깨고 다지며 거래량 확인 후 ' . $watchSize,
                'rules' => $rules,
                'reason' => sprintf('%s [%s]: 중간 가격대 근처(점수 %d). 관심만 — 지금 전량 매수 권고 아님', $symbol, $p->id, $score),
                'entry_zone' => $levels['entry_zone'],
                'invalidation' => $levels['invalidation'],
                'target_hint' => $levels['target_hint'],
                'blocked' => false,
                'profile' => $p->id,
            ];
        }

        if ($score < $p->trimScoreThreshold || !$higherLow) {
            return [
                'action' => 'hold_or_trim_on_strength',
                'size_hint' => '새로 사지 말 것. 갖고 있으면 올라올 때 일부 줄이기 검토',
                'rules' => $rules,
                'reason' => sprintf('%s [%s]: 차트 그림 미회복·점수 낮음(%d). 새로 사지 말 것(쫓아 사기 금지)', $symbol, $p->id, $score),
                'entry_zone' => $levels['entry_zone'],
                'invalidation' => $levels['invalidation'],
                'target_hint' => $levels['target_hint'],
                'blocked' => false,
                'profile' => $p->id,
            ];
        }

        return [
            'action' => 'wait',
            'size_hint' => '현금 유지 (지금 시장가 매수 아님)',
            'rules' => $rules,
            'reason' => sprintf('%s [%s]: 중간 점수(%d). 지금은 안 삼 — 중간 가격대가 “지금 살 가격”이 아님', $symbol, $p->id, $score),
            'entry_zone' => $levels['entry_zone'],
            'invalidation' => $levels['invalidation'],
            'target_hint' => $levels['target_hint'],
            'blocked' => false,
            'profile' => $p->id,
        ];
    }

    /**
     * @param array<string, float|int|bool|string|null> $features
     * @return array{
     *   entry_zone:?array{low:float,high:float,mid:float,rule:string},
     *   invalidation:?float,
     *   invalidation_tight:?float,
     *   invalidation_wide:?float,
     *   invalidation_rule:string,
     *   target_hint:?array{price:float,rule:string,wide:?float,wide_rule:string},
     *   target_tight:?float,
     *   target_wide:?float,
     *   target_wide_rule:string
     * }
     */
    private function buildLevels(array $features): array
    {
        $half = $features['half_retrace'] ?? null;
        $invalidation = $features['invalidation_level'] ?? null;
        $swingHigh = $features['swing_high'] ?? ($features['swing_high_20'] ?? null);
        $targetTight = $features['target_tight'] ?? $swingHigh;
        $targetWide = $features['target_wide'] ?? null;
        $targetWideRule = (string) ($features['target_wide_rule'] ?? '');

        $entryZone = null;
        if (is_numeric($half)) {
            $mid = (float) $half;
            $entryZone = [
                'low' => round($mid * 0.96, 4),
                'high' => round($mid * 1.04, 4),
                'mid' => round($mid, 4),
                'rule' => 'half_retrace_±4%',
            ];
        }

        $targetHint = null;
        if (is_numeric($targetTight)) {
            $targetHint = [
                'price' => round((float) $targetTight, 4),
                'rule' => 'structure_swing_high',
                'wide' => is_numeric($targetWide) ? round((float) $targetWide, 4) : null,
                'wide_rule' => $targetWideRule,
            ];
        }

        $tight = $features['stop_tight'] ?? null;
        $wide = $features['stop_wide'] ?? $invalidation;

        return [
            'entry_zone' => $entryZone,
            'invalidation' => is_numeric($invalidation) ? round((float) $invalidation, 4) : null,
            'invalidation_tight' => is_numeric($tight) ? round((float) $tight, 4) : null,
            'invalidation_wide' => is_numeric($wide) ? round((float) $wide, 4) : null,
            'invalidation_rule' => (string) ($features['invalidation_rule'] ?? 'structure_swing_low'),
            'target_hint' => $targetHint,
            'target_tight' => is_numeric($targetTight) ? round((float) $targetTight, 4) : null,
            'target_wide' => is_numeric($targetWide) ? round((float) $targetWide, 4) : null,
            'target_wide_rule' => $targetWideRule,
        ];
    }
}
