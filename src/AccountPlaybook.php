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
        ];
        if ($p->id === 'isa') {
            $rules[] = 'ISA/연금: 회전율 낮게, 점수 문턱 상향';
        }

        $levels = $this->buildLevels($features);

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
     *   target_hint:?array{price:float,rule:string}
     * }
     */
    private function buildLevels(array $features): array
    {
        $half = $features['half_retrace'] ?? null;
        $invalidation = $features['invalidation_level'] ?? null;
        $target = $features['suggested_target_half_to_high'] ?? null;
        $swingHigh = $features['swing_high'] ?? ($features['swing_high_20'] ?? null);

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
        if (is_numeric($target)) {
            $targetHint = [
                'price' => round((float) $target, 4),
                'rule' => 'midpoint_half_to_swing_high',
            ];
        } elseif (is_numeric($swingHigh)) {
            $targetHint = [
                'price' => round((float) $swingHigh, 4),
                'rule' => 'structure_swing_high',
            ];
        }

        return [
            'entry_zone' => $entryZone,
            'invalidation' => is_numeric($invalidation) ? round((float) $invalidation, 4) : null,
            'target_hint' => $targetHint,
        ];
    }
}
