<?php

declare(strict_types=1);

namespace ChartEntryLab;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Plan text stored apart from prices, accounts, and schedules. A read does not create the folder. */
final class SwingPlan
{
    private const TEXT = [
        'hold' => '계획 보유 기간',
        'entry' => '진입 조건',
        'entry_frame' => '진입 확인 시간',
        'stop' => '무효화·손절 조건',
        'stop_frame' => '무효화 확인 시간',
        'target' => '목표 가격 또는 익절 조건',
        'note' => '판단 근거',
    ];

    public static function insert(string $page, string $section): string
    {
        $marker = '추천 점수나 자동 주문이 아닙니다.</p>';
        $pos = strpos($page, $marker);
        if ($pos === false) {
            return $page;
        }
        $at = $pos + strlen($marker);

        return substr($page, 0, $at) . $section . substr($page, $at);
    }

    public static function section(string $dir, array $query, ?array $post, string $cookie): string
    {
        $result = null;
        if ($post !== null) {
            try {
                $result = self::accept($dir, $post, $cookie, new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')));
            } catch (Throwable $e) {
                $result = self::result('rejected', '저장하지 못했습니다.');
            }
        }

        return self::html($dir, $query, $cookie, $result);
    }

    public static function accept(string $dir, array $post, string $cookie, DateTimeImmutable $now): array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $cookie) !== 1 || !hash_equals($cookie, (string) ($post['csrf'] ?? ''))) {
            return self::result('rejected', '요청을 확인하지 못했습니다.');
        }
        if (($post['plan_form'] ?? '') !== '1') {
            return self::result('rejected', '요청이 올바르지 않습니다.');
        }
        $submit = (string) ($post['submit_id'] ?? '');
        if (preg_match('/^s[a-f0-9]{16}$/', $submit) !== 1) {
            return self::result('rejected', '제출 번호가 올바르지 않습니다.');
        }
        $plan = (string) ($post['plan_id'] ?? '');
        $base = (string) ($post['base_version'] ?? '');
        try {
            $fields = self::fields($post);
            $reason = $plan === '' ? null : self::text((string) ($post['reason'] ?? ''), '변경 사유');
        } catch (InvalidArgumentException $e) {
            return self::result('rejected', $e->getMessage());
        }
        if ($plan === '') {
            if ($base !== '') {
                return self::result('rejected', '새 계획에는 기준 버전이 없습니다.');
            }
        } else {
            if (preg_match('/^p[a-f0-9]{16}$/', $plan) !== 1 || preg_match('/^[1-9][0-9]*$/', $base) !== 1) {
                return self::result('rejected', '수정 대상이 올바르지 않습니다.');
            }
            if ($reason === null) {
                return self::result('rejected', '변경 사유가 필요합니다.');
            }
        }

        return self::withLock($dir, function () use ($dir, $fields, $plan, $base, $reason, $submit, $now): array {
            $found = self::findSubmit($dir, $submit);
            if ($found !== null) {
                return self::result('duplicate', '같은 제출이 이미 저장되어 있습니다.', $found['id'], $found['version']);
            }
            if ($plan === '') {
                return self::create($dir, $fields, $submit, $now);
            }

            return self::revise($dir, $plan, (int) $base, $fields, $reason, $submit, $now);
        });
    }

    public static function plans(string $dir): array
    {
        $folder = $dir . '/plans';
        if (!is_dir($folder)) {
            return [];
        }
        $paths = glob($folder . '/p*.json');
        if ($paths === false) {
            return [];
        }
        sort($paths);
        $out = [];
        foreach ($paths as $path) {
            $name = basename($path, '.json');
            if (preg_match('/^p[a-f0-9]{16}$/', $name) !== 1) {
                $out[] = ['id' => '', 'damaged' => true, 'versions' => []];
                continue;
            }
            try {
                $doc = self::load($path);
                $doc['damaged'] = false;
                $out[] = $doc;
            } catch (Throwable $e) {
                $out[] = ['id' => $name, 'damaged' => true, 'versions' => []];
            }
        }

        return $out;
    }

    private static function html(string $dir, array $query, string $cookie, ?array $result): string
    {
        $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $focus = $query;
        if ($result !== null && $result['id'] !== null && $result['version'] !== null) {
            $focus['plan'] = $result['id'];
            $focus['version'] = (string) $result['version'];
        }
        $html = '<section id="swing-plan"><h2>사전 매매 계획</h2>'
            . '<p>며칠에서 수주 보유할 계획을 판단 시점에 적습니다. 조건 문장은 주문 규칙으로 해석하지 않고, 진단 결과는 계획으로 옮기지 않습니다. 실제 기록 시각은 서버가 남기며 과거로 바꿀 수 없습니다. 항목이 모두 있어도 주문 신호가 아닙니다.</p>';
        if ($result !== null) {
            $html .= '<p class="notice">' . $h($result['message']) . '</p>';
        }
        $html .= self::detail($dir, $focus, $cookie, $h);
        $html .= '<h3>저장된 계획</h3>' . self::listHtml($dir, $h);
        $html .= '<h3>새 계획</h3>' . self::form($cookie, null, null, $h) . '</section>';

        return $html;
    }

    private static function detail(string $dir, array $query, string $cookie, callable $h): string
    {
        $id = (string) ($query['plan'] ?? '');
        if ($id === '') {
            return '';
        }
        if (preg_match('/^p[a-f0-9]{16}$/', $id) !== 1) {
            return '<p class="notice">계획 번호가 올바르지 않습니다.</p>';
        }
        $doc = null;
        foreach (self::plans($dir) as $plan) {
            if ($plan['id'] === $id) {
                $doc = $plan;
                break;
            }
        }
        if ($doc === null) {
            return '<p class="notice">저장된 계획이 없습니다.</p>';
        }
        if (!empty($doc['damaged'])) {
            return '<p class="notice">이 기록은 손상되어 내용을 보여 주지 않습니다. 덮어쓰지 않았습니다.</p>';
        }
        $want = (string) ($query['version'] ?? '');
        $picked = null;
        $history = '<table><thead><tr><th scope="col">버전</th><th scope="col">실제 기록 시각</th><th scope="col">변경 사유</th><th scope="col">열기</th></tr></thead><tbody>';
        foreach ($doc['versions'] as $version) {
            $history .= '<tr><td>' . $h((string) $version['version']) . '</td><td>' . $h((string) $version['recorded_at']) . '</td><td>'
                . ($version['reason'] === null ? '없음' : $h((string) $version['reason'])) . '</td><td><a href="swing_research.php?plan='
                . $h($id) . '&amp;version=' . $h((string) $version['version']) . '">이 버전</a></td></tr>';
            if ($want === (string) $version['version']) {
                $picked = $version;
            }
        }
        $history .= '</tbody></table>';
        if ($picked === null) {
            return $history . '<p class="notice">보려는 버전이 없습니다.</p>';
        }
        $rows = '<tr><th scope="row">종목코드</th><td>' . $h((string) $picked['symbol']) . '</td></tr>';
        $missing = [];
        foreach (self::TEXT as $key => $label) {
            $rows .= '<tr><th scope="row">' . $h($label) . '</th><td>' . self::shown($picked[$key] ?? null, $h) . '</td></tr>';
            if (($picked[$key] ?? null) === null) {
                $missing[] = $label;
            }
        }
        $rows .= '<tr><th scope="row">원문 링크</th><td>' . self::link($picked['source_url'] ?? null, $h) . '</td></tr>';
        if (($picked['source_url'] ?? null) === null) {
            $missing[] = '원문 링크';
        }
        $rows .= '<tr><th scope="row">자료 기준 시각</th><td>' . self::shown($picked['as_of'] ?? null, $h) . '</td></tr>';
        if (($picked['as_of'] ?? null) === null) {
            $missing[] = '자료 기준 시각';
        }
        $rows .= '<tr><th scope="row">실제 기록 시각</th><td>' . $h((string) $picked['recorded_at']) . '</td></tr>';
        $gap = $missing === [] ? '없음' : implode(', ', $missing);

        return $history . '<h3>버전 ' . $h((string) $picked['version']) . '</h3><table><tbody>' . $rows . '</tbody></table>'
            . '<p>빠진 항목: ' . $h($gap) . '</p>'
            . '<p>자료 기준 시각은 참고 시각입니다. 실제 기록 시각보다 이전이어도 당시 미리 기록한 계획이 아닙니다.</p>'
            . '<h3>이 버전을 수정</h3>' . self::form($cookie, $picked, $id, $h);
    }

    private static function listHtml(string $dir, callable $h): string
    {
        $plans = self::plans($dir);
        if ($plans === []) {
            return '<p>저장된 계획이 없습니다.</p>';
        }
        $html = '<ul>';
        foreach ($plans as $plan) {
            if (!empty($plan['damaged'])) {
                $html .= '<li>손상된 기록이 있습니다. 내용을 보여 주지 않고 덮어쓰지 않습니다.</li>';
                continue;
            }
            $latest = $plan['versions'][count($plan['versions']) - 1];
            $links = [];
            foreach ($plan['versions'] as $version) {
                $links[] = '<a href="swing_research.php?plan=' . $h($plan['id']) . '&amp;version=' . $h((string) $version['version']) . '">버전 ' . $h((string) $version['version']) . '</a>';
            }
            $html .= '<li>' . $h((string) $latest['symbol']) . ' ' . implode(' ', $links) . '</li>';
        }

        return $html . '</ul>';
    }

    private static function form(string $cookie, ?array $version, ?string $planId, callable $h): string
    {
        $prefix = $planId === null ? 'plan-new' : 'plan-edit';
        $value = static function (string $key) use ($version): string {
            if ($version === null || ($version[$key] ?? null) === null) {
                return '';
            }

            return (string) $version[$key];
        };
        $asOf = '';
        if ($version !== null && ($version['as_of'] ?? null) !== null) {
            $asOf = (new DateTimeImmutable((string) $version['as_of']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d\TH:i');
        }
        $html = '<form method="post" action="swing_research.php"><input type="hidden" name="plan_form" value="1">'
            . '<input type="hidden" name="csrf" value="' . $h($cookie) . '">'
            . '<input type="hidden" name="submit_id" value="s' . bin2hex(random_bytes(8)) . '">';
        if ($planId !== null && $version !== null) {
            $html .= '<input type="hidden" name="plan_id" value="' . $h($planId) . '">'
                . '<input type="hidden" name="base_version" value="' . $h((string) $version['version']) . '">';
        }
        $html .= self::input($prefix, 'symbol', '종목코드', $value('symbol'), $h)
            . self::input($prefix, 'hold', '계획 보유 기간', $value('hold'), $h)
            . self::input($prefix, 'entry', '진입 조건', $value('entry'), $h)
            . self::input($prefix, 'entry_frame', '진입 확인 시간', $value('entry_frame'), $h)
            . self::input($prefix, 'stop', '무효화·손절 조건', $value('stop'), $h)
            . self::input($prefix, 'stop_frame', '무효화 확인 시간', $value('stop_frame'), $h)
            . self::input($prefix, 'target', '목표 가격 또는 익절 조건', $value('target'), $h)
            . self::input($prefix, 'note', '판단 근거', $value('note'), $h, true)
            . self::input($prefix, 'source_url', '원문 링크', $value('source_url'), $h)
            . '<label for="' . $prefix . '-as-of">자료 기준 시각 <input id="' . $prefix . '-as-of" type="datetime-local" name="as_of" value="' . $h($asOf) . '"></label> ';
        if ($planId !== null) {
            $html .= self::input($prefix, 'reason', '변경 사유', '', $h, true)
                . '<button type="submit">새 버전으로 기록</button>';
        } else {
            $html .= '<button type="submit">계획 기록</button>';
        }

        return $html . '<p>비운 항목은 미정으로 저장됩니다.</p></form>';
    }

    private static function input(string $prefix, string $name, string $label, string $value, callable $h, bool $area = false): string
    {
        $id = $prefix . '-' . str_replace('_', '-', $name);
        $control = $area
            ? '<textarea id="' . $id . '" name="' . $name . '">' . $h($value) . '</textarea>'
            : '<input id="' . $id . '" name="' . $name . '" value="' . $h($value) . '">';

        return '<label for="' . $id . '">' . $h($label) . ' ' . $control . '</label> ';
    }

    private static function shown(?string $value, callable $h): string
    {
        return $value === null || $value === '' ? '<strong>미정</strong>' : $h($value);
    }

    private static function link(?string $url, callable $h): string
    {
        if ($url === null || $url === '') {
            return '<strong>미정</strong>';
        }

        return '<a href="' . $h($url) . '" rel="noreferrer">' . $h($url) . '</a>';
    }

    private static function create(string $dir, array $fields, string $submit, DateTimeImmutable $now): array
    {
        $plans = $dir . '/plans';
        if (!is_dir($plans) && !mkdir($plans, 0775) && !is_dir($plans)) {
            throw new RuntimeException('계획 저장소를 만들 수 없습니다.');
        }
        $id = 'p' . bin2hex(random_bytes(8));
        $path = $plans . '/' . $id . '.json';
        if (is_file($path)) {
            throw new RuntimeException('계획 번호가 겹칩니다.');
        }
        self::atomic($path, self::seal(['id' => $id, 'versions' => [self::version(1, $fields, null, $submit, $now)]]));

        return self::result('saved', '계획을 기록했습니다.', $id, 1);
    }

    private static function revise(string $dir, string $plan, int $base, array $fields, string $reason, string $submit, DateTimeImmutable $now): array
    {
        $path = $dir . '/plans/' . $plan . '.json';
        if (!is_file($path)) {
            return self::result('rejected', '저장된 계획이 없습니다.');
        }
        try {
            $doc = self::load($path);
        } catch (Throwable $e) {
            return self::result('damaged', '기록이 손상되어 수정하지 않았습니다.', $plan, null);
        }
        $latest = (int) $doc['versions'][count($doc['versions']) - 1]['version'];
        if ($latest !== $base) {
            return self::result('conflict', '다른 수정이 먼저 저장되어 이 제출은 넣지 않았습니다.', $plan, $latest);
        }
        $doc['versions'][] = self::version($latest + 1, $fields, $reason, $submit, $now);
        unset($doc['sha256'], $doc['damaged']);
        self::atomic($path, self::seal($doc));

        return self::result('saved', '새 버전으로 기록했습니다.', $plan, $latest + 1);
    }

    private static function findSubmit(string $dir, string $submit): ?array
    {
        foreach (self::plans($dir) as $doc) {
            if (!empty($doc['damaged'])) {
                continue;
            }
            foreach ($doc['versions'] as $version) {
                if (($version['submit_id'] ?? '') === $submit) {
                    return ['id' => $doc['id'], 'version' => $version['version']];
                }
            }
        }

        return null;
    }

    private static function version(int $n, array $fields, ?string $reason, string $submit, DateTimeImmutable $now): array
    {
        return $fields + [
            'version' => $n,
            'recorded_at' => $now->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d\TH:i:sP'),
            'reason' => $reason,
            'submit_id' => $submit,
        ];
    }

    private static function fields(array $post): array
    {
        $symbol = strtoupper(self::text((string) ($post['symbol'] ?? ''), '종목코드') ?? '');
        if (preg_match('/^[0-9]{6}\.(KS|KQ)$/', $symbol) !== 1) {
            throw new InvalidArgumentException('종목코드는 6자리 숫자와 .KS 또는 .KQ입니다.');
        }
        $out = ['symbol' => $symbol];
        foreach (self::TEXT as $key => $label) {
            $out[$key] = self::text((string) ($post[$key] ?? ''), $label);
        }
        $url = self::text((string) ($post['source_url'] ?? ''), '원문 링크');
        if ($url !== null && preg_match('#^https://[^\s<>"\']+$#', $url) !== 1) {
            throw new InvalidArgumentException('원문 링크는 https 주소이거나 비워야 합니다.');
        }
        $out['source_url'] = $url;
        $out['as_of'] = self::asOf(self::text((string) ($post['as_of'] ?? ''), '자료 기준 시각') ?? '');

        return $out;
    }

    private static function text(string $value, string $label): ?string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException($label . ' 문자가 올바르지 않습니다.');
        }
        $value = str_replace("\0", '', trim($value));
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 2000) {
            throw new InvalidArgumentException($label . ' 길이가 너무 깁니다.');
        }

        return $value;
    }

    private static function asOf(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $zone = new DateTimeZone('Asia/Seoul');
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, $zone);
            $errors = DateTimeImmutable::getLastErrors();
            $bad = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
            if ($parsed instanceof DateTimeImmutable && !$bad && $parsed->format($format) === $value) {
                return $parsed->format('Y-m-d\TH:i:sP');
            }
        }
        throw new InvalidArgumentException('자료 기준 시각이 올바르지 않습니다.');
    }

    private static function seal(array $doc): array
    {
        $body = ['id' => $doc['id'], 'versions' => $doc['versions']];
        $body['sha256'] = hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $body;
    }

    private static function load(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('damaged');
        }
        try {
            $doc = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException('damaged');
        }
        if (!is_array($doc)) {
            throw new RuntimeException('damaged');
        }
        $body = ['id' => $doc['id'] ?? null, 'versions' => $doc['versions'] ?? null];
        $hash = hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (!is_string($doc['sha256'] ?? null) || !hash_equals($hash, $doc['sha256'])) {
            throw new RuntimeException('damaged');
        }
        if (!is_string($body['id']) || preg_match('/^p[a-f0-9]{16}$/', $body['id']) !== 1 || basename($path) !== $body['id'] . '.json' || !is_array($body['versions']) || $body['versions'] === []) {
            throw new RuntimeException('damaged');
        }
        $n = 0;
        foreach ($body['versions'] as $version) {
            $n++;
            if (!is_array($version) || ($version['version'] ?? null) !== $n || !is_string($version['recorded_at'] ?? null) || !is_string($version['symbol'] ?? null)) {
                throw new RuntimeException('damaged');
            }
        }

        return $body + ['sha256' => $doc['sha256']];
    }

    private static function atomic(string $path, array $doc): void
    {
        $bytes = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tmp = tempnam(dirname($path), 'plan');
        if ($tmp === false) {
            throw new RuntimeException('계획을 저장하지 못했습니다.');
        }
        try {
            if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !rename($tmp, $path)) {
                throw new RuntimeException('계획을 저장하지 못했습니다.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private static function withLock(string $dir, callable $fn): array
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('계획 저장소를 만들 수 없습니다.');
        }
        $lock = fopen($dir . '/write.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('계획 저장소가 사용 중입니다.');
        }
        try {
            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function result(string $status, string $message, ?string $id = null, ?int $version = null): array
    {
        return ['status' => $status, 'message' => $message, 'id' => $id, 'version' => $version];
    }
}
