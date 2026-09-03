<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 티커 → OHLCV → 피처 → 계좌 프로필별 제안 (CLI/UI 공용).
 * lens(탭)에 따라 작성자 관점을 차트 구조 점수와 분리·합성한다.
 */
final class ProposalService
{
    private readonly AccountPlaybook $playbook;
    private readonly LearnedLevels $learnedLevels;
    private readonly ?EntryRepository $entries;
    private readonly string $lens;
    private readonly SectorMap $sectors;

    public function __construct(
        private readonly YahooChartClient $client,
        private readonly FeatureEngine $engine = new FeatureEngine(),
        ?AccountPlaybook $playbook = null,
        string $profileId = 'account1',
        ?EntryRepository $entries = null,
        ?LearnedLevels $learnedLevels = null,
        string $lens = AlphaEntries::TAB_NORAMU,
        ?SectorMap $sectors = null,
    ) {
        $this->playbook = $playbook ?? AccountPlaybook::forProfile($profileId);
        $this->entries = $entries;
        $this->learnedLevels = $learnedLevels ?? new LearnedLevels();
        $this->lens = AlphaEntries::normalizeTab($lens)['id'];
        $root = dirname(__DIR__);
        $this->sectors = $sectors ?? new SectorMap($root . '/data/cache/sector');
    }

    public function profile(): AccountProfile
    {
        return $this->playbook->profile();
    }

    public function lens(): string
    {
        return $this->lens;
    }

