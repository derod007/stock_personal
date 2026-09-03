<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 노라무+알파(다른 닉) entries 병합·탭용 뷰 헬퍼.
 */
final class AlphaEntries
{
    public const TAB_NORAMU = 'chart';
    public const TAB_DIGINGONYOU = 'digingonyou';
    public const TAB_MERGED = 'merged';

    /** @var list<string> */
    public const TABS = [
        self::TAB_NORAMU,
        self::TAB_DIGINGONYOU,
        self::TAB_MERGED,
    ];

    /**
     * @return array{id:string,label:string}
     */
    public static function normalizeTab(?string $tab): array
    {
        if ($tab === 'noramu') {
            $tab = self::TAB_NORAMU;
        }
        $id = is_string($tab) && in_array($tab, self::TABS, true) ? $tab : self::TAB_NORAMU;
        $label = match ($id) {
            self::TAB_DIGINGONYOU => '기타',
            self::TAB_MERGED => '합침',
            default => '차트',
        };

        return ['id' => $id, 'label' => $label];
    }

    /**
     * @param list<array<string, mixed>> $noramu
     * @param list<array<string, mixed>> $digingonyou
     * @return list<array<string, mixed>>
     */
    public static function merge(array $noramu, array $digingonyou): array
    {
        $out = [];
        foreach ($noramu as $row) {
            $out[] = self::tagSource($row, '노라무');
        }
        foreach ($digingonyou as $row) {
            $out[] = self::tagSource($row, '디깅온유');
        }

        usort($out, static function (array $a, array $b): int {
            return strcmp((string) ($b['posted_at_kst'] ?? ''), (string) ($a['posted_at_kst'] ?? ''));
        });

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $noramu
     * @param list<array<string, mixed>> $digingonyou
     * @return list<array<string, mixed>>
     */
    public static function forTab(string $tab, array $noramu, array $digingonyou): array
    {
        return match ($tab) {
            self::TAB_DIGINGONYOU => array_map(
                static fn (array $row): array => self::tagSource($row, '디깅온유'),
                $digingonyou
            ),
            self::TAB_MERGED => self::merge($noramu, $digingonyou),
            default => array_map(
                static fn (array $row): array => self::tagSource($row, '노라무'),
                $noramu
            ),
        };
    }

    /**
     * @param list<array<string, mixed>> $noramu
     * @param list<array<string, mixed>> $digingonyou
     * @return list<array<string, mixed>> entries for ProposalService learning/bias
     */
    public static function scoringEntries(string $tab, array $noramu, array $digingonyou): array
    {
        return match ($tab) {
            self::TAB_DIGINGONYOU => $digingonyou,
            self::TAB_MERGED => array_values(array_merge($noramu, $digingonyou)),
            default => $noramu,
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function tagSource(array $row, string $sourceAuthor): array
    {
        if (!isset($row['author']) || (string) $row['author'] === '') {
            $row['author'] = $sourceAuthor;
        }
        $row['source_author'] = $sourceAuthor;
        return $row;
    }
}
