<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/AccountReplay.php';
require_once __DIR__.'/HistoryResearch.php';
require_once __DIR__.'/StrategyVersion.php';
require_once __DIR__.'/ResearchCashFee.php';
require_once __DIR__.'/SectorFreeze.php';
use ChartEntryLab\{CandleClock,ChartPlanEngine,PaperJournal,PaperPortfolio,PaperQuality,PaperScanUniverse};

/**
 * Research only: dated KRX amount TOP100 as new-entry candidates on the existing paper-kr-recovery-v1 rules.
 * Holdings and pending orders keep the existing replay rules after a drop-out.
 * It never writes operational accounts, caches, datasets other than history-research/<this dataset>, or strategy files.
 */
final class PaperDatedTop100Replay
{
    public const VERSION='dated_krx_amount_top100_replay_v1';
    public const RESEARCH_CONFIG='config/paper-history-kr-dated-top100-202510.json';
    public const LABEL='KRX 일별 거래대금 TOP100 연구';
    public const DATASET='kr-dated-amount-top100-202510';
    public const CANDIDATES='docs/historical-top100-replay-202510/candidates.json';
    public const UNIVERSE='docs/historical-top100-replay-202510/universe.json';
    public const PERIOD='oct2025';
    public const EVAL_START='2025-10-01';
    public const AS_OF='2025-10-31';
    public const CODE=['bin/paper/DatedTop100Replay.php','bin/paper_dated_top100_replay.php','tests/dated_top100_replay.php',
        'config/paper-history-kr-dated-top100-202510.json','config/paper-kr-recovery-v1.json','config/paper-strategy-files.json',
        'bin/paper/HistoryResearch.php','bin/paper/StrategyVersion.php','bin/paper/ResearchCashFee.php','bin/paper/AccountReplay.php'];
    public const RUNTIME_CODE=['bin/paper/DatedTop100Replay.php','bin/paper/HistoryResearch.php','bin/paper/StrategyVersion.php',
        'bin/paper/ResearchCashFee.php','config/paper-history-kr-dated-top100-202510.json','config/paper-kr-recovery-v1.json',
        'config/paper-strategy-files.json'];

    public static function configs(string $root):array
    {
        $rc=json_decode((string)file_get_contents($root.'/'.self::RESEARCH_CONFIG),true,512,JSON_THROW_ON_ERROR);
        $base=json_decode((string)file_get_contents($root.'/'.$rc['base_config']),true,512,JSON_THROW_ON_ERROR);
        return ['research'=>$rc,'base'=>$base];
    }

