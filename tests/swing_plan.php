<?php

declare(strict_types=1);

require __DIR__ . '/../bin/bootstrap.php';

use ChartEntryLab\SwingPlan;
use ChartEntryLab\SwingResearch;

function sp(bool $ok, string $why): void
{
    if (!$ok) {
        throw new RuntimeException($why);
    }
    echo "PLAN_PASS $why\n";
}

function planDir(string $name): string
{
    $dir = sys_get_temp_dir() . '/swing-plan-' . $name . '-' . getmypid();
    if (is_dir($dir)) {
        planRemove($dir);
    }

    return $dir;
}

function planRemove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function planPost(array $extra = []): array
{
    return $extra + [
        'plan_form' => '1',
        'csrf' => '0123456789abcdef0123456789abcdef',
        'submit_id' => 's' . bin2hex(random_bytes(8)),
        'symbol' => '005930.KS',
        'hold' => '',
        'entry' => '',
        'entry_frame' => '',
        'stop' => '',
        'stop_frame' => '',
        'target' => '',
        'note' => '',
        'source_url' => '',
        'as_of' => '',
        'recorded_at' => '2000-01-01T00:00',
    ];
}

function planSave(string $dir, array $post, DateTimeImmutable $now): array
{
    return SwingPlan::accept($dir, $post, '0123456789abcdef0123456789abcdef', $now);
}

function planDoc(string $dir, string $id): array
{
    foreach (SwingPlan::plans($dir) as $plan) {
        if ($plan['id'] === $id) {
            return $plan;
        }
    }
    throw new RuntimeException('missing plan');
}

$now = new DateTimeImmutable('2026-11-15T23:40:00+09:00');
$missing = planDir('missing');
sp(SwingPlan::plans($missing) === [] && !is_dir($missing), 'a missing store stays missing on lookup');
$emptyHtml = SwingPlan::section($missing, [], null, '0123456789abcdef0123456789abcdef');
sp(!is_dir($missing) && str_contains($emptyHtml, '저장된 계획이 없습니다') && str_contains($emptyHtml, '사전 매매 계획'), 'rendering a lookup does not create a folder');

$bad = planDir('bad');
$rejected = planSave($bad, planPost(['symbol' => '../005930.KS']), $now);
sp($rejected['status'] === 'rejected' && !is_dir($bad), 'a bad symbol does not create a store');
$csrf = SwingPlan::accept($bad, planPost(), 'ffffffffffffffffffffffffffffffff', $now);
sp($csrf['status'] === 'rejected' && !is_dir($bad), 'a mismatched token does not write');
$script = planPost([
    'entry' => '<script>alert(1)</script>',
    'source_url' => 'javascript:alert(1)',
    'as_of' => '2026-10-21T17:53',
]);
$script['source_url'] = 'javascript:alert(1)';
$badUrl = planSave($bad, $script, $now);
sp($badUrl['status'] === 'rejected' && !is_dir($bad), 'a script url is rejected');

