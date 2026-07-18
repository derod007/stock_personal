<?php

declare(strict_types=1);

namespace ChartEntryLab;

final class EntryRepository
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        return $rows;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function append(array $entry): void
    {
        $rows = $this->all();
        $rows[] = $entry;
        $this->write($rows);
    }

    /**
     * id 기준 병합. 신규 건수 반환.
     *
     * @param list<array<string, mixed>> $entries
     */
    public function mergeMany(array $entries): int
    {
        $rows = $this->all();
        $byId = [];
        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $byId[(string) $row['id']] = $row;
            }
        }

        $added = 0;
        foreach ($entries as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (!isset($byId[$id])) {
                $added++;
            }
            // 기존 수동 시드가 있으면 스크래퍼 필드로 보강만
            $byId[$id] = isset($byId[$id]) ? array_merge($byId[$id], $entry) : $entry;
        }

        $this->write(array_values($byId));
        return $added;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function write(array $rows): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(
            $this->path,
            json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
