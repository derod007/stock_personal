<?php
declare(strict_types=1);
// Deliberately outside src: reporting must not change the pinned strategy hash.
final class PaperDiagnostics
{
    public static function summarize(array $events, int $days=30): array
    {
        $latest=0;
        foreach($events as $e) $latest=max($latest,(int)($e['payload']['session']??0));
        $cutoff=$latest-max(1,$days)*86400;
        $out=['latest_session'=>$latest,'days'=>$days,'origins'=>[]];$snapshots=[];
        foreach($events as $e) {
            $p=$e['payload'];$type=$e['type'];$session=(int)($p['session']??0);
            if(!$session || $session<$cutoff) continue;
            $key=($p['symbol']??'').':'.$session;
            if($type==='snapshot') $snapshots[$key]=$p;
            $origin=$snapshots[$key]['origin']??'execution';
            // Fills/exits relate to older orders; don't attribute them to today's recommendation.
            if(in_array($type,['fill','exit','order_cancelled','account_halted'],true)) $origin='execution';
            if(!in_array($type,['snapshot','decision','order','fill','exit','order_cancelled','account_halted'],true)) continue;
            if(!isset($out['origins'][$origin])) $out['origins'][$origin]=['counts'=>[], 'reasons'=>[], 'plan_reasons'=>[], 'quality_reasons'=>[]];
            $g=&$out['origins'][$origin];
            $g['counts'][$type]=($g['counts'][$type]??0)+1;
            if(isset($p['reason'])) {
                $r=$type.':'.$p['reason'];$g['reasons'][$r]=($g['reasons'][$r]??0)+1;
            }
            if($type==='decision' && $p['reason']==='signal_not_confirmed') {
                $r=$snapshots[$key]['plan']['reason']??$snapshots[$key]['plan']['status']??'unknown';
                $g['plan_reasons'][$r]=($g['plan_reasons'][$r]??0)+1;
            }
            if($type==='snapshot') {
                foreach(array_unique($p['quality']['reasons']??[]) as $r) $g['quality_reasons'][$r]=($g['quality_reasons'][$r]??0)+1;
            }
            unset($g);
        }
        foreach($out['origins'] as &$g) foreach(['reasons','plan_reasons','quality_reasons'] as $k) arsort($g[$k]);
        return $out;
    }
    public static function runs(string $directory, int $now): array
    {
        $runs=[];$invalid=0;
        foreach(glob($directory.'/*.json')?:[] as $file) {
            try {
                $r=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
                if(!is_array($r) || !isset($r['started_at'],$r['status'],$r['stage'])) throw new RuntimeException('Invalid run');
                $runs[]=$r;
            } catch(Throwable $e) {$invalid++;}
        }
        usort($runs,fn($a,$b)=>($a['started_at']<=>$b['started_at'])?:strcmp($a['run_id'],$b['run_id']));
        $previous=null;
        foreach($runs as &$r) {
            $r['outcome']=$r['status'];
            if($r['status']==='success') {
                $session=$r['summary']['last_session']??null;
                $r['outcome']=$previous===null?'success_first_observed':($session>$previous?'success_advanced':'success_no_new_session');
                $previous=max($previous??0,$session??0);
            } elseif($r['status']==='running' && $now-$r['started_at']>3700) $r['outcome']='unfinished';
        }
        unset($r);
        $latest=$runs===[]?null:$runs[count($runs)-1];
        return ['latest'=>$latest,'recent'=>array_reverse(array_slice($runs,-30)),'invalid_files'=>$invalid,
            'hours_since_start'=>$latest?max(0,($now-$latest['started_at'])/3600):null];
    }
    public static function label(string $code): string
    {
        return [
            'success_first_observed'=>'정상 · 첫 실행 관측', 'success_advanced'=>'정상 · 새 거래일 갱신',
            'success_no_new_session'=>'정상 · 새 거래일 없음', 'failed'=>'실패', 'halted'=>'계좌 중단',
            'running'=>'실행 중', 'unfinished'=>'완료 기록 없음 · 강제 종료 가능',
            'decision:signal_not_confirmed'=>'진입 조건 미충족', 'decision:data_quality'=>'데이터 품질로 제외',
            'decision:retrospective_signal'=>'사후 기록 · 신규 주문 제외', 'decision:already_active'=>'이미 보유/주문 중',
            'decision:position_limit'=>'동시 보유 한도', 'decision:cash_or_risk_or_sector_limit'=>'현금·위험·업종 한도 (기존 통합 사유)',
            'decision:invalid_plan'=>'가격 계획 오류', 'decision:unpriced_position'=>'보유 가격 확인 불가',
            'order_cancelled:unfilled'=>'매수가 미도달 · 주문 만료', 'order_cancelled:data_quality'=>'데이터 품질로 주문 취소',
            'order_cancelled:cancelled_before_entry'=>'진입 전 취소 조건 충족',
            'snapshot'=>'추천 평가', 'decision'=>'신규 주문 제외', 'order'=>'주문 생성', 'fill'=>'체결',
            'exit'=>'청산', 'order_cancelled'=>'주문 취소/만료', 'account_halted'=>'계좌 중단',
            'forward'=>'당시 기록', 'catchup'=>'놓친 기간 사후 기록', 'replay'=>'과거 재현', 'execution'=>'주문 실행 결과',
        ][$code]??$code;
    }
}