$dir = planDir('save');
$post = planPost([
    'entry' => '<script>alert(1)</script>',
    'entry_frame' => '일봉',
    'as_of' => '2026-10-21T17:53',
    'note' => '판단 메모',
]);
$saved = planSave($dir, $post, $now);
sp($saved['status'] === 'saved' && $saved['version'] === 1, 'a new plan is saved');
$doc = planDoc($dir, $saved['id']);
$first = $doc['versions'][0];
sp($first['symbol'] === '005930.KS' && $first['entry'] === '<script>alert(1)</script>' && $first['hold'] === null, 'the saved plan reads back with an open field');
sp($first['recorded_at'] === '2026-11-15T23:40:00+09:00' && $first['as_of'] === '2026-10-21T17:53:00+09:00' && $first['recorded_at'] !== $post['recorded_at'], 'record time stays the server time');
$html = SwingPlan::section($dir, ['plan' => $saved['id'], 'version' => '1'], null, '0123456789abcdef0123456789abcdef');
sp(str_contains($html, '<strong>미정</strong>') && str_contains($html, '빠진 항목: 계획 보유 기간') && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($html, '<script>'), 'missing fields and script text are escaped');
sp(str_contains($html, '2026-11-15T23:40:00+09:00') && str_contains($html, '2026-10-21T17:53:00+09:00') && str_contains($html, '당시 미리 기록한 계획이 아닙니다'), 'the reference time is not shown as an advance record');
sp(!str_contains($html, '매수') && !str_contains($html, '추천'), 'a filled plan is not a recommendation');
$before = hash_file('sha256', $dir . '/plans/' . $saved['id'] . '.json');
$lock = $dir . '/write.lock';
$hadLock = is_file($lock);
if ($hadLock) {
    unlink($lock);
}
SwingPlan::plans($dir);
SwingPlan::section($dir, ['plan' => $saved['id'], 'version' => '1'], null, '0123456789abcdef0123456789abcdef');
sp(hash_file('sha256', $dir . '/plans/' . $saved['id'] . '.json') === $before && !is_file($lock), 'lookup does not change the plan file');

$again = planSave($dir, $post, $now->modify('+1 minute'));
sp($again['status'] === 'duplicate' && $again['id'] === $saved['id'] && count(planDoc($dir, $saved['id'])['versions']) === 1, 'the same submit does not add a second plan');

$edited = planPost([
    'plan_id' => $saved['id'],
    'base_version' => '1',
    'reason' => '보유 기간을 정함',
    'hold' => '5거래일',
    'entry' => $first['entry'],
    'entry_frame' => '일봉',
    'note' => '판단 메모',
    'as_of' => '2026-10-21T17:53',
]);
$later = planSave($dir, $edited, $now->modify('+2 minutes'));
sp($later['status'] === 'saved' && $later['version'] === 2, 'an edit becomes a new version');
$doc = planDoc($dir, $saved['id']);
sp($doc['versions'][0]['hold'] === null && $doc['versions'][0]['entry'] === '<script>alert(1)</script>' && $doc['versions'][1]['hold'] === '5거래일' && $doc['versions'][1]['reason'] === '보유 기간을 정함', 'the original version stays attached');
$history = SwingPlan::section($dir, ['plan' => $saved['id'], 'version' => '1'], null, '0123456789abcdef0123456789abcdef');
sp(str_contains($history, '버전 1') && str_contains($history, '버전 2') && str_contains($history, '보유 기간을 정함') && str_contains($history, 'version=2'), 'each version can be opened');
$stale = planSave($dir, planPost([
    'plan_id' => $saved['id'],
    'base_version' => '1',
    'reason' => '늦은 수정',
    'hold' => '다른 기간',
]), $now->modify('+3 minutes'));
sp($stale['status'] === 'conflict' && count(planDoc($dir, $saved['id'])['versions']) === 2 && planDoc($dir, $saved['id'])['versions'][1]['hold'] === '5거래일', 'a stale edit does not drop the newer version');

$raceDir = planDir('race');
$base = planSave($raceDir, planPost(['hold' => '원본']), $now);
$job = sys_get_temp_dir() . '/swing-plan-job.php';
file_put_contents($job, <<<'PHP'
<?php
require $argv[1];
$post = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
echo json_encode(ChartEntryLab\SwingPlan::accept($argv[2], $post, $argv[4], $now), JSON_UNESCAPED_UNICODE);
PHP);
$left = planPost(['plan_id' => $base['id'], 'base_version' => '1', 'reason' => '왼쪽', 'hold' => '왼쪽 기간']);
$right = planPost(['plan_id' => $base['id'], 'base_version' => '1', 'reason' => '오른쪽', 'hold' => '오른쪽 기간']);
$spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']];
$cmd = static fn(array $body): array => [PHP_BINARY, $job, __DIR__ . '/../bin/bootstrap.php', $raceDir, json_encode($body, JSON_UNESCAPED_UNICODE), '0123456789abcdef0123456789abcdef'];
$open = static function (array $command) use ($spec): array {
    $proc = proc_open($command, $spec, $pipes);
    fclose($pipes[0]);

    return [$proc, $pipes];
};
[$procA, $pipesA] = $open($cmd($left));
[$procB, $pipesB] = $open($cmd($right));
$outA = stream_get_contents($pipesA[1]);
$outB = stream_get_contents($pipesB[1]);
fclose($pipesA[1]);
fclose($pipesB[1]);
sp(proc_close($procA) === 0 && proc_close($procB) === 0, 'both concurrent edits finish');
$race = planDoc($raceDir, $base['id']);
$statuses = [json_decode($outA, true)['status'] ?? '', json_decode($outB, true)['status'] ?? ''];
sort($statuses);
sp($statuses === ['conflict', 'saved'] && count($race['versions']) === 2 && $race['versions'][0]['hold'] === '원본', 'concurrent edits keep the original and one new version');

