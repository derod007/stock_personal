<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Diagnostic evidence only; never repairs or rejects a price response. */
final class YahooResponseEvidence
{
    public function __construct(private readonly string $directory) {}

    public function record(string $body, string $symbol, string $range, string $interval, int $receivedAt): void
    {
        if ($interval !== '1d') return;
        $reason = null;
        $sessionTime = null;
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $result = $data['chart']['result'][0] ?? null;
            $times = $result['timestamp'] ?? null;
            $quote = $result['indicators']['quote'][0] ?? null;
            if (!is_array($times) || !is_array($quote)) {
                $reason = 'missing_daily_quote';
            } else {
                $indices = [];
                foreach ($times as $i => $time) {
                    if (!is_int($time) || $time <= 0) continue;
                    if (CandleClock::closeTime(['time'=>$time], $symbol) > $receivedAt) continue;
                    if ($sessionTime === null || $time > $sessionTime) {
                        $sessionTime = $time;
                        $indices = [$i];
                    } elseif ($time === $sessionTime) {
                        $indices[] = $i;
                    }
                }
                if (count($indices) > 1) $reason = 'duplicate_latest_timestamp';
                if (count($indices) === 1) {
                    $i = $indices[0];
                    foreach (['open','high','low','close','volume'] as $field) {
                        $v = $quote[$field][$i] ?? null;
                        if (!is_numeric($v) || !is_finite((float)$v) || ($field === 'volume' ? $v < 0 : $v <= 0)) {
                            $reason = 'invalid_'.$field;
                            break;
                        }
                    }
                    if ($reason === null && ($quote['low'][$i] > min($quote['open'][$i], $quote['close'][$i])
                        || $quote['high'][$i] < max($quote['open'][$i], $quote['close'][$i]))) {
                        $reason = 'inconsistent_ohlc';
                    }
                }
            }
        } catch (\JsonException) {
            $reason = 'invalid_json';
        }
        if ($reason === null) return;
        // Base64 preserves response bytes, including whitespace and invalid UTF-8.
        $bytes = json_encode([
            'schema'=>1, 'provider'=>'yahoo_chart', 'symbol'=>$symbol, 'range'=>$range,
            'interval'=>$interval, 'received_at_utc'=>gmdate('Y-m-d\TH:i:s\Z', $receivedAt),
            'session_timestamp'=>$sessionTime, 'reason'=>$reason,
            'body_sha256'=>hash('sha256', $body), 'body_base64'=>base64_encode($body),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create Yahoo evidence directory');
        }
        // Every response gets a separate receipt, including identical retries.
        $path = $this->directory.'/'.gmdate('Ymd-His', $receivedAt).'-'.bin2hex(random_bytes(12));
        $handle = @fopen($path.'.pending', 'xb');
        if ($handle === false) throw new \RuntimeException('Cannot create Yahoo evidence receipt');
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new \RuntimeException('Incomplete Yahoo evidence receipt');
            }
        } finally {
            fclose($handle);
        }
        if (!@rename($path.'.pending', $path.'.json')) throw new \RuntimeException('Cannot publish Yahoo evidence receipt');
    }
}
