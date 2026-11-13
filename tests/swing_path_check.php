<?php

declare(strict_types=1);

require __DIR__ . '/../bin/bootstrap.php';

use ChartEntryLab\SwingPathCheck;

function check(bool $ok, string $why): void
{
    if (!$ok) {
        throw new RuntimeException($why);
    }
    echo "CHECK_PASS $why\n";
}

$root = dirname(__DIR__);
$batchDir = $root . '/docs/swing-path-compare-2025h1/batches';
$summaryPath = $root . '/docs/swing-path-compare-2025h1/summary.json';
check(SwingPathCheck::verify($batchDir, $summaryPath) === [], 'the preserved batches match the summary');

$temp = sys_get_temp_dir() . '/swing-path-check-' . getmypid();
mkdir($temp);
$manifest = json_decode((string) file_get_contents($batchDir . '/manifest.json'), true);
$missing = $manifest;
$missing['files'] = [$manifest['files'][0]];
$missing['files'][0]['file'] = 'absent.json';
file_put_contents($temp . '/manifest.json', json_encode($missing));
$gone = SwingPathCheck::verify($temp, $summaryPath);
check($gone === ['묶음 1 파일이 없습니다.'], 'a missing batch file fails');

file_put_contents($temp . '/batch-1.json', '{"changed":true}');
$changed = $manifest;
$changed['files'] = [$manifest['files'][0]];
file_put_contents($temp . '/manifest.json', json_encode($changed));
$hash = SwingPathCheck::verify($temp, $summaryPath);
check($hash === ['묶음 1 해시가 저장된 값과 다릅니다.'], 'a changed batch hash fails');

$summary = json_decode((string) file_get_contents($summaryPath), true);
$summary['as_of'] = '2025-10-31';
$shifted = $temp . '/summary.json';
file_put_contents($shifted, json_encode($summary));
$condition = SwingPathCheck::verify($batchDir, $shifted);
check($condition === ['실행 조건이 summary.json 과 다릅니다.'], 'a different observation end fails');
echo "CHECK_OK\n";
