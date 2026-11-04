<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;
use ChartEntryLab\Type1Breakout;

/** After-close replay. Times are bar END times, never assumed execution times. */
final class BreakoutRetest
{
    public static function evaluate(string $day,float $level,?float $dailyOpen,array $bars): array
    {
        $r=['status'=>'미확정','reason'=>null,'open'=>null,'recovery'=>null,'retest'=>null];
        $start=(new \DateTimeImmutable($day.' 09:00:00',new \DateTimeZone('Asia/Seoul')))->getTimestamp();
        $slots=[];
        foreach($bars as $b){
            $t=(int)($b['time']??0);$delta=$t-$start;
            if($delta<0||$delta>=390*60)continue;
            $slots[intdiv($delta,300)][]=$b;
        }
        for($i=0;$i<78;$i++){
            $slot=$slots[$i]??[];
            if(count($slot)!==1||($slot[0]['time']??0)!=$start+$i*300||!Tracker::valid($slot[0])){
                $r['reason']='누락·중복·비정규 또는 무효 봉: '.gmdate('H:i',$start+32400+$i*300);return $r;
            }
            $b=$slot[0];
            if((float)$b['volume']===0.0 && $b['open']==$b['high'] && $b['high']==$b['low'] && $b['low']==$b['close']){
                $r['reason']='거래 없는 단일가격 봉: '.gmdate('H:i',$start+32400+$i*300);return $r;
            }
            if($i===0){
                $r['open']=$b['open'];
                if($dailyOpen===null||$dailyOpen!=(float)$b['open']){$r['reason']='일봉 시가와 첫 5분봉 시가 대조 불가';return $r;}
                if($dailyOpen>=$level){$r['status']='대상 아님';return $r;}
            }
            $event=['kst'=>gmdate('Y-m-d H:i',$start+32400+($i+1)*300),'low'=>$b['low'],'close'=>$b['close']];
            if($r['recovery']===null){
                if($b['close']>$level)$r['recovery']=$event;
                elseif($i===5){$r['status']='미회복';return $r;}
            }elseif($b['low']<=$level){
                $r['retest']=$event;$r['status']=$b['close']>=$level?'유지':'재이탈';return $r;
            }
        }
        $r['status']='재시험 없음';return $r;
    }

