<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 레버·인버스·배수 상품 → 본주 치환.
 * - 레버 호가(3.63 등)는 본주 진입가로 쓰지 않음
 * - 방향(롱/숏)만 본주 bias로 옮김 (인버스면 반전)
 * - 계좌에서는 레버 상품 직접 매매 대신 본주 구조로 대응
 */
final class UnderlyingProxy
{
    /**
     * @var array<string, array{spot:string, inverse:bool, product:string, note:string}>
     */
    private const MAP = [
        'SOXS' => [
            'spot' => 'MU',
            'inverse' => true,
            'product' => 'us_stock',
            'note' => 'SOXS(반도체 3x 인버스) → MU 본주 (방향 반전)',
        ],
        'SOXL' => [
            'spot' => 'MU',
            'inverse' => false,
            'product' => 'us_stock',
            'note' => 'SOXL(반도체 3x 롱) → MU 본주',
        ],
        'SNDK_2X' => [
            'spot' => 'SNDK',
            'inverse' => false,
            'product' => 'us_stock',
            'note' => '샌디 2배 → SNDK 본주 (가격 라벨 제외, 구조·방향만)',
        ],
        'SNDK_SHORT' => [
            'spot' => 'SNDK',
            'inverse' => true,
            'product' => 'us_stock',
            'note' => '샌디숏/인버스 → SNDK 본주 (방향 반전)',
        ],
        '122630.KS' => [
            'spot' => '005930.KS',
            'inverse' => false,
            'product' => 'kr_stock',
            'note' => '코루(코스피 레버) → 삼전 본주로 국장 방향 대응',
        ],
    ];

    public static function isLeverageSymbol(string $symbol): bool
    {
        return isset(self::MAP[strtoupper($symbol)]) || isset(self::MAP[$symbol]);
    }

    /**
     * @return array{spot:string, inverse:bool, product:string, note:string}|null
     */
    public static function map(string $symbol): ?array
    {
        $key = strtoupper($symbol);
        if (isset(self::MAP[$symbol])) {
            return self::MAP[$symbol];
        }
        if (isset(self::MAP[$key])) {
            return self::MAP[$key];
        }
        // 국장 티커는 대소문자 유지
        return self::MAP[$symbol] ?? null;
    }

    /**
     * 점수/차트에 쓸 본주 심볼. 레버면 치환, 아니면 null.
     */
    public static function scoreSymbol(string $symbol): ?string
    {
        $m = self::map($symbol);
        return $m['spot'] ?? null;
    }

    /**
     * UI/CLI 입력 기준 프록시 메타.
     *
     * @return array{
     *   input:string,
     *   source_instrument:string,
     *   spot:string,
     *   inverse:bool,
     *   note:string
     * }|null
     */
    public static function fromInput(string $input): ?array
    {
        $raw = trim($input);
        if ($raw === '') {
            return null;
        }
        $key = mb_strtolower($raw);
        $aliases = [
            '쏙스' => 'SOXS',
            'soxs' => 'SOXS',
            'soxl' => 'SOXL',
            '코루' => '122630.KS',
            '샌디숏' => 'SNDK_SHORT',
            '샌디2배' => 'SNDK_2X',
        ];
        $instrument = $aliases[$key] ?? null;
        if ($instrument === null) {
            $upper = strtoupper($raw);
            if (self::isLeverageSymbol($upper) || self::isLeverageSymbol($raw)) {
                $instrument = self::map($raw) !== null ? $raw : $upper;
            }
        }
        if ($instrument === null) {
            return null;
        }
        $m = self::map($instrument);
        if ($m === null) {
            return null;
        }

        return [
            'input' => $raw,
            'source_instrument' => $instrument,
            'spot' => $m['spot'],
            'inverse' => $m['inverse'],
            'note' => $m['note'],
        ];
    }

