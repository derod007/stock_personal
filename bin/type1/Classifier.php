<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;

use ChartEntryLab\CandleClock;

final class Classifier
{
    private array $centers;
    public function __construct(string $path)
    {
        if (hash('sha256', str_replace("\r\n", "\n", (string)file_get_contents($path))) !== '36d5af0bc6e49a6e559ce23626659ab8657babf446635c14f9ea61a9ebaceb39') {
            throw new \RuntimeException('고정 유형 기준 파일 검증 실패');
        }
        $this->centers = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['centers'];
    }

    public function classify(array $bars, string $symbol, int $asOf): array
    {
        if (!preg_match('/^\d{6}\.(KS|KQ)$/D', $symbol)) {
            throw new \RuntimeException('지원하지 않는 종목 코드');
        }
        $closed = [];
        foreach ($bars as $bar) {
            if (!isset($bar['time']) && !isset($bar['time_kst'])) {
                throw new \RuntimeException('봉 날짜 누락');
            }
            $stamp = CandleClock::closeTime($bar, $symbol);
            if ($stamp > $asOf || ($bar['is_complete'] ?? true) === false) { continue; }
            $day = (new \DateTimeImmutable('@'.$stamp))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d');
            if (isset($closed[$day])) { throw new \RuntimeException('중복 일봉'); }
            $closed[$day] = $bar + ['completed_at' => $stamp];
        }
        ksort($closed);
        if (count($closed) < 60) { throw new \RuntimeException('완료된 일봉 60개 미만'); }
        $closed = array_slice($closed, -60, null, true);
        $last = end($closed);
        if ($asOf - $last['completed_at'] > 144 * 3600) { throw new \RuntimeException('최근 완료봉이 144시간보다 오래됨'); }
        $prices = $volumes = [];
        foreach ($closed as $bar) {
            if (!empty($bar['synthetic'])) { throw new \RuntimeException('합성 일봉 포함'); }
            foreach (['open','high','low','close','volume'] as $key) {
                if (!isset($bar[$key]) || !is_numeric($bar[$key]) || !is_finite((float)$bar[$key])
                    || ($key === 'volume' ? $bar[$key] < 0 : $bar[$key] <= 0)) {
                    throw new \RuntimeException('최근 60봉 가격·거래량 오류');
                }
            }
            if ($bar['high'] < max($bar['open'],$bar['close']) || $bar['low'] > min($bar['open'],$bar['close']) || $bar['low'] > $bar['high']) {
                throw new \RuntimeException('최근 60봉 OHLC 모순');
            }
            $prices[] = $bar['close'] / $last['close'];
            $volumes[] = (float)$bar['volume'];
        }
        $mean = array_sum($volumes) / 60;
        if ($mean <= 0) { throw new \RuntimeException('거래량 평균 0'); }
        $volumes = array_map(static fn($v) => $v/$mean, $volumes);
        $normalize = static function(array $a): array {
            $norm = sqrt(array_sum(array_map(static fn($v) => $v*$v, $a)));
            if (!is_finite($norm) || $norm <= 0) { throw new \RuntimeException('정규화 불가'); }
            return array_map(static fn($v) => $v/$norm, $a);
        };
        $vector = array_merge($normalize($prices), $normalize($volumes));
        $distances = [];
        foreach ($this->centers as $center) {
            $distance = 0.0;
            foreach ($vector as $i => $v) { $distance += ($v-$center[$i])**2; }
            $distances[] = $distance;
        }
        $cluster = array_search(min($distances), $distances, true);
        return ['cluster'=>$cluster, 'matches'=>$cluster === 1, 'analysis_date'=>array_key_last($closed),
            'analysis_close'=>$last['close'], 'distance_squared'=>$distances[$cluster]];
    }
}
