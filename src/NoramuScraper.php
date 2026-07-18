<?php

declare(strict_types=1);

namespace ChartEntryLab;

final class NoramuScraper
{
    public function __construct(
        private readonly FmkoreaClient $client,
        private readonly FmkoreaSearchParser $searchParser,
        private readonly FmkoreaPostParser $postParser,
        private readonly EntrySignalExtractor $extractor,
        private readonly EntryRepository $repo,
    ) {
    }

    /**
     * @return array{
     *   pages_scanned:int,
     *   posts_listed:int,
     *   posts_fetched:int,
     *   events_extracted:int,
     *   events_added:int,
     *   skipped_chat:int,
     *   errors:list<string>
     * }
     */
    public function run(string $nick = '노라무', int $maxPages = 8, bool $refresh = false, bool $dryRun = false): array
    {
        $stats = [
            'pages_scanned' => 0,
            'posts_listed' => 0,
            'posts_fetched' => 0,
            'events_extracted' => 0,
            'events_added' => 0,
            'skipped_chat' => 0,
            'errors' => [],
        ];

        $listed = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $html = $this->client->searchByNick($nick, $page, useCache: !$refresh);
                $stats['pages_scanned']++;
                foreach ($this->searchParser->parsePostList($html) as $post) {
                    $listed[$post['id']] = $post;
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = "search page {$page}: " . $e->getMessage();
                break;
            }
        }
        $stats['posts_listed'] = count($listed);

        $newEvents = [];
        foreach ($listed as $postMeta) {
            $title = $postMeta['title'];
            if ($this->searchParser->isLikelyChat($title) && !$this->searchParser->isEntryCandidate($title)) {
                $stats['skipped_chat']++;
                continue;
            }
            if (!$this->searchParser->isEntryCandidate($title) && !$this->searchParser->isLikelyChat($title)) {
                // 애매한 제목은 본문까지 열어본다 (너무 공격적이면 후보만)
                if (mb_strlen($title) < 6) {
                    $stats['skipped_chat']++;
                    continue;
                }
            }

            // 제목 기준 강한 필터: 진입/분석 후보만 본문 요청
            if (!$this->searchParser->isEntryCandidate($title)) {
                $stats['skipped_chat']++;
                continue;
            }

            try {
                $html = $this->client->fetchDocument($postMeta['id'], useCache: !$refresh);
                $stats['posts_fetched']++;
                $parsed = $this->postParser->parse($html);
                if (($parsed['document_srl'] ?? null) === null) {
                    $parsed['document_srl'] = $postMeta['id'];
                }
                if ($parsed['title'] === '') {
                    $parsed['title'] = $title;
                }
                $events = $this->extractor->extract($parsed);
                $stats['events_extracted'] += count($events);
                foreach ($events as $event) {
                    $newEvents[] = $event;
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = "doc {$postMeta['id']}: " . $e->getMessage();
                if (str_contains($e->getMessage(), 'blocked request')) {
                    $stats['errors'][] = 'stopped early due to rate limit; rerun later or import browser HTML';
                    break;
                }
            }
        }

        if (!$dryRun) {
            $stats['events_added'] = $this->repo->mergeMany($newEvents);
        } else {
            $stats['events_added'] = 0;
            $stats['dry_run_preview'] = array_slice($newEvents, 0, 20);
        }

        return $stats;
    }
}
