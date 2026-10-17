<?php
declare(strict_types=1);
require_once __DIR__.'/RecoveryCloseAccount.php';
use ChartEntryLab\{CandleClock,ChartPlanEngine,PaperQuality,PaperJournal};

/** Read-only price adapter and signal diagnostics. No portfolio, orders or return calculations. */
final class PaperKrxSignalStudy
{
    public const VERSION='krx_signal_compare_v1';
    public const BASIS='KRX TDD 원본, 수정 여부 미확정';
    public const DOC='docs/krx-signal-compare';
    public const CAND='docs/historical-top100-20241008-20251002/candidates.json';
    public const DATA='docs/krx-tdd-dataset-20231013-20251002';
    public const PREP='docs/krx-prep-bars-before-20241008';
    public const EVAL='docs/historical-top100-20241008-20251002';
    public const FIELDS=['open'=>'TDD_OPNPRC','high'=>'TDD_HGPRC','low'=>'TDD_LWPRC',
        'close'=>'TDD_CLSPRC','volume'=>'ACC_TRDVOL','amount_won'=>'ACC_TRDVAL'];

    public static function json(string $path):array
    {
        if(!is_file($path))throw new RuntimeException('Missing file: '.$path);
        return json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    }
    public static function encode(mixed $v):string
    {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
    public static function digest(mixed $v):string{return hash('sha256',self::encode($v));}
    public static function num(mixed $v):?float
    {
        if($v===null)return null;
        if(is_bool($v))throw new RuntimeException('Boolean price/volume');
        $s=is_string($v)?trim(str_replace(',','',$v)):$v;
        if($s===''||$s==='-')return null;
        if(!is_numeric($s)||!is_finite((float)$s))throw new RuntimeException('Nonfinite or malformed price/volume');
        return (float)$s;
    }
    public static function status(array $r):string
    {
        [$o,$h,$l,$c,$v]=array_map(fn($k)=>self::num($r[$k]??null),['open','high','low','close','volume']);
        if($o===0.0&&$h===0.0&&$l===0.0&&$c!==null&&$c>0)return 'ohl_zero';
        $normal=$o!==null&&$h!==null&&$l!==null&&$c!==null&&min($o,$h,$l,$c)>0
            &&$h>=$l&&$o>=$l&&$o<=$h&&$c>=$l&&$c<=$h;
        return !$normal?'other_abnormal':($v!==null&&$v>0?'normal_ohlcv':'normal_ohlc_not_ohlcv');
    }
    /** All fields used by this adapter are independently reconstructed from hashed KRX originals. */
    public static function canonical(array $r):array
    {
        $out=[];
        foreach(['day','scope','symbol','code','lookup','status','market_observed','suffix_observed','file','file_sha256'] as $k){
            if(!array_key_exists($k,$r))throw new RuntimeException('Missing provenance '.$k);
            $out[$k]=$r[$k];
        }
        foreach(self::FIELDS as $k=>$_){
            if(!array_key_exists($k,$r))throw new RuntimeException('Missing OHLCV field '.$k);
            $out[$k]=self::num($r[$k]);
        }
        return $out;
    }
    public static function profile(string $root):array
    {
        PaperRecoveryCloseAccount::assertMirror($root);
        if(PaperStrategyVersion::current($root)!==PaperDatedTop100Replay::STRATEGY_FINGERPRINT)
            throw new RuntimeException('Operating strategy fingerprint changed');
        $fixed=self::json($root.'/'.self::DOC.'/frozen-inputs.json');
        foreach($fixed['repository_lf_sha256'] as $path=>$sha)
            if(PaperStrategyVersion::fileHash($root.'/'.$path)!==$sha)throw new RuntimeException('Frozen input changed: '.$path);
        $cand=PaperDatedTop100Replay::loadCandidates($root.'/'.self::CAND,243,1248);
        $prep=array_map(fn($d)=>substr($d,0,4).'-'.substr($d,4,2).'-'.substr($d,6,2),self::json($root.'/'.self::PREP.'/trading-days.json')['days']);
        $days=array_merge($prep,array_column($cand['by_session'],'day'));
        if(count($prep)!==240||count(array_unique($days))!==483||$days[0]!=='2023-10-13'||end($days)!=='2025-10-02')
            throw new RuntimeException('Unexpected calendar');
        $symbols=[];foreach(self::json($root.'/'.self::DATA.'/symbol-index.json')['rows'] as $r)$symbols[$r['symbol']]=$r['code'];
        $a=array_keys($symbols);$b=$cand['union'];sort($a);sort($b);
        if($a!==$b)throw new RuntimeException('Universe/candidates differ');
        $transfers=[];foreach(self::json($root.'/'.self::DATA.'/transfers.json')['rows'] as $r)$transfers[$r['code']]=$r;
        $hashes=array_merge(self::json($root.'/'.self::PREP.'/file-hashes.json')['raw'],self::json($root.'/'.self::EVAL.'/file-hashes.json')['raw']);
        $breaks=[];foreach(self::json($root.'/'.self::DATA.'/price-breaks.json')['linked_confirmed_transfer'] as $r)$breaks[$r['symbol']][]=$r;
        return ['days'=>$days,'prep_days'=>$prep,'eval_days'=>array_slice($days,240),'symbols'=>$symbols,
            'candidates'=>$cand['by_session'],'transfers'=>$transfers,'raw_hashes'=>$hashes,'breaks'=>$breaks];
    }
    public static function rawDay(string $dir,string $day,string $suffix,array $hashes):array
    {
        $file=str_replace('-','',$day).'_'.$suffix.'.json';$path=$dir.'/'.$file;
        if(!isset($hashes[$file])||!is_file($path)||hash_file('sha256',$path)!==$hashes[$file])
            throw new RuntimeException('Missing/changed KRX original: '.$file);
        $block=self::json($path)['OutBlock_1']??null;
        if(!is_array($block)||$block===[])throw new RuntimeException('Empty KRX block: '.$file);
        $out=[];foreach($block as $r){
            if(($r['BAS_DD']??null)!==str_replace('-','',$day))throw new RuntimeException('KRX date mismatch: '.$file);
            $code=(string)($r['ISU_CD']??'');
            if($code===''||isset($out[$code]))throw new RuntimeException('Missing/duplicate KRX code: '.$file);
            $out[$code]=$r;
        }
        return $out;
    }
    public static function expected(string $symbol,string $code,string $day,bool $prep,array $tables,array $p):array
    {
        $suffix=str_ends_with($symbol,'.KS')?'kospi_equity':'kosdaq_equity';
        $raw=$tables[$suffix][$code]??null;$lookup=$raw===null?'missing':'assigned';
        if($raw===null&&isset($p['transfers'][$code])){
            $t=$p['transfers'][$code];
            if(!empty($t['confirmed'])&&$day<$t['first_to_market_day']){
                $other=$tables[$t['from_suffix']][$code]??null;
                if($other!==null&&($other['MKT_NM']??null)===$t['from_market']){
                    $raw=$other;$suffix=$t['from_suffix'];$lookup='linked_prior_market';
                }
            }
        }
        $file=$raw===null?null:str_replace('-','',$day).'_'.$suffix.'.json';
        $r=['day'=>$day,'scope'=>$prep?'prep':'eval','symbol'=>$symbol,'code'=>$code,'lookup'=>$lookup,
            'status'=>'no_row','market_observed'=>$raw['MKT_NM']??null,'suffix_observed'=>$raw===null?null:$suffix,
            'file'=>$file,'file_sha256'=>$file===null?null:$p['raw_hashes'][$file]];
        foreach(self::FIELDS as $k=>$field)$r[$k]=self::num($raw[$field]??null);
        if($raw!==null)$r['status']=self::status($r);
        return self::canonical($r);
    }
    public static function rows(string $path):Generator
    {
        $f=fopen($path,'rb');if(!$f)throw new RuntimeException('Cannot read '.$path);
        try{while(($line=fgets($f))!==false){if(trim($line)==='')continue;yield self::canonical(json_decode($line,true,512,JSON_THROW_ON_ERROR));}}
        finally{fclose($f);}
    }
    /** Bounded memory: two daily market maps and one hash context per symbol, not 600k PHP rows. */
    public static function validate(array $p,string $bars,string $prepRaw,string $evalRaw):array
    {
        $ctx=[];foreach($p['symbols'] as $s=>$_)$ctx[$s]=hash_init('sha256');
        $prepSet=array_fill_keys($p['prep_days'],true);
        foreach($p['days'] as $day){
            $prep=isset($prepSet[$day]);$dir=$prep?$prepRaw:$evalRaw;$tables=[];
            foreach(['kospi_equity','kosdaq_equity'] as $suffix)$tables[$suffix]=self::rawDay($dir,$day,$suffix,$p['raw_hashes']);
            foreach($p['symbols'] as $s=>$code)hash_update($ctx[$s],self::encode(self::expected($s,$code,$day,$prep,$tables,$p))."\n");
        }
        $hashes=[];$counts=[];
        foreach($p['symbols'] as $s=>$code){
            if(!preg_match('/^[A-Z0-9]{6}\.(KS|KQ)$/',$s))throw new RuntimeException('Unsafe symbol');
            $path=$bars.'/'.$s.'.jsonl';if(!is_file($path))throw new RuntimeException('Missing jsonl '.$s);
            $before=hash_file('sha256',$path);$actual=hash_init('sha256');$i=0;
            foreach(self::rows($path) as $r){
                if($r['symbol']!==$s||$r['code']!==$code||$r['day']!==($p['days'][$i]??null))throw new RuntimeException('JSONL identity/order/calendar mismatch '.$s);
                hash_update($actual,self::encode($r)."\n");$i++;$counts[$r['status']]=($counts[$r['status']]??0)+1;
            }
            if($i!==count($p['days'])||hash_final($actual)!==hash_final($ctx[$s]))throw new RuntimeException('JSONL differs from KRX originals: '.$s);
            if(hash_file('sha256',$path)!==$before)throw new RuntimeException('JSONL changed while verifying '.$s);
            $hashes[$s]=$before;
        }
        ksort($hashes);ksort($counts);
        return ['symbols'=>count($hashes),'calendar_days'=>count($p['days']),'status_counts'=>$counts,'jsonl_sha256'=>$hashes,
            'raw_hashes'=>$p['raw_hashes'],'raw_value_comparison_pass'=>true];
    }
    public static function bar(array $r):?array
    {
        if($r['status']==='no_row')return null;
        $b=['time_kst'=>$r['day'].' 09:00:00','available_at'=>PaperDatedTop100Replay::sessionClose($r['day'])];
        foreach(['open','high','low','close','volume'] as $k)$b[$k]=$r[$k];
        return $b;
    }
    public static function compact(array $plan):array
    {
        $out=[];foreach(['ready','status','reason','pattern','entry','stop','target','reward_risk','signal_at',
            'order_valid_bars','context','candidate','diagnostics'] as $k)$out[$k]=$plan[$k]??null;
        // Keep independent pattern states/gates, but avoid copying full feature arrays per session.
        foreach(($out['diagnostics']['patterns']??[]) as $k=>$p){
            $v=[];foreach(['version','ready','status','reason','entry','stop','target','reward_risk','checks','gates'] as $f)$v[$f]=$p[$f]??null;
            $out['diagnostics']['patterns'][$k]=$v;
        }
        return $out;
    }
    public static function evaluate(array $rows,string $symbol,string $day,array $breaks):array
    {
        $at=PaperDatedTop100Replay::sessionClose($day);$past=[];$missing=[];$zeros=[];$recent=[];$bad=[];
        $firstPresent=null;$leading=[];$internal=[];
        foreach($rows as $r){
            if($r['day']>$day)break;
            $b=self::bar($r);
            if($b===null){$missing[]=$r['day'];if($firstPresent===null)$leading[]=$r['day'];else $internal[]=$r['day'];continue;}
            $firstPresent??=$r['day'];$past[]=$b;
            if($r['status']==='ohl_zero'){$zeros[]=$r['day'];if($at-$b['available_at']<=90*86400)$recent[]=$r['day'];}
            if($r['status']==='other_abnormal')$bad[]=$r['day'];
        }
        $source=['kind'=>'krx_tdd_research_jsonl','price_basis'=>self::BASIS,'sha256'=>self::digest($past)];
        $quality=PaperQuality::inspect($past,$symbol,$at,$source,[]);
        $valid=CandleClock::completed($past,$symbol,$at);
        $baseline=['ready'=>false,'status'=>'data_quality','reason'=>'완료 일봉 40개 미만'];$variant=$baseline;$checked=false;
        if(count($valid)>=40){
            $baseline=(new ChartPlanEngine())->analyze($valid,$symbol,$at,'account1',true)['plan'];
            $mirror=new \RecoveryCloseStudy\ChartPlanEngine();
            $control=$mirror->analyze($valid,$symbol,$at,'account1',true,false)['plan'];
            if(PaperRecoveryCloseAccount::differences($baseline,$control)!==[])throw new RuntimeException('Baseline mirror mismatch '.$symbol.'@'.$day);
            $variant=$mirror->analyze($valid,$symbol,$at,'account1',true,true)['plan'];$checked=true;
        }
        $events=[];foreach($breaks as $e)if($e['to']<=$day)$events[]=['from'=>$e['from'],'to'=>$e['to'],
            'ratio'=>$e['ratio'],'corporate_action'=>$e['corporate_action']??'미확정'];
        $b=self::compact($baseline);$v=self::compact($variant);
        return ['symbol'=>$symbol,'day'=>$day,'as_of'=>$at,'past_sha256'=>self::digest($past),'valid_sha256'=>self::digest($valid),
            'past_count'=>count($past),'valid_count'=>count($valid),'quality'=>$quality,'baseline_mirror_checked'=>$checked,
            'baseline'=>$b,'variant'=>$v,'plans_differ'=>PaperRecoveryCloseAccount::differences($b,$v)!==[],
            'baseline_quality_eligible'=>!empty($b['ready'])&&!empty($quality['can_simulate']),
            'variant_quality_eligible'=>!empty($v['ready'])&&!empty($quality['can_simulate']),
            'input_warnings'=>['price_basis_unverified'=>true,'breaks_in_history'=>$events,'ohl_zero_days'=>$zeros,
                'ohl_zero_recent90_days'=>$recent,'no_row_days'=>$missing,'leading_no_row_days'=>$leading,
                'internal_no_row_days'=>$internal,'other_abnormal_days'=>$bad],
            'warning_is_not_proven_decision_effect'=>true,'account_eligibility_not_asserted'=>true];
    }
    /** Resume a symbol by cumulative candidate dates. Results from a longer prefix are never discarded. */
    public static function extend(array $rows,string $symbol,array $wanted,array $breaks,array $results=[]):array
    {
        foreach($results as $day=>$r)if(!in_array($day,$wanted,true)||($r['day']??null)!==$day||($r['symbol']??null)!==$symbol)
            throw new RuntimeException('Unexpected result date/identity; do not shorten --sessions');
        $processed=0;
        foreach($wanted as $day)if(!isset($results[$day])){
            $results[$day]=self::evaluate($rows,$symbol,$day,$breaks);$processed++;
        }
        ksort($results);
        return ['results'=>$results,'processed'=>$processed];
    }
    public static function summary(array $results,array $days):array
    {
        $s=['sessions'=>count($days),'pairs'=>0,'baseline_ready'=>0,'variant_ready'=>0,'baseline_quality_eligible'=>0,
            'variant_quality_eligible'=>0,'baseline_mirror_checked'=>0,'plans_differ'=>0,'quality_reasons'=>[],
            'baseline_patterns'=>[],'variant_patterns'=>[],'transitions'=>[],'eligible_with_break_history'=>['baseline'=>0,'variant'=>0]];
        foreach($results as $r){
            $s['pairs']++;
            foreach(['baseline_quality_eligible','variant_quality_eligible','baseline_mirror_checked','plans_differ'] as $k)$s[$k]+=(int)$r[$k];
            foreach(['baseline','variant'] as $arm){
                if(!empty($r[$arm]['ready']))$s[$arm.'_ready']++;
                if($r[$arm.'_quality_eligible']){$key=$r[$arm]['pattern']??'none';$s[$arm.'_patterns'][$key]=($s[$arm.'_patterns'][$key]??0)+1;
                    if($r['input_warnings']['breaks_in_history']!==[])$s['eligible_with_break_history'][$arm]++;}
            }
            $key=(int)$r['baseline_quality_eligible'].' -> '.(int)$r['variant_quality_eligible'];$s['transitions'][$key]=($s['transitions'][$key]??0)+1;
            foreach($r['quality']['reasons'] as $reason)$s['quality_reasons'][$reason]=($s['quality_reasons'][$reason]??0)+1;
        }
        return $s;
    }
    public static function save(string $path,array $v):void
    {
        if(is_link($path)||is_link($path.'.tmp'))throw new RuntimeException('Symlink output refused');
        $bytes=self::encode($v)."\n";
        if(file_put_contents($path.'.tmp',$bytes)!==strlen($bytes)||!rename($path.'.tmp',$path))throw new RuntimeException('Cannot save '.$path);
    }
    public static function readResult(string $path,string $inputsHash,string $symbol):array
    {
        $r=self::json($path);
        if(($r['inputs_sha256']??'')!==$inputsHash||($r['symbol']??'')!==$symbol
            ||self::digest($r['results']??[])!==($r['results_sha256']??''))throw new RuntimeException('Changed research result '.$symbol);
        return $r['results'];
    }
}
