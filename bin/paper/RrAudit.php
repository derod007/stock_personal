<?php
declare(strict_types=1);

use ChartEntryLab\CandleClock;
use ChartEntryLab\PaperQuality;
use ChartEntryLab\PriceCandidate;
use ChartEntryLab\TradeSimulator;

/** Read-only research. Never returns an operational scan recommendation. */
final class PaperRrAudit
{
    public const VERSION = 'confirmed_rr_limit_v1';
    private const REASONS = [
        'data_quality_blocked'=>'일봉 품질 검사 차단', 'stale_data'=>'완료 일봉이 오래됨',
        'blocked'=>'기존 매수 금지 조건', 'spike_dump'=>'급등 후 급락 경고',
        'top_collapse'=>'고점 붕괴 경고', 'risk_blocked'=>'기존 위험 차단',
        'context_wait'=>'상위 추세·추가 손익비 조건 미충족', 'already_ready'=>'기존 조건으로 이미 추천됨',
        'not_confirmed_rr_rejection'=>'패턴 확인 후 손익비 탈락 사례가 아님',
        'not_selected_pattern'=>'기존 엔진이 선택한 패턴이 아님', 'baseline_already_ready'=>'기존 추천이 이미 있음',
        'no_valid_lower_limit'=>'정수 절삭 후 손절과 목표 사이 유효한 낮은 지정가가 없음',
        'rr_below_threshold'=>'지정가에서도 손익비 1.5 미달',
    ];
    private const GATES = [
        'rising_structure'=>'상승 구조', 'atr_valid'=>'ATR 유효', 'valid_zone'=>'유효 관심 구간',
        'near_ma20'=>'MA20 눌림', 'volume_contracted'=>'눌림 거래량 85% 미만',
        'recovery_close'=>'직전봉 고점 회복', 'bullish_candle'=>'양봉',
        'volume_recovery'=>'거래량 회복', 'fresh_confirmation'=>'새 확인 신호',
    ];

    public static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function capture(array $leader, array $result): array
    {
        $symbol = (string) $leader['yahoo'];
        $input = $result['research_input'] ?? null;
        if (empty($result['ok']) || !is_array($input)) {
            return ['symbol'=>$symbol, 'name'=>$leader['name'] ?? $symbol, 'amount_rank'=>$leader['rank'] ?? null,
                'status'=>'unavailable', 'reason'=>empty($result['ok']) ? 'chart_query_failed' : 'cached_evidence_unavailable',
                'detail'=>$result['error'] ?? '캐시에는 확인 당시 원본·조건 증거가 없어 평가하지 않음', 'patterns'=>[]];
        }
        $bars = $input['bars'];
        $analysis = $input['analysis'];
        $session = (int) $analysis['plan']['data_asof'];
        $source = ['sha256'=>hash('sha256', self::encode($bars)), 'kind'=>$result['evidence_origin'] ?? 'exact_scan_input',
            'price_basis'=>'provider_daily_with_existing_naver_merge'];
        $quality = PaperQuality::inspect($bars, $symbol, $session, $source);
        return ['symbol'=>$symbol, 'name'=>$leader['name'] ?? $symbol, 'amount_rank'=>$leader['rank'] ?? null,
            'session'=>$session, 'asof'=>$analysis['plan']['asof'], 'status'=>'evaluated',
            'input_hash'=>$source['sha256'], 'bars'=>$bars, 'analysis'=>$analysis, 'quality'=>$quality,
            'measurements'=>self::measurements(CandleClock::completed($bars, $symbol, $session)),
            'patterns'=>self::evaluate($analysis, $quality)];
    }

    private static function measurements(array $bars): array
    {
        if (count($bars) < 24) return [];
        $last=$bars[count($bars)-1]; $prev=$bars[count($bars)-2];
        $base=array_sum(array_column(array_slice($bars,-24,20),'volume'))/20;
        $pull=array_sum(array_column(array_slice($bars,-4,3),'volume'))/3;
        return ['pullback_volume_ratio'=>$base>0?$pull/$base:null, 'required_volume_ratio_lt'=>0.85,
            'confirmation_close'=>$last['close'], 'previous_high'=>$prev['high'],
            'high_recovery_shortfall_pct'=>$prev['high']>0?max(0,($prev['high']-$last['close'])/$prev['high']*100):null,
            'current_volume'=>$last['volume'], 'previous_volume'=>$prev['volume'],
            'required_rr'=>1.5];
    }

