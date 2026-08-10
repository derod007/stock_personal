<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 계좌별 운영 프로필.
 * - account1: 메모리 중장기, 레버는 본주 치환(직접 매매 비권고), 보수적 분할
 * - custom: 임의 티커, 같은 구조 논리, 비중만 조금 여유
 * - isa: 레버 본주 치환 + 더 낮은 회전/비중
 */
final class AccountProfile
{
    /**
     * @param list<string>|null $coreSymbols null이면 종목 제한 없음(표시용 힌트만)
     * @param list<string> $blockedSymbols
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly bool $blockLeverage,
        public readonly int $addScoreThreshold,
        public readonly int $watchScoreThreshold,
        public readonly int $trimScoreThreshold,
        public readonly float $addSizeMinPct,
        public readonly float $addSizeMaxPct,
        public readonly int $maxSplits,
        public readonly ?array $coreSymbols,
        public readonly array $blockedSymbols,
    ) {
    }

    public static function fromId(string $id): self
    {
        return match ($id) {
            'custom' => self::custom(),
            'isa' => self::isa(),
            default => self::account1(),
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return [self::account1(), self::custom(), self::isa()];
    }

    public static function account1(): self
    {
        return new self(
            id: 'account1',
            label: '1번 계좌 (메모리 스윙)',
            blockLeverage: true,
            addScoreThreshold: 70,
            watchScoreThreshold: 55,
            trimScoreThreshold: 35,
            addSizeMinPct: 10.0,
            addSizeMaxPct: 20.0,
            maxSplits: 3,
            coreSymbols: [
                '000660.KS',
                '005930.KS',
                '084370.KQ',
                'MU',
                'SNDK',
            ],
            blockedSymbols: ['SOXS', 'SNDK_2X', 'SNDK_SHORT', '122630.KS'],
        );
    }

    public static function custom(): self
    {
        return new self(
            id: 'custom',
            label: '커스텀 (임의 티커)',
            blockLeverage: true,
            addScoreThreshold: 70,
            watchScoreThreshold: 55,
            trimScoreThreshold: 35,
            addSizeMinPct: 10.0,
            addSizeMaxPct: 25.0,
            maxSplits: 3,
            coreSymbols: null,
            blockedSymbols: ['SOXS', 'SNDK_2X', 'SNDK_SHORT', '122630.KS'],
        );
    }

    public static function isa(): self
    {
        return new self(
            id: 'isa',
            label: 'ISA/연금 (낮은 회전)',
            blockLeverage: true,
            addScoreThreshold: 75,
            watchScoreThreshold: 60,
            trimScoreThreshold: 40,
            addSizeMinPct: 5.0,
            addSizeMaxPct: 10.0,
            maxSplits: 2,
            coreSymbols: null,
            blockedSymbols: ['SOXS', 'SNDK_2X', 'SNDK_SHORT', '122630.KS'],
        );
    }

    public function isBlocked(string $symbol): bool
    {
        if (!$this->blockLeverage) {
            return false;
        }
        $upper = strtoupper($symbol);
        foreach ($this->blockedSymbols as $blocked) {
            if (strtoupper($blocked) === $upper) {
                return true;
            }
        }
        return false;
    }

    public function sizeHintAdd(): string
    {
        return sprintf(
            '가진 현금의 %.0f~%.0f%%만, 최대 %d번에 나눠서',
            $this->addSizeMinPct,
            $this->addSizeMaxPct,
            $this->maxSplits
        );
    }
}
