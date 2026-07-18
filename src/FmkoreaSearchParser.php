<?php

declare(strict_types=1);

namespace ChartEntryLab;

final class FmkoreaSearchParser
{
    /**
     * @return list<array{id:string,title:string,url:string}>
     */
    public function parsePostList(string $html): array
    {
        $posts = [];
        if (preg_match_all(
            '/document_srl=(\d+)[^"]*"[^>]*>([^<]{2,200})</u',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $id = $m[1];
                $title = html_entity_decode(trim(preg_replace('/\s+/u', ' ', $m[2]) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($title === '' || ctype_digit($title) || $title === '노라무') {
                    continue;
                }
                $posts[$id] = [
                    'id' => $id,
                    'title' => $title,
                    'url' => 'https://www.fmkorea.com/' . $id,
                ];
            }
        }

        // fallback: absolute /{id} links with title nearby
        if ($posts === [] && preg_match_all('/href="https?:\/\/www\.fmkorea\.com\/(\d+)"[^>]*>([^<]{2,200})</u', $html, $m2, PREG_SET_ORDER)) {
            foreach ($m2 as $row) {
                $id = $row[1];
                $title = html_entity_decode(trim($row[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($title === '' || ctype_digit($title)) {
                    continue;
                }
                $posts[$id] = [
                    'id' => $id,
                    'title' => $title,
                    'url' => 'https://www.fmkorea.com/' . $id,
                ];
            }
        }

        return array_values($posts);
    }

    public function isLikelyChat(string $title): bool
    {
        $t = trim($title);
        if (mb_strlen($t) <= 2) {
            return true;
        }
        $chatExact = ['ㅇㅇ', 'ㅋㅋ', 'ㅋㅋㅋ', 'ㅋㅋㅋㅋ', 'ㅋㅋㅋㅋㅋ', 'ㅊㅋㅊㅋ', '주하', '흠', '정보', '공시', '재공시', '...', '흑흑', '으흠흠~', '흐흫...', '머야머야'];
        if (in_array($t, $chatExact, true)) {
            // "공시/재공시"는 제목만으로는 채팅일 수도, 진입공시일 수도 → 본문 검사 대상
            return !in_array($t, ['공시', '재공시', '정보'], true);
        }
        if (preg_match('/^(ㅋ+|ㅎ+|ㅇ+|ㅠ+|ㅜ+|\.+)$/u', $t)) {
            return true;
        }
        return false;
    }

    public function isEntryCandidate(string $title): bool
    {
        if ($this->isLikelyChat($title) && !preg_match('/공시|진입|익절|손절|매수|매도|숏|롱|타점|차트|분석|시나리오|추천|목표가/u', $title)) {
            return false;
        }
        return (bool) preg_match(
            '/공시|진입|익절|손절|매수|매도|숏|롱|타점|차트|분석|시나리오|추천|목표가|자리|비중|프로그램|레버|인버스|샌디|마이크론|하이닉스|삼전|코루|쏙스|SOXS|SOXL|평단/iu',
            $title
        ) || in_array(trim($title), ['공시', '재공시', '정보'], true);
    }
}
