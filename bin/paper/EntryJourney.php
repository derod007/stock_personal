<?php
declare(strict_types=1);
require_once __DIR__.'/Followup.php';

/** Read-only links to frozen audit outcomes; manual observations never become orders. */
final class PaperEntryJourney
{
    public static function scanTime(string $value): int
    {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?: ?[+-]\d{2}:\d{2})?$/',$value))throw new RuntimeException('수동 스캔 시각 형식 오류');
        $date=new DateTimeImmutable($value,new DateTimeZone('Asia/Seoul'));
        $errors=DateTimeImmutable::getLastErrors();
        if($errors&&($errors['warning_count']||$errors['error_count']))throw new RuntimeException('수동 스캔 시각 오류');
        return $date->getTimestamp();
    }

    public static function manualBundle(array $report): array
    {
        $rows=[];
        foreach($report['rows']??[] as $row){
            if(!is_array($row))continue;
            // Preserve all final statuses so a later close comparison can include a lost signal.
            $rows[]=array_intersect_key($row,array_flip(['yahoo','name','score','price','entry_status','order_ready',
                'order_plan','pattern_evidence','entry_candidate','analysis_mode','analysis_note','quote_fetched_at','reason','new_entry_sentence']));
        }
        return ['schema'=>1,'execution'=>'manual_scan','fetched_at'=>$report['fetched_at']??null,
            'profile'=>$report['profile']??null,'market'=>$report['market']??null,'rows'=>$rows];
    }

    public static function saveManual(string $dir,array $report): array
    {
        if(empty($report['ok']))return ['status'=>'scan_failed','saved'=>false];
        $bundle=self::manualBundle($report);
        if(!is_string($bundle['fetched_at']))throw new RuntimeException('수동 스캔 시각 없음');
        self::scanTime($bundle['fetched_at']);
        $json=PaperRrAudit::encode($bundle);$id=hash('sha256',$json);
        if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('진입 기록 폴더 생성 실패');
        $lock=fopen($dir.'/write.lock','c');
        if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('진입 기록 잠금 실패');
        try{
            $file=$dir.'/'.$id.'.json';
            if(is_file($file)){
                if(!hash_equals($id,hash_file('sha256',$file)))throw new RuntimeException('기존 진입 기록 해시 불일치');
                return ['status'=>'existing','saved'=>true,'id'=>$id];
            }
            $tmp=tempnam($dir,'write-');
            try{
                if(file_put_contents($tmp,$json)!==strlen($json)||!rename($tmp,$file))throw new RuntimeException('진입 기록 저장 실패');
            }finally{if(is_file($tmp))unlink($tmp);}
            return ['status'=>'saved','saved'=>true,'id'=>$id];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    public static function linkedOutcome(array $record,?array $followup): array
    {
        if(!$followup)return ['link'=>'missing_followup','outcome'=>null];
        foreach(['symbol','session','source_file','observation_hash'] as $key){
            if(!isset($record[$key],$followup[$key])||$record[$key]!==$followup[$key])return ['link'=>'source_mismatch','outcome'=>null];
        }
        if(($followup['tracking_policy']??'')!==PaperTrackingInput::POLICY)return ['link'=>'policy_mismatch','outcome'=>null];
        foreach($followup['trades']??[] as $trade){
            if(($trade['kind']??'')==='baseline'&&is_array($trade['outcome']??null))return ['link'=>'linked','outcome'=>$trade['outcome']];
        }
        return ['link'=>$followup['status']??'missing_outcome','outcome'=>null];
    }

    public static function read(string $stateDir,string $account,string $profile): array
    {
        $rows=[];$errors=[];$closeRecords=[];
        $followup=null;
        try{$followup=PaperFollowup::load($stateDir,$account);}catch(Throwable $e){$errors[]='후속 기록 읽기 실패: '.$e->getMessage();}
        $dir=$stateDir.'/rr-audit/'.$account;
        $files=PaperRrView::files($dir);sort($files,SORT_STRING);
        foreach($files as $file){
            try{
                $bundle=PaperRrView::load($dir.'/'.$file);
                if(($bundle['membership']??'')!=='observed_scan_only')continue;
                foreach($bundle['records'] as $r){
                    if(($r['status']??'')!=='evaluated')continue;
                    $p=$r['analysis']['plan']??[];
                    $r['source_file']=$file;$r['captured_at']=(int)($bundle['recorded_at']??$r['session']);
                    $r['observation_hash']=hash('sha256',PaperRrAudit::encode($r));
                    $key=$r['symbol'].'@'.$r['session'];
                    // Close comparison is descriptive; not a trade linkage or strategy equivalence claim.
                    $closeRecords[$r['symbol']][]=['session'=>$r['session'],'recorded_at'=>$r['captured_at'],
                        'status'=>$p['status']??'unknown','ready'=>!empty($p['ready'])];
                    if(empty($p['ready']))continue;
                    $linked=self::linkedOutcome($r,$followup['rows'][$key]??null);
                    $rows[]=['source'=>'completed_audit','mode'=>'completed','name'=>$r['name']??$r['symbol'],
                        'symbol'=>$r['symbol'],'recorded_at'=>$r['captured_at'],'session'=>$r['session'],
                        'plan'=>$p,'status'=>$p['status']??'unknown','reason'=>$p['reason']??'기록 없음','note'=>'완료봉 감사 기록 · 예약/수동 CLI 실행 구분 미기록',
                        'link'=>$linked['link'],'outcome'=>$linked['outcome'],'evaluated_at'=>$linked['link']==='linked'?($followup['rows'][$key]['as_of']??null):null,
                        'source_file'=>$file,'close_comparison'=>null];
                }
                unset($bundle);
            }catch(Throwable $e){$errors[]=$file.': '.$e->getMessage();}
        }
        foreach(glob($stateDir.'/entry-observations/manual/*.json')?:[] as $path){
            try{
                $json=file_get_contents($path);$hash=basename($path,'.json');
                if(!preg_match('/^[a-f0-9]{64}$/',$hash)||!hash_equals($hash,hash('sha256',$json)))throw new RuntimeException('원본 해시 불일치');
                $b=json_decode($json,true,512,JSON_THROW_ON_ERROR);
                if(($b['schema']??0)!==1||($b['execution']??'')!=='manual_scan')throw new RuntimeException('지원하지 않는 수동 기록');
                if(($b['profile']??null)!==$profile)continue;
                $at=self::scanTime($b['fetched_at']);
                foreach($b['rows'] as $r){
                    $p=$r['order_plan']??[];
                    $live=($r['analysis_mode']??'')==='intraday'&&($r['entry_status']??'')==='intraday_preview'&&!empty($r['entry_candidate']);
                    if(!$live&&empty($p['ready'])&&($p['confirmation_status']??'')!=='confirmed'&&($r['entry_status']??'')!=='ready')continue;
                    $symbol=$r['yahoo']??'';
                    $rows[]=['source'=>'manual_scan','mode'=>$r['analysis_mode']??'unknown','name'=>$r['name']??$symbol,
                        'symbol'=>$symbol,'recorded_at'=>$at,'session'=>$live?null:($p['signal_at']??null),'plan'=>$p,'candidate'=>$r['entry_candidate']??null,
                        'status'=>$r['entry_status']??'unknown','reason'=>$r['new_entry_sentence']??$r['reason']??'기록 없음','note'=>$r['analysis_note']??'분석 기준 미기록',
                        'link'=>'manual_observation_only','outcome'=>null,'evaluated_at'=>null,'source_file'=>$hash.'.json',
                        'pattern_evidence'=>is_array($r['pattern_evidence']??null)?$r['pattern_evidence']:null,
                        'close_comparison'=>self::closeComparison($at,$r['analysis_mode']??'', $closeRecords[$symbol]??[])];
                }
            }catch(Throwable $e){$errors[]=basename($path).': '.$e->getMessage();}
        }
        usort($rows,fn($a,$b)=>($b['recorded_at']<=>$a['recorded_at'])?:strcmp($a['symbol'],$b['symbol']));
        return ['rows'=>$rows,'errors'=>$errors,'followup_as_of'=>$followup['as_of']??null];
    }

    public static function closeComparison(int $at,string $mode,array $records): ?array
    {
        if($mode!=='intraday')return null;
        $day=fn($t)=>(new DateTimeImmutable('@'.$t))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
        usort($records,fn($a,$b)=>$a['recorded_at']<=>$b['recorded_at']);
        foreach($records as $r)if($r['session']>$at&&$r['recorded_at']>$at&&$day($r['session'])===$day($at))return $r;
        return null;
    }
}
