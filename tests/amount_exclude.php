<?php

declare(strict_types=1);

require __DIR__ . '/../bin/bootstrap.php';

use ChartEntryLab\KrAmountLeadersClient;

function ck(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "OK {$label}\n";
}

$drop = [
    '한국제17호스팩',
    'KB제34호스팩',
    '엔에이치스팩29호',
    'KODEX SK하이닉스단일종목레버리지',
    'SOL AI반도체TOP2플러스',
    'TIGER SK하이닉스단일종목레버리지',
    'TIGER CD1년금리액티브(합성)',
    'RISE 삼성전자SK하이닉스채권혼합50',
    '신한 인버스 2X 천연가스 선물 ETN(H)',
    '삼성 레버리지 항셍테크 ETN(H)',
    '히어로즈 단기통안채',
    'TIME 미국나스닥100액티브',
];
$keep = [
    '삼성전자',
    '삼성전자우',
    'SK하이닉스',
    '네오사피엔스',
    '스카이랩스',
    '인제니아테라퓨틱스(Reg.S)',
    '현대차',
    '카카오',
    '솔브레인',
    '에이스침대',
    '맥쿼리인프라',
];

foreach ($drop as $name) {
    ck(KrAmountLeadersClient::excludedFromAmountRank($name), "제외 {$name}");
}
foreach ($keep as $name) {
    ck(!KrAmountLeadersClient::excludedFromAmountRank($name), "유지 {$name}");
}

echo "AMOUNT_EXCLUDE_PASS\n";
