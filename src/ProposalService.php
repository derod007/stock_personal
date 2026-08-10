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

    public function __construct(
        private readonly YahooChartClient $client,
        private readonly FeatureEngine $engine = new FeatureEngine(),
        ?AccountPlaybook $playbook = null,
        string $profileId = 'account1',
        ?EntryRepository $entries = null,
        ?LearnedLevels $learnedLevels = null,
        string $lens = AlphaEntries::TAB_NORAMU,
    ) {
        $this->playbook = $playbook ?? AccountPlaybook::forProfile($profileId);
        $this->entries = $entries;
        $this->learnedLevels = $learnedLevels ?? new LearnedLevels();
        $this->lens = AlphaEntries::normalizeTab($lens)['id'];
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
    /**
     * @param ?int $cacheMaxAgeSeconds Yahoo 일봉 캐시 허용 나이(초). 스캔 시 짧게, 새로고침 시 0.
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
                'price' => $features['price'] ?? null,
                'entry_zone' => $decision['entry_zone'],
                'invalidation' => $decision['invalidation'],
                'target_hint' => $decision['target_hint'],
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
                'underlying_proxy' => $proxyInput,
            ];
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

            $proposal['new_entry'] = (new NewEntryGuide())->build($proposal, $features);
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
}