    /**
     * 레버 이벤트를 본주 구조 이벤트로 치환.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public static function toSpotEntry(array $entry): array
    {
        $symbol = (string) ($entry['symbol'] ?? '');
        $m = self::map($symbol);
        if ($m === null) {
            return $entry;
        }

        $origSide = (string) ($entry['side'] ?? 'observe');
        $blob = trim((string) ($entry['title'] ?? '') . "\n" . (string) ($entry['raw_quote'] ?? ''));
        // SOXS "진입/매수" = 인버스 상품 롱 (글에 숏뷰가 같이 있어도 상품 side는 long)
        if (
            strtoupper($symbol) === 'SOXS'
            && preg_match('/진입|매수/u', $blob) === 1
            && in_array($origSide, ['mixed_or_hedge', 'observe', 'short'], true)
        ) {
            $origSide = 'long';
        }
        $spotSide = self::mapSide($origSide, $m['inverse']);

        $entry['source_instrument'] = $symbol;
        $entry['leveraged_entry_price'] = $entry['entry_price'] ?? null;
        $entry['leveraged_stop_price'] = $entry['stop_price'] ?? null;
        $entry['leveraged_target_price'] = $entry['target_price'] ?? null;
        $entry['entry_price'] = null;
        $entry['stop_price'] = null;
        $entry['target_price'] = null;

        $entry['symbol'] = $m['spot'];
        $entry['related_underlying'] = $m['spot'];
        $entry['product_type'] = $m['product'];
        $entry['side'] = $spotSide;
        $entry['proxy_bias'] = self::biasFromSide($spotSide);
        $entry['exclude_price_label'] = true;

        $tags = array_map('strval', $entry['tags'] ?? []);
        $tags[] = 'from_leveraged_proxy';
        $tags[] = 'structure_or_view';
        if ($m['inverse']) {
            $tags[] = 'inverse_side_flipped';
        }
        $entry['tags'] = array_values(array_unique($tags));

        $note = trim((string) ($entry['symbol_note'] ?? ''));
        $proxyNote = $m['note'] . sprintf(' · 원상품 side=%s → 본주 side=%s', $origSide, $spotSide);
        $entry['symbol_note'] = $note === '' ? $proxyNote : ($note . ' | ' . $proxyNote);

        return $entry;
    }

    /**
     * 이미 치환된 엔트리의 side/bias 재계산 (symbol은 본주인 상태).
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public static function refreshProxiedEntry(array $entry): array
    {
        $src = (string) ($entry['source_instrument'] ?? '');
        $m = self::map($src);
        if ($m === null) {
            return $entry;
        }

        $origSide = (string) ($entry['side'] ?? 'observe');
        // 치환 전 상품 side 복원 시도: 본주 side를 역변환
        if ($m['inverse']) {
            $origSide = self::mapSide($origSide, true);
        }
        $blob = trim((string) ($entry['title'] ?? '') . "\n" . (string) ($entry['raw_quote'] ?? ''));
        if (
            strtoupper($src) === 'SOXS'
            && preg_match('/진입|매수/u', $blob) === 1
        ) {
            $origSide = 'long';
        }

        $spotSide = self::mapSide($origSide, $m['inverse']);
        $entry['side'] = $spotSide;
        $entry['proxy_bias'] = self::biasFromSide($spotSide);
        $entry['symbol'] = $m['spot'];
        $entry['related_underlying'] = $m['spot'];
        $entry['product_type'] = $m['product'];
        $entry['exclude_price_label'] = true;

        return $entry;
    }

    public static function mapSide(string $side, bool $inverse): string
    {
        if (!$inverse) {
            return $side;
        }

        return match ($side) {
            'long' => 'short',
            'short' => 'long',
            'exit' => 'exit',
            'observe' => 'observe',
            'mixed_or_hedge' => 'mixed_or_hedge',
            default => $side,
        };
    }

    public static function biasFromSide(string $side): string
    {
        return match ($side) {
            'long' => 'long',
            'short' => 'short',
            'mixed_or_hedge' => 'mixed',
            default => 'neutral',
        };
    }

    /**
     * 최근 본주 프록시 이벤트에서 방향 바이어스 집계.
     *
     * @param list<array<string, mixed>> $entries
     * @return array{bias:string, count:int, latest_id:?string, source_instruments:list<string>, note:string}|null
     */
    public static function recentBias(array $entries, string $spotSymbol, int $withinDays = 14): ?array
    {
        $spot = SymbolMap::resolveInput($spotSymbol) ?? $spotSymbol;
        $tz = new \DateTimeZone('Asia/Seoul');
        $now = new \DateTimeImmutable('now', $tz);
        $cut = $now->modify(sprintf('-%d days', max(1, $withinDays)));

        $hits = [];
        foreach ($entries as $e) {
            $tags = array_map('strval', $e['tags'] ?? []);
            $isProxy = in_array('from_leveraged_proxy', $tags, true)
                || in_array('semi_risk_off', $tags, true)
                || in_array('from_post_comments', $tags, true)
                || (($e['proxy_bias'] ?? null) !== null && ($e['proxy_bias'] ?? '') !== '');
            if (!$isProxy) {
                continue;
            }
            $esym = (string) ($e['related_underlying'] ?? $e['symbol'] ?? '');
            $resolved = SymbolMap::resolveInput($esym) ?? $esym;
            if ($resolved !== $spot && $esym !== $spotSymbol) {
                continue;
            }
            $posted = (string) ($e['posted_at_kst'] ?? '');
            if ($posted === '') {
                continue;
            }
            try {
                $pd = new \DateTimeImmutable($posted);
            } catch (\Throwable) {
                continue;
            }
            if ($pd < $cut) {
                continue;
            }
            $hits[] = $e;
        }

        if ($hits === []) {
            return null;
        }

        usort($hits, static fn(array $a, array $b): int => strcmp((string) ($b['posted_at_kst'] ?? ''), (string) ($a['posted_at_kst'] ?? '')));

        $score = 0;
        $instruments = [];
        foreach ($hits as $e) {
            $bias = (string) ($e['proxy_bias'] ?? self::biasFromSide((string) ($e['side'] ?? '')));
            $score += match ($bias) {
                'long' => 1,
                'short' => -1,
                default => 0,
            };
            $src = (string) ($e['source_instrument'] ?? '');
            if ($src !== '') {
                $instruments[] = $src;
            }
        }

        $bias = $score > 0 ? 'long' : ($score < 0 ? 'short' : 'mixed');
        $srcLabel = $instruments !== [] ? implode(',', array_unique($instruments)) : '시황/구조 글';
        $note = match ($bias) {
            'short' => sprintf('최근 %d일 숏/비중축소 바이어스 (%s)', $withinDays, $srcLabel),
            'long' => sprintf('최근 %d일 롱 바이어스 (%s)', $withinDays, $srcLabel),
            default => sprintf('최근 %d일 바이어스 혼재 (%s)', $withinDays, $srcLabel),
        };

        return [
            'bias' => $bias,
            'count' => count($hits),
            'latest_id' => isset($hits[0]['id']) ? (string) $hits[0]['id'] : null,
            'source_instruments' => array_values(array_unique($instruments)),
            'note' => $note,
        ];
    }