    public static function sessionClose(string $day):int
    {
        if(preg_match('/^\d{8}$/',$day))$day=substr($day,0,4).'-'.substr($day,4,2).'-'.substr($day,6,2);
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$day))throw new InvalidArgumentException('Invalid day '.$day);
        return (new DateTimeImmutable($day.' 15:30:00',new DateTimeZone('Asia/Seoul')))->getTimestamp();
    }

    public static function loadCandidates(string $path):array
    {
        $j=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(($j['kind']??'')!=='dated_krx_amount_top100_candidates_v1')throw new RuntimeException('Not a dated TOP100 candidate file');
        $byTs=[];$union=[];
        foreach($j['dates'] as $day=>$rec){
            if((int)($rec['n']??0)!==100||count($rec['rows']??[])!==100)throw new RuntimeException('Candidate day '.$day.' is not 100 names');
            $codes=[];$symbols=[];
            foreach($rec['rows'] as $r){
                $sym=(string)$r['symbol'];
                if(isset($symbols[$sym]))throw new RuntimeException('Duplicate candidate '.$sym.' on '.$day);
                $symbols[$sym]=true;$codes[]=$r['code'];$union[$sym]=true;
            }
            if(count($codes)!==count(array_unique($codes)))throw new RuntimeException('Duplicate codes on '.$day);
            $byTs[self::sessionClose($day)]=['day'=>$day,'symbols'=>array_keys($symbols),'rows'=>$rec['rows'],'source_sha256'=>$rec['source_sha256']??null];
        }
        ksort($byTs);
        if(count($byTs)!==18)throw new RuntimeException('Expected 18 dated sessions, got '.count($byTs));
        if(count($union)!==305)throw new RuntimeException('Expected 305 union symbols, got '.count($union));
        $ending=self::lineEndingHashes($path);
        return ['file'=>$path,'file_sha256'=>hash_file('sha256',$path),'file_sha256_lf'=>$ending['sha256_lf'],
            'file_ending'=>$ending['ending'],'days'=>$j['days'],'by_session'=>$byTs,'union'=>array_keys($union),'raw'=>$j];
    }

    /** Raw / LF / CRLF hashes of a file. Journal candidates_sha256 stays the raw hash_file value from the run. */
    public static function lineEndingHashes(string $path):array
    {
        $bytes=file_get_contents($path);
        if($bytes===false)throw new RuntimeException('Cannot read '.$path);
        $lf=str_replace("\r\n","\n",$bytes);
        $crlf=str_replace("\n","\r\n",$lf);
        $hasCrlf=str_contains($bytes,"\r\n");
        $hasBareLf=(bool)preg_match('/(?<!\r)\n/',$bytes);
        $hasBareCr=(bool)preg_match('/\r(?!\n)/',$bytes);
        $ending='lf';
        if($hasCrlf&&!$hasBareLf&&!$hasBareCr)$ending='crlf';
        elseif($hasCrlf||$hasBareCr)$ending='mixed';
        return ['path'=>$path,'sha256_raw'=>hash('sha256',$bytes),'sha256_lf'=>hash('sha256',$lf),
            'sha256_crlf'=>hash('sha256',$crlf),'ending'=>$ending];
    }

    /**
     * Connect Git LF (or a CRLF working copy) to a recorded raw hash without rewriting the journal.
     * Pass if recorded equals raw, CRLF(LF(bytes)), or LF(bytes).
     */
    public static function linkRecordedHash(string $path,string $recorded):array
    {
        $h=self::lineEndingHashes($path);
        $match=null;
        if($recorded!==''&&hash_equals($h['sha256_raw'],$recorded))$match='raw';
        elseif($recorded!==''&&hash_equals($h['sha256_crlf'],$recorded))$match='crlf_from_lf';
        elseif($recorded!==''&&hash_equals($h['sha256_lf'],$recorded))$match='lf_from_raw';
        $h['recorded']=$recorded;$h['match']=$match;$h['pass']=$match!==null;
        return $h;
    }

    /** Include collected bars even when dataset quality is hold. Failed downloads stay as empty rows, not a rank-101 fill. */
    public static function loadDataset(string $dir,array $candidates):array
    {
        $dir=rtrim(str_replace('\\','/',$dir),'/');
        $problems=PaperHistoryResearch::verify($dir);
        if($problems)throw new RuntimeException('Dataset verify failed: '.implode('; ',$problems));
        $ds=PaperHistoryResearch::readJson($dir.'/dataset.json')??throw new RuntimeException('dataset.json missing');
        $uni=PaperHistoryResearch::readJson($dir.'/universe.json')??throw new RuntimeException('universe.json missing');
        if(($ds['dataset']??'')!==self::DATASET)throw new RuntimeException('Dataset id is not '.self::DATASET);
        $symbols=[];$missing=[];$hold=[];
        foreach($uni['rows'] as $u){
            if(($u['status']??'')!=='included')continue;
            $symbol=(string)$u['symbol'];
            $st=PaperHistoryResearch::readStatus($dir,$symbol);
            if(!$st||($st['state']??'')!=='done'){
                $missing[]=['symbol'=>$symbol,'name'=>$u['name']??null,'error'=>$st['error']??'not_collected'];
                $symbols[$symbol]=['name'=>$u['name']??$symbol,'rows'=>[],'bars_sha256'=>'missing','quality'=>['status'=>'hold','hold_reasons'=>['not_collected']],'failed'=>true];
                continue;
            }
            $path=$dir.'/'.$st['bars_path'];
            if(!hash_equals((string)$st['bars_sha256'],(string)hash_file('sha256',$path)))throw new RuntimeException('Changed bars: '.$symbol);
            $b=PaperHistoryResearch::readJson($path)??throw new RuntimeException('Unreadable bars: '.$symbol);
            $rows=$b['rows']??[];
            foreach($rows as &$r)$r['available_at']=CandleClock::closeTime($r,$symbol);
            unset($r);
            usort($rows,fn($x,$y)=>$x['available_at']<=>$y['available_at']);
            $q=$st['quality']??[];
            if(($q['status']??'')==='hold')$hold[]=['symbol'=>$symbol,'reasons'=>$q['hold_reasons']??[]];
            $symbols[$symbol]=['name'=>$u['name']??$symbol,'rows'=>$rows,'bars_sha256'=>$st['bars_sha256'],'quality'=>$q,'failed'=>false];
        }
        ksort($symbols);
        foreach($candidates['union'] as $sym){
            if(!isset($symbols[$sym])){
                $missing[]=['symbol'=>$sym,'error'=>'not_in_dataset'];
                $symbols[$sym]=['name'=>$sym,'rows'=>[],'bars_sha256'=>'missing','quality'=>['status'=>'hold','hold_reasons'=>['not_in_dataset']],'failed'=>true];
            }
        }
        ksort($symbols);
        return ['dataset'=>$ds['dataset'],'dir'=>$dir,'start_day'=>self::EVAL_START,'as_of_day'=>self::AS_OF,
            'as_of_ts'=>self::sessionClose(self::AS_OF),'symbols'=>$symbols,'missing'=>$missing,'hold'=>$hold,
            'files'=>['dataset.json'=>(string)hash_file('sha256',$dir.'/dataset.json'),'universe.json'=>(string)hash_file('sha256',$dir.'/universe.json')]];
    }

    public static function sessions(array $candidates):array
    {
        return array_keys($candidates['by_session']);
    }

    public static function sectorMap(array $data,?array $map):array
    {
        $out=[];
        foreach($data['symbols'] as $symbol=>$_){
            $v=$map['sectors'][$symbol]??'unclassified';
            $out[$symbol]=$v===''?'unclassified':(string)$v;
        }
        return $out;
    }

    public static function runtimeCodeHashes():array
    {
        $root=dirname(__DIR__,2);$out=[];
        foreach(self::RUNTIME_CODE as $p)$out[$p]=PaperStrategyVersion::fileHash($root.'/'.$p);
        return $out;
    }

    public static function run(array $data,array $config,string $journalPath,array $candidates,array $opts=[]):array
    {
        ResearchCashFee::feeBps($config);
        $chunk=max(1,(int)($opts['chunk']??8));$provider=$opts['plan_provider']??null;
        $startDay=$opts['start']??self::EVAL_START;$throughDay=$opts['through']??self::AS_OF;
        if(($opts['sector_map']??null)!==null){
            $union=$candidates['union']??[];
            if($union===[])throw new InvalidArgumentException('An explicit sector map needs the candidate union');
            PaperSectorFreeze::assertCoversUnion($union,$opts['sector_map']);
        }
        $sectors=self::sectorMap($data,$opts['sector_map']??null);
        $sectorHash=hash('sha256',PaperJournal::encode($sectors));
        $version=PaperStrategyVersion::current();
        $pinned=PaperScanUniverse::pinned($config);$configHash=hash('sha256',PaperJournal::encode($pinned));
        $bars=[];foreach($data['symbols'] as $symbol=>$d)$bars[$symbol]=$d['bars_sha256'];
        $sessions=[];
        foreach(self::sessions($candidates) as $ts){
            $day=$candidates['by_session'][$ts]['day'];
            if($day<$startDay||$day>$throughDay)continue;
            $sessions[]=$ts;
        }
        $meta=['kind'=>self::VERSION,'label'=>self::LABEL,'dataset'=>$data['dataset'],'dataset_files'=>$data['files'],'bars_sha256'=>$bars,
            'start_day'=>$startDay,'as_of_day'=>$data['as_of_day'],'plan_source'=>$provider===null?'engine':'injected_test',
            'sector_source'=>($opts['sector_map']??null)===null?'none_all_unclassified':'explicit_map','sector_map_sha256'=>$sectorHash,
            'sector_map_file_sha256'=>$opts['sector_map_file_sha256']??null,
            'candidates_sha256'=>$candidates['file_sha256'],'entry_rule'=>'dated_top100_new_entries_only',
            'smoke'=>!empty($opts['smoke']),'missing_symbols'=>$data['missing'],'hold_symbols'=>$data['hold'],
            'cash_fee_bps'=>ResearchCashFee::feeBps($config),'slippage_bps'=>ResearchCashFee::SLIP_BPS,
            'research_fee_sha256'=>ResearchCashFee::hashes(dirname(__DIR__,2)),
            'code_sha256_lf'=>self::runtimeCodeHashes()];
        $journal=new PaperJournal($journalPath);
        $existing=$journal->read();
        if($existing!==null)self::assertCompatible($existing['state'],$meta,$configHash,$version);
        $last=($journal->read()['state']['last_session']??0);
        $pending=array_values(array_filter($sessions,fn($t)=>$t>$last));
        if(isset($opts['stop_after_sessions']))$pending=array_slice($pending,0,(int)$opts['stop_after_sessions']);
        $processed=0;$halted=false;
        foreach(array_chunk($pending,$chunk) as $dates){
            $journal->transact(function(&$s,$emit)use($dates,$data,$pinned,$configHash,$version,$meta,$sectors,$provider,$candidates,&$processed,&$halted){
                if($s===null){
                    $s=PaperPortfolio::start($pinned,$version,'replay');$s['config_hash']=$configHash;$s['strategy_fingerprint']=$version;
                    $s['research']=$meta;
                    $emit('account_started',['config'=>$pinned,'mode'=>'replay','version'=>$version,'research'=>$meta]);
                }
                self::assertCompatible($s,$meta,$configHash,$version);
                foreach($sectors as $symbol=>$sector)$s['sectors'][$symbol]=$sector;
                foreach($dates as $date){
                    if(!empty($s['halted'])){$halted=true;break;}
                    if(self::day($s,$date,$data,$sectors,$provider,$candidates,$emit))$processed++;
                }
                if(!empty($s['halted']))$halted=true;
            });
            if($halted)break;
        }
        $state=($journal->read()['state']??null);
        return ['account'=>$config['id'],'processed_sessions'=>$processed,'pending_before'=>count($pending),'halted'=>$halted||!empty($state['halted']),
            'last_session'=>$state['last_session']??0,'last_day'=>isset($state['last_session'])&&$state['last_session']>0?PaperHistoryResearch::day($state['last_session']):null];
    }

    private static function assertCompatible(array $s,array $meta,string $configHash,string $version):void
    {
        if(($s['config_hash']??null)!==$configHash||($s['mode']??null)!=='replay')throw new RuntimeException('Account config changed; use a new research account ID');
        PaperStrategyVersion::verify($s,$version);
        $r=$s['research']??[];
        foreach(['dataset','dataset_files','bars_sha256','plan_source','sector_map_sha256','sector_map_file_sha256','start_day','as_of_day','cash_fee_bps','slippage_bps','research_fee_sha256','code_sha256_lf','candidates_sha256','entry_rule'] as $k){
            if(($r[$k]??null)!==$meta[$k])throw new RuntimeException('Research input changed ('.$k.'); use a new research account ID');
        }
    }

    private static function day(array &$s,int $date,array $data,array $sectors,?callable $provider,array $candidates,callable $emit):bool
    {
        $entry=array_fill_keys($candidates['by_session'][$date]['symbols']??[],true);
        if($entry===[])throw new RuntimeException('No dated TOP100 for session '.PaperHistoryResearch::day($date));
        $watch=$entry;
        foreach(array_keys($s['active']??[]) as $sym)$watch[$sym]=true;
        $barSet=[];$snapshots=[];
        ksort($watch);
        foreach($watch as $symbol=>$_){
            $info=$data['symbols'][$symbol]??['name'=>$symbol,'rows'=>[],'bars_sha256'=>'missing'];
            $rows=$info['rows']??[];
            $past=[];foreach($rows as $b){if($b['available_at']>$date)break;$past[]=$b;}
            $valid=CandleClock::completed($past,$symbol,$date);
            $source=['sha256'=>$info['bars_sha256'],'price_basis'=>PaperHistoryResearch::PRICE_BASIS,'kind'=>'research_dataset','dataset'=>$data['dataset']];
            $quality=PaperQuality::inspect($past,$symbol,$date,$source,[]);
            $inEntry=isset($entry[$symbol]);
            $plan=['ready'=>false,'status'=>'not_in_dated_top100','reason'=>'당일 TOP100 밖. 보유·대기 주문만 기존 규칙으로 관리'];
            if($inEntry){
                $plan=['ready'=>false,'status'=>'data_quality','reason'=>'데이터 품질 확인 필요'];
                if($provider!==null)$plan=$provider($symbol,$valid,$date)??['ready'=>false,'status'=>'no_injected_plan','reason'=>'test plan not injected'];
                elseif(count($valid)>=40)$plan=(new ChartPlanEngine())->analyze($valid,$symbol,$date)['plan'];
            }
            if($valid!==[]&&end($valid)['available_at']===$date)$barSet[$symbol]=end($valid);
            $snapshots[$symbol]=['symbol'=>$symbol,'session'=>$date,'recorded_at'=>$date,'origin'=>'replay','version'=>$s['version'],
                'input_hash'=>hash('sha256',PaperJournal::encode($past)),'plan'=>$plan,'quality'=>$quality,
                'in_dated_top100'=>$inEntry];
        }
        $log=new class($emit,$s,$sectors,$date,$entry){
            public array $day;
            public function __construct(private $emit,private array &$s,private array $sectors,private int $date,private array $entry)
            {$this->reset();}
            private function reset():void{$this->day=['evaluated'=>0,'top100'=>0,'analyzable'=>0,'plan_status'=>[],'quality_blocked'=>[],'signals'=>[]];}
            public function __invoke(string $type,array $p):void
            {
                $emit=$this->emit;
                switch($type){
                    case 'snapshot':
                        $this->day['evaluated']++;
                        if(!empty($p['in_dated_top100']))$this->day['top100']++;
                        $pl=$p['plan'];$q=$p['quality'];
                        if(empty($q['can_simulate'])){
                            foreach($q['reasons'] as $r)$this->day['quality_blocked'][$r][]=$p['symbol'];
                        }else{
                            if(!empty($p['in_dated_top100']))$this->day['analyzable']++;
                            $this->day['plan_status'][(string)($pl['status']??'unknown')]=($this->day['plan_status'][(string)($pl['status']??'unknown')]??0)+1;
                        }
                        if(!empty($pl['ready'])&&!empty($p['in_dated_top100'])){
                            $this->day['signals'][$p['symbol']]=['symbol'=>$p['symbol'],'pattern'=>$pl['pattern']??null,'entry'=>$pl['entry']??null,
                                'stop'=>$pl['stop']??null,'target'=>$pl['target']??null,'reward_risk'=>$pl['reward_risk']??null,
                                'signal_at'=>$pl['signal_at']??null,'order_valid_bars'=>$pl['order_valid_bars']??null,
                                'sector'=>$this->sectors[$p['symbol']]??'unclassified','outcome'=>null];
                        }
                        return;
                    case 'decision':
                        if(isset($this->day['signals'][$p['symbol']]))$this->day['signals'][$p['symbol']]['outcome']=$p['reason'];
                        return;
                    case 'order':
                        if(isset($this->day['signals'][$p['symbol']]))$this->day['signals'][$p['symbol']]['outcome']='order';
                        $sig=$this->day['signals'][$p['symbol']]??[];
                        $emit('order',$p+['pattern'=>$sig['pattern']??null,'signal_at'=>$sig['signal_at']??null,
                            'order_valid_bars'=>$sig['order_valid_bars']??null,'reward_risk'=>$sig['reward_risk']??null,'sector'=>$sig['sector']??null]);
                        return;
                    case 'equity':
                        $held=[];
                        foreach($this->s['active'] as $symbol=>$o){
                            if($o['filled'])$held[$symbol]=['quantity'=>$o['quantity'],'entry_fill'=>$o['entry_fill'],
                                'mark_close'=>$this->s['marks'][$symbol]['close']??null,'mark_session'=>$this->s['marks'][$symbol]['session']??null,
                                'in_dated_top100'=>isset($this->entry[$symbol])];
                        }
                        $pending=[];foreach($this->s['active'] as $symbol=>$o)if(!$o['filled'])$pending[]=$symbol;
                        ksort($held);sort($pending);
                        $qb=$this->day['quality_blocked'];ksort($qb);ksort($this->day['plan_status']);
                        $emit('day_log',['session'=>$this->date,'date'=>PaperHistoryResearch::day($this->date),
                            'top100'=>$this->day['top100'],'analyzable'=>$this->day['analyzable'],'evaluated'=>$this->day['evaluated'],
                            'plan_status'=>$this->day['plan_status'],'quality_blocked'=>$qb,'signals'=>array_values($this->day['signals']),
                            'held'=>$held,'pending_orders'=>$pending]);
                        $emit('equity',$p);$this->reset();return;
                    default:
                        $emit($type,$p);
                }
            }
        };
        ResearchCashFee::advance($s,$date,$barSet,$snapshots,$log);
        return true;
    }

    public static function initDataset(string $stateRoot,string $universePath):array
    {
        $dir=PaperHistoryResearch::datasetDir($stateRoot,self::DATASET);
        PaperHistoryResearch::assertResearchDir($dir);
        $uni=json_decode((string)file_get_contents($universePath),true,512,JSON_THROW_ON_ERROR);
        if(($uni['policy']??'')!=='dated_krx_amount_top100_202510_v1')throw new RuntimeException('Universe policy mismatch');
        if(is_file($dir.'/dataset.json')){
            $ds=PaperHistoryResearch::readJson($dir.'/dataset.json');
            if(!is_array($ds)||is_array($ds['as_of']??null)||is_dir($dir.'/status')||is_dir($dir.'/raw'))
                throw new RuntimeException('Dataset already has a fixed universe; use a new --dataset id');
        }elseif(is_dir($dir)&&(glob($dir.'/*')?:[])!==[])throw new RuntimeException('Refusing to write into a non-empty folder without dataset.json');
        $bytes=PaperHistoryResearch::encode($uni,true);
        PaperHistoryResearch::writeFile($dir.'/universe.json',$bytes);
        $asTs=self::sessionClose(self::AS_OF);
        $ds=['schema'=>1,'policy'=>'dated_krx_amount_top100_202510_v1','dataset'=>self::DATASET,'created_at'=>time(),
            'universe_sha256'=>hash('sha256',$bytes),'requested_as_of'=>self::AS_OF,'evaluation_start_day'=>self::EVAL_START,
            'primary_range'=>PaperHistoryResearch::EXTENDED_RANGE,
            'as_of'=>['day'=>self::AS_OF,'close_ts'=>$asTs,'fixed_at'=>time()],
            'as_of_basis'=>'dated TOP100 last session 2025-10-31 15:30 KST'];
        PaperHistoryResearch::writeFile($dir.'/dataset.json',PaperHistoryResearch::encode($ds,true));
        return $ds+['dir'=>$dir];
    }

    /** Copy intact bars from another research dataset. Never writes into the source. */
    public static function importSymbol(string $dest,string $srcDir,array $u,array $as,array $neededDays):string
    {
        $symbol=(string)$u['symbol'];
        $st=PaperHistoryResearch::readStatus($dest,$symbol);
        if($st&&($st['state']??'')==='done'){
            $path=$dest.'/'.($st['bars_path']??'');
            if(is_file($path)&&hash_equals((string)$st['bars_sha256'],(string)hash_file('sha256',$path)))return 'reused';
        }
        $src=PaperHistoryResearch::readStatus($srcDir,$symbol);
        if(!$src||($src['state']??'')!=='done')return 'absent';
        $srcBars=$srcDir.'/'.($src['bars_path']??'');
        if(!is_file($srcBars)||!hash_equals((string)$src['bars_sha256'],(string)hash_file('sha256',$srcBars)))return 'absent';
        $bars=PaperHistoryResearch::readJson($srcBars)??null;
        if(!is_array($bars)||($bars['symbol']??null)!==$symbol)return 'absent';
        $have=[];
        foreach($bars['rows'] as $r){
            $t=CandleClock::closeTime($r,$symbol);
            if(!empty($r['synthetic'])||($r['is_complete']??true)===false)continue;
            $have[PaperHistoryResearch::day($t)]=true;
        }
        foreach($neededDays as $day)if(!isset($have[$day]))return 'incomplete';
        foreach($src['requests']??[] as $rq){
            $raw=$srcDir.'/'.($rq['raw_path']??'');
            if(!is_file($raw))return 'absent';
            $destRaw=$dest.'/'.$rq['raw_path'];
            if(!is_file($destRaw)){
                $bytes=(string)file_get_contents($raw);
                PaperHistoryResearch::writeFile($destRaw,$bytes);
            }
            $idxSrc=$srcDir.'/raw/yahoo/index/'.$symbol.'.'.$rq['range'].'.json';
            $idxDst=$dest.'/raw/yahoo/index/'.$symbol.'.'.$rq['range'].'.json';
            if(is_file($idxSrc)&&!is_file($idxDst))PaperHistoryResearch::writeFile($idxDst,(string)file_get_contents($idxSrc));
        }
        $rawLast=$src['requests'][array_key_last($src['requests'])]??null;
        $rawBody=$rawLast?@file_get_contents($dest.'/'.$rawLast['raw_path']):false;
        $raw=is_string($rawBody)?PaperHistoryResearch::inspectRaw($rawBody,$symbol):['hold_reasons'=>[]];
        $info=PaperHistoryResearch::inspectRows($bars['rows'],$symbol,$as['close_ts'],$as['evaluation_start_day'],$raw);
        $payload=['schema'=>1,'policy'=>'dated_krx_amount_top100_202510_v1','symbol'=>$symbol,'name'=>$u['name'],
            'provider'=>PaperHistoryResearch::PROVIDER,'price_basis'=>PaperHistoryResearch::PRICE_BASIS,
            'request'=>$rawLast,'as_of_session'=>$as['day'],'as_of_close_ts'=>$as['close_ts'],
            'pipeline'=>$bars['pipeline']??'imported research bars','raw_meta'=>$bars['raw_meta']??[],
            'quality'=>$info,'rows'=>$bars['rows'],'imported_from'=>basename($srcDir)];
        $bytes=PaperHistoryResearch::encode($payload);$barsPath='bars/'.$symbol.'.json';
        PaperHistoryResearch::writeFile($dest.'/'.$barsPath,$bytes);
        $out=['schema'=>1,'symbol'=>$symbol,'name'=>$u['name'],'attempts'=>0,'state'=>'done','error'=>null,
            'extension'=>'imported','final_range'=>$rawLast['range']??null,'requests'=>$src['requests']??[],
            'bars_path'=>$barsPath,'bars_sha256'=>hash('sha256',$bytes),'bars_bytes'=>strlen($bytes),
            'collected_at'=>time(),'quality'=>$info,'raw_checks'=>$raw,'imported_from'=>basename($srcDir)];
        PaperHistoryResearch::writeFile($dest.'/status/'.$symbol.'.json',PaperHistoryResearch::encode($out,true));
        return 'imported';
    }
}