    public static function evaluate(array $analysis, array $quality): array
    {
        $plan = $analysis['plan'];
        $features = $analysis['features'];
        $context = $plan['context'] ?? [];
        // Preserve every independently observable blocker, including those hidden by final status.
        $blockers = [];
        if (empty($quality['can_simulate'])) $blockers[] = 'data_quality_blocked';
        if (($plan['asof'] ?? 0) - ($plan['data_asof'] ?? 0) > 4 * 86400) $blockers[] = 'stale_data';
        if (($analysis['decision']['action'] ?? '') === 'blocked') $blockers[] = 'blocked';
        if (($features['spike_dump_status'] ?? 'none') !== 'none') $blockers[] = 'spike_dump';
        if (in_array($features['top_pattern_status'] ?? 'none', ['warning','confirmed'], true)
            && ($features['top_pattern_phase'] ?? '') !== 'bounce_confirmed') $blockers[] = 'top_collapse';
        // This experiment changes only entry price. A context rejection is not rescued by the new RR.
        if (in_array($plan['status'] ?? '', ['context_wait','stale_data','blocked','risk_blocked'], true)) {
            $blockers[] = $plan['status'];
        }
        if (!empty($plan['context_applied']) && (($context['daily'] ?? '') === 'down'
            || (($context['weekly'] ?? '') === 'down'
                && (($context['daily'] ?? '') !== 'up' || ($plan['reward_risk'] ?? 0) < 2)))) $blockers[] = 'context_wait';
        $out = [];
        foreach ($plan['diagnostics']['patterns'] ?? [] as $name=>$raw) {
            $status = $raw['status'] ?? 'unknown';
            $failed = []; $unknown = [];
            if ($name === 'trend_pullback') {
                foreach (self::GATES as $gate=>$label) {
                    if (!array_key_exists($gate, $raw['gates'] ?? [])) $unknown[$gate] = $label;
                    elseif ($raw['gates'][$gate] === false) $failed[$gate] = $label;
                }
            } else {
                $unknown['detailed_gates'] = '재지지 세부 조건은 엔진이 개별 제공하지 않음: 상태·사유 참조';
            }
            $reasons = array_values(array_unique($blockers));
            $confirmedRr = $status === 'rejected_rr' && isset($raw['signal_at'])
                && $raw['signal_at'] === ($plan['data_asof'] ?? null);
            if (!$confirmedRr) $reasons[] = !empty($raw['ready']) ? 'already_ready' : 'not_confirmed_rr_rejection';
            if (($raw['version'] ?? '') !== ($plan['pattern'] ?? '')) $reasons[] = 'not_selected_pattern';
            if (!empty($plan['ready'])) $reasons[] = 'baseline_already_ready';
            $candidate = null;
            if ($confirmedRr) {
                $entry = (float) ($raw['entry'] ?? 0); $stop = (float) ($raw['stop'] ?? 0); $target = (float) ($raw['target'] ?? 0);
                $limit = PriceCandidate::trunc(($target + 1.5 * $stop) / 2.5);
                if (!($stop > 0 && $stop < $entry && $target > $stop && $limit > $stop
                    && $limit < $target && $limit < $entry)) {
                    $reasons[] = 'no_valid_lower_limit';
                } else {
                    $rr = ($target - $limit) / ($limit - $stop);
                    if ($rr < 1.5) $reasons[] = 'rr_below_threshold';
                    if (!empty($plan['context_applied']) && ($context['weekly'] ?? '') === 'down'
                        && (($context['daily'] ?? '') !== 'up' || $rr < 2)) $reasons[] = 'context_wait';
                    $candidate = ['ready'=>true, 'status'=>'research_limit', 'entry'=>$limit,
                        'stop'=>$raw['stop'], 'target'=>$raw['target'], 'reward_risk'=>$rr,
                        'rr_basis'=>'before_costs', 'signal_at'=>$raw['signal_at'], 'order_valid_bars'=>3];
                }
            }
            $reasons = array_values(array_unique($reasons));
            $accepted = $confirmedRr && $candidate !== null && $reasons === [];
            $out[] = ['pattern'=>$name, 'raw_status'=>$status, 'raw_reason'=>$raw['reason'] ?? null,
                'final_status'=>$plan['status'], 'final_reason'=>$plan['reason'] ?? null,
                'confirmed_rr_rejection'=>$confirmedRr, 'original'=>array_intersect_key($raw,
                    array_flip(['entry','stop','target','reward_risk','signal_at','target_rule'])),
                'gates'=>$raw['gates'] ?? [], 'missing_conditions'=>$failed, 'not_evaluated'=>$unknown,
                'context'=>$context, 'quality_reasons'=>$quality['reasons'] ?? [],
                'status'=>$accepted ? 'added' : 'excluded', 'exclusion_reasons'=>$reasons,
                'exclusion_reason_labels'=>array_map(static fn($r)=>self::REASONS[$r]??$r,$reasons),
                'addition_reason'=>$accepted ? '패턴 확인 완료·손익비만 부족: 손절/목표 고정, 낮은 지정가에서 RR 1.5 이상 충족' : null,
                'candidate'=>$accepted ? $candidate : null,
                'hypothetical_limit'=>$candidate, 'operational_order'=>false];
        }
        return $out;
    }

