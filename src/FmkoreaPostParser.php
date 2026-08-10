<?php

declare(strict_types=1);

namespace ChartEntryLab;

final class FmkoreaPostParser
{
    /**
     * @return array{
     *   document_srl:?string,
     *   title:string,
     *   author:string,
     *   posted_at_kst:?string,
     *   body:string,
     *   author_comments:list<string>
     * }
     */
    public function parse(string $html, ?string $authorNick = null): array
    {
        return $this->parsePages([$html], $authorNick);
    }

    /**
     * 본문 + 댓글 여러 페이지 HTML을 합쳐 파싱.
     *
     * @param list<string> $htmlPages
     * @return array{
     *   document_srl:?string,
     *   title:string,
     *   author:string,
     *   posted_at_kst:?string,
     *   body:string,
     *   author_comments:list<string>
     * }
     */
    public function parsePages(array $htmlPages, ?string $authorNick = null): array
    {
        $htmlPages = array_values(array_filter($htmlPages, static fn ($h) => is_string($h) && $h !== ''));
        if ($htmlPages === []) {
            return [
                'document_srl' => null,
                'title' => '',
                'author' => $authorNick !== null && trim($authorNick) !== '' ? trim($authorNick) : '노라무',
                'posted_at_kst' => null,
                'body' => '',
                'author_comments' => [],
            ];
        }

        $main = $htmlPages[0];
        $author = $authorNick !== null && trim($authorNick) !== ''
            ? trim($authorNick)
            : ($this->extractAuthorFromHtml($main) ?? '노라무');

        $title = $this->matchOne('/<title>(.*?)\s*-\s*주식\s*-\s*에펨코리아<\/title>/su', $main)
            ?? $this->matchOne('/class="np_18px_span"[^>]*>(.*?)<\/span>/su', $main)
            ?? '';
        $title = html_entity_decode(trim(strip_tags($title)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $documentSrl = $this->matchOne('/document_srl=(\d+)/', $main)
            ?? $this->matchOne('/content="https?:\/\/www\.fmkorea\.com\/(\d+)"/', $main);

        $posted = null;
        if (preg_match('/(\d{4})\.\s*(\d{2})\.\s*(\d{2})\s+(\d{2}):(\d{2})/u', $main, $m)) {
            $posted = sprintf('%s-%s-%sT%s:%s:00+09:00', $m[1], $m[2], $m[3], $m[4], $m[5]);
        } elseif (preg_match('/(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/u', $main, $m)) {
            $posted = sprintf('%s-%s-%sT%s:%s:00+09:00', $m[1], $m[2], $m[3], $m[4], $m[5]);
        }

        $bodyHtml = $this->matchOne('/class="[^"]*xe_content[^"]*"[^>]*>(.*?)<\/div>/su', $main) ?? '';
        $body = $this->normalizeText($bodyHtml);
        // 동영상만 있고 본문 텍스트가 meta에만 있는 경우 보정
        if ($body === '' || preg_match('/Video 태그를 지원하지 않는/u', $body) === 1) {
            $meta = $this->matchOne('/<meta\s+name="description"\s+content="([^"]*)"/u', $main)
                ?? $this->matchOne('/<meta\s+property="og:description"\s+content="([^"]*)"/u', $main);
            if ($meta !== null && trim($meta) !== '') {
                $metaText = html_entity_decode(trim($meta), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($metaText !== '' && !str_starts_with($metaText, 'Video')) {
                    $body = $metaText;
                }
            }
            $body = trim(preg_replace('/Video 태그를 지원하지 않는 브라우저입니다\.?/u', '', $body) ?? $body);
        }

        $authorComments = [];
        $memberId = $this->extractAuthorMemberId($main);
        foreach ($htmlPages as $pageHtml) {
            foreach ($this->collectAuthorComments($pageHtml, $author, $memberId) as $c) {
                $authorComments[] = $c;
            }
        }

        return [
            'document_srl' => $documentSrl,
            'title' => $title,
            'author' => $author,
            'posted_at_kst' => $posted,
            'body' => $body,
            'author_comments' => array_values(array_unique($authorComments)),
        ];
    }

    /**
     * @return list<string>
     */
    private function collectAuthorComments(string $html, string $author, ?string $memberId = null): array
    {
        $authorComments = [];
        $memberId ??= $this->extractAuthorMemberId($html);

        if (preg_match_all('/<li\b[^>]*\bfdb_itm\b[^>]*>.*?<\/li>/su', $html, $blocks)) {
            foreach ($blocks[0] as $block) {
                if (!$this->isAuthorCommentBlock($block, $author, $memberId)) {
                    continue;
                }
                $cHtml = $this->matchOne('/class="[^"]*xe_content[^"]*"[^>]*>(.*?)<\/div>/su', $block) ?? '';
                $cText = $this->normalizeText($cHtml);
                if ($cText !== '') {
                    $authorComments[] = $cText;
                }
            }
        }

        return array_values(array_unique($authorComments));
    }

    private function isAuthorCommentBlock(string $block, string $author, ?string $memberId): bool
    {
        // 에펨: 본문 작성자 댓글에 document_writer 클래스가 붙음
        if (str_contains($block, 'document_writer')) {
            return true;
        }

        // meta 안의 첫 member_plate 만 본다 (본문/인용 닉 오탐 방지)
        if (!preg_match(
            '/<div class="meta">.*?class=[\'"]member_(\d+)[^\'"]*[\'"][^>]*>(.*?)<\/a>/su',
            $block,
            $m
        )) {
            return false;
        }
        $cmtMemberId = $m[1];
        $cmtNick = html_entity_decode(trim(strip_tags($m[2])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($memberId !== null && $cmtMemberId === $memberId) {
            return true;
        }
        return $cmtNick === $author;
    }

    private function extractAuthorMemberId(string $html): ?string
    {
        // 본문 헤더 쪽 작성자 (댓글 영역 이전)
        $head = $html;
        if (preg_match('/class="rd_hd".*?class="rd_body"/su', $html, $sec)) {
            $head = $sec[0];
        }
        if (preg_match('/class=[\'"]member_(\d+)\s+member_plate[\'"]/u', $head, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractAuthorFromHtml(string $html): ?string
    {
        $nick = $this->matchOne('/class="[^"]*member_[^"]*"[^>]*>([^<]{1,40})<\/a>/u', $html)
            ?? $this->matchOne('/\/user\/[^"]+"[^>]*>([^<]{1,40})<\/a>/u', $html);
        if ($nick === null || $nick === '') {
            return null;
        }
        $nick = html_entity_decode(trim($nick), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $nick !== '' ? $nick : null;
    }

    private function normalizeText(string $html): string
    {
        $html = preg_replace('/<br\s*\/?>/iu', "\n", $html) ?? $html;
        $html = preg_replace('/<\/p>/iu', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function matchOne(string $pattern, string $html): ?string
    {
        if (preg_match($pattern, $html, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