    /**
     * @param ?int $cacheMaxAgeSeconds Yahoo 일봉 캐시 허용 나이(초). 스캔 시 짧게, 새로고침 시 0.
     * @return array{
     *   ok:bool,
     *   input:string,
     *   symbol:?string,
     *   profile:string,
     *   lens:string,
     *   error:?string,
     *   features:?array<string,mixed>,
     *   account1_decision:?array<string,mixed>,
     *   proposal:?array<string,mixed>,
     *   tradingview_url:?string
     * }
     */
    public function propose(string $input, bool $useCache = true, ?int $cacheMaxAgeSeconds = null): array
    {
        $input = trim($input);
        $profileId = $this->playbook->profile()->id;
        $proxyInput = UnderlyingProxy::fromInput($input);
        $symbol = SymbolMap::resolveInput($input);
        if ($symbol === null) {
            return [
                'ok' => false,
                'input' => $input,
                'symbol' => null,
                'profile' => $profileId,
                'lens' => $this->lens,
                'error' => '심볼을 해석할 수 없거나 차트 미지원 종목입니다.',
                'features' => null,
                'account1_decision' => null,
                'proposal' => null,
                'tradingview_url' => null,
            ];
        }

        try {
            $bars = $this->client->fetch(
                $symbol,
                '3mo',
                '1d',
                useCache: $useCache,
                maxAgeSeconds: $cacheMaxAgeSeconds,
            );
            $features = $this->engine->extract($bars);
            $decision = $this->playbook->decide($features, $symbol);
            $tv = SymbolMap::tradingViewUrl($symbol);
            $hourly = (new HourlyAssist($this->client))->analyze($symbol);
            $allEntries = $this->entries?->all() ?? [];
            $learned = null;
            if ($allEntries !== []) {
                $learned = $this->learnedLevels->forSymbol($allEntries, $symbol);
            }
            $proxyBias = $this->lens === AlphaEntries::TAB_NORAMU && $allEntries !== []
                ? UnderlyingProxy::recentBias($allEntries, $symbol)
                : null;
            $proposal = [
                'action' => $decision['action'],
                'score' => $features['pullback_long_score'] ?? null,
                'score_breakdown' => $features['score_breakdown'] ?? null,
                'price' => $features['price'] ?? null,
                'asof_kst' => $features['asof_kst'] ?? null,
                'entry_zone' => $decision['entry_zone'],
                'invalidation' => $decision['invalidation'],
                'invalidation_tight' => $features['stop_tight'] ?? null,
                'invalidation_wide' => $features['stop_wide'] ?? null,
                'invalidation_rule' => $features['invalidation_rule'] ?? null,
                'invalidation_structural' => $features['invalidation_structural'] ?? null,
                'levels' => $features['levels'] ?? [],
                'levels_note' => $features['levels_note'] ?? null,
                'level_support' => $features['level_support'] ?? null,
                'level_support_touches' => $features['level_support_touches'] ?? null,
                'level_support_flip' => $features['level_support_flip'] ?? false,
                'level_resistance' => $features['level_resistance'] ?? null,
                'rising_lows_count' => $features['rising_lows_count'] ?? 0,
                'dist_swing_high_pct' => $features['dist_swing_high_pct'] ?? null,
                'target_hint' => $decision['target_hint'],
                'target_tight' => $features['target_tight'] ?? null,
                'target_wide' => $features['target_wide'] ?? null,
                'target_wide_rule' => $features['target_wide_rule'] ?? null,
                'eta' => $features['eta'] ?? null,
                'atr14' => $features['atr14'] ?? null,
                'target_learned' => $learned !== null && $learned['target_price'] !== null
                    ? [
                        'price' => $learned['target_price'],
                        'rule' => 'learned_median_full_labels',
                        'sample_count' => $learned['with_target'],
                        'source_ids' => $learned['source_ids'],
                    ]
                    : null,
                'stop_learned' => $learned !== null && $learned['stop_price'] !== null
                    ? [
                        'price' => $learned['stop_price'],
                        'rule' => 'learned_median_full_labels',
                        'sample_count' => $learned['with_stop'],
                    ]
                    : null,
                'entry_learned' => $learned !== null && $learned['entry_price'] !== null
                    ? [
                        'price' => $learned['entry_price'],
                        'rule' => 'learned_median_full_labels',
                        'sample_count' => $learned['with_entry'],
                    ]
                    : null,
                'size_hint' => $decision['size_hint'],
                'reason' => $decision['reason'],
                'swing_method' => $features['swing_method'] ?? null,
                'profile' => $profileId,
                'lens' => $this->lens,
                'rules' => $decision['rules'] ?? [],
                'hourly_note' => $hourly['note'] ?? null,
                'unusual_volume_1h' => $hourly['unusual_volume'] ?? false,
                'lesson1_note' => $features['lesson1_note'] ?? null,
                'lesson1_candle_recipe' => $features['lesson1_candle_recipe'] ?? false,
                'lesson1_upper_box' => $features['lesson1_upper_box'] ?? false,
                'lesson1_breakout_no_box' => $features['lesson1_breakout_no_box'] ?? false,
                'lesson1_score_bonus' => $features['lesson1_score_bonus'] ?? 0,
                'top_pattern_status' => $features['top_pattern_status'] ?? 'none',
                'top_pattern_phase' => $features['top_pattern_phase'] ?? 'none',
                'inverse_bottom_status' => $features['inverse_bottom_status'] ?? 'none',
                'inverse_bottom_phase' => $features['inverse_bottom_phase'] ?? 'none',
                'top_pattern_adjustment' => $features['top_pattern_adjustment'] ?? 0,
                'top_pattern_note' => $features['top_pattern_note'] ?? null,
                'top_pattern' => $features['top_pattern'] ?? null,
                'inverse_bottom_pattern' => $features['inverse_bottom_pattern'] ?? null,
                'theme_smell_status' => $features['theme_smell_status'] ?? 'none',
                'theme_smell_label' => $features['theme_smell_label'] ?? '',
                'theme_smell_note' => $features['theme_smell_note'] ?? null,
                'theme_smell' => $features['theme_smell'] ?? null,
                'spike_dump_status' => $features['spike_dump_status'] ?? 'none',
                'spike_dump_label' => $features['spike_dump_label'] ?? '',
                'spike_dump_note' => $features['spike_dump_note'] ?? null,
                'spike_dump_adjustment' => $features['spike_dump_adjustment'] ?? 0,
                'spike_dump' => $features['spike_dump'] ?? null,
                'underlying_proxy' => $proxyInput,
            ];
            $sectorInfo = $this->sectors->resolve($symbol);
            $proposal['sector'] = $sectorInfo['sector'];
            $proposal['sector_bucket'] = $sectorInfo['sector_bucket'];
            $proposal['sector_label'] = $sectorInfo['sector_label'];
            $proposal['name'] = $sectorInfo['name'] ?? SymbolMap::koreanName($symbol);
            $proposal['market_label'] = SymbolMap::marketLabel($symbol);
            if ($proxyBias !== null) {
                $proposal = UnderlyingProxy::applyBiasToProposal($proposal, $proxyBias);
            }
            if ($proxyInput !== null) {
                $proposal['reason'] = trim(
                    (string) ($proposal['reason'] ?? '')
                    . ' | 입력 '
                    . $proxyInput['source_instrument']
                    . ' → 본주 '
                    . $proxyInput['spot']
                    . ' 구조로 대응 (레버 호가≠본주가)'
                );
            }

            // 디깅온유 탭: 투매저 재진입 규칙을 현재 차트에 적용 (과거 절대가 복붙이 아님)
            $dgoMethod = null;
            if ($this->lens === AlphaEntries::TAB_DIGINGONYOU) {
                $dgoMethod = (new DigingonyouMethod())->analyze($features);
                if (!empty($dgoMethod['ok'])) {
                    $proposal['chart_action'] = (string) ($proposal['action'] ?? '');
                    $proposal['chart_score'] = $proposal['score'] ?? null;
                    $proposal['chart_entry_zone'] = $proposal['entry_zone'] ?? null;
                    $proposal['chart_invalidation'] = $proposal['invalidation'] ?? null;
                    $proposal['chart_target_hint'] = $proposal['target_hint'] ?? null;

                    $proposal['action'] = $dgoMethod['action'];
                    $proposal['score'] = $dgoMethod['score'];
                    $proposal['entry_zone'] = $dgoMethod['entry_zone'];
                    $proposal['invalidation'] = $dgoMethod['invalidation'];
                    $proposal['target_hint'] = $dgoMethod['target_hint'];
                    $proposal['size_hint'] = $dgoMethod['size_hint'];
                    $proposal['reason'] = $dgoMethod['reason'];
                    $proposal['level_method'] = $dgoMethod['method'];
                    $proposal['level_method_label'] = $dgoMethod['method_label'];
                    $proposal['digingonyou_method'] = $dgoMethod;
                }
            }

            $perspective = (new AuthorPerspective())->build($this->lens, $allEntries, $symbol, $proposal);
            $proposal = (new AuthorPerspective())->applyToProposal($proposal, $perspective);

            // Method 레벨이 있으면 글 가격 밴드로 다시 덮지 않음 (글은 관점·참고만)
            if (is_array($dgoMethod) && !empty($dgoMethod['ok'])) {
                $proposal['entry_zone'] = $dgoMethod['entry_zone'];
                $proposal['invalidation'] = $dgoMethod['invalidation'];
                $proposal['target_hint'] = $dgoMethod['target_hint'];
                $proposal['action'] = $dgoMethod['action'];
                $proposal['score'] = $dgoMethod['score'];
                $proposal['size_hint'] = $dgoMethod['size_hint'];
                $proposal['level_method'] = $dgoMethod['method'];
                $proposal['level_method_label'] = $dgoMethod['method_label'];
                $postSummary = is_array($perspective) ? (string) ($perspective['summary'] ?? '') : '';
                if ($postSummary !== '') {
                    $proposal['reason'] = (string) $dgoMethod['reason'] . ' || 글참고: ' . $postSummary;
                } else {
                    $proposal['reason'] = (string) $dgoMethod['reason'];
                }
                $proposal['author_action_label'] = (string) ($dgoMethod['action_label'] ?? '');
            }

            // 불법과외1 하드 필터: 작성자 관점이 돌파 추격·하락중 캔들을 덮어쓰지 않음
            $proposal = $this->applyLesson1HardFilters($proposal, $features, $decision);
            // 고점판독 하드 필터: 고점 붕괴 경고 중에는 작성자 관점도 신규 롱으로 덮지 않음
            $proposal = $this->applyTopPatternHardFilter($proposal, $features);

            // 현재가 기준 당일 저가가 미래 눌림 진입가보다 높으면 손절로 쓸 수 없다.
            // 디깅온유는 자체 레벨 규칙을 유지한다.
            if (!(is_array($dgoMethod) && !empty($dgoMethod['ok']))) {
                $proposal = (new TradePlanLevels())->alignStops($proposal, $features);
            }

            $proposal['new_entry'] = (new NewEntryGuide())->build($proposal, $features);
            $proposal = $this->applyStructureBrokenHardFilter($proposal);
            $proposal['explain'] = (new ProposalExplain())->build($proposal, $features);

            return [
                'ok' => true,
                'input' => $input,
                'symbol' => $symbol,
                'profile' => $profileId,
                'lens' => $this->lens,
                'error' => null,
                'features' => $features,
                'account1_decision' => $decision,
                'hourly_assist' => $hourly,
                'learned_levels' => $learned,
                'perspective' => $perspective,
                'proposal' => $proposal,
                'tradingview_url' => $tv,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'input' => $input,
                'symbol' => $symbol,
                'profile' => $profileId,
                'lens' => $this->lens,
                'error' => $e->getMessage(),
                'features' => null,
                'account1_decision' => null,
                'proposal' => null,
                'tradingview_url' => null,
            ];
        }
    }