    public static function record(string $folder,array $state,string $today,callable $fetch,?callable $fetchDaily=null): array
    {
        if($state['last_day']!==$today)return ['status'=>'당일 마감 기록 없음'];
        $current=Charts::snapshot($folder,$state,$today);
        $idx=array_search($today,$current['days'],true);
        if($idx===false||$idx===0)return ['status'=>'전 거래일 미확정'];
        $anchor=$current['days'][$idx-1];
        if(!isset($state['captures'][$anchor]))return ['status'=>'전 거래일 후보 기록 없음'];
        $dir=$folder.'/breakout-retest';
        if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new \RuntimeException('관찰 폴더 생성 실패');
        $lock=fopen($dir.'/write.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('관찰 기록 잠금 실패');
        try{
            $path=$dir.'/'.$anchor.'.json';
            if(is_file($path))return ['status'=>'이미 저장','anchor'=>$anchor];
            $pre=Charts::snapshot($folder,$state,$anchor);$rows=[];
            foreach($state['captures'][$anchor]['rows'] as $row){
                $c=$row['classification']??null;if(!($c['matches']??false)||$c['analysis_date']!==$anchor)continue;
                $s=$row['symbol'];$e=Type1Breakout::evidence($pre['prices'][$s]??[],$s,$c,$pre['as_of']);
                if(!$e['confirmed'])continue;
                try{
                    $d=Tracker::byDay($current['prices'][$s]??[],$s,$current['as_of'])[$today]??null;
                    $dailySource='당일 스냅샷';
                    if(!$d && $fetchDaily){$dailySource='마감 일봉 추가 조회';$d=Tracker::byDay($fetchDaily($s),$s,$current['as_of'])[$today]??null;}
                    $open=$d&&Tracker::valid($d)?(float)$d['open']:null;
                    if($open!==null&&$open>=$e['level'])$event=['status'=>'대상 아님','open'=>$open];
                    elseif($open===null)$event=['status'=>'미확정','reason'=>'당일 일봉 시가 없음'];
                    else $event=self::evaluate($today,$e['level'],$open,$fetch($s));
                    $event['daily_open']=$open;$event['daily_source']=$dailySource;
                }catch(\Throwable){$event=['status'=>'미확정','reason'=>'일봉 또는 5분봉 조회 실패'];}
                $rows[$s]=['level'=>$e['level']]+$event;
            }
            Store::atomic($path,['version'=>1,'anchor'=>$anchor,'day'=>$today,'recorded_at'=>date(DATE_ATOM),'snapshot_sha256'=>$state['captures'][$anchor]['snapshot_sha256'],'day_snapshot_sha256'=>$state['captures'][$today]['snapshot_sha256'],'rows'=>$rows]);
            return ['status'=>'저장','anchor'=>$anchor,'rows'=>count($rows)];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    /** Read-only index of recent judgment days. Uses stored retest files when present; does not invent 미회복. */
    public static function recentSummaries(string $folder, array $state, int $limit = 10): array
    {
        $days = array_keys($state['captures'] ?? []);
        rsort($days, SORT_STRING);
        $days = array_slice($days, 0, max(0, $limit));
        $out = [];
        foreach ($days as $day) {
            $capture = $state['captures'][$day];
            $unevaluable = 0;
            foreach ($capture['rows'] as $row) {
                if (($row['error'] ?? null) !== null) {
                    $unevaluable++;
                }
            }
            $item = [
                'day' => $day,
                'confirmed' => null,
                'unevaluable' => $unevaluable,
                'recent_candidate' => false,
                'observation' => [
                    'state' => '기록 없음',
                    'label' => '다음 거래일 관찰 기록 없음',
                    'counts' => null,
                ],
            ];
            $path = $folder.'/breakout-retest/'.$day.'.json';
            if (is_file($path)) {
                try {
                    $record = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                    if (($record['anchor'] ?? null) !== $day
                        || ($record['snapshot_sha256'] ?? null) !== ($capture['snapshot_sha256'] ?? null)) {
                        $item['observation'] = [
                            'state' => '미확정',
                            'label' => '관찰 기록 기준일·해시 불일치',
                            'counts' => null,
                        ];
                    } else {
                        $rows = $record['rows'] ?? [];
                        if (!is_array($rows)) {
                            throw new \RuntimeException('rows');
                        }
                        $item['confirmed'] = count($rows);
                        $item['observation'] = self::observationLabel($rows);
                    }
                } catch (\Throwable) {
                    $item['observation'] = [
                        'state' => '미확정',
                        'label' => '관찰 기록 손상 또는 읽기 실패',
                        'counts' => null,
                    ];
                }
            } elseif (($state['last_day'] ?? null) === $day) {
                $item['confirmed'] = self::confirmedCount($folder, $state, $day);
                $item['observation'] = [
                    'state' => '대기',
                    'label' => '다음 거래일 관찰 대기',
                    'counts' => null,
                ];
            } else {
                // Older capture without a stored replay: do not invent 미회복 from daily opens alone.
                $item['confirmed'] = self::confirmedCount($folder, $state, $day);
                $item['observation'] = [
                    'state' => '기록 없음',
                    'label' => '다음 거래일 관찰 기록 없음',
                    'counts' => null,
                ];
            }
            if ($item['confirmed'] === null) {
                $item['confirmed'] = self::confirmedCount($folder, $state, $day);
            }
            $out[] = $item;
        }
        foreach ($out as $i => $row) {
            if (($row['confirmed'] ?? 0) > 0) {
                $out[$i]['recent_candidate'] = true;
                break;
            }
        }
        return $out;
    }

    private static function observationLabel(array $rows): array
    {
        if ($rows === []) {
            return [
                'state' => '요약',
                'label' => '종가 돌파 확인 0건',
                'counts' => [],
            ];
        }
        $counts = [];
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '미확정');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        $watched = count($rows) - ($counts['대상 아님'] ?? 0);
        if ($watched === 0) {
            return [
                'state' => '요약',
                'label' => '회복 관찰 대상 0건 (다음 시가 ≥ 기준선 '.($counts['대상 아님'] ?? 0).'건)',
                'counts' => $counts,
            ];
        }
        $parts = [];
        foreach (['회복', '유지', '재이탈', '미회복', '재시험 없음', '미확정', '대상 아님'] as $status) {
            if (($counts[$status] ?? 0) > 0) {
                $parts[] = $status.' '.$counts[$status].'건';
            }
        }
        foreach ($counts as $status => $n) {
            if (!in_array($status, ['회복', '유지', '재이탈', '미회복', '재시험 없음', '미확정', '대상 아님'], true) && $n > 0) {
                $parts[] = $status.' '.$n.'건';
            }
        }
        return [
            'state' => '요약',
            'label' => '회복 관찰 대상 '.$watched.'건 · '.implode(' · ', $parts),
            'counts' => $counts,
        ];
    }

    private static function confirmedCount(string $folder, array $state, string $day): ?int
    {
        try {
            $snapshot = Charts::snapshot($folder, $state, $day);
            $n = 0;
            foreach ($state['captures'][$day]['rows'] as $row) {
                $c = $row['classification'] ?? null;
                if (!($c['matches'] ?? false) || ($c['analysis_date'] ?? null) !== $day) {
                    continue;
                }
                $e = Type1Breakout::evidence($snapshot['prices'][$row['symbol']] ?? [], $row['symbol'], $c, $snapshot['as_of']);
                if ($e['confirmed'] ?? false) {
                    $n++;
                }
            }
            return $n;
        } catch (\Throwable) {
            return null;
        }
    }
}
