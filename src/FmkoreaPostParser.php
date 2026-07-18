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
    public function parse(string $html): array
    {
        $title = $this->matchOne('/<title>(.*?)\s*-\s*주식\s*-\s*에펨코리아<\/title>/su', $html)
            ?? $this->matchOne('/class="np_18px_span"[^>]*>(.*?)<\/span>/su', $html)
            ?? '';
        $title = html_entity_decode(trim(strip_tags($title)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $documentSrl = $this->matchOne('/document_srl=(\d+)/', $html)
            ?? $this->matchOne('/content="https?:\/\/www\.fmkorea\.com\/(\d+)"/', $html);

        $posted = null;
        if (preg_match('/(\d{4})\.\s*(\d{2})\.\s*(\d{2})\s+(\d{2}):(\d{2})/u', $html, $m)) {
            $posted = sprintf('%s-%s-%sT%s:%s:00+09:00', $m[1], $m[2], $m[3], $m[4], $m[5]);
        } elseif (preg_match('/(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/u', $html, $m)) {
            $posted = sprintf('%s-%s-%sT%s:%s:00+09:00', $m[1], $m[2], $m[3], $m[4], $m[5]);
        }

        $bodyHtml = $this->matchOne('/class="[^"]*xe_content[^"]*"[^>]*>(.*?)<\/div>/su', $html) ?? '';
        $body = $this->normalizeText($bodyHtml);

        $authorComments = [];
        if (preg_match_all('/fdb_itm.*?<\/(?:li|div)>/su', $html, $blocks)) {
            foreach ($blocks[0] as $block) {
                if (!str_contains($block, '노라무')) {
                    continue;
                }
                $cHtml = $this->matchOne('/class="[^"]*xe_content[^"]*"[^>]*>(.*?)<\/div>/su', $block) ?? '';
                $cText = $this->normalizeText($cHtml);
                if ($cText !== '') {
                    $authorComments[] = $cText;
                }
            }
        }

        // BEST 댓글 텍스트 폴백
        if (preg_match_all('/BEST\s*노라무.*?댓글로 가기/su', $html, $bests)) {
            foreach ($bests[0] as $chunk) {
                $t = $this->normalizeText($chunk);
                $t = preg_replace('/BEST\s*노라무\s*\d{4}\.\s*\d{2}\.\s*\d{2}\s*\d{2}:\d{2}/u', '', $t) ?? $t;
                $t = trim(str_replace('댓글로 가기', '', $t));
                if ($t !== '') {
                    $authorComments[] = $t;
                }
            }
        }

        $authorComments = array_values(array_unique($authorComments));

        return [
            'document_srl' => $documentSrl,
            'title' => $title,
            'author' => '노라무',
            'posted_at_kst' => $posted,
            'body' => $body,
            'author_comments' => $authorComments,
        ];
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
