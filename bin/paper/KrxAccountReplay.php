<?php
// Static research adapter; exact declared changes are checked by PaperKrxAccountStudy::assertAdapter.
declare(strict_types=1);
use ChartEntryLab\{CandleClock,ChartPlanEngine,PaperJournal,PaperPortfolio,PaperQuality,PaperScanUniverse};
final class PaperKrxAccountReplay
{
    public const VERSION='krx_continuity_account_v1';
    public const LABEL='KRX TDD 원가격 · 동일 단절 보류 연구';
    public const STRATEGY_FINGERPRINT=PaperDatedTop100Replay::STRATEGY_FINGERPRINT;
    public const EVAL_START='2024-10-08';
    public const AS_OF='2025-10-02';
    public static function sessions(array $c):array{return PaperDatedTop100Replay::sessions($c);}
    public static function sectorMap(array $d,?array $m):array{return PaperDatedTop100Replay::sectorMap($d,$m);}
    public static function runtimeCodeHashes(array $e=[]):array{return PaperDatedTop100Replay::runtimeCodeHashes($e);}
    public static function supportsRuntime(string $v):bool{return PaperDatedTop100Replay::supportsRuntime($v);}
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
        if(!self::supportsRuntime($version))throw new RuntimeException('Operational strategy fingerprint changed');
        $pinned=PaperScanUniverse::pinned($config);$configHash=hash('sha256',PaperJournal::encode($pinned));
        $bars=[];foreach($data['symbols'] as $symbol=>$d)$bars[$symbol]=$d['bars_sha256'];
        $sessions=[];
        foreach(self::sessions($candidates) as $ts){
            $day=$candidates['by_session'][$ts]['day'];
            if($day<$startDay||$day>$throughDay)continue;
            $sessions[]=$ts;
        }
        $meta=['kind'=>self::VERSION,'label'=>self::LABEL,'dataset'=>$data['dataset'],'dataset_files'=>$data['files'],'bars_sha256'=>$bars,
            'start_day'=>$startDay,'as_of_day'=>$data['as_of_day'],'plan_source'=>'krx_guarded_asof_engine',
            'sector_source'=>($opts['sector_map']??null)===null?'none_all_unclassified':'explicit_map','sector_map_sha256'=>$sectorHash,
            'sector_map_file_sha256'=>$opts['sector_map_file_sha256']??null,
            'candidates_sha256'=>$candidates['file_sha256'],'entry_rule'=>'dated_top100_new_entries_only',
            'smoke'=>!empty($opts['smoke']),'missing_symbols'=>$data['missing'],'hold_symbols'=>$data['hold'],
            'cash_fee_bps'=>ResearchCashFee::feeBps($config),'slippage_bps'=>ResearchCashFee::SLIP_BPS,
            'research_fee_sha256'=>ResearchCashFee::hashes(dirname(__DIR__,2)),
            'code_sha256_lf'=>self::runtimeCodeHashes($opts['runtime_code_extra']??[])];
        $journal=new PaperJournal($journalPath);
        $existing=$journal->read();
        if($existing!==null)self::assertCompatible($existing['state'],$meta,$configHash,$version);
        if(!empty($existing['state']['halted']))return ['account'=>$config['id'],'processed_sessions'=>0,'halted'=>true,'last_session'=>$existing['state']['last_session'],'halt_at'=>$existing['state']['halt_at']??null];
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
            $source=['sha256'=>$info['bars_sha256'],'price_basis'=>PaperKrxSignalStudy::BASIS,'kind'=>'krx_tdd_research_jsonl','dataset'=>$data['dataset']];
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
        if(!PaperKrxAccountStudy::beforeAdvance($s,$date,$barSet,$snapshots,$data['breaks']??[],$emit))return false;
        ResearchCashFee::advance($s,$date,$barSet,$snapshots,$log);
        return true;
    }

}
