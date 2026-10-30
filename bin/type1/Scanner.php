<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;

final class Scanner
{
    public function __construct(private Classifier $classifier) {}
    public function scan(callable $leaders, callable $prices, int $asOf): array
    {
        $report = ['ok'=>true, 'as_of'=>date(DATE_ATOM,$asOf), 'rows'=>[], 'errors'=>[],
            'summary'=>['total'=>0,'evaluated'=>0,'matches'=>0,'errors'=>0], 'error'=>null];
        try {
            $list = $leaders();
            if (!$list) { throw new \RuntimeException('empty'); }
        } catch (\Throwable $e) {
            $report['ok'] = false;
            $report['error'] = '거래대금 순위를 가져오지 못했습니다. 잠시 후 다시 조회해 주세요.';
            return $report;
        }
        $seen = [];
        foreach (array_slice($list,0,100) as $row) {
            $symbol = (string)($row['yahoo'] ?? '');
            if (isset($seen[$symbol])) { continue; }
            $seen[$symbol] = true;
            $report['summary']['total']++;
            try {
                try { $bars = $prices($symbol); }
                catch (\Throwable $e) { throw new \RuntimeException('일봉 조회 실패'); }
                $result = $this->classifier->classify($bars, $symbol, $asOf);
                $report['summary']['evaluated']++;
                if ($result['matches']) { $report['rows'][] = array_merge($row,$result,['symbol'=>$symbol,'breakout20'=>\ChartEntryLab\Type1Breakout::evidence($bars,$symbol,$result,$asOf)]); }
            } catch (\Throwable $e) {
                $report['errors'][] = ['symbol'=>$symbol,'name'=>$row['name'] ?? '',
                    'reason'=>$e instanceof \RuntimeException ? $e->getMessage() : '일봉 평가 실패'];
            }
        }
        usort($report['rows'], static fn($a,$b) => ($a['rank'] <=> $b['rank']) ?: strcmp($a['symbol'],$b['symbol']));
        $report['summary']['matches'] = count($report['rows']);
        $report['summary']['errors'] = count($report['errors']);
        return $report;
    }
}
