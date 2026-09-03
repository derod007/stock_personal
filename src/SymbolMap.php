<?php

declare(strict_types=1);

namespace ChartEntryLab;

/**
 * 내부 심볼 → Yahoo Finance 티커.
 * null이면 차트 조회 불가(백테스트·스코어 스킵).
 */
final class SymbolMap
{
    /** @var array<string, string|null> */
    private const YAHOO = [
        'SNDK' => 'SNDK',
        'MU' => 'MU',
        'ORCL' => 'ORCL',
        'STX' => 'STX',
        'EWY' => 'EWY',
        'SOXS' => 'SOXS',
        'SQQQ' => 'SQQQ',
        'SPY' => 'SPY',
        'CL=F' => 'CL=F',
        'IONQ' => 'IONQ',
        'NBIS' => 'NBIS',
        'SKYQ' => 'SKYQ',
        '000660.KS' => '000660.KS',
        '005930.KS' => '005930.KS',
        '084370.KQ' => '084370.KQ',
        '122630.KS' => '122630.KS',
        '035420.KS' => '035420.KS',
        '005380.KS' => '005380.KS',
        '017670.KS' => '017670.KS',
        '006400.KS' => '006400.KS',
        '096770.KS' => '096770.KS',
        '003680.KS' => '003680.KS',
        '300080.KQ' => '300080.KQ',
        // SOL 하닉 인버스2X — Yahoo 미확인 시 본주 프록시만
        '0197X0.KS' => null,
        'HYNIX_INV_2X' => '000660.KS',
        // 레몬헬스케어: Yahoo에 심볼 없음(404) → 차트 제외
        '247960.KQ' => null,
        'SNDK_2X' => 'SNDK',
        'SNDK_SHORT' => 'SNDK',
        'KR_MARKET_VIEW' => null,
        'US_MARKET_VIEW' => null,
        'EPIS_HOLDINGS' => null,
        'UNKNOWN' => null,
    ];

    /** @var array<string, string> 한글/별칭 → 내부 심볼 */
    private const ALIASES = [
        '마이크론' => 'MU',
        'mu' => 'MU',
        '시게이트' => 'STX',
        'stx' => 'STX',
        '오라클' => 'ORCL',
        'orcl' => 'ORCL',
        'ewy' => 'EWY',
        '샌디' => 'SNDK',
        '샌디스크' => 'SNDK',
        'sndk' => 'SNDK',
        '하이닉스' => '000660.KS',
        '하닉' => '000660.KS',
        'sk하이닉스' => '000660.KS',
        '삼전' => '005930.KS',
        '삼성전자' => '005930.KS',
        '삼성' => '005930.KS',
        '유진테크' => '084370.KQ',
        '유진' => '084370.KQ',
        '애플' => 'AAPL',
        'aapl' => 'AAPL',
        '엔비디아' => 'NVDA',
        'nvda' => 'NVDA',
        '테슬라' => 'TSLA',
        'tsla' => 'TSLA',
        '아마존' => 'AMZN',
        '구글' => 'GOOGL',
        '알파벳' => 'GOOGL',
        '메타' => 'META',
        '마이크로소프트' => 'MSFT',
        'msft' => 'MSFT',
        '현대차' => '005380.KS',
        '현대자동차' => '005380.KS',
        '기아' => '000270.KS',
        '네이버' => '035420.KS',
        '카카오' => '035720.KS',
        'ionq' => 'IONQ',
        'ionz' => 'IONQ',
        '아이온큐' => 'IONQ',
        '양자' => 'IONQ',
        'nbis' => 'NBIS',
        'nbiz' => 'NBIS',
        '네비우스' => 'NBIS',
        '네비우스그룹' => 'NBIS',
        'skyq' => 'SKYQ',
        '스카이쿼리' => 'SKYQ',
        '플리토' => '300080.KQ',
        '삼성sdi' => '006400.KS',
        '삼성SDI' => '006400.KS',
        'sdi' => '006400.KS',
        '대원전선' => '006340.KS',
        '대원' => '006340.KS',
        'sk이노베이션' => '096770.KS',
        '한성기업' => '003680.KS',
        'gs건설' => '006360.KS',
        'sk텔레콤' => '017670.KS',
        'skt' => '017670.KS',
        '하닉인버스' => '0197X0.KS',
        '하닉인버2' => '0197X0.KS',
        'sqqq' => 'SQQQ',
        'spy' => 'SPY',
        '에스피' => 'SPY',
        '에스피지' => 'SPY',
        '오일' => 'CL=F',
        '원유' => 'CL=F',
        'crude' => 'CL=F',
        'cl' => 'CL=F',
        '빙그레' => '005180.KS',
        '이노테크' => '469610.KQ',
        '져스텍' => '153890.KQ',
        '한화생명' => '088350.KS',
        '씨젠' => '096530.KQ',
        '로보티즈' => '108490.KQ',
        '농심' => '004370.KS',
        'sgc에너지' => '005090.KS',
        '마녀공장' => '439090.KQ',
        'snxx' => 'SNDK',
        '삼하' => '000660.KS',
    ];

