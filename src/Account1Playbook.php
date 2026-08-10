<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 하위 호환 래퍼 — 내부적으로 AccountPlaybook(account1) 사용.
 */
final class Account1Playbook
{
    /** @var list<string> */
    public const CORE_SYMBOLS = [
        '000660.KS',
        '005930.KS',
        '084370.KQ',
        'MU',
        'SNDK',
    ];

    private readonly AccountPlaybook $inner;

    public function __construct()
    {
        $this->inner = AccountPlaybook::forProfile('account1');
    }

    /**
     * @param array<string, float|int|bool|string|null> $features
     * @return array<string, mixed>
     */
    public function decide(array $features, string $symbol): array
    {
        return $this->inner->decide($features, $symbol);
    }
}
