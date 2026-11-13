<?php

declare(strict_types=1);

namespace ChartEntryLab;

/** Re-sums the preserved batch reports and compares them with summary.json. */
final class SwingPathCheck
{
    /** @return list<string> */
    public static function verify(string $batchDir, string $summaryPath): array
    {
        $manifestPath = $batchDir . '/manifest.json';
        if (!is_file($manifestPath)) {
            return ['묶음 목록이 없습니다.'];
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $entries = is_array($manifest['files'] ?? null) ? $manifest['files'] : null;
        if ($entries === null || $entries === []) {
            return ['묶음 목록이 없습니다.'];
        }
        $errors = [];
        $loaded = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_int($entry['batch'] ?? null) || !is_string($entry['file'] ?? null) || !is_string($entry['sha256'] ?? null) || $entry['sha256'] === '') {
                $errors[] = '묶음 목록 항목이 올바르지 않습니다.';
                continue;
            }
            $batch = $entry['batch'];
            $path = $batchDir . '/' . $entry['file'];
            if (!is_file($path)) {
                $errors[] = '묶음 ' . $batch . ' 파일이 없습니다.';
                continue;
            }
            if (hash_file('sha256', $path) !== $entry['sha256']) {
                $errors[] = '묶음 ' . $batch . ' 해시가 저장된 값과 다릅니다.';
                continue;
            }
            $report = json_decode((string) file_get_contents($path), true);
            if (!is_array($report)) {
                $errors[] = '묶음 ' . $batch . ' 보고서를 읽지 못했습니다.';
                continue;
            }
            $loaded[$batch] = $report;
        }
        if ($errors !== []) {
            return $errors;
        }
        ksort($loaded);
        if (!is_file($summaryPath)) {
            return ['summary.json 이 없습니다.'];
        }
        $summary = json_decode((string) file_get_contents($summaryPath), true);
        if (!is_array($summary)) {
            return ['summary.json 을 읽지 못했습니다.'];
        }
        $combined = SwingPathTally::combine(array_values($loaded));
        if ($combined['errors'] !== []) {
            foreach ($combined['errors'] as $error) {
                $errors[] = $error;
            }
        }
        if (($summary['errors'] ?? null) !== []) {
            $errors[] = 'summary.json 오류가 0건이 아닙니다.';
        }
        if (count($combined['observations']) !== 294) {
            $errors[] = '관찰값이 294건이 아닙니다.';
        }
        $signals = [];
        foreach ($combined['groups'] as $group) {
            $signals[] = $group['signals'] ?? null;
        }
        if ($signals !== [98, 131, 65]) {
            $errors[] = '집단별 신호 수가 98, 131, 65가 아닙니다.';
        }
        if (json_encode($combined['groups']) !== json_encode($summary['groups'] ?? null)) {
            $errors[] = '기간별 통계가 summary.json 과 다릅니다.';
        }
        $sameCondition = true;
        foreach (['version', 'research_version', 'confirm_from', 'confirm_to', 'as_of'] as $key) {
            if (($combined[$key] ?? null) !== ($summary[$key] ?? null)) {
                $sameCondition = false;
            }
        }
        if (json_encode($combined['calendars']) !== json_encode($summary['calendars'] ?? null)) {
            $sameCondition = false;
        }
        if (!$sameCondition) {
            $errors[] = '실행 조건이 summary.json 과 다릅니다.';
        }
        return $errors;
    }
}
