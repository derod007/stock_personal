<?php

declare(strict_types=1);

namespace ChartEntryLab;

final class EntryRepository
{
    /** @var list<array<string, mixed>>|null */
    private ?array $memory = null;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * 읽기 전용 인메모리 저장소 (탭별 점수용).
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function fromArray(array $rows): self
    {
        $repo = new self('');
        $repo->memory = $rows;
        return $repo;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->memory !== null) {
            return $this->memory;
        }
        if ($this->path === '' || !is_file($this->path)) {
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
                $byId[$id] = $entry;
                continue;
            }
            // 기존 행을 우선: 스크래퍼 UNKNOWN/null로 확정 심볼·큐레이션을 덮지 않음
            $byId[$id] = $this->mergeEntry($byId[$id], $entry);
        }

        $this->write(array_values($byId));
        return $added;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeEntry(array $existing, array $incoming): array
    {
        $merged = $existing;
        foreach ($incoming as $key => $value) {
            if (in_array($key, ['learning_use', 'learning_reasons', 'exclude_price_label', 'price_scale_suspect', 'curated_at_kst', 'exit_reason'], true)) {
                continue;
            }
            if ($key === 'symbol') {
                $old = (string) ($existing['symbol'] ?? 'UNKNOWN');
                $new = (string) ($value ?? 'UNKNOWN');
                if ($new === 'UNKNOWN' || $new === '') {
                    continue;
                }
                if ($old !== 'UNKNOWN' && $old !== '' && $old !== $new) {
                    continue; // 기존 확정 심볼 유지
                }
            }
            if ($value === null && array_key_exists($key, $existing) && $existing[$key] !== null) {
                continue;
            }
            $merged[$key] = $value;
        }
        return $merged;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function writeAll(array $rows): void
    {
        $this->write($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function write(array $rows): void
    {
        if ($this->memory !== null || $this->path === '') {
            throw new \RuntimeException('In-memory EntryRepository is read-only');
        }
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
