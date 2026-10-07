<?php
declare(strict_types=1);
require_once __DIR__.'/EntryJourney.php';
require_once __DIR__.'/JsonRows.php';
use ChartEntryLab\PaperJournal;

/** Read-only event digest. Missing membership and missing evidence are never lost signals. */
final class PaperDailyChanges
{
    public static function day(int $at):string{return (new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');}
    public static function transitions(array $runs,string $day):array
    {
        usort($runs,fn($a,$b)=>($a['at']<=>$b['at'])?:strcmp($a['id'],$b['id']));
        $previous=[];$out=[];
        foreach($runs as $run){
            $scope=$run['scope'];$before=$previous[$scope]??null;
            if(self::day($run['at'])===$day)foreach($run['rows'] as $symbol=>$r){
                if(!is_bool($r['ready']??null))continue;
                $old=$before['rows'][$symbol]??null;
                $known=is_bool($old['ready']??null)&&($before['at']??0)<$run['at'];
                $kind=null;
                if(!$known&&$r['ready'])$kind='first_seen_ready';
                elseif($known&&$old['ready']!==$r['ready'])$kind=$r['ready']?'new_ready':'lost_ready';
                if(!$kind)continue;
                $out[]=['kind'=>$kind,'symbol'=>$symbol,'name'=>$r['name'],'at'=>$run['at'],'before_at'=>$known?$before['at']:null,
                    'before'=>$known?$old['status']:null,'after'=>$r['status'],'source'=>$run['source'],
                    'same_bar'=>$known&&isset($old['session'],$r['session'])&&$old['session']===$r['session'],
                    'note'=>$run['note'],'reference'=>$run['id']];
            }
            // Replace the complete membership; absent/failed rows cannot imply a transition.
            $previous[$scope]=$run;
        }
        return $out;
    }

    public static function read(string $dir,string $account,string $profile,string $day):array
    {
        $runs=[];$allowed=[];$errors=[];$lastScan=null;$lastFollowup=null;
        foreach(PaperRrView::files($dir.'/rr-audit/'.$account) as $file){
            try{
                $b=PaperRrView::load($dir.'/rr-audit/'.$account.'/'.$file);
                if(($b['membership']??'')!=='observed_scan_only')continue;
                $at=(int)($b['recorded_at']??0);if($at<=0)throw new RuntimeException('감사 기록 시각 없음');
                $rows=[];
                foreach($b['records'] as $r){
                    $symbol=$r['symbol']??'';$p=$r['analysis']['plan']??[];
                    $valid=($r['status']??'')==='evaluated'&&is_bool($p['ready']??null);
                    $rows[$symbol]=['name'=>$r['name']??$symbol,'ready'=>$valid?$p['ready']:null,'status'=>$p['status']??'unavailable','session'=>$r['session']??null];
                    if($valid&&$p['ready']){
                        $r['source_file']=$file;$r['captured_at']=(int)($b['recorded_at']??$r['session']);
                        $r['observation_hash']=hash('sha256',PaperRrAudit::encode($r));
                        $allowed[$r['observation_hash']]=array_intersect_key($r,array_flip(['symbol','session','source_file','observation_hash','name']));
                    }
                }
                $runs[]=['id'=>$file,'at'=>$at,'scope'=>'audit:'.$account,'source'=>'완료봉 감사',
                    'note'=>'과거 전략·실행 구분 미기록 · 관찰 비교','rows'=>$rows];
                if(self::day($at)===$day&&($lastScan===null||$at>$lastScan['at']))$lastScan=['at'=>$at,'summary'=>$b['summary']??[],'scan_summary'=>$b['scan_summary']??[],'count'=>count($b['records'])];
                unset($b);
            }catch(Throwable $e){$errors[]=$file.': '.$e->getMessage();}
        }
        // If an audit file failed, do not silently compare over that unknown run.
        $auditBad=(bool)$errors;if($auditBad)$runs=[];
        $manualBad=false;
        foreach(glob($dir.'/entry-observations/manual/*.json')?:[] as $file){
            try{
                $raw=file_get_contents($file);
                if(!hash_equals(basename($file,'.json'),hash('sha256',$raw)))throw new RuntimeException('수동 원본 해시 불일치');
                $b=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
                if(($b['schema']??0)!==1||($b['execution']??'')!=='manual_scan')throw new RuntimeException('수동 기록 형식 오류');
                if(($b['profile']??'')!==$profile)continue;
                $rows=[];
                foreach($b['rows'] as $r){
                    $p=$r['order_plan']??[];
                    // Provisional and fallback observations cannot confirm or revoke a completed signal.
                    $valid=($r['analysis_mode']??'')==='completed'&&is_bool($p['ready']??null);
                    $rows[$r['yahoo']]=['name'=>$r['name']??$r['yahoo'],'ready'=>$valid?$p['ready']:null,'status'=>$r['entry_status']??'unknown','session'=>$p['signal_at']??null];
                }
                $runs[]=['id'=>basename($file),'at'=>PaperEntryJourney::scanTime($b['fetched_at']),
                    'scope'=>'manual:'.$profile.':'.($b['market']??'unknown'),'source'=>'수동 스캔',
                    'note'=>'완료봉 분석끼리만 비교 · 동일 전략 여부 미확인','rows'=>$rows];
            }catch(Throwable $e){$manualBad=true;$errors[]=basename($file).': '.$e->getMessage();}
        }
        if($manualBad)$runs=array_values(array_filter($runs,fn($r)=>$r['source']!=='수동 스캔'));
        $events=self::transitions($runs,$day);$research=[];$firstTerminal=[];$historyBad=false;
        $files=PaperRrView::files($dir.'/followup/'.$account);sort($files,SORT_STRING);
        // latest may be the only retained report; never call its timestamp an execution date.
        if(is_file($dir.'/followup/'.$account.'/latest.json'))$files[]='latest.json';
        foreach($files as $file){
            try{
                $pending=[];$generator=PaperJsonRows::read($dir.'/followup/'.$account.'/'.$file);
                foreach($generator as $r){
                    $record=$allowed[$r['observation_hash']??'']??null;if(!$record)continue;
                    $link=PaperEntryJourney::linkedOutcome($record,$r);$o=$link['outcome'];if(!$o)continue;
                    $key=$r['observation_hash'];$pending[$key]=['record'=>$record,'row'=>$r,'outcome'=>$o];
                }
                $meta=$generator->getReturn();
                if(($meta['schema']??0)!==1||!is_numeric($meta['generated_at']??null))throw new RuntimeException('후속 기록 생성 시각/형식 없음');
                $generated=(int)$meta['generated_at'];$asOf=(int)($meta['as_of']??0);
                $lastFollowup=max($lastFollowup??0,$asOf);
                foreach($pending as $key=>$v){
                    // Preserve newest evaluated result, never reuse a later-saved older offline replay.
                    if(!isset($research[$key])||($v['row']['as_of']??0)>($research[$key]['row']['as_of']??0))$research[$key]=$v;
                    if(in_array($v['outcome']['status']??'',['unfilled','cancelled_before_entry'],true)){
                        $terminalKey=$key.':'.$v['outcome']['status'];
                        if(!isset($firstTerminal[$terminalKey])||$generated<$firstTerminal[$terminalKey]['at'])$firstTerminal[$terminalKey]=['at'=>$generated,'value'=>$v];
                    }
                }
            }catch(Throwable $e){$historyBad=true;$errors[]=$file.': '.$e->getMessage();}
        }
        $tradeEvents=[];
        foreach($research as $key=>$v){
            $o=$v['outcome'];
            foreach(['entry_at'=>'fill','exit_at'=>'exit'] as $field=>$kind){
                if(is_numeric($o[$field]??null)&&self::day((int)$o[$field])===$day)$tradeEvents[]=self::tradeEvent($v,$kind,(int)$o[$field],'모형 거래일');
            }
        }
        if(!$historyBad)foreach($firstTerminal as $t){
            if(self::day($t['at'])===$day)$tradeEvents[]=self::tradeEvent($t['value'],$t['value']['outcome']['status'],$t['at'],'보존 기록 중 최초 확인일 · 발생일 아님');
        }
        $accountEvents=[];
        try{
            $journal=(new PaperJournal($dir.'/'.$account.'-forward.json'))->read();
            foreach($journal['events']??[] as $e){
                $p=$e['payload'];if(!in_array($e['type'],['fill','exit','order_cancelled'],true)||!is_numeric($p['session']??null)||self::day((int)$p['session'])!==$day)continue;
                $accountEvents[]=['kind'=>$e['type'],'symbol'=>$p['symbol'],'at'=>$p['session'],'recorded_at'=>$e['recorded_at'],
                    'price'=>$p['price']??null,'reason'=>$p['reason']??null,'net_pnl'=>$p['net_pnl']??null,'id'=>$e['id']];
            }
        }catch(Throwable $e){$errors[]='모의 계좌: '.$e->getMessage();}
        return ['changes'=>$events,'research'=>$tradeEvents,'account_events'=>$accountEvents,'errors'=>$errors,
            'last_scan'=>$lastScan,'last_followup'=>$lastFollowup];
    }
    private static function tradeEvent(array $v,string $kind,int $at,string $basis):array
    {
        $o=$v['outcome'];return ['kind'=>$kind,'at'=>$at,'basis'=>$basis,'symbol'=>$v['record']['symbol'],
            'name'=>$v['record']['name']??$v['record']['symbol'],'reason'=>$kind==='exit'?($o['first_exit']??null):null,
            'entry'=>$o['entry_fill']??null,'exit'=>$kind==='exit'?($o['exit_fill']??null):null,'net_return_pct'=>$kind==='exit'?($o['net_return_pct']??null):null,
            'reference'=>$v['record']['source_file'],'ambiguous'=>in_array($kind,['exit','cancelled_before_entry'],true)&&!empty($o['ambiguous_bar'])];
    }
}