    /**
     * 본주 제안에 레버 바이어스 반영 (레버 호가로 진입가를 바꾸지 않음).
     *
     * @param array<string, mixed> $proposal
     * @param array{bias:string, count:int, latest_id:?string, source_instruments:list<string>, note:string} $bias
     * @return array<string, mixed>
     */
    public static function applyBiasToProposal(array $proposal, array $bias): array
    {
        $proposal['proxy_bias'] = $bias;
        $action = (string) ($proposal['action'] ?? '');

        if ($bias['bias'] === 'short' && in_array($action, ['add_on_pullback', 'watchlist_buy_zone'], true)) {
            $proposal['action'] = 'hold_or_trim_on_strength';
            $proposal['size_hint'] = '레버→본주 숏 바이어스: 신규 매수 보류. 보유 시 반등 축소 검토';
            $proposal['reason'] = trim((string) ($proposal['reason'] ?? '') . ' | ' . $bias['note']);
        } elseif ($bias['bias'] === 'short' && $action === 'wait') {
            $proposal['reason'] = trim((string) ($proposal['reason'] ?? '') . ' | ' . $bias['note']);
            $proposal['size_hint'] = '현금 유지 · 레버 치환상 반도체 숏 바이어스';
        } elseif ($bias['bias'] === 'long' && $action === 'wait') {
            $proposal['reason'] = trim((string) ($proposal['reason'] ?? '') . ' | ' . $bias['note'] . ' (차트 점수와 별개 참고)');
        } else {
            $proposal['reason'] = trim((string) ($proposal['reason'] ?? '') . ' | ' . $bias['note']);
        }

        return $proposal;
    }
}