    /**
     * @param array<string, mixed> $proposal
     * @param array<string, mixed> $features
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    private function applyLesson1HardFilters(array $proposal, array $features, array $decision): array
    {
        $recipeDecline = ($features['lesson1_candle_context'] ?? null) === 'in_decline'
            && !empty($features['lesson1_candle_recipe']);
        $recipeBreak = ($features['lesson1_candle_context'] ?? null) === 'after_supply_break'
            && !empty($features['lesson1_candle_recipe']);
        $breakoutNoBox = !empty($features['lesson1_breakout_no_box'])
            && empty($features['lesson1_upper_box'])
            && !$recipeBreak;
        $lessonNote = (string) ($features['lesson1_note'] ?? '');

        if ($breakoutNoBox && ($decision['action'] ?? '') === 'wait') {
            $proposal['action'] = 'wait';
            $proposal['size_hint'] = (string) ($decision['size_hint'] ?? '현금 유지 — 돌파 추격 금지');
            $proposal['reason'] = (string) ($decision['reason'] ?? $lessonNote);
            return $proposal;
        }

        if ($recipeDecline && ($decision['action'] ?? '') === 'hold_or_trim_on_strength') {
            $proposal['action'] = 'hold_or_trim_on_strength';
            $proposal['size_hint'] = (string) ($decision['size_hint'] ?? '새로 사지 말 것');
            $proposal['reason'] = (string) ($decision['reason'] ?? $lessonNote);
        }

        return $proposal;
    }

    /**
     * @param array<string, mixed> $proposal
     * @param array<string, mixed> $features
     * @return array<string, mixed>
     */
    private function applyTopPatternHardFilter(array $proposal, array $features): array
    {
        $status = (string) ($features['top_pattern_status'] ?? 'none');
        $phase = (string) ($features['top_pattern_phase'] ?? 'none');
        if (!in_array($status, ['warning', 'confirmed'], true) || $phase === 'bounce_confirmed') {
            return $proposal;
        }

        $note = (string) ($features['top_pattern_note'] ?? '고점 붕괴 패턴');
        if (($proposal['action'] ?? '') !== 'blocked') {
            $proposal['action'] = $status === 'confirmed' ? 'hold_or_trim_on_strength' : 'wait';
            $proposal['size_hint'] = $status === 'confirmed'
                ? '신규 매수 금지 — 반등 시 비중 축소 검토'
                : '현금 유지 — 파동 하단·추세 회복 확인 전 대기';
            $proposal['reason'] = trim($note . ' | ' . (string) ($proposal['reason'] ?? ''));
        }

        return $proposal;
    }

    /**
     * 손절선 아래면 작성자 관점·점수와 무관하게 신규 롱 추천을 막는다.
     *
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    private function applyStructureBrokenHardFilter(array $proposal): array
    {
        $newEntry = is_array($proposal['new_entry'] ?? null) ? $proposal['new_entry'] : [];
        if (($newEntry['status'] ?? '') !== 'structure_broken') {
            return $proposal;
        }
        if (($proposal['action'] ?? '') === 'blocked') {
            return $proposal;
        }

        $proposal['action'] = 'hold_or_trim_on_strength';
        $proposal['size_hint'] = '새로 사지 말 것 — 이번 그림 손절선 이탈';
        $note = (string) ($newEntry['sentence'] ?? '손절선 아래라 이번 그림 기준으로 새로 살 가격 없음.');
        $proposal['reason'] = trim($note . ' | ' . (string) ($proposal['reason'] ?? ''));

        return $proposal;
    }
}
