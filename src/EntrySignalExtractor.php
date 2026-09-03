<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 본문/댓글에서 진입·손절·목표가·방향을 규칙 기반으로 추출.
 */
final class EntrySignalExtractor
{
    /**
     * @param array{
     *   document_srl:?string,
     *   title:string,
     *   author:string,
     *   posted_at_kst:?string,
     *   body:string,
     *   author_comments:list<string>
     * } $post
     * @return list<array<string, mixed>>
     */
    public function extract(array $post): array
    {
        $blob = trim($post['title'] . "\n" . $post['body'] . "\n" . implode("\n", $post['author_comments']));
        if ($blob === '') {
            return [];
        }

        $events = [];
        $baseId = 'fm-' . ($post['document_srl'] ?? sha1($post['title']));
        $isTopPatternLesson = (string) ($post['document_srl'] ?? '') === '10237234875';

        // 평단/매수/진입/타점 (공백·조사 변형 허용)
        if (preg_match_all('/(?:평단|매수(?:타점|완)?|진입|타점|분할해서)\s*([0-9]+(?:\.[0-9]+)?)\s*(불|달러|원|₩)?/u', $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $row) {
                $events[] = $this->makeEvent($post, $baseId . '-entry-' . $i, [
                    'side' => $this->inferSide($blob, 'long'),
                    'entry_price' => (float) $row[1],
                    'currency' => $this->currency($row[2] ?? null),
                    'tags' => ['parsed_entry'],
                    'raw_quote' => $row[0],
                ]);
            }
        }
        // "soxs 3.36" / "쏙스 3.63" / "snxx4.9" 한 줄 포지션
        if (preg_match_all('/(?:^|[\s\n])(soxs|SOXS|쏙스|snxx|SNDK|sndk)\s*([0-9]+(?:\.[0-9]+)?)/u', $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $row) {
                $tok = strtolower($row[1]);
                $hint = in_array($tok, ['snxx', 'sndk'], true) ? 'SNDK' : 'SOXS';
                $events[] = $this->makeEvent($post, $baseId . '-soxs-px-' . $i, [
                    'side' => 'long',
                    'entry_price' => (float) $row[2],
                    'symbol_hint' => $hint,
                    'tags' => ['parsed_entry', $hint === 'SNDK' ? 'sndk_line_price' : 'soxs_line_price'],
                    'raw_quote' => trim($row[0]),
                ]);
            }
        }
        // "코루매수 완 19.50" / "SOXS 매수타점이 3.91"
        if (preg_match_all('/([A-Za-z가-힣0-9_]+)\s*(?:매수(?:타점)?|롱)\s*(?:완|이|이?\s*)?(?:이|가|은|는)?\s*([0-9]+(?:\.[0-9]+)?)/u', $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $row) {
                $events[] = $this->makeEvent($post, $baseId . '-entry2-' . $i, [
                    'side' => 'long',
                    'entry_price' => (float) $row[2],
                    'symbol_hint' => $row[1],
                    'tags' => ['parsed_entry'],
                    'raw_quote' => $row[0],
                ]);
            }
        }

        // 손절
        if (preg_match_all('/손절\s*(?:-|—|–)?\s*([0-9]+(?:\.[0-9]+)?)(%|불|달러|원)?/u', $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $row) {
                $stop = (float) $row[1];
                $events[] = $this->makeEvent($post, $baseId . '-stop-' . $i, [
                    'side' => 'risk',
                    'stop_price' => ($row[2] ?? '') === '%' ? null : $stop,
                    'stop_rule' => ($row[2] ?? '') === '%' ? ('pct_-' . $stop) : 'absolute',
                    'tags' => ['parsed_stop'],
                    'raw_quote' => $row[0],
                ]);
            }
        }
        if (preg_match_all('/([0-9]+(?:\.[0-9]+)?)\s*(?:불|달러|원)?\s*(?:깨지면|이탈|이하)\s*손절/u', $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $row) {
                $events[] = $this->makeEvent($post, $baseId . '-stop2-' . $i, [
                    'side' => 'risk',
                    'stop_price' => (float) $row[1],
                    'stop_rule' => 'break_level',
                    'tags' => ['parsed_stop'],
                    'raw_quote' => $row[0],
                ]);
            }
        }

        // 목표가/매도예정/익절가
        if (preg_match_all('/(?:목표가|익절(?:가)?|매도예정)\s*(?:는|은|이)?\s*([0-9]+(?:\.[0-9]+)?)/u', $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $row) {
                $events[] = $this->makeEvent($post, $baseId . '-tp-' . $i, [
                    'side' => 'exit',
                    'target_price' => (float) $row[1],
                    'tags' => ['parsed_target'],
                    'raw_quote' => $row[0],
                ]);
            }
        }
        // "샌디숏 4.29" / "쏙스 5.05" 익절가 나열
        if (preg_match('/익절/u', $post['title'] . $blob) && preg_match_all('/(샌디숏|쏙스|SOXS|코루)\s*([0-9]+(?:\.[0-9]+)?)/iu', $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $row) {
                $events[] = $this->makeEvent($post, $baseId . '-tp3-' . $i, [
                    'side' => 'exit',
                    'target_price' => (float) $row[2],
                    'symbol_hint' => $row[1],
                    'tags' => ['parsed_target', 'scale_out'],
                    'raw_quote' => $row[0],
                ]);
            }
        }
        if (preg_match_all('/([0-9]+(?:\.[0-9]+)?)\s*불에\s*매도예정/u', $blob, $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $row) {
                $events[] = $this->makeEvent($post, $baseId . '-tp2-' . $i, [
                    'side' => 'exit',
                    'target_price' => (float) $row[1],
                    'tags' => ['parsed_target'],
                    'raw_quote' => $row[0],
                ]);
            }
        }

        // 심볼 힌트만 있는 구조 글
        $needsSamhaView = preg_match('/삼성전자|하이닉스|삼하/u', $post['title'] . "\n" . $blob) === 1
            && preg_match('/들어갈자리없음|물리는자리|굳이\s*삼하/u', $blob) === 1;
        if ($events === [] && preg_match('/차트|분석|숏|롱|자리|시나리오|프로그램|비중|삼하|공부하기/u', $blob) === 1) {
            $structureTags = ['structure_or_view'];
            if ($isTopPatternLesson) {
                $structureTags = array_merge($structureTags, [
                    'important_lesson',
                    'top_failure_pattern',
                    'inverse_bottom_pattern',
                    'no_direct_entry',
                ]);
            }
            $events[] = $this->makeEvent($post, $baseId . '-structure', [
                'side' => $this->inferSide($blob, 'observe'),
                'entry_price' => null,
                'tags' => $structureTags,
                'raw_quote' => mb_substr($blob, 0, 180),
            ]);
        } elseif ($needsSamhaView) {
            $events[] = $this->makeEvent($post, $baseId . '-structure', [
                'side' => 'observe',
                'entry_price' => null,
                'tags' => ['structure_or_view', 'samha_no_entry'],
                'raw_quote' => mb_substr($blob, 0, 180),
            ]);
        }

        // 심볼 보강: raw_quote/힌트가 있으면 그걸로 먼저 판별 (한 글에 여러 종목 익절가 나열 대응)
        foreach ($events as &$e) {
            $raw = trim((string) ($e['raw_quote'] ?? ''));
            $hint = trim((string) ($e['symbol_hint'] ?? ''));
            $tags = array_map('strval', $e['tags'] ?? []);
            $resolved = null;
            $isViewOnly = in_array('structure_or_view', $tags, true)
                && !in_array('parsed_entry', $tags, true);
            if ($isViewOnly && trim((string) ($post['title'] ?? '')) !== '') {
                $resolved = $this->resolveSymbol((string) $post['title']);
            }
            if ($raw !== '' && ($resolved === null || $resolved['symbol'] === 'UNKNOWN')) {
                $resolved = $this->resolveSymbol($raw);
            }
            if (($resolved === null || $resolved['symbol'] === 'UNKNOWN') && $hint !== '') {
                $resolved = $this->resolveSymbol($hint);
            }
            if ($resolved === null || $resolved['symbol'] === 'UNKNOWN') {
                $resolved = $this->resolveSymbol($post['title'] . ' ' . $blob);
            }
            $e['symbol'] = $resolved['symbol'];
            $e['symbol_note'] = $resolved['note'];
            $e['related_underlying'] = $resolved['underlying'];
            $e['product_type'] = $resolved['product_type'];
            if ($isTopPatternLesson) {
                $e['lesson_ref'] = 'noramu_top_pattern_v1';
                $e['important_post_archive'] = 'data/important_posts/10237234875.json';
            }
            unset($e['symbol_hint']);
        }
        unset($e);

        return $this->dedupe($events);
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function makeEvent(array $post, string $id, array $extra): array
    {
        return array_merge([
            'id' => $id,
            'source_url' => isset($post['document_srl']) ? 'https://www.fmkorea.com/' . $post['document_srl'] : null,
            'document_srl' => $post['document_srl'] ?? null,
            'title' => $post['title'] ?? '',
            'posted_at_kst' => $post['posted_at_kst'] ?? null,
            'author' => $post['author'] ?? '노라무',
            'collected_at_kst' => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Seoul')))->format(DATE_ATOM),
            'source' => 'fmkorea_scraper',
        ], $extra);
    }

    private function inferSide(string $blob, string $default): string
    {
        $hasLong = (bool) preg_match('/롱|매수|진입/u', $blob);
        $hasShort = (bool) preg_match('/숏|매도|인버스|쏙스|SOXS/iu', $blob);
        if ($hasLong && !$hasShort) {
            return 'long';
        }
        if ($hasShort && !$hasLong) {
            return 'short';
        }
        if ($hasLong && $hasShort) {
            return 'mixed_or_hedge';
        }
        return $default;
    }

    private function currency(?string $unit): string
    {
        return match ($unit) {
            '불', '달러' => 'USD',
            '원', '₩' => 'KRW',
            default => 'unknown',
        };
    }

    /**
     * @return array{symbol:string,note:string,underlying:?string,product_type:string}
     */
    private function resolveSymbol(string $text): array
    {
        // 짧은 raw_quote/힌트를 우선 (같은 본문에 SOXS+샌디숏이 같이 있을 때 오분류 방지)
        if (preg_match('/^샌디숏\b/u', trim($text)) || preg_match('/\b샌디숏\s*[0-9]/u', $text)) {
            return ['symbol' => 'SNDK_SHORT', 'note' => '샌디스크 숏/인버스성', 'underlying' => 'SNDK', 'product_type' => 'leveraged'];
        }
        if (preg_match('/^쏙스\b|^SOXS\b/iu', trim($text)) || preg_match('/\b(?:쏙스|SOXS)\s*[0-9]/iu', $text)) {
            return ['symbol' => 'SOXS', 'note' => '반도체 인버스 → curate 시 MU 본주 치환', 'underlying' => 'MU', 'product_type' => 'leveraged_etf'];
        }
        if (preg_match('/코루/u', $text)) {
            return ['symbol' => '122630.KS', 'note' => '코덱스 레버리지 → curate 시 삼전 본주 치환', 'underlying' => '005930.KS', 'product_type' => 'leveraged_etf'];
        }
        if (preg_match('/샌디.*2배|2배.*샌디/u', $text)) {
            return ['symbol' => 'SNDK_2X', 'note' => '샌디스크 2배 상품', 'underlying' => 'SNDK', 'product_type' => 'leveraged'];
        }
        if (preg_match('/샌디숏/u', $text)) {
            return ['symbol' => 'SNDK_SHORT', 'note' => '샌디스크 숏/인버스성', 'underlying' => 'SNDK', 'product_type' => 'leveraged'];
        }
        if (preg_match('/SOXS|쏙스/iu', $text)) {
            return ['symbol' => 'SOXS', 'note' => '반도체 인버스 → curate 시 MU 본주 치환', 'underlying' => 'MU', 'product_type' => 'leveraged_etf'];
        }
        if (preg_match('/샌디|샌디스크|SNDK|snxx/iu', $text)) {
            return ['symbol' => 'SNDK', 'note' => '샌디스크 본주 또는 관련', 'underlying' => 'SNDK', 'product_type' => 'us_stock'];
        }
        if (preg_match('/마이크론|\bMU\b/iu', $text)) {
            return ['symbol' => 'MU', 'note' => '', 'underlying' => 'MU', 'product_type' => 'us_stock'];
        }
        if (preg_match('/하이닉스|하닉|\b닉스\b|sk하이닉스/iu', $text)) {
            return ['symbol' => '000660.KS', 'note' => 'SK하이닉스', 'underlying' => '000660.KS', 'product_type' => 'kr_stock'];
        }
        if (preg_match('/삼하/u', $text)) {
            return ['symbol' => '000660.KS', 'note' => '삼하(삼성·하이닉스) — 대표 심볼 하이닉스', 'underlying' => '000660.KS', 'product_type' => 'kr_stock'];
        }
        if (preg_match('/삼전|삼성전자/u', $text)) {
            return ['symbol' => '005930.KS', 'note' => '삼성전자', 'underlying' => '005930.KS', 'product_type' => 'kr_stock'];
        }
        if (preg_match('/레몬헬스케어/u', $text)) {
            return ['symbol' => '247960.KQ', 'note' => '레몬헬스케어', 'underlying' => '247960.KQ', 'product_type' => 'kr_stock'];
        }
        if (preg_match('/오라클|ORCL/iu', $text)) {
            return ['symbol' => 'ORCL', 'note' => '', 'underlying' => 'ORCL', 'product_type' => 'us_stock'];
        }
        if (preg_match('/대원전선/u', $text)) {
            return ['symbol' => '006340.KS', 'note' => '대원전선', 'underlying' => '006340.KS', 'product_type' => 'kr_stock'];
        }
        if (preg_match('/한성기업/u', $text)) {
            return ['symbol' => '003680.KS', 'note' => '한성기업', 'underlying' => '003680.KS', 'product_type' => 'kr_stock'];
        }
        if (preg_match('/GS건설|지에스건설/iu', $text)) {
            return ['symbol' => '006360.KS', 'note' => 'GS건설', 'underlying' => '006360.KS', 'product_type' => 'kr_stock'];
        }
        if (preg_match('/sk이노베이션|에스케이이노/iu', $text)) {
            return ['symbol' => '096770.KS', 'note' => 'SK이노베이션', 'underlying' => '096770.KS', 'product_type' => 'kr_stock'];
        }
        if (preg_match('/유가|원유|오일/u', $text) === 1 && preg_match('/오일롱/u', $text) !== 1) {
            return ['symbol' => 'CL=F', 'note' => '원유(WTI)', 'underlying' => 'CL=F', 'product_type' => 'us_stock'];
        }
        return ['symbol' => 'UNKNOWN', 'note' => '심볼 미확정', 'underlying' => null, 'product_type' => 'unknown'];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function dedupe(array $events): array
    {
        $out = [];
        $seen = [];
        foreach ($events as $e) {
            $key = implode('|', [
                (string) ($e['symbol'] ?? ''),
                (string) ($e['side'] ?? ''),
                (string) ($e['entry_price'] ?? ''),
                (string) ($e['stop_price'] ?? ''),
                (string) ($e['target_price'] ?? ''),
                (string) ($e['raw_quote'] ?? ''),
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $e;
        }
        return $out;
    }
}