    public static function summary(array $records): array
    {
        $s = ['symbols'=>count($records), 'unavailable'=>0, 'confirmed_rr_symbol_days'=>0,
            'confirmed_rr_patterns'=>0, 'added'=>0, 'excluded'=>0, 'rr_final_statuses'=>[], 'exclusion_reasons'=>[]];
        $seen=[];
        foreach ($records as $record) {
            if (($record['status'] ?? '') === 'unavailable') { $s['unavailable']++; continue; }
            foreach ($record['patterns'] as $p) {
                if ($p['confirmed_rr_rejection']) {
                    $s['confirmed_rr_patterns']++;
                    $key=$record['symbol'].'@'.$record['session'];
                    if (!isset($seen[$key])) {
                        $seen[$key]=true; $s['confirmed_rr_symbol_days']++;
                        $f=$p['final_status']; $s['rr_final_statuses'][$f]=($s['rr_final_statuses'][$f]??0)+1;
                    }
                    $s[$p['status']==='added'?'added':'excluded']++;
                    foreach ($p['exclusion_reasons'] as $r) $s['exclusion_reasons'][$r]=($s['exclusion_reasons'][$r]??0)+1;
                }
            }
        }
        return $s;
    }

    /** Immutable, atomic run bundle. An error is surfaced to the daily run log, not hidden. */
    public static function save(string $directory, array $report, array $records): array
    {
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) throw new RuntimeException('감사 로그 디렉터리 생성 실패');
        $summary=self::summary($records);
        $bundle=['schema'=>1, 'version'=>self::VERSION, 'recorded_at'=>time(),
            'membership'=>'observed_scan_only', 'fetched_at'=>$report['fetched_at']??null,
            'scan_summary'=>$report['summary']??null, 'summary'=>$summary, 'records'=>$records];
        $name=gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
        $tmp=$directory.'/'.$name.'.tmp'; $path=$directory.'/'.$name;
        $json=self::encode($bundle)."\n";
        try {
            if (@file_put_contents($tmp,$json,LOCK_EX)!==strlen($json) || !@rename($tmp,$path)) throw new RuntimeException('감사 로그 저장 실패');
        } finally { if(is_file($tmp)) @unlink($tmp); }
        return ['status'=>'saved', 'path'=>$path, 'summary'=>$summary];
    }

    public static function outcome(array $record, array $candidate, array $raw, int $asOf): array
    {
        $symbol=$record['symbol']; $session=(int)$record['session'];
        if (hash('sha256',self::encode($record['bars']))!==$record['input_hash']) return ['status'=>'input_hash_mismatch'];
        $old=CandleClock::completed($record['bars'],$symbol,$session);
        $new=CandleClock::completed($raw,$symbol,$session);
        $index=[];
        foreach($new as $b) $index[$b['available_at']]=$b;
        foreach($old as $b) {
            $n=$index[$b['available_at']]??null;
            foreach(['open','high','low','close','volume'] as $k) {
                if($n===null || (float)($n[$k]??-1)!==(float)($b[$k]??-1)) return ['status'=>'historical_revision_or_missing'];
            }
        }
        $completed=CandleClock::completed($raw,$symbol,$asOf);
        $future=array_values(array_filter($completed,static fn($b)=>$b['available_at']>$session));
        $observedSession=$session;
        foreach ($raw as $b) {
            $t=CandleClock::closeTime($b,$symbol);
            if ($t<=$asOf && empty($b['synthetic']) && ($b['is_complete']??true)!==false) $observedSession=max($observedSession,$t);
        }
        // Check raw input before a simulator can silently skip duplicate or invalid bars.
        $quality=PaperQuality::inspect($raw,$symbol,$observedSession,
            ['sha256'=>hash('sha256',self::encode($raw))]);
        if(empty($quality['can_simulate'])) return ['status'=>'future_quality_blocked','quality'=>$quality];
        if($future===[]) return ['status'=>'no_future_bars','complete'=>false];
        $result=(new TradeSimulator())->simulate($candidate,$future,20);
        $result['entry_bar_stop_touch']=false;
        foreach($future as $b) {
            if($b['available_at']===($result['entry_at']??null) && $b['low']<=$candidate['stop']) $result['entry_bar_stop_touch']=true;
        }
        $result['observed_future_bars']=count($future);
        $result['basis']='independent_trade_not_portfolio';
        return $result;
    }
}