    public static function toYahoo(string $symbol): ?string
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            return null;
        }
        if (array_key_exists($symbol, self::YAHOO)) {
            return self::YAHOO[$symbol];
        }
        // 이미 Yahoo 형태로 보이면 대문자로 통일 (304100.ks → 304100.KS)
        if (preg_match('/^[A-Z0-9.^_-]+$/i', $symbol) === 1) {
            return strtoupper($symbol);
        }
        return null;
    }

    /**
     * UI/CLI 입력(티커 또는 한글명) → Yahoo 티커.
     */
    public static function resolveInput(string $input): ?string
    {
        $raw = trim($input);
        if ($raw === '') {
            return null;
        }
        $key = mb_strtolower($raw);
        if (isset(self::ALIASES[$key])) {
            return self::toYahoo(self::ALIASES[$key]);
        }
        // 국장 6자리 숫자만 넣으면 .KS 로 시도 (코스닥은 035420.KQ 처럼 접미사 필요)
        if (preg_match('/^\d{6}$/', $raw) === 1) {
            return $raw . '.KS';
        }
        // 내부 심볼 직접
        $yahoo = self::toYahoo($raw);
        if ($yahoo === null) {
            $yahoo = self::toYahoo(strtoupper($raw));
        }
        if ($yahoo === null) {
            $proxy = UnderlyingProxy::fromInput($raw);
            if ($proxy !== null) {
                return self::toYahoo($proxy['spot']);
            }
            return null;
        }
        // 레버/인버스 입력 → 본주 차트로 치환
        $spot = UnderlyingProxy::scoreSymbol($yahoo);
        if ($spot !== null) {
            return self::toYahoo($spot) ?? $spot;
        }
        $spot2 = UnderlyingProxy::scoreSymbol($raw);
        if ($spot2 !== null) {
            return self::toYahoo($spot2) ?? $spot2;
        }

        return $yahoo;
    }

    public static function isChartable(string $symbol): bool
    {
        return self::toYahoo($symbol) !== null;
    }

    /**
     * TradingView 심볼 (거래소 프리픽스).
     */
    public static function toTradingView(string $yahooOrInternal): ?string
    {
        $yahoo = self::toYahoo($yahooOrInternal);
        if ($yahoo === null) {
            return null;
        }
        if (str_ends_with($yahoo, '.KS')) {
            return 'KRX:' . substr($yahoo, 0, -3);
        }
        if (str_ends_with($yahoo, '.KQ')) {
            return 'KOSDAQ:' . substr($yahoo, 0, -3);
        }
        // 미장: 거래소 미지정 시 TV가 해석 (NYSE/NASDAQ 혼재 대응)
        return strtoupper($yahoo);
    }

    public static function tradingViewUrl(string $yahooOrInternal, string $interval = 'D'): ?string
    {
        $tv = self::toTradingView($yahooOrInternal);
        if ($tv === null) {
            return null;
        }
        return 'https://www.tradingview.com/chart/?symbol=' . rawurlencode($tv) . '&interval=' . rawurlencode($interval);
    }

    /** .KS → 코스피, .KQ → 코스닥. 그 외는 null. */
    public static function marketLabel(string $yahooOrInternal): ?string
    {
        $yahoo = self::toYahoo($yahooOrInternal) ?? $yahooOrInternal;
        if (str_ends_with($yahoo, '.KS')) {
            return '코스피';
        }
        if (str_ends_with($yahoo, '.KQ')) {
            return '코스닥';
        }

        return null;
    }

    /** 별칭 표에서 한글 종목명. 없으면 null. */
    public static function koreanName(string $yahooOrInternal): ?string
    {
        $yahoo = self::toYahoo($yahooOrInternal) ?? strtoupper(trim($yahooOrInternal));
        $best = null;
        $bestLen = 0;
        foreach (self::ALIASES as $alias => $internal) {
            if (preg_match('/[가-힣]/u', $alias) !== 1) {
                continue;
            }
            $mapped = self::toYahoo($internal) ?? $internal;
            if ($mapped !== $yahoo && $internal !== $yahoo) {
                continue;
            }
            $len = mb_strlen($alias);
            if ($len > $bestLen) {
                $best = $alias;
                $bestLen = $len;
            }
        }

        return $best;
    }
}
