<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * entries.json 학습 라벨 정리.
 * - full: 가격·구조 모두 학습 가능
 * - structure_only: 구조만 참고, 가격 라벨 제외
 * - ignore: 학습·점수에서 제외
 * - needs_review: 심볼/단위 미확정
 */
final class EntryCurator
{
    /** @var list<string> */
    private const LEVERAGED_TYPES = ['leveraged', 'leveraged_etf'];

    /** @var list<string> */
    private const FULL_TYPES = ['us_stock', 'kr_stock'];

    /**
     * @param list<array<string, mixed>> $entries
     * @return array{entries:list<array<string,mixed>>, summary:array<string,int>}
     */
    public function curate(array $entries): array
    {
        $summary = [
            'full' => 0,
            'structure_only' => 0,
            'ignore' => 0,
            'needs_review' => 0,
            'price_scale_suspect' => 0,
        ];

        $out = [];
        $exitReasons = ['stop' => 0, 'target' => 0, 'observe' => 0, 'none' => 0];
        foreach ($entries as $entry) {
            $curated = $this->annotateExitReason($this->curateOne($entry));
            $use = (string) ($curated['learning_use'] ?? 'needs_review');
            $summary[$use] = ($summary[$use] ?? 0) + 1;
            if (!empty($curated['price_scale_suspect'])) {
                $summary['price_scale_suspect']++;
            }
            $er = $curated['exit_reason'] ?? null;
            if ($er === 'stop' || $er === 'target' || $er === 'observe') {
                $exitReasons[$er]++;
            } else {
                $exitReasons['none']++;
            }
            $out[] = $curated;
        }

        $summary['exit_stop'] = $exitReasons['stop'];
        $summary['exit_target'] = $exitReasons['target'];
        $summary['exit_observe'] = $exitReasons['observe'];

        return ['entries' => $out, 'summary' => $summary];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function curateOne(array $entry): array
    {
        $entry = $this->normalizeMisclassifiedLeveraged($entry);

        $symbol = (string) ($entry['symbol'] ?? 'UNKNOWN');
        $productType = (string) ($entry['product_type'] ?? 'unknown');
        $tags = array_map('strval', $entry['tags'] ?? []);
        $note = (string) ($entry['symbol_note'] ?? '');
        $blob = trim(
            ($entry['title'] ?? '') . "\n"
            . ($entry['raw_quote'] ?? '') . "\n"
            . implode("\n", is_array($entry['author_comments'] ?? null) ? $entry['author_comments'] : [])
        );
        $reasons = [];

        $priceSuspect = $this->isPriceScaleSuspect($entry, $note);
        if ($priceSuspect) {
            $reasons[] = 'price_scale_suspect';
        }

        if (preg_match('/공부하기좋은\s*종목/u', $blob) === 1) {
            $tags[] = 'study_watchlist';
            if (preg_match('/원전.{0,12}돈/u', $blob) === 1) {
                $tags[] = 'nuclear_flow_comment';
            }
            $entry['tags'] = array_values(array_unique($tags));
            if (($entry['symbol_note'] ?? '') === '' || preg_match('/삼하/u', (string) ($entry['symbol_note'] ?? '')) === 1) {
                $entry['symbol_note'] = '삼하 추격 비추천 + 공부용 차트 예시 (타점 아님)';
            }
        }

        if ($symbol === 'UNKNOWN' || $productType === 'unknown') {
            return $this->curateUnknown($entry, $blob, $priceSuspect, $reasons);
        }

        // 이미 본주 치환된 레버 이벤트 — 사이드/바이어스만 재보정
        if (in_array('from_leveraged_proxy', $tags, true) && ($entry['source_instrument'] ?? '') !== '') {
            $entry = UnderlyingProxy::refreshProxiedEntry($entry);
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['leveraged_to_spot_proxy'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        // 엔진 ge70 스냅샷: 원글이 아님 → 가격 full 라벨·백테스트에서 제외
        if (
            in_array('engine_ge70_snapshot', $tags, true)
            || in_array('not_noramu_post', $tags, true)
            || ($entry['source'] ?? '') === 'engine_ge70_snapshot'
            || ($entry['author'] ?? '') === 'engine_snapshot'
        ) {
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['engine_snapshot_not_noramu'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (in_array('do_not_copy', $tags, true)) {
            $reasons[] = 'do_not_copy';
        }

        if ($productType === 'market_view') {
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['market_scenario_no_ticker'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        // Yahoo 차트 없는 국장 소형주 등 — 가격 라벨은 남기되 백테스트 제외
        if ($symbol === '247960.KQ') {
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['chart_available'] = false;
            $entry['learning_reasons'] = ['yahoo_chart_unavailable'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        // 레버/인버스/배수 → 폐기하지 않고 본주로 치환 (호가는 본주 가격 라벨에 쓰지 않음)
        if (in_array($productType, self::LEVERAGED_TYPES, true) || UnderlyingProxy::isLeverageSymbol($symbol)) {
            $entry = UnderlyingProxy::toSpotEntry($entry);
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = array_values(array_unique(array_merge($reasons, ['leveraged_to_spot_proxy'])));
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if ($priceSuspect) {
            // 본주로 잡혔지만 레버 가격대면 위에서 SNDK_2X로 올린 뒤 재시도되지 않은 잔여
            if (UnderlyingProxy::isLeverageSymbol((string) ($entry['symbol'] ?? ''))) {
                $entry = UnderlyingProxy::toSpotEntry($entry);
                $entry['learning_use'] = 'structure_only';
                $entry['exclude_price_label'] = true;
                $entry['price_scale_suspect'] = false;
                $entry['learning_reasons'] = ['leveraged_to_spot_proxy', 'price_scale_suspect'];
                $entry['curated_at_kst'] = $this->nowKst();
                return $entry;
            }
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = true;
            $entry['learning_reasons'] = array_values(array_unique($reasons));
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (in_array('structure_or_view', $tags, true)
            && ($entry['entry_price'] ?? null) === null
            && ($entry['stop_price'] ?? null) === null
            && ($entry['target_price'] ?? null) === null
        ) {
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = in_array('important_lesson', $tags, true)
                ? ['structure_view_only', 'important_pattern_lesson', 'no_direct_price_label']
                : ['structure_view_only'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (in_array($productType, self::FULL_TYPES, true)) {
            $tagsNow = array_map('strval', $entry['tags'] ?? []);
            $isSnap = in_array('engine_ge70_snapshot', $tagsNow, true);
            $entry['learning_use'] = 'full';
            $entry['exclude_price_label'] = false;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = $isSnap ? ['engine_ge70_snapshot'] : ['spot_equity'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        $entry['learning_use'] = 'needs_review';
        $entry['exclude_price_label'] = true;
        $entry['price_scale_suspect'] = $priceSuspect;
        $entry['learning_reasons'] = array_values(array_unique(array_merge($reasons, ['unclassified_product_type'])));
        $entry['curated_at_kst'] = $this->nowKst();
        return $entry;
    }

    /**
     * 본주(SNDK ~1000달러대)로 잡혔지만 가격이 레버(10달러대)인 경우 교정.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function normalizeMisclassifiedLeveraged(array $entry): array
    {
        $symbol = (string) ($entry['symbol'] ?? '');
        if ($symbol !== 'SNDK') {
            return $entry;
        }

        $prices = array_filter([
            $entry['entry_price'] ?? null,
            $entry['stop_price'] ?? null,
            $entry['target_price'] ?? null,
        ], static fn($v): bool => is_numeric($v));

        if ($prices === []) {
            return $entry;
        }

        $maxPrice = max(array_map('floatval', $prices));
        // 본주 일봉이 수백~수천대인데 가격 라벨이 100 미만이면 2배/숏 상품 타점
        if ($maxPrice > 0 && $maxPrice < 100.0) {
            $entry['symbol'] = 'SNDK_2X';
            $entry['related_underlying'] = 'SNDK';
            $entry['product_type'] = 'leveraged';
            $entry['symbol_note'] = trim(($entry['symbol_note'] ?? '') . ' [auto] 본주 대비 가격대 불일치 → 레버 타점으로 재분류');
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string> $reasons
     * @return array<string, mixed>
     */
    private function curateUnknown(array $entry, string $blob, bool $priceSuspect, array $reasons): array
    {
        // 같은 글에 샌디 레버 타점이 있으면 구조 참고로 SNDK 연결
        if (($entry['document_srl'] ?? null) === '10095647739' || preg_match('/숏뷰|롱으로 전환/u', $blob) === 1) {
            $entry['symbol'] = 'SNDK';
            $entry['related_underlying'] = 'SNDK';
            $entry['product_type'] = 'us_stock';
            $entry['symbol_note'] = '동일 스레드 맥락상 샌디 관련 구조 뷰';
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = $priceSuspect;
            $entry['learning_reasons'] = ['mapped_from_thread_context'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (preg_match('/에피스/u', $blob) === 1) {
            $entry['symbol'] = 'EPIS_HOLDINGS';
            $entry['related_underlying'] = 'EPIS_HOLDINGS';
            $entry['product_type'] = 'kr_stock';
            $entry['symbol_note'] = '삼성에피스홀딩스 (코어 외 · 방식 샘플)';
            if (preg_match('/([0-9]{5,})부터/u', $blob, $m)) {
                $entry['entry_price'] = (float) $m[1];
            }
            if (preg_match('/([0-9]+)\s*만\s*원?\s*이탈/u', $blob, $m)) {
                $entry['stop_price'] = ((float) $m[1]) * 10000;
            }
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['chart_available'] = false;
            $entry['learning_reasons'] = ['epis_method_sample_no_core'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        // 3달러대 재진입 + 숏/박스 → SOXS 맥락 → MU 본주 숏 바이어스
        if (preg_match('/3\.\d+/u', $blob) === 1 && preg_match('/숏|박스/u', $blob) === 1) {
            $entry['symbol'] = 'SOXS';
            $entry['product_type'] = 'leveraged_etf';
            $entry['side'] = preg_match('/진입|잡|매수/u', $blob) === 1 ? 'long' : 'short';
            $entry['symbol_note'] = '3달러대·숏 맥락 → SOXS로 간주 후 본주 치환';
            $entry = UnderlyingProxy::toSpotEntry($entry);
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['soxs_context_price_band'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (preg_match('/오일롱|도로롱|본장\s*개장|전량\s*본전/u', $blob) === 1) {
            $entry['learning_use'] = 'ignore';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['off_universe_chat'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (preg_match('/삼하|하이닉스|하닉/u', $blob) === 1) {
            $entry['symbol'] = '000660.KS';
            $entry['related_underlying'] = '000660.KS';
            $entry['product_type'] = 'kr_stock';
            $entry['symbol_note'] = '삼하/하이닉스 구조 뷰';
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['mapped_samha_or_hynix'];
            if (preg_match('/공부하기좋은\s*종목/u', $blob) === 1) {
                $tags = array_map('strval', $entry['tags'] ?? []);
                $tags[] = 'study_watchlist';
                if (preg_match('/원전.{0,12}돈/u', $blob) === 1) {
                    $tags[] = 'nuclear_flow_comment';
                }
                $entry['tags'] = array_values(array_unique($tags));
                $entry['symbol_note'] = '삼하 추격 비추천 + 공부용 차트 예시 (타점 아님)';
                $entry['learning_reasons'][] = 'study_watchlist';
            }
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (preg_match('/양빵/u', $blob) === 1) {
            $entry['learning_use'] = 'ignore';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['hedge_advice_not_tradeable'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (preg_match('/선물|국장\s*시나리오/u', $blob) === 1) {
            $entry['symbol'] = 'KR_MARKET_VIEW';
            $entry['symbol_note'] = '국장/선물 시나리오 (개별 종목 아님)';
            $entry['product_type'] = 'market_view';
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['market_scenario_no_ticker'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (preg_match('/단타일지/u', $blob) === 1 && preg_match('/끝나면\s*올림/u', $blob) === 1) {
            $entry['learning_use'] = 'ignore';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['placeholder_post'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (preg_match('/한성기업/u', $blob) === 1) {
            $entry['symbol'] = '003680.KS';
            $entry['related_underlying'] = '003680.KS';
            $entry['product_type'] = 'kr_stock';
            $entry['symbol_note'] = '한성기업 — 시총 보고 추천 안 함(관찰)';
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['watch_no_recommend'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (preg_match('/스테이블코인/u', $blob) === 1) {
            $entry['learning_use'] = 'ignore';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['off_universe_chat'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (preg_match('/유가|원유|오일/u', $blob) === 1 && preg_match('/오일롱/u', $blob) !== 1) {
            $entry['symbol'] = 'CL=F';
            $entry['related_underlying'] = 'CL=F';
            $entry['product_type'] = 'us_stock';
            $entry['symbol_note'] = '원유 뷰 (개별 국장 종목 아님)';
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['oil_market_view'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (preg_match('/GS건설|지에스건설/iu', $blob) === 1) {
            $entry['symbol'] = '006360.KS';
            $entry['related_underlying'] = '006360.KS';
            $entry['product_type'] = 'kr_stock';
            $entry['symbol_note'] = 'GS건설 관찰(진입가 없음)';
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['watch_no_entry'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (preg_match('/로봇주|로봇\s*냄새|에이치브이엠|비츠로넥스텍/u', $blob) === 1) {
            $entry['symbol'] = 'KR_MARKET_VIEW';
            $entry['symbol_note'] = '테마 관찰(로봇 등) — 진입가 없음';
            $entry['product_type'] = 'market_view';
            $entry['learning_use'] = 'structure_only';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['theme_watch_no_entry'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        if (preg_match('/차트/u', $blob) === 1 && preg_match('/매수|진입|목표가|손절/u', $blob) !== 1) {
            $entry['learning_use'] = 'ignore';
            $entry['exclude_price_label'] = true;
            $entry['price_scale_suspect'] = false;
            $entry['learning_reasons'] = ['no_tradeable_levels'];
            $entry['curated_at_kst'] = $this->nowKst();
            return $entry;
        }

        $entry['learning_use'] = 'needs_review';
        $entry['exclude_price_label'] = true;
        $entry['price_scale_suspect'] = $priceSuspect;
        $entry['learning_reasons'] = array_values(array_unique(array_merge($reasons, ['symbol_unknown'])));
        $entry['curated_at_kst'] = $this->nowKst();
        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function isPriceScaleSuspect(array $entry, string $note): bool
    {
        // 레버 호가·본주 불일치는 UnderlyingProxy로 처리. 여기선 명시적 스케일 경고만.
        if (preg_match('/역분할|단위|표기|스케일|가격대\s*다름|Yahoo/u', $note) === 1) {
            return true;
        }

        return false;
    }

    /**
     * 손절·익절·관망 결과 라벨. 이미 수동으로 넣은 값은 유지.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function annotateExitReason(array $entry): array
    {
        $existing = $entry['exit_reason'] ?? null;
        if (is_string($existing) && $existing !== '') {
            return $entry;
        }

        $side = (string) ($entry['side'] ?? '');
        $tags = array_map('strval', $entry['tags'] ?? []);
        $blob = trim((string) ($entry['title'] ?? '') . "\n" . (string) ($entry['raw_quote'] ?? ''));

        // 케이스 D: "gg 손절" / 손절 후 관망
        if (preg_match('/gg\s*손절|손절\s*(?:후|이후)?\s*관망/u', $blob) === 1) {
            $entry['exit_reason'] = 'stop';
            return $entry;
        }

        if ($side === 'exit') {
            if (array_intersect($tags, ['take_profit', 'parsed_target', 'scale_out']) !== []) {
                $entry['exit_reason'] = 'target';
                return $entry;
            }
            if (array_intersect($tags, ['parsed_stop', 'stop_defined']) !== []) {
                $entry['exit_reason'] = 'stop';
                return $entry;
            }
        }

        if ($side === 'observe' && preg_match('/관망/u', $blob) === 1) {
            $entry['exit_reason'] = 'observe';
            return $entry;
        }

        $entry['exit_reason'] = null;
        return $entry;
    }

    private function nowKst(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Seoul')))->format(DATE_ATOM);
    }
}
