<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;

/** Daily bars for confirmed breakout candidates, separate from snapshot prices and trades. */
final class BreakoutWatch
{
    public static function record(string $folder, array $state, string $today, ?callable $fetch): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $today)) {
            throw new \InvalidArgumentException('관찰일이 아닙니다.');
        }
        $dir = $folder.'/breakout-watch';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('관찰 폴더를 만들지 못했습니다.');
        }
        $lock = fopen($dir.'/write.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new \RuntimeException('관찰 저장 잠금을 얻지 못했습니다.');
        }
        try {
            $days = $state['days'] ?? [];
            $at = array_search($today, $days, true);
            $report = ['status'=>'ok','day'=>$today,'start_day'=>null,'registry_initialized'=>false,'enrolled'=>0,'reused'=>0,'fetched'=>0,'failed'=>0];
            if ($at === false) {
                $report['status'] = '달력 미확정';
                return $report;
            }
            $snapshot = null;
            $hash = null;
            try {
                if (isset($state['captures'][$today])) {
                    $snapshot = Charts::snapshot($folder, $state, $today);
                    $hash = $state['captures'][$today]['snapshot_sha256'];
                }
            } catch (\Throwable) {
                $snapshot = null;
            }
            $path = $dir.'/registry.json';
            $reg = self::load($path);
            if ($reg === null) {
                if (!$snapshot) {
                    $report['status'] = '스냅샷 검증 실패';
                    return $report;
                }
                $reg = ['version'=>1,'start_day'=>$today,'started_at'=>date(DATE_ATOM),'entries'=>[]];
                self::atomic($path, $reg);
                $report['registry_initialized'] = true;
            }
            $report['start_day'] = $reg['start_day'] ?? null;
            if ($snapshot && $today >= ($reg['start_day'] ?? $today)) {
                $rows = $state['captures'][$today]['rows'] ?? [];
                $reg['entries'] = self::enrollRows($reg['entries'], $today, $rows, $snapshot, (string)$hash, (int)$snapshot['as_of'], $report);
                self::atomic($path, $reg);
            }
            $want = [];
            foreach ($reg['entries'] as $entry) {
                $from = array_search($entry['judgment_day'], $days, true);
                if ($from === false) {
                    continue;
                }
                $elapsed = $at - $from;
                if ($elapsed >= 1 && $elapsed <= 20) {
                    $want[$entry['symbol']] = true;
                }
            }
            if (!$want) {
                return $report;
            }
            $barsPath = $dir.'/bars/'.$today.'.json';
            $stored = self::load($barsPath);
            if ($stored !== null && ($stored['day'] ?? null) !== $today) {
                $report['status'] = '일봉 날짜 불일치';
                return $report;
            }
            if ($stored === null) {
                $stored = ['day'=>$today,'symbols'=>[]];
            }
            $asOf = (int)($snapshot['as_of'] ?? $state['captures'][$today]['as_of'] ?? strtotime($today.' 23:59:59 +0900'));
            foreach (array_keys($want) as $symbol) {
                if (self::validStored($stored['symbols'][$symbol]['bar'] ?? null)) {
                    continue;
                }
                $reused = $snapshot ? self::reusable($snapshot, $symbol, $today, $asOf) : null;
                if ($reused) {
                    $stored['symbols'][$symbol] = self::kept($today, $reused, 'snapshot_reuse', $hash);
                    $report['reused']++;
                    continue;
                }
                try {
                    $bars = $fetch ? $fetch($symbol) : null;
                    if (!is_array($bars)) {
                        throw new \RuntimeException($fetch ? '일봉 응답이 없습니다.' : '별도 조회 없음');
                    }
                    $picked = self::oneValid($bars, $symbol, $today, $asOf);
                    if ($picked['bar']) {
                        $stored['symbols'][$symbol] = self::kept($today, $picked['bar'], 'watch_fetch', null);
                        $report['fetched']++;
                    } else {
                        $stored['symbols'][$symbol] = self::missed($today, $picked['kind'], $picked['reason']);
                        $report['failed']++;
                    }
                } catch (\Throwable $e) {
                    $stored['symbols'][$symbol] = self::missed($today, 'fetch_error', $e->getMessage());
                    $report['failed']++;
                }
            }
            self::atomic($barsPath, $stored);
            return $report;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function enrollRows(array $entries, string $today, array $rows, array $snapshot, string $hash, int $asOf, array &$report): array
    {
        foreach ($rows as $row) {
            if (($row['error'] ?? null) !== null) {
                continue;
            }
            $c = $row['classification'] ?? null;
            if (!($c['matches'] ?? false) || ($c['analysis_date'] ?? null) !== $today) {
                continue;
            }
            $symbol = $row['symbol'];
            $id = $today.'|'.$symbol;
            if (isset($entries[$id])) {
                continue;
            }
            $ev = \ChartEntryLab\Type1Breakout::evidence($snapshot['prices'][$symbol] ?? [], $symbol, $c, $asOf);
            if (!($ev['confirmed'] ?? false) || !is_numeric($ev['level'] ?? null) || !is_numeric($c['analysis_close'] ?? null)) {
                continue;
            }
            $entries[$id] = [
                'judgment_day'=>$today,
                'symbol'=>$symbol,
                'name'=>$row['name'] ?? $symbol,
                'level'=>(float)$ev['level'],
                'judgment_close'=>(float)$c['analysis_close'],
                'snapshot_sha256'=>$hash,
                'enrolled_at'=>date(DATE_ATOM),
            ];
            $report['enrolled']++;
        }
        return $entries;
    }

    private static function reusable(array $snapshot, string $symbol, string $day, int $asOf): ?array
    {
        $bars = $snapshot['prices'][$symbol] ?? null;
        if (!is_array($bars) || $bars === []) {
            return null;
        }
        $picked = self::oneValid($bars, $symbol, $day, $asOf);
        return $picked['bar'];
    }

    /** @return array{bar:?array,kind:string,reason:string} */
    private static function oneValid(array $bars, string $symbol, string $day, int $asOf): array
    {
        $hits = [];
        foreach ($bars as $bar) {
            if (!is_array($bar)) {
                continue;
            }
            try {
                $stamp = \ChartEntryLab\CandleClock::closeTime($bar, $symbol);
            } catch (\Throwable) {
                continue;
            }
            if ($stamp > $asOf) {
                continue;
            }
            $got = (new \DateTimeImmutable('@'.$stamp))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d');
            if ($got === $day) {
                $hits[] = $bar;
            }
        }
        if (!$hits) {
            return ['bar'=>null,'kind'=>'missing','reason'=>'정확한 당일 봉이 없습니다.'];
        }
        $valid = array_values(array_filter($hits, [Tracker::class, 'valid']));
        if (count($valid) === 1 && count($hits) === 1) {
            return ['bar'=>$valid[0],'kind'=>'ok','reason'=>''];
        }
        if (count($hits) === 1 && (($hits[0]['is_complete'] ?? true) === false)) {
            return ['bar'=>null,'kind'=>'incomplete','reason'=>'당일 봉이 미완료입니다.'];
        }
        return ['bar'=>null,'kind'=>'invalid','reason'=>'당일 봉이 유효하지 않거나 중복입니다.'];
    }

    private static function validStored(mixed $bar): bool
    {
        return is_array($bar) && Tracker::valid($bar);
    }

    private static function kept(string $day, array $bar, string $source, ?string $hash): array
    {
        return ['session_day'=>$day,'collected_at'=>date(DATE_ATOM),'source'=>$source,'snapshot_sha256'=>$hash,'bar'=>$bar,'attempt'=>null];
    }

    private static function missed(string $day, string $kind, string $reason): array
    {
        return ['session_day'=>$day,'collected_at'=>date(DATE_ATOM),'source'=>null,'snapshot_sha256'=>null,'bar'=>null,'attempt'=>['kind'=>$kind,'reason'=>$reason,'at'=>date(DATE_ATOM)]];
    }

    /** Restore a crash that left only the backup. Null means neither file exists. */
    private static function load(string $path): ?array
    {
        $text = self::prepare($path);
        if ($text === null) {
            return null;
        }
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('관찰 기록을 읽지 못했습니다.');
        }
        return $data;
    }

    private static function prepare(string $path): ?string
    {
        $bak = $path.'.bak';
        $pathOk = self::readable($path);
        $bakOk = self::readable($bak);
        if ($pathOk && $bakOk) {
            unlink($bak);
        } elseif (!$pathOk && $bakOk) {
            if (is_file($path) && !unlink($path)) {
                throw new \RuntimeException('손상된 관찰 기록을 치우지 못했습니다.');
            }
            if (!rename($bak, $path)) {
                throw new \RuntimeException('관찰 기록 복구에 실패했습니다.');
            }
        } elseif ((is_file($path) || is_file($bak)) && !$pathOk && !$bakOk) {
            throw new \RuntimeException('관찰 기록을 읽지 못했습니다.');
        }
        if (!is_file($path)) {
            return null;
        }
        $text = file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException('관찰 기록을 읽지 못했습니다.');
        }
        return $text;
    }

    private static function readable(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return false;
        }
        try {
            return is_array(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return false;
        }
    }

    private static function atomic(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('관찰 폴더를 만들지 못했습니다.');
        }
        self::prepare($path);
        $bytes = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $tmp = $path.'.tmp';
        $bak = $path.'.bak';
        $tmpReady = false;
        try {
            if (file_put_contents($tmp, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('관찰 기록을 쓰지 못했습니다.');
            }
            $tmpReady = true;
            if (is_file($path) && !rename($path, $bak)) {
                throw new \RuntimeException('관찰 기록을 바꾸지 못했습니다.');
            }
            if (!rename($tmp, $path)) {
                if (is_file($bak) && !is_file($path)) {
                    rename($bak, $path);
                }
                throw new \RuntimeException('관찰 기록을 바꾸지 못했습니다.');
            }
            $tmpReady = false;
            if (is_file($bak) && is_file($path)) {
                unlink($bak);
            }
        } finally {
            if ($tmpReady && is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
