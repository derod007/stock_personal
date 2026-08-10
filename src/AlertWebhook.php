<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * Discord/Slack 호환 Incoming Webhook 전송.
 * Discord: content 필드 / Slack: text 필드 둘 다 넣음.
 */
final class AlertWebhook
{
    public function __construct(
        private readonly string $url,
    ) {
    }

    public static function fromEnvOrArg(?string $urlArg): ?self
    {
        $url = $urlArg ?? (getenv('NORAMU_ALERT_WEBHOOK') ?: null);
        if (!is_string($url) || trim($url) === '') {
            return null;
        }
        return new self(trim($url));
    }

    /**
     * @param list<array<string, mixed>> $alerts
     * @return array{ok:bool, http_code:int, error:?string}
     */
    public function sendAlerts(string $profileId, array $alerts): array
    {
        if ($alerts === []) {
            return ['ok' => true, 'http_code' => 0, 'error' => null];
        }

        $lines = [
            "noramu alert · profile=`{$profileId}` · " . count($alerts) . '건',
            '',
        ];
        foreach (array_slice($alerts, 0, 12) as $a) {
            $sym = (string) ($a['symbol'] ?? '?');
            $score = (string) ($a['score'] ?? '?');
            $action = (string) ($a['action'] ?? '?');
            $vol = !empty($a['hourly']['unusual_volume']) ? ' · 1h vol!' : '';
            $tv = (string) ($a['tradingview_url'] ?? '');
            $lines[] = "• **{$sym}** score={$score} `{$action}`{$vol}";
            if ($tv !== '') {
                $lines[] = "  {$tv}";
            }
        }
        $text = implode("\n", $lines);

        $payload = json_encode([
            'content' => $text,
            'text' => $text,
            'username' => 'noramu',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'http_code' => 0, 'error' => $err];
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $code >= 200 && $code < 300,
            'http_code' => $code,
            'error' => $code >= 200 && $code < 300 ? null : 'HTTP ' . $code,
        ];
    }
}
