<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 탭(작성자)별 관점: 최근 글·스탠스·선언 가격.
 * 차트 구조 점수(공통)와 분리해, 탭마다 다른 해석이 보이게 한다.
 */
final class AuthorPerspective
{
    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, mixed>|null $proposal chart-based proposal
     * @return array<string, mixed>
     */
    public function build(string $lens, array $entries, string $symbol, ?array $proposal = null): array
    {
        $symbol = SymbolMap::resolveInput($symbol) ?? $symbol;
        $chartAction = is_array($proposal) ? (string) ($proposal['action'] ?? '') : '';
        $chartScore = is_array($proposal) && is_numeric($proposal['score'] ?? null)
            ? (int) $proposal['score']
            : null;
        $chartLevels = [
            'entry_zone' => is_array($proposal['entry_zone'] ?? null) ? $proposal['entry_zone'] : null,
            'invalidation' => is_numeric($proposal['invalidation'] ?? null) ? (float) $proposal['invalidation'] : null,
            'target_hint' => is_array($proposal['target_hint'] ?? null) ? $proposal['target_hint'] : null,
            'price' => is_numeric($proposal['price'] ?? null) ? (float) $proposal['price'] : null,
        ];

        if ($lens === AlphaEntries::TAB_MERGED) {
            $noramu = $this->forAuthor('노라무', $entries, $symbol, $chartAction, $chartScore, $chartLevels);
            $dgo = $this->forAuthor('디깅온유', $entries, $symbol, $chartAction, $chartScore, $chartLevels);
            return [
                'lens' => $lens,
                'label' => '합침',
                'mode' => 'compare',
                'chart_score' => $chartScore,
                'chart_action' => $chartAction,
                'chart_levels' => $chartLevels,
                'authors' => [$noramu, $dgo],
                'synthesis' => $this->synthesize($noramu, $dgo, $chartAction),
                'summary' => $this->synthesize($noramu, $dgo, $chartAction)['sentence'],
            ];
        }

        $author = $lens === AlphaEntries::TAB_DIGINGONYOU ? '디깅온유' : '노라무';
        $one = $this->forAuthor($author, $entries, $symbol, $chartAction, $chartScore, $chartLevels);

        return [
            'lens' => $lens,
            'label' => $author,
            'mode' => 'single',
            'chart_score' => $chartScore,
            'chart_action' => $chartAction,
            'chart_levels' => $chartLevels,
            'authors' => [$one],
            'primary' => $one,
            'synthesis' => null,
            'summary' => $one['summary'],
        ];
    }