$dupDir = planDir('dup');
$same = planPost(['hold' => '한번']);
[$procA, $pipesA] = $open([PHP_BINARY, $job, __DIR__ . '/../bin/bootstrap.php', $dupDir, json_encode($same, JSON_UNESCAPED_UNICODE), '0123456789abcdef0123456789abcdef']);
[$procB, $pipesB] = $open([PHP_BINARY, $job, __DIR__ . '/../bin/bootstrap.php', $dupDir, json_encode($same, JSON_UNESCAPED_UNICODE), '0123456789abcdef0123456789abcdef']);
$dupA = stream_get_contents($pipesA[1]);
$dupB = stream_get_contents($pipesB[1]);
fclose($pipesA[1]);
fclose($pipesB[1]);
sp(proc_close($procA) === 0 && proc_close($procB) === 0, 'both duplicate submits finish');
$dupPlans = SwingPlan::plans($dupDir);
$dupStatus = [json_decode($dupA, true)['status'] ?? '', json_decode($dupB, true)['status'] ?? ''];
sort($dupStatus);
sp($dupStatus === ['duplicate', 'saved'] && count($dupPlans) === 1 && count($dupPlans[0]['versions']) === 1, 'a repeated submit keeps a single plan');

$brokenDir = planDir('broken');
$kept = planSave($brokenDir, planPost(['note' => '정상']), $now);
$brokenId = 'p0123456789abcdef';
$brokenPath = $brokenDir . '/plans/' . $brokenId . '.json';
file_put_contents($brokenPath, '{');
$brokenBytes = file_get_contents($brokenPath);
$oddPath = $brokenDir . '/plans/pzzzzzzzzzzzzzzzz.json';
file_put_contents($oddPath, '<script>alert(1)</script>');
$damage = planSave($brokenDir, planPost([
    'plan_id' => $brokenId,
    'base_version' => '1',
    'reason' => '고치려다 멈춤',
]), $now);
$listed = SwingPlan::section($brokenDir, ['plan' => $brokenId, 'version' => '1'], null, '0123456789abcdef0123456789abcdef');
sp($damage['status'] === 'damaged' && file_get_contents($brokenPath) === $brokenBytes && is_file($oddPath), 'damaged files are not overwritten');
sp(str_contains($listed, '손상되어 내용을 보여 주지 않습니다') && !str_contains($listed, '<script>'), 'damaged names and script files are not rendered');
sp(planDoc($brokenDir, $kept['id'])['versions'][0]['note'] === '정상', 'a damaged neighbor does not erase a valid plan');

$page = SwingResearch::page(null, ['symbols' => [], 'symbol' => '', 'day' => '', 'from' => '', 'to' => '', 'mode' => '']);
$inserted = SwingPlan::insert($page, $emptyHtml);
sp(str_contains($inserted, '스윙 연구') && strpos($inserted, '사전 매매 계획') < strpos($inserted, '판단일') && str_contains($inserted, '재생'), 'the plan sits inside the existing research page');

planRemove($dir);
planRemove($raceDir);
planRemove($dupDir);
planRemove($brokenDir);
echo "PLAN_DONE\n";
