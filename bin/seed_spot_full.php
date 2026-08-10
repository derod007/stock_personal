<?php

declare(strict_types=1);

/**
 * browser_posts / retrospective 기반 본주 full 시드 병합.
 */

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryCurator;
use ChartEntryLab\EntryRepository;

$root = dirname(__DIR__);
$repo = new EntryRepository($root . '/data/entries.json');

$seeds = [
    [
        'id' => 'e-20260717-mu-buyzone-800',
        'source_url' => 'https://www.fmkorea.com/10093834545',
        'document_srl' => '10093834545',
        'posted_at_kst' => '2026-07-17T09:59:00+09:00',
        'author' => '노라무',
        'symbol' => 'MU',
        'side' => 'long',
        'entry_price' => 800.0,
        'entry_price_ref' => 'buy_zone_declared',
        'stop_price' => null,
        'target_price' => null,
        'product_type' => 'us_stock',
        'tags' => ['buy_zone', 'structure_level', 'manual_seed'],
        'raw_quote' => '마이크론 받을려면 800불근처에서 해야됩니다 800불도 깨지면 끝',
        'source' => 'manual_seed',
    ],
    [
        'id' => 'e-20260703-samsung-resist-330k',
        'source_url' => 'https://www.fmkorea.com/10038691200',
        'document_srl' => '10038691200',
        'posted_at_kst' => '2026-07-03T23:44:00+09:00',
        'author' => '노라무',
        'symbol' => '005930.KS',
        'side' => 'long',
        'entry_price' => null,
        'entry_price_ref' => 'structure_reclaim',
        'stop_price' => null,
        'target_price' => 330000.0,
        'product_type' => 'kr_stock',
        'tags' => ['resistance_level', 'structure_playbook', 'manual_seed'],
        'raw_quote' => '33만원을 뚫지 못한다면 하락추세… 저점을 올려주고 33만원을 때리고 또 저점을 올려줘야됨. 아직은 진입 안함',
        'source' => 'manual_seed',
    ],
    [
        'id' => 'e-20260716-hynix-190-level',
        'source_url' => 'https://www.fmkorea.com/10089740119',
        'document_srl' => '10089740119',
        'posted_at_kst' => '2026-07-16T10:28:00+09:00',
        'author' => '노라무',
        'symbol' => '000660.KS',
        'side' => 'risk',
        'entry_price' => null,
        'stop_price' => 1900000.0,
        'stop_rule' => 'break_level',
        'target_price' => null,
        'product_type' => 'kr_stock',
        'symbol_note' => '글의 190 = 190만원으로 해석',
        'tags' => ['invalidation_declared', 'manual_seed'],
        'raw_quote' => '선제조건은 하이닉스가 오늘 190을 지켜주지못했을때',
        'source' => 'manual_seed',
    ],
    [
        'id' => 'e-20260717-stx-buyzone-700',
        'source_url' => 'https://www.fmkorea.com/10093834545',
        'document_srl' => '10093834545',
        'posted_at_kst' => '2026-07-17T09:59:00+09:00',
        'author' => '노라무',
        'symbol' => 'STX',
        'side' => 'long',
        'entry_price' => 700.0,
        'entry_price_ref' => 'buy_zone_declared',
        'stop_price' => null,
        'target_price' => null,
        'product_type' => 'us_stock',
        'tags' => ['buy_zone', 'structure_level', 'manual_seed'],
        'raw_quote' => '시게이트 받을꺼면 700불에서 받을만하구용',
        'source' => 'manual_seed',
    ],
    [
        'id' => 'e-20260717-orcl-140-level',
        'source_url' => 'https://www.fmkorea.com/10093834545',
        'document_srl' => '10093834545',
        'posted_at_kst' => '2026-07-17T09:59:00+09:00',
        'author' => '노라무',
        'symbol' => 'ORCL',
        'side' => 'short',
        'entry_price' => null,
        'stop_price' => null,
        'target_price' => 140.0,
        'product_type' => 'us_stock',
        'tags' => ['resistance_level', 'structure_level', 'manual_seed'],
        'raw_quote' => '오라클 140때리고 저점 못올리면 사망차트',
        'source' => 'manual_seed',
    ],
    [
        'id' => 'e-20260717-ewy-buyzone-156',
        'source_url' => 'https://www.fmkorea.com/10093834545',
        'document_srl' => '10093834545',
        'posted_at_kst' => '2026-07-17T09:59:00+09:00',
        'author' => '노라무',
        'symbol' => 'EWY',
        'side' => 'long',
        'entry_price' => 156.0,
        'entry_price_ref' => 'buy_zone_declared',
        'stop_price' => null,
        'target_price' => null,
        'product_type' => 'us_stock',
        'symbol_note' => '한국 ETF — 1번 계좌 직접 종목은 아니나 구조 라벨용',
        'tags' => ['buy_zone', 'structure_level', 'manual_seed'],
        'raw_quote' => 'EWY 받을자리는 156불정도',
        'source' => 'manual_seed',
    ],
];

$added = $repo->mergeMany($seeds);
$curator = new EntryCurator();
$result = $curator->curate($repo->all());
$repo->writeAll($result['entries']);

echo "seeds_merged_newish={$added}\n";
foreach ($result['summary'] as $k => $v) {
    echo "  {$k}={$v}\n";
}