    /**
     * 작성자 관점으로 proposal 행동·이유를 덮어쓴다 (차트 점수는 유지).
     *
     * @param array<string, mixed> $proposal
     * @param array<string, mixed> $perspective
     * @return array<string, mixed>
     */
    public function applyToProposal(array $proposal, array $perspective): array
    {
        $proposal['perspective'] = $perspective;
        $proposal['chart_action'] = (string) ($proposal['action'] ?? '');
        $proposal['chart_score'] = $proposal['score'] ?? null;
        // 공통 차트 레벨 백업 (탭별 레벨로 덮기 전)
        $proposal['chart_entry_zone'] = $proposal['entry_zone'] ?? null;
        $proposal['chart_invalidation'] = $proposal['invalidation'] ?? null;
        $proposal['chart_target_hint'] = $proposal['target_hint'] ?? null;

        $summary = (string) ($perspective['summary'] ?? '');
        if ($summary !== '') {
            $proposal['reason'] = $summary . ' || 차트구조: ' . (string) ($proposal['reason'] ?? '');
        }

        // 합침 탭: 메인 레벨은 차트, 작성자별 레벨은 카드에만
        if (($perspective['mode'] ?? '') === 'compare') {
            $proposal['author_stance'] = 'compare';
            $proposal['author_action'] = 'compare';
            $proposal['author_action_label'] = '두 관점 비교 (합침)';
            $proposal['size_hint'] = '합침 탭 — 아래 카드의 관심 구간·손절선이 작성자별로 다름';
            $proposal['level_method'] = 'chart_half_retrace';
            $proposal['level_method_label'] = '공통 차트 중간 가격 (합침 기본) · 작성자 레벨은 관점 카드';
            return $proposal;
        }

        $primary = is_array($perspective['primary'] ?? null)
            ? $perspective['primary']
            : (is_array($perspective['authors'][0] ?? null) ? $perspective['authors'][0] : null);

        if ($primary === null) {
            return $proposal;
        }

        $stance = (string) ($primary['stance'] ?? 'none');
        $authorAction = (string) ($primary['author_action'] ?? 'none');
        $proposal['author_stance'] = $stance;
        $proposal['author_action'] = $authorAction;
        $proposal['author_action_label'] = (string) ($primary['author_action_label'] ?? '');
        $proposal['author_levels'] = $primary['levels'] ?? null;

        if ($authorAction === 'buy_bias') {
            if (in_array((string) ($proposal['action'] ?? ''), ['hold_or_trim_on_strength', 'wait'], true)) {
                $proposal['action'] = 'watchlist_buy_zone';
            }
            $proposal['size_hint'] = (string) ($primary['size_hint'] ?? '작성자 최근 매수 쪽 · 나눠서만');
        } elseif ($authorAction === 'sell_bias') {
            $proposal['action'] = 'hold_or_trim_on_strength';
            $proposal['size_hint'] = (string) ($primary['size_hint'] ?? '작성자 최근 매도/축소 바이어스');
        } elseif ($authorAction === 'cash_bias') {
            $proposal['action'] = 'wait';
            $proposal['size_hint'] = (string) ($primary['size_hint'] ?? '작성자 지금은 안 삼/현금 쪽');
        }

        // 작성자 접근법으로 관심 구간·손절선·익절을 교체
        $trade = is_array($primary['trade_levels'] ?? null) ? $primary['trade_levels'] : null;
        if ($trade !== null) {
            if (isset($trade['entry_zone'])) {
                $proposal['entry_zone'] = $trade['entry_zone'];
            }
            if (array_key_exists('invalidation', $trade)) {
                $proposal['invalidation'] = $trade['invalidation'];
            }
            if (isset($trade['target_hint'])) {
                $proposal['target_hint'] = $trade['target_hint'];
            }
            $proposal['level_method'] = (string) ($trade['method'] ?? '');
            $proposal['level_method_label'] = (string) ($trade['method_label'] ?? '');
        }

        $levels = is_array($primary['levels'] ?? null) ? $primary['levels'] : [];
        if (isset($levels['entry']) && is_numeric($levels['entry'])) {
            $proposal['entry_learned_author'] = [
                'price' => (float) $levels['entry'],
                'rule' => 'author_declared_median',
                'sample_count' => (int) ($levels['entry_n'] ?? 1),
                'author' => (string) ($primary['author'] ?? ''),
            ];
        }
        if (isset($levels['stop']) && is_numeric($levels['stop'])) {
            $proposal['stop_learned'] = [
                'price' => (float) $levels['stop'],
                'rule' => 'author_declared_median',
                'sample_count' => (int) ($levels['stop_n'] ?? 1),
            ];
        }
        if (isset($levels['target']) && is_numeric($levels['target'])) {
            $proposal['target_learned'] = [
                'price' => (float) $levels['target'],
                'rule' => 'author_declared_median',
                'sample_count' => (int) ($levels['target_n'] ?? 1),
                'source_ids' => $primary['source_ids'] ?? [],
            ];
        }

        return $proposal;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    /**
     * @param array{
     *   entry_zone:?array<string,mixed>,
     *   invalidation:?float,
     *   target_hint:?array<string,mixed>,
     *   price:?float
     * } $chartLevels
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private function forAuthor(
        string $author,
        array $entries,
        string $symbol,
        string $chartAction,
        ?int $chartScore,
        array $chartLevels,
    ): array {
        $related = $this->relatedEntries($entries, $author, $symbol);
        $stanceScore = 0;
        $posts = [];
        $entriesPx = [];
        $stops = [];
        $targets = [];
        $ids = [];

        foreach ($related as $e) {
            $ids[] = (string) ($e['id'] ?? '');
            $text = trim((string) ($e['title'] ?? '') . "\n" . (string) ($e['raw_quote'] ?? ''));
            $side = (string) ($e['side'] ?? '');
            $local = $this->stanceFromText($text, $side);
            $stanceScore += $local['score'];

            if (is_numeric($e['entry_price'] ?? null)) {
                $px = (float) $e['entry_price'];
                if ($this->plausiblePrice($px, $symbol)) {
                    $entriesPx[] = $px;
                }
            }
            foreach ($this->extractPriceHints($text, $symbol) as $hint) {
                if ($hint['kind'] === 'entry') {
                    $entriesPx[] = $hint['price'];
                } elseif ($hint['kind'] === 'stop') {
                    $stops[] = $hint['price'];
                } elseif ($hint['kind'] === 'target') {
                    $targets[] = $hint['price'];
                }
            }
            if (is_numeric($e['stop_price'] ?? null) && $this->plausiblePrice((float) $e['stop_price'], $symbol)) {
                $stops[] = (float) $e['stop_price'];
            }
            if (is_numeric($e['target_price'] ?? null) && $this->plausiblePrice((float) $e['target_price'], $symbol)) {
                $targets[] = (float) $e['target_price'];
            }

            $posts[] = [
                'id' => (string) ($e['id'] ?? ''),
                'title' => (string) ($e['title'] ?? ''),
                'posted_at_kst' => (string) ($e['posted_at_kst'] ?? ''),
                'side' => $side,
                'stance' => $local['stance'],
                'snippet' => mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 140),
                'url' => (string) ($e['source_url'] ?? ''),
                'symbol' => (string) ($e['symbol'] ?? ''),
            ];
            if (count($posts) >= 6) {
                break;
            }
        }

        $entriesPx = array_values(array_unique(array_map(static fn (float $v): float => round($v, 2), $entriesPx)));
        $stops = array_values(array_unique(array_map(static fn (float $v): float => round($v, 2), $stops)));
        $targets = array_values(array_unique(array_map(static fn (float $v): float => round($v, 2), $targets)));

        $stance = $stanceScore >= 2 ? 'long' : ($stanceScore <= -2 ? 'short' : ($stanceScore === 0 ? 'none' : 'mixed'));
        if ($related === []) {
            $stance = 'none';
        }

        $authorAction = match ($stance) {
            'long' => 'buy_bias',
            'short' => 'sell_bias',
            'mixed' => 'mixed',
            default => 'none',
        };
        if ($stanceScore <= 0 && $this->hasCashBias($related)) {
            $authorAction = 'cash_bias';
            $stance = 'cash';
        }

        $levels = [
            'entry' => $this->median($entriesPx),
            'entry_n' => count($entriesPx),
            'entry_min' => $entriesPx !== [] ? min($entriesPx) : null,
            'entry_max' => $entriesPx !== [] ? max($entriesPx) : null,
            'stop' => $this->median($stops),
            'stop_n' => count($stops),
            'target' => $this->median($targets),
            'target_n' => count($targets),
        ];

        $tradeLevels = $this->buildTradeLevels($author, $levels, $chartLevels);
        $summary = $this->summarizeAuthor($author, $stance, $authorAction, count($related), $levels, $chartAction, $chartScore, $tradeLevels);

        return [
            'author' => $author,
            'post_count' => count($related),
            'stance' => $stance,
            'stance_score' => $stanceScore,
            'author_action' => $authorAction,
            'author_action_label' => match ($authorAction) {
                'buy_bias' => '매수·나눠서 관심 (작성자)',
                'sell_bias' => '매도·줄이기 쪽 (작성자)',
                'cash_bias' => '지금은 안 삼·현금 (작성자)',
                'mixed' => '혼재 (작성자)',
                default => '관련 글 없음 / 중립',
            },
            'size_hint' => match ($authorAction) {
                'buy_bias' => '작성자 최근 매수 쪽 · 나눠서·가격대 확인',
                'sell_bias' => '작성자 최근 매도/줄이기 · 새로 사기 보류',
                'cash_bias' => '작성자 지금은 안 삼 · 현금 유지',
                default => null,
            },
            'levels' => $levels,
            'trade_levels' => $tradeLevels,
            'posts' => $posts,
            'source_ids' => array_values(array_filter($ids)),
            'summary' => $summary,
            'agrees_with_chart' => $this->agrees($authorAction, $chartAction),
        ];
    }

    /**
     * 작성자 접근법에 맞는 관심 구간·손절선·익절.
     * 노라무 = 차트 중간 가격/최근 저점. 디깅온유 = 글에 나온 매수가 구간.
     *
     * @param array<string, mixed> $levels
     * @param array<string, mixed> $chartLevels
     * @return array{
     *   entry_zone:?array{low:float,high:float,mid:float,rule:string},
     *   invalidation:?float,
     *   target_hint:?array{price:float,rule:string},
     *   method:string,
     *   method_label:string
     * }
     */
    private function buildTradeLevels(string $author, array $levels, array $chartLevels): array
    {
        $chartZone = is_array($chartLevels['entry_zone'] ?? null) ? $chartLevels['entry_zone'] : null;
        $chartInv = is_numeric($chartLevels['invalidation'] ?? null) ? (float) $chartLevels['invalidation'] : null;
        $chartTarget = is_array($chartLevels['target_hint'] ?? null) ? $chartLevels['target_hint'] : null;

        if ($author === '노라무') {
            return [
                'entry_zone' => $chartZone,
                'invalidation' => $chartInv,
                'target_hint' => $chartTarget,
                'method' => 'noramu_half_retrace',
                'method_label' => '고점·저점 중간 ±4% · 손절선=최근 저점',
            ];
        }

        // 디깅온유: 글에 찍힌 매수가를 관심구간으로
        $mid = is_numeric($levels['entry'] ?? null) ? (float) $levels['entry'] : null;
        $emin = is_numeric($levels['entry_min'] ?? null) ? (float) $levels['entry_min'] : null;
        $emax = is_numeric($levels['entry_max'] ?? null) ? (float) $levels['entry_max'] : null;
        $stop = is_numeric($levels['stop'] ?? null) ? (float) $levels['stop'] : null;
        $target = is_numeric($levels['target'] ?? null) ? (float) $levels['target'] : null;

        if ($mid === null) {
            return [
                'entry_zone' => $chartZone,
                'invalidation' => $chartInv,
                'target_hint' => $chartTarget,
                'method' => 'digingonyou_fallback_chart',
                'method_label' => '기타: 글에 매수가 없어 차트 중간 가격을 임시 사용',
            ];
        }

        if ($emin !== null && $emax !== null && $emax > $emin * 1.005) {
            $low = round($emin, 2);
            $high = round($emax, 2);
            $zoneMid = round(($low + $high) / 2, 2);
            $rule = 'digingonyou_declared_buy_band';
        } else {
            $zoneMid = round($mid, 2);
            $low = round($mid * 0.97, 2);
            $high = round($mid * 1.03, 2);
            $rule = 'digingonyou_declared_buy±3%';
        }

        $inv = $stop;
        if ($inv === null) {
            $inv = round(($emin ?? $mid) * 0.95, 2);
        }

        $targetHint = null;
        if ($target !== null) {
            $targetHint = ['price' => round($target, 2), 'rule' => 'digingonyou_declared_target'];
        } else {
            $targetHint = ['price' => round($zoneMid * 1.12, 2), 'rule' => 'digingonyou_approx_+12%_from_buy'];
        }

        return [
            'entry_zone' => [
                'low' => $low,
                'high' => $high,
                'mid' => $zoneMid,
                'rule' => $rule,
            ],
            'invalidation' => $inv,
            'target_hint' => $targetHint,
            'method' => 'digingonyou_declared_prices',
            'method_label' => '기타: 글 매수가 구간 · 손절선=손절/매수가−5%',
        ];
    }

    private function plausiblePrice(float $px, string $symbol): bool
    {
        if ($symbol === '000660.KS') {
            return $px >= 500000 && $px <= 4000000;
        }
        if ($symbol === '005930.KS') {
            return $px >= 40000 && $px <= 500000;
        }
        if (str_ends_with($symbol, '.KS') || str_ends_with($symbol, '.KQ')) {
            return $px >= 1000;
        }
        return $px > 0 && $px < 100000;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function relatedEntries(array $entries, string $author, string $symbol): array
    {
        $out = [];
        foreach ($entries as $e) {
            $a = (string) ($e['source_author'] ?? $e['author'] ?? '');
            if ($a !== $author && !str_contains($a, $author)) {
                // 노라무 기본 entries는 author만 있는 경우
                if (!($author === '노라무' && ($a === '' || $a === '노라무'))) {
                    continue;
                }
            }
            if (($e['learning_use'] ?? '') === 'ignore') {
                continue;
            }
            if (!$this->matchesSymbol($e, $symbol)) {
                continue;
            }
            $out[] = $e;
        }

        usort($out, static function (array $a, array $b): int {
            return strcmp((string) ($b['posted_at_kst'] ?? ''), (string) ($a['posted_at_kst'] ?? ''));
        });

        return $out;
    }

    /**
     * @param array<string, mixed> $e
     */
    private function matchesSymbol(array $e, string $symbol): bool
    {
        $esym = (string) ($e['related_underlying'] ?? $e['symbol'] ?? '');
        $resolved = SymbolMap::resolveInput($esym) ?? $esym;
        if ($resolved === $symbol || $esym === $symbol) {
            return true;
        }

        $blob = (string) ($e['title'] ?? '') . ' ' . (string) ($e['raw_quote'] ?? '') . ' ' . (string) ($e['symbol_note'] ?? '');
        $aliases = match ($symbol) {
            '000660.KS' => ['하이닉스', '하닉', '000660', 'SK하이닉스'],
            '005930.KS' => ['삼성전자', '삼전', '005930', '삼닉'],
            'MU' => ['마이크론', 'MU', '마이크'],
            'SNDK' => ['샌디', 'SNDK', '샌디스크'],
            default => [$symbol],
        };
        foreach ($aliases as $al) {
            if ($al !== '' && mb_stripos($blob, $al) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{stance:string, score:int}
     */
    private function stanceFromText(string $text, string $side): array
    {
        $score = match ($side) {
            'long' => 2,
            'short' => -2,
            'risk', 'observe' => 0,
            'mixed_or_hedge' => 0,
            default => 0,
        };

        if (preg_match('/매도\s*시작|분할매도|털었|축소|숏|관망\s*유지|현금/u', $text) === 1) {
            $score -= 2;
        }
        if (preg_match('/매수\s*계획|매수\s*했|분할\s*매수|바닥|과매도|저점|담아|롱/u', $text) === 1) {
            $score += 2;
        }
        if (preg_match('/매도/u', $text) === 1 && preg_match('/매수/u', $text) !== 1) {
            $score -= 1;
        }

        $stance = $score > 0 ? 'long' : ($score < 0 ? 'short' : 'mixed');
        return ['stance' => $stance, 'score' => $score];
    }

    /**
     * @param list<array<string, mixed>> $related
     */
    private function hasCashBias(array $related): bool
    {
        foreach (array_slice($related, 0, 3) as $e) {
            $t = (string) ($e['title'] ?? '') . (string) ($e['raw_quote'] ?? '');
            if (preg_match('/관망|현금|쉬어|안\s*삼|보류/u', $t) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array{kind:string, price:float}>
     */
    private function extractPriceHints(string $text, string $symbol): array
    {
        $out = [];
        // 1,890,000 / 1984000
        if (preg_match_all('/(\d{1,3}(?:,\d{3}){2,})/u', $text, $mFull)) {
            foreach ($mFull[1] as $raw) {
                $px = (float) str_replace(',', '', $raw);
                if ($this->plausiblePrice($px, $symbol)) {
                    $kind = preg_match('/매도|익절|털/u', $text) === 1 && preg_match('/매수|잡은/u', $text) !== 1
                        ? 'target'
                        : 'entry';
                    $out[] = ['kind' => $kind, 'price' => $px];
                }
            }
        }
        // 169만
        if (preg_match_all('/(\d{2,4})\s*만/u', $text, $m)) {
            foreach ($m[1] as $n) {
                $px = (float) $n * 10000;
                if ($this->plausiblePrice($px, $symbol)) {
                    $out[] = ['kind' => 'entry', 'price' => $px];
                }
            }
        }
        // 211-220 사이로 분할매도
        if (preg_match_all('/(\d{2,3})\s*[-~]\s*(\d{2,3})\s*(?:만)?/u', $text, $mRange, PREG_SET_ORDER)) {
            foreach ($mRange as $mr) {
                $a = (float) $mr[1];
                $b = (float) $mr[2];
                if ($symbol === '000660.KS' && $a >= 100 && $a <= 400) {
                    $out[] = ['kind' => 'target', 'price' => (($a + $b) / 2) * 10000];
                }
            }
        }
        if ($symbol === '005930.KS' && preg_match_all('/삼전[^\d]{0,12}(\d{2,3})(?:\s*만)?/u', $text, $m2)) {
            foreach ($m2[1] as $n) {
                $v = (float) $n;
                $px = $v < 1000 ? $v * 1000 : $v;
                if ($this->plausiblePrice($px, $symbol)) {
                    $out[] = ['kind' => 'entry', 'price' => $px];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $levels
     * @param array<string, mixed> $tradeLevels
     */
    private function summarizeAuthor(
        string $author,
        string $stance,
        string $authorAction,
        int $n,
        array $levels,
        string $chartAction,
        ?int $chartScore,
        array $tradeLevels = [],
    ): string {
        if ($n === 0) {
            return sprintf(
                '%s: 이 종목 관련 최근 글이 없어 공통 차트 구조만 표시 (점수 %s, 행동 %s).',
                $this->displayAuthor($author),
                $chartScore !== null ? (string) $chartScore : '—',
                $chartAction !== '' ? $chartAction : '—'
            );
        }

        $zone = is_array($tradeLevels['entry_zone'] ?? null) ? $tradeLevels['entry_zone'] : null;
        $levelBit = '';
        if (is_array($zone) && isset($zone['low'], $zone['high'])) {
            $levelBit = sprintf(
                ' · 관심구간 %s~%s',
                number_format((float) $zone['low']),
                number_format((float) $zone['high'])
            );
        } elseif (isset($levels['entry']) && is_numeric($levels['entry'])) {
            $levelBit = sprintf(' · 작성자 언급 매수가 중앙값 %s', number_format((float) $levels['entry']));
        }
        if (isset($tradeLevels['invalidation']) && is_numeric($tradeLevels['invalidation'])) {
            $levelBit .= sprintf(' · 손절선 %s', number_format((float) $tradeLevels['invalidation']));
        }

        $stanceKo = match ($stance) {
            'long' => '매수·나눠서 쪽',
            'short' => '매도·줄이기 쪽',
            'cash' => '지금은 안 삼·현금',
            'mixed' => '혼재',
            default => '중립',
        };

        $method = (string) ($tradeLevels['method_label'] ?? '');
        $agree = $this->agrees($authorAction, $chartAction);
        $vs = $agree === true
            ? '차트 방향과 비슷한 편'
            : ($agree === false ? '차트 방향과 결이 다름' : '차트와 직접 비교 어려움');

        return sprintf(
            '%s 관점: 관련 글 %d건 → %s%s. %s. %s (공통 차트점수 %s)',
            $this->displayAuthor($author),
            $n,
            $stanceKo,
            $levelBit,
            $method !== '' ? $method : '레벨 규칙 미정',
            $vs,
            $chartScore !== null ? (string) $chartScore : '—'
        );
    }

    private function agrees(string $authorAction, string $chartAction): ?bool
    {
        if ($authorAction === 'none' || $chartAction === '') {
            return null;
        }
        $chartBuy = in_array($chartAction, ['add_on_pullback', 'watchlist_buy_zone'], true);
        $chartSell = in_array($chartAction, ['hold_or_trim_on_strength'], true);
        $chartWait = $chartAction === 'wait';

        if ($authorAction === 'buy_bias') {
            return $chartBuy ? true : ($chartSell || $chartWait ? false : null);
        }
        if ($authorAction === 'sell_bias' || $authorAction === 'cash_bias') {
            return ($chartSell || $chartWait) ? true : ($chartBuy ? false : null);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $noramu
     * @param array<string, mixed> $dgo
     * @return array{agree:?bool, sentence:string}
     */
    private function synthesize(array $noramu, array $dgo, string $chartAction): array
    {
        $a = (string) ($noramu['stance'] ?? 'none');
        $b = (string) ($dgo['stance'] ?? 'none');
        $agree = null;
        if ($a !== 'none' && $b !== 'none') {
            $agree = ($a === $b) || ($a === 'mixed' || $b === 'mixed');
            if (in_array($a, ['long', 'short', 'cash'], true) && in_array($b, ['long', 'short', 'cash'], true)) {
                $agree = $a === $b;
            }
        }

        $sentence = sprintf(
            '합침: 차트=%s · 기타=%s · 공통차트=%s. %s',
            (string) ($noramu['author_action_label'] ?? $a),
            (string) ($dgo['author_action_label'] ?? $b),
            $chartAction !== '' ? $chartAction : '—',
            $agree === true ? '두 관점이 비슷한 편' : ($agree === false ? '두 관점이 갈림 — 글·레벨을 각각 확인' : '한쪽 데이터 부족')
        );

        return ['agree' => $agree, 'sentence' => $sentence];
    }

    private function displayAuthor(string $author): string
    {
        return $author === '노라무' ? '차트' : ($author === '디깅온유' ? '기타' : $author);
    }

    /**
     * @param list<float> $xs
     */
    private function median(array $xs): ?float
    {
        if ($xs === []) {
            return null;
        }
        sort($xs);
        $n = count($xs);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return $xs[$mid];
        }
        return ($xs[$mid - 1] + $xs[$mid]) / 2.0;
    }
}
