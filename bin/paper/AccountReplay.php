<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/HistoryResearch.php';
require_once __DIR__.'/StrategyVersion.php';
use ChartEntryLab\{CandleClock,ChartPlanEngine,PaperJournal,PaperPortfolio,PaperQuality,PaperScanUniverse,TradeSimulator};

/**
 * Day-by-day account replay on a fixed, already collected universe. Research only.
 *
 * It reuses the operational account code unchanged: PaperPortfolio::advance (orders, fills, exits, cash, limits),
 * PaperQuality::inspect, ChartPlanEngine::analyze, TradeSimulator (through the portfolio) and PaperJournal.
 * What differs from bin/paper_account.php is only where bars come from (a verified research dataset instead of a fetched
 * folder), an explicit reproduction date instead of the wall clock, and compact journal events. See the protocol document.
 * It never writes outside <state>/history-account/<account id>/ .
 */
final class PaperAccountReplay
{
    public const VERSION='historical_account_replay_v1';
    public const RESEARCH_CONFIG='config/paper-history-kr-recovery-v1.json';
    public const LABEL='저장 스캔에서 확보한 고정 종목군 계좌 재현 (당시 매일의 TOP100 복원 아님)';
    public const FEE_RATE=0.001;
    /** Files whose bytes define this study besides the strategy fingerprint. */
    public const CODE=['bin/paper/AccountReplay.php','bin/paper_account_replay.php','tests/account_replay.php',
        'config/paper-history-kr-recovery-v1.json','config/paper-kr-recovery-v1.json','config/paper-strategy-files.json',
        'bin/paper/HistoryResearch.php','bin/paper/StrategyVersion.php'];

    // ---- configuration and paths ---------------------------------------------------------------------------

    public static function stateRoot():string
    {
        return rtrim(str_replace('\\','/',getenv('PAPER_STATE_DIR')?:dirname(__DIR__,3).'/stock-personal-paper'),'/');
    }

    /** @return array{research:array,base:array} */
    public static function configs(string $root):array
    {
        $rc=json_decode((string)file_get_contents($root.'/'.self::RESEARCH_CONFIG),true,512,JSON_THROW_ON_ERROR);
        $base=json_decode((string)file_get_contents($root.'/'.$rc['base_config']),true,512,JSON_THROW_ON_ERROR);
        return ['research'=>$rc,'base'=>$base];
    }

    /** The operational configuration with only the account ID replaced. Any other difference is refused. */
    public static function accountConfig(array $base,string $id,array $research):array
    {
        if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account ID');
        $okPrefix=str_starts_with($id,$research['id_prefix'])||str_starts_with($id,$research['smoke_id_prefix']);
        if(!$okPrefix||$id===($base['id']??''))throw new InvalidArgumentException('Research accounts need their own ID prefix');
        $config=$base;$config['id']=$id;
        $diff=array_keys(array_filter($config,fn($v,$k)=>$k!=='id'&&$v!==$base[$k],ARRAY_FILTER_USE_BOTH));
        if($diff!==[]||array_diff_key($base,$config)!==[])throw new RuntimeException('Research config differs from the operational config');
        return $config;
    }

    public static function accountId(array $research,string $period,?string $smokeLabel):string
    {
        $p=$research['periods'][$period]??throw new InvalidArgumentException('Unknown period '.$period);
        if($smokeLabel===null)return $p['account_id'];
        if(!preg_match('/^[a-z0-9]{1,24}$/',$smokeLabel))throw new InvalidArgumentException('Smoke label must be [a-z0-9]{1,24}');
        $stamp=substr($p['account_id'],strlen($research['id_prefix']));
        return $research['smoke_id_prefix'].$stamp.'-'.$smokeLabel;
    }

    /** Account folder. Never an operational path and never inside a dataset. */
    public static function accountDir(string $stateRoot,array $research,string $id):string
    {
        if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id)||!(str_starts_with($id,$research['id_prefix'])||str_starts_with($id,$research['smoke_id_prefix'])))
            throw new InvalidArgumentException('Not a research account ID');
        if($research['state_subdir']!=='history-account')throw new RuntimeException('Unexpected research state folder');
        $dir=rtrim(str_replace('\\','/',$stateRoot),'/').'/'.$research['state_subdir'].'/'.$id;
        if(str_contains($dir,'..')||str_contains($dir,'/history-research/')||preg_match('#/data/(ohlcv|raw|cache|paper)#',$dir))
            throw new RuntimeException('Refusing a non-research path');
        return $dir;
    }

    public static function journalPath(string $accountDir):string{return $accountDir.'/account-replay.json';}

    // ---- dataset -------------------------------------------------------------------------------------------

    /** Verified, read-only load. The manifest rules decide which symbols are usable; nothing is fetched or filled. */
    public static function loadDataset(string $dir):array
    {
        $dir=rtrim(str_replace('\\','/',$dir),'/');
        $quiet=static function(int $no,string $str):bool{return str_contains($str,'preparation_window_bars')||str_contains($str,'bars_before_preparation_window');};
        set_error_handler($quiet);
        try{
            $problems=PaperHistoryResearch::verify($dir);
            if($problems)throw new RuntimeException('Dataset verify failed: '.implode('; ',$problems));
            $manifest=PaperHistoryResearch::manifest($dir);
        }finally{restore_error_handler();}
        $ds=PaperHistoryResearch::readJson($dir.'/dataset.json')??throw new RuntimeException('dataset.json missing');
        $symbols=[];$excluded=[];
        foreach($manifest['symbols'] as $s){
            if(($s['state']??'')!=='done'||($s['quality']??'hold')==='hold'){
                $excluded[]=['symbol'=>$s['symbol'],'state'=>$s['state']??null,'reasons'=>$s['hold_reasons']??[($s['error']??'not_collected')]];
                continue;
            }
            $path=$dir.'/'.$s['bars_path'];
            if(!hash_equals($s['bars_sha256'],(string)hash_file('sha256',$path)))throw new RuntimeException('Changed bars: '.$s['symbol']);
            $b=PaperHistoryResearch::readJson($path)??throw new RuntimeException('Unreadable bars: '.$s['symbol']);
            if(($b['symbol']??null)!==$s['symbol'])throw new RuntimeException('Bars identity mismatch');
            $rows=$b['rows'];
            foreach($rows as &$r)$r['available_at']=CandleClock::closeTime($r,$s['symbol']);
            unset($r);
            usort($rows,fn($x,$y)=>$x['available_at']<=>$y['available_at']);
            $symbols[$s['symbol']]=['name'=>$s['name'],'rows'=>$rows,'bars_sha256'=>$s['bars_sha256']];
        }
        ksort($symbols);
        return ['dataset'=>$ds['dataset']??basename($dir),'dir'=>$dir,'start_day'=>PaperHistoryResearch::evalStartOf($ds),
            'as_of_day'=>$ds['as_of']['day'],'as_of_ts'=>(int)$ds['as_of']['close_ts'],
            'symbols'=>$symbols,'excluded'=>$excluded,
            'files'=>['dataset.json'=>(string)hash_file('sha256',$dir.'/dataset.json'),'universe.json'=>(string)hash_file('sha256',$dir.'/universe.json')]];
    }

    /** Sessions on which any usable symbol has a row (valid or not), inside the evaluation window. */
    public static function sessions(array $data,?string $startDay=null,?string $throughDay=null):array
    {
        $start=$startDay??$data['start_day'];$end=$data['as_of_ts'];
        if($throughDay!==null){
            $limit=(new DateTimeImmutable($throughDay.' 23:59:59',new DateTimeZone('Asia/Seoul')))->getTimestamp();
            $end=min($end,$limit);
        }
        $out=[];
        foreach($data['symbols'] as $symbol=>$d){
            foreach($d['rows'] as $r){
                $t=$r['available_at'];
                if($t>$end||PaperHistoryResearch::day($t)<$start)continue;
                $out[$t]=true;
            }
        }
        $t=array_keys($out);sort($t);
        return $t;
    }

    /** Explicit sector classification. Without a map every symbol is "unclassified", which is recorded and not hidden. */
    public static function sectorMap(array $data,?array $map):array
    {
        $out=[];
        foreach($data['symbols'] as $symbol=>$_){
            $v=$map['sectors'][$symbol]??'unclassified';
            $out[$symbol]=$v===''?'unclassified':(string)$v;
        }
        return $out;
    }

    // ---- replay --------------------------------------------------------------------------------------------

    /**
     * @param array $opts through(Y-m-d), start(Y-m-d, smoke only), chunk(sessions per transaction), sector_map(array|null),
     *                    plan_provider(callable|null, tests only), stop_after_sessions(int|null)
     */
    public static function run(array $data,array $config,string $journalPath,array $opts=[]):array
    {
        $chunk=max(1,(int)($opts['chunk']??20));$provider=$opts['plan_provider']??null;
        $startDay=$opts['start']??null;
        if($startDay!==null&&$startDay<$data['start_day'])throw new InvalidArgumentException('Start precedes the evaluation start');
        $sectors=self::sectorMap($data,$opts['sector_map']??null);
        $sectorHash=hash('sha256',PaperJournal::encode($sectors));
        $version=PaperStrategyVersion::current();
        $pinned=PaperScanUniverse::pinned($config);$configHash=hash('sha256',PaperJournal::encode($pinned));
        $bars=[];foreach($data['symbols'] as $symbol=>$d)$bars[$symbol]=$d['bars_sha256'];
        $meta=['kind'=>self::VERSION,'label'=>self::LABEL,'dataset'=>$data['dataset'],'dataset_files'=>$data['files'],'bars_sha256'=>$bars,
            'start_day'=>$startDay??$data['start_day'],'as_of_day'=>$data['as_of_day'],'plan_source'=>$provider===null?'engine':'injected_test',
            'sector_source'=>($opts['sector_map']??null)===null?'none_all_unclassified':'explicit_map','sector_map_sha256'=>$sectorHash,
            'sector_map_file_sha256'=>$opts['sector_map_file_sha256']??null,
            'smoke'=>$startDay!==null||!empty($opts['smoke']),'excluded_symbols'=>$data['excluded'],
            'code_sha256_lf'=>self::runtimeCodeHashes()];
        $sessions=self::sessions($data,$startDay,$opts['through']??null);
        $journal=new PaperJournal($journalPath);
        $existing=$journal->read();
        if($existing!==null)self::assertCompatible($existing['state'],$meta,$configHash,$version);
        $last=($journal->read()['state']['last_session']??0);
        $pending=array_values(array_filter($sessions,fn($t)=>$t>$last));
        if(isset($opts['stop_after_sessions']))$pending=array_slice($pending,0,(int)$opts['stop_after_sessions']);
        $processed=0;$halted=false;
        foreach(array_chunk($pending,$chunk) as $dates){
            $result=$journal->transact(function(&$s,$emit)use($dates,$data,$config,$pinned,$configHash,$version,$meta,$sectors,$provider,&$processed,&$halted){
                if($s===null){
                    $s=PaperPortfolio::start($pinned,$version,'replay');$s['config_hash']=$configHash;$s['strategy_fingerprint']=$version;
                    $s['research']=$meta;
                    $emit('account_started',['config'=>$pinned,'mode'=>'replay','version'=>$version,'research'=>$meta]);
                }
                self::assertCompatible($s,$meta,$configHash,$version);
                foreach($sectors as $symbol=>$sector)$s['sectors'][$symbol]=$sector;
                foreach($dates as $date){
                    if(!empty($s['halted'])){$halted=true;break;}
                    if(self::day($s,$date,$data,$sectors,$provider,$emit))$processed++;
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
        foreach(['dataset','dataset_files','bars_sha256','plan_source','sector_map_sha256','sector_map_file_sha256','start_day','as_of_day','code_sha256_lf'] as $k){
            if(($r[$k]??null)!==$meta[$k])throw new RuntimeException('Research input changed ('.$k.'); use a new research account ID');
        }
    }

    /** One session. Mirrors the per-day loop of bin/paper_account.php (replay mode). */
    private static function day(array &$s,int $date,array $data,array $sectors,?callable $provider,callable $emit):bool
    {
        $barSet=[];$snapshots=[];$enough=false;
        foreach($sectors as $symbol=>$sector){
            $rows=$data['symbols'][$symbol]['rows'];
            $past=[];foreach($rows as $b){if($b['available_at']>$date)break;$past[]=$b;}
            $valid=CandleClock::completed($past,$symbol,$date);
            if(count($valid)>=60)$enough=true;
            $source=['sha256'=>$data['symbols'][$symbol]['bars_sha256'],'price_basis'=>PaperHistoryResearch::PRICE_BASIS,'kind'=>'research_dataset','dataset'=>$data['dataset']];
            $quality=PaperQuality::inspect($past,$symbol,$date,$source,[]);
            $plan=['ready'=>false,'status'=>'data_quality','reason'=>'데이터 품질 확인 필요'];
            if($provider!==null)$plan=$provider($symbol,$valid,$date)??['ready'=>false,'status'=>'no_injected_plan','reason'=>'test plan not injected'];
            elseif(count($valid)>=40)$plan=(new ChartPlanEngine())->analyze($valid,$symbol,$date)['plan'];
            if($valid!==[]&&end($valid)['available_at']===$date)$barSet[$symbol]=end($valid);
            // recorded_at is the session close, never the wall clock.
            $snapshots[$symbol]=['symbol'=>$symbol,'session'=>$date,'recorded_at'=>$date,'origin'=>'replay','version'=>$s['version'],
                'input_hash'=>hash('sha256',PaperJournal::encode($past)),'plan'=>$plan,'quality'=>$quality];
        }
        if(!$enough)return false;
        $log=new class($emit,$s,$sectors,$date){
            public array $day;
            public function __construct(private $emit,private array &$s,private array $sectors,private int $date)
            {$this->reset();}
            private function reset():void{$this->day=['evaluated'=>0,'plan_status'=>[],'quality_blocked'=>[],'signals'=>[]];}
            public function __invoke(string $type,array $p):void
            {
                $emit=$this->emit;
                switch($type){
                    case 'snapshot':
                        $this->day['evaluated']++;$pl=$p['plan'];$q=$p['quality'];
                        if(empty($q['can_simulate']))foreach($q['reasons'] as $r)$this->day['quality_blocked'][$r][]=$p['symbol'];
                        else $this->day['plan_status'][(string)($pl['status']??'unknown')]=($this->day['plan_status'][(string)($pl['status']??'unknown')]??0)+1;
                        if(!empty($pl['ready'])){
                            $this->day['signals'][$p['symbol']]=['symbol'=>$p['symbol'],'pattern'=>$pl['pattern']??null,'entry'=>$pl['entry']??null,
                                'stop'=>$pl['stop']??null,'target'=>$pl['target']??null,'reward_risk'=>$pl['reward_risk']??null,
                                'signal_at'=>$pl['signal_at']??null,'order_valid_bars'=>$pl['order_valid_bars']??null,
                                'sector'=>$this->sectors[$p['symbol']]??'unclassified','outcome'=>null];
                        }
                        return;
                    case 'decision':
                        if(isset($this->day['signals'][$p['symbol']]))$this->day['signals'][$p['symbol']]['outcome']=$p['reason'];
                        return; // not-a-signal decisions are counted in plan_status, not logged one by one
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
                                'mark_close'=>$this->s['marks'][$symbol]['close']??null,'mark_session'=>$this->s['marks'][$symbol]['session']??null];
                        }
                        $pending=[];foreach($this->s['active'] as $symbol=>$o)if(!$o['filled'])$pending[]=$symbol;
                        ksort($held);sort($pending);
                        $qb=$this->day['quality_blocked'];ksort($qb);ksort($this->day['plan_status']);
                        $emit('day_log',['session'=>$this->date,'date'=>PaperHistoryResearch::day($this->date),'evaluated'=>$this->day['evaluated'],
                            'plan_status'=>$this->day['plan_status'],'quality_blocked'=>$qb,'signals'=>array_values($this->day['signals']),
                            'held'=>$held,'pending_orders'=>$pending]);
                        $emit('equity',$p);$this->reset();return;
                    default:
                        $emit($type,$p);
                }
            }
        };
        PaperPortfolio::advance($s,$date,$barSet,$snapshots,$log);
        return true;
    }

    // ---- books ---------------------------------------------------------------------------------------------

    /** Event stream without wall-clock fields, for run-to-run comparison. */
    public static function normalized(array $journal):array
    {
        $events=[];
        foreach($journal['events'] as $e)$events[]=['type'=>$e['type'],'payload'=>$e['payload']];
        return ['events'=>$events,'state_hash'=>$journal['state_hash']];
    }

    public static function normalizedHash(array $journal):string{return hash('sha256',PaperJournal::encode(self::normalized($journal)));}

    private static function near(float $a,float $b,float $abs=0.01):bool{return abs($a-$b)<=max($abs,1e-9*max(abs($a),abs($b)));}

    /**
     * Rebuilds cash, positions, fees, realised P&L and equity from the events alone and compares them with the stored state,
     * then re-runs every order through TradeSimulator on the dataset bars.
     * @return array{pass:bool,checks:array<string,array{pass:bool,detail:string}>,summary:array}
     */
    public static function reconcile(array $journal,?array $data=null):array
    {
        $s=$journal['state'];$cfg=$s['config'];$checks=[];
        $add=function(string $name,bool $ok,string $detail)use(&$checks){$checks[$name]=['pass'=>$ok,'detail'=>$detail];};
        $cash=(float)$cfg['initial_cash'];$realized=0.0;$orders=[];$positions=[];$closed=0;$wins=0;$losses=0;$errors=[];
        $lastEq=0;$equityDays=0;$logDays=0;$lastLog=0;$exits=[];$fills=[];$orderEvents=[];
        $dayLogs=[];$pendingLog=null;$activeCount=0;
        foreach($journal['events'] as $e){
            $p=$e['payload'];
            switch($e['type']){
                case 'order':
                    $sym=$p['symbol'];
                    if(isset($orders[$sym]))$errors[]='order while active '.$sym.' '.$p['session'];
                    $orders[$sym]=['qty'=>$p['quantity'],'entry'=>$p['entry'],'stop'=>$p['stop'],'target'=>$p['target'],'reserved'=>$p['reserved_cash'],
                        'filled'=>false,'cost'=>null,'session'=>$p['session'],'planned_risk'=>$p['planned_risk'],'pattern'=>$p['pattern']??null,
                        'signal_at'=>$p['signal_at']??null,'ttl'=>$p['order_valid_bars']??null];
                    if(!self::near((float)$p['reserved_cash'],$p['quantity']*$p['entry']*(1+self::FEE_RATE),1e-6))$errors[]='reservation math '.$sym;
                    if(count($orders)>$cfg['max_positions'])$errors[]='max positions exceeded at '.$p['session'];
                    $orderEvents[]=$p;
                    break;
                case 'fill':
                    $sym=$p['symbol'];$o=$orders[$sym]??null;
                    if($o===null||$o['filled']){$errors[]='fill without open order '.$sym;break;}
                    if($o['qty']!==$p['quantity'])$errors[]='fill quantity differs '.$sym;
                    if($p['session']<=$o['session'])$errors[]='fill not after the signal session '.$sym;
                    if($p['price']>$o['entry']+1e-9)$errors[]='fill above limit '.$sym;
                    $cost=$p['quantity']*$p['price']*(1+self::FEE_RATE);
                    if(!self::near($cost-$p['quantity']*$p['price'],$p['fee'],1e-6))$errors[]='fee math '.$sym;
                    $cash-=$cost;if($cash<-0.01)$errors[]='negative cash after fill '.$sym;
                    $orders[$sym]['filled']=true;$orders[$sym]['cost']=$cost;$orders[$sym]['fill']=$p['price'];$orders[$sym]['fill_session']=$p['session'];
                    $fills[$sym.'@'.$o['session']]=$p;
                    break;
                case 'exit':
                    $sym=$p['symbol'];$o=$orders[$sym]??null;
                    if($o===null||!$o['filled']){$errors[]='exit without fill '.$sym;break;}
                    if($o['qty']!==$p['quantity'])$errors[]='exit quantity differs '.$sym;
                    $proceeds=$p['quantity']*$p['price']*(1-self::FEE_RATE);$net=$proceeds-$o['cost'];
                    if(!self::near($net,$p['net_pnl'],0.01))$errors[]='net pnl math '.$sym;
                    $cash+=$proceeds;$realized+=$net;$closed++;if($net>0)$wins++;if($net<0)$losses++;
                    $exits[$sym.'@'.$o['session']]=$p+['entry_fill'=>$o['fill']];
                    unset($orders[$sym]);
                    break;
                case 'order_cancelled':
                    $sym=$p['symbol'];
                    if(!isset($orders[$sym])||$orders[$sym]['filled'])$errors[]='cancel of a missing or filled order '.$sym;
                    else unset($orders[$sym]);
                    break;
                case 'day_log':
                    if($p['session']<=$lastLog)$errors[]='day_log not increasing';$lastLog=$p['session'];$logDays++;$pendingLog=$p;$dayLogs[$p['session']]=$p;
                    break;
                case 'equity':
                    if($p['session']<=$lastEq)$errors[]='equity not increasing';$lastEq=$p['session'];$equityDays++;
                    $reserved=0.0;foreach($orders as $o)if(!$o['filled'])$reserved+=$o['reserved'];
                    if(!self::near($cash,(float)$p['cash'],0.01))$errors[]='cash differs on '.$p['session'].': '.$cash.' vs '.$p['cash'];
                    if(!self::near($reserved,(float)$p['reserved_cash'],0.01))$errors[]='reserved cash differs on '.$p['session'];
                    if(!self::near($realized,(float)$p['realized'],0.01))$errors[]='realised differs on '.$p['session'];
                    $mkt=0.0;
                    foreach(($pendingLog['held']??[]) as $sym=>$h)$mkt+=$h['quantity']*($h['mark_close']??$h['entry_fill']);
                    if(!self::near($cash+$mkt,(float)$p['equity'],0.01))$errors[]='equity differs on '.$p['session'];
                    foreach($orders as $sym=>$o)if($o['filled']&&!isset($pendingLog['held'][$sym]))$errors[]='held position missing from day log '.$sym;
                    if($cash-$reserved<-0.01)$errors[]='reserved exceeds cash on '.$p['session'];
                    break;
            }
        }
        $add('cash_positions_fees_pnl_rebuilt_from_events',$errors===[],$errors===[]?'cash, reserved cash, realised P&L, fees and equity rebuilt for '.$equityDays.' sessions':implode('; ',array_slice($errors,0,5)));
        $add('state_matches_rebuild',self::near($cash,(float)$s['cash'])&&self::near($realized,(float)$s['realized'])&&$closed===$s['closed_trades']&&$wins===$s['wins']&&$losses===$s['losses'],
            sprintf('cash %.2f/%.2f realised %.2f/%.2f closed %d/%d',$cash,$s['cash'],$realized,$s['realized'],$closed,$s['closed_trades']));
        $stateOpen=[];foreach($s['active'] as $sym=>$o)$stateOpen[$sym]=[$o['filled'],$o['quantity']];
        $evOpen=[];foreach($orders as $sym=>$o)$evOpen[$sym]=[$o['filled'],$o['qty']];
        ksort($stateOpen);ksort($evOpen);
        $add('open_orders_and_positions_match',$stateOpen===$evOpen,count($evOpen).' open orders/positions');
        $add('sessions_unique_and_logged',$logDays===$equityDays&&$equityDays===count($s['equity']),$equityDays.' equity points, '.$logDays.' day logs');
        $dup=[];foreach($orderEvents as $o){$k=$o['symbol'].'@'.$o['session'];if(isset($dup[$k]))$errors[]='duplicate order '.$k;$dup[$k]=true;}
        $add('no_duplicate_orders',count($dup)===count($orderEvents),count($orderEvents).' orders');
        // Limits at order time, against that session's equity point.
        $eq=[];foreach($s['equity'] as $pt)$eq[$pt['session']]=$pt;
        $limitErrors=[];
        foreach($orderEvents as $o){
            $pt=$eq[$o['session']]??null;if($pt===null){$limitErrors[]='no equity point '.$o['session'];continue;}
            if($o['quantity']*$o['entry']*(1+self::FEE_RATE)>$pt['equity']*$cfg['position_pct']+0.01)$limitErrors[]='position size '.$o['symbol'];
            if($o['planned_risk']>$pt['equity']*$cfg['risk_pct']+0.01)$limitErrors[]='risk size '.$o['symbol'];
        }
        $add('order_size_and_risk_within_limits',$limitErrors===[],$limitErrors===[]?count($orderEvents).' orders checked':implode('; ',array_slice($limitErrors,0,5)));
        $add('sector_notional_within_limit',...self::sectorLimitCheck($journal));
        // Marks against the dataset
        if($data!==null){
            $bad=[];$n=0;
            foreach($dayLogs as $session=>$log)foreach($log['held'] as $sym=>$h){
                if(($h['mark_session']??null)!==$session)continue;
                $bar=null;foreach($data['symbols'][$sym]['rows'] as $r)if($r['available_at']===$session)$bar=$r;
                $n++;if($bar===null||!self::near((float)$bar['close'],(float)$h['mark_close'],1e-9))$bad[]=$sym.'@'.$session;
            }
            $add('marks_equal_dataset_closes',$bad===[],$n.' marks checked'.($bad?': '.implode(',',array_slice($bad,0,5)):''));
            $add('orders_and_trades_equal_independent_simulator',...self::simulatorCheck($journal,$data));
        }
        $reasons=[];foreach($journal['events'] as $e)if($e['type']==='exit')$reasons[$e['payload']['reason']]=($reasons[$e['payload']['reason']]??0)+1;
        $add('no_forced_liquidation',array_diff(array_keys($reasons),['stop','target','time'])===[],'exit reasons: '.json_encode($reasons));
        $pass=true;foreach($checks as $c)$pass=$pass&&$c['pass'];
        return ['pass'=>$pass,'checks'=>$checks,'summary'=>self::summary($journal)];
    }

    /**
     * Replays sector notionals from the events. A filled position counts as the larger of its reservation and its mark,
     * which is how PaperPortfolio applies the sector cap. Unclassified names share one bucket.
     * @return array{0:bool,1:string}
     */
    private static function sectorLimitCheck(array $journal):array
    {
        $cfg=$journal['state']['config'];$pct=(float)$cfg['sector_pct'];
        $marks=[];$eq=[];
        foreach($journal['events'] as $e){
            $p=$e['payload'];
            if($e['type']==='day_log')foreach($p['held']??[] as $sym=>$h)if(isset($h['mark_close']))$marks[$p['session']][$sym]=(float)$h['mark_close'];
            if($e['type']==='equity')$eq[$p['session']]=(float)$p['equity'];
        }
        $active=[];$errors=[];$max=0.0;$checked=0;
        foreach($journal['events'] as $e){
            $p=$e['payload'];$sym=$p['symbol']??'';
            if($e['type']==='order'){
                $sector=(string)($p['sector']??'');if($sector==='')$sector='unclassified';
                $equity=$eq[$p['session']]??null;
                if($equity===null){$errors[]='no equity '.$sym;continue;}
                $used=0.0;
                foreach($active as $osym=>$o){
                    $n=$o['filled']?max($o['reservation'],$o['qty']*($marks[$p['session']][$osym]??$o['fill']??0)):$o['reservation'];
                    if($o['sector']===$sector)$used+=$n;
                }
                $room=$equity*$pct-$used;
                if((float)$p['reserved_cash']>$room+1.0)$errors[]=$sym.' '.PaperHistoryResearch::day($p['session']);
                if($equity>0)$max=max($max,($used+(float)$p['reserved_cash'])/$equity);
                $active[$sym]=['sector'=>$sector,'filled'=>false,'qty'=>(int)$p['quantity'],'reservation'=>(float)$p['reserved_cash'],'fill'=>null];
                $checked++;
            }elseif($e['type']==='fill'&&isset($active[$sym])){
                $active[$sym]['filled']=true;$active[$sym]['fill']=(float)$p['price'];
            }elseif(in_array($e['type'],['exit','order_cancelled'],true))unset($active[$sym]);
        }
        return [$errors===[],$errors===[]?$checked.' orders, max sector share '.round($max*100,2).'%':implode('; ',array_slice($errors,0,5))];
    }

    /** Every ordered trade re-simulated on the dataset's completed bars, outside the portfolio. */
    private static function simulatorCheck(array $journal,array $data):array
    {
        $trades=[];$cur=[];$last=$journal['state']['last_session'];
        foreach($journal['events'] as $e){
            $p=$e['payload'];$sym=$p['symbol']??'';
            if($e['type']==='order'){$trades[]=['o'=>$p,'fill'=>null,'exit'=>null,'cancel'=>null];$cur[$sym]=array_key_last($trades);}
            elseif(in_array($e['type'],['fill','exit','order_cancelled'],true)&&isset($cur[$sym])){
                $trades[$cur[$sym]][$e['type']==='order_cancelled'?'cancel':$e['type']]=$p;
            }
        }
        $bad=[];$skipped=0;
        foreach($trades as $tr){
            $o=$tr['o'];$sym=$o['symbol'];$signal=$o['session'];$tag=$sym.'@'.PaperHistoryResearch::day($signal);
            if(($tr['cancel']['reason']??null)==='data_quality'||!empty($journal['state']['halted'])&&$tr['exit']===null&&$tr['cancel']===null){$skipped++;continue;}
            $plan=['ready'=>true,'entry'=>$o['entry'],'stop'=>$o['stop'],'target'=>$o['target'],'signal_at'=>$signal];
            if(($o['order_valid_bars']??null)!==null)$plan['order_valid_bars']=$o['order_valid_bars'];
            $valid=CandleClock::completed($data['symbols'][$sym]['rows'],$sym,$last);
            $after=array_values(array_filter($valid,fn($b)=>$b['available_at']>$signal));
            $t=(new TradeSimulator())->simulate($plan,$after,20);
            $f=$tr['fill'];$x=$tr['exit'];$c=$tr['cancel'];
            if($t['status']==='closed'){
                if($f===null||!self::near((float)$t['entry_fill'],(float)$f['price'],1e-6))$bad[]=$tag.' entry fill';
                elseif($x===null||$x['session']!==$t['exit_at']||$x['reason']!==$t['first_exit']||!self::near((float)$t['exit_fill'],(float)$x['price'],1e-6))$bad[]=$tag.' exit';
            }elseif(in_array($t['status'],['unfilled','cancelled_before_entry'],true)){
                if($f!==null||$x!==null||$c===null||$c['reason']!==$t['status'])$bad[]=$tag.' cancel '.$t['status'];
            }elseif($t['filled']){
                if($f===null||$x!==null||!self::near((float)$t['entry_fill'],(float)$f['price'],1e-6))$bad[]=$tag.' open position';
            }elseif($f!==null||$x!==null||$c!==null)$bad[]=$tag.' still pending';
        }
        return [$bad===[],count($trades).' orders re-simulated, '.$skipped.' skipped (data-quality cancel or halted)'.($bad?': '.implode('; ',array_slice($bad,0,6)):'')];
    }

    /** Held positions at the last session are not closed: they are listed with their mark and unrealised result. */
    public static function summary(array $journal):array
    {
        $s=$journal['state'];$open=[];$pending=[];$unrealized=0.0;
        foreach($s['active'] as $sym=>$o){
            if(!$o['filled']){$pending[]=['symbol'=>$sym,'quantity'=>$o['quantity'],'entry'=>$o['plan']['entry']??null,'stop'=>$o['plan']['stop']??null,'target'=>$o['plan']['target']??null,'bars_seen'=>count($o['history']),'reserved_cash'=>$o['reservation']];continue;}
            $mark=$s['marks'][$sym]['close']??$o['entry_fill'];
            $u=$o['quantity']*$mark*(1-self::FEE_RATE)-$o['entry_cost'];$unrealized+=$u;
            $open[]=['symbol'=>$sym,'quantity'=>$o['quantity'],'entry_fill'=>$o['entry_fill'],'mark_close'=>$mark,'mark_session'=>$s['marks'][$sym]['session']??null,
                'unrealized_pnl_after_sell_cost'=>$u,'bars_held'=>count($o['history'])];
        }
        $types=[];foreach($journal['events'] as $e)$types[$e['type']]=($types[$e['type']]??0)+1;ksort($types);
        $initial=(float)$s['config']['initial_cash'];$eq=PaperPortfolio::equity($s);
        return ['account'=>$s['config']['id'],'initial_cash'=>$initial,'cash'=>$s['cash'],'reserved_cash'=>PaperPortfolio::reserved($s),'equity'=>$eq,
            'realized_pnl'=>$s['realized'],'closed_trades'=>$s['closed_trades'],'wins'=>$s['wins'],'losses'=>$s['losses'],
            'open_positions'=>$open,'pending_orders'=>$pending,'unrealized_pnl_open_positions'=>$unrealized,
            'max_close_drawdown'=>$s['max_drawdown'],'last_session'=>$s['last_session'],
            'last_day'=>$s['last_session']>0?PaperHistoryResearch::day($s['last_session']):null,'halted'=>!empty($s['halted']),'halt_reason'=>$s['halt_reason']??null,
            'event_counts'=>$types,'research'=>['dataset'=>$s['research']['dataset']??null,'start_day'=>$s['research']['start_day']??null,
                'plan_source'=>$s['research']['plan_source']??null,'sector_source'=>$s['research']['sector_source']??null,'smoke'=>$s['research']['smoke']??null]];
    }

    /** Day-by-day log rows: recommendations with their outcome, exclusion reasons, orders, fills, exits and the money. */
    public static function dailyLog(array $journal,?string $from=null,?string $to=null):array
    {
        $by=[];foreach($journal['events'] as $e){$p=$e['payload'];if(isset($p['session'])&&in_array($e['type'],['order','fill','exit','order_cancelled','account_halted','equity'],true))$by[$p['session']][$e['type']][]=$p;}
        $rows=[];
        foreach($journal['events'] as $e){
            if($e['type']!=='day_log')continue;$p=$e['payload'];
            if($from!==null&&$p['date']<$from)continue;if($to!==null&&$p['date']>$to)continue;
            $x=$by[$p['session']]??[];$eq=$x['equity'][0]??null;
            $rows[]=['date'=>$p['date'],'evaluated'=>$p['evaluated'],'plan_status'=>$p['plan_status'],'quality_blocked'=>$p['quality_blocked'],
                'signals'=>$p['signals'],'orders'=>$x['order']??[],'fills'=>$x['fill']??[],'exits'=>$x['exit']??[],'cancelled'=>$x['order_cancelled']??[],
                'halted'=>$x['account_halted']??[],'held'=>$p['held'],'pending_orders'=>$p['pending_orders'],
                'cash'=>$eq['cash']??null,'reserved_cash'=>$eq['reserved_cash']??null,'equity'=>$eq['equity']??null,'realized'=>$eq['realized']??null,'unrealized'=>$eq['unrealized']??null];
        }
        return $rows;
    }

    /**
     * The PR #65 population is the earlier fixed-universe pullback replay. Its selected rising pullbacks inside the replayed
     * window must appear as pullback signals with the same entry, stop and target; anything else is reported, not hidden.
     */
    public static function crosscheck(array $journal,array $population,string $period):array
    {
        $rows=self::dailyLog($journal);$byDay=[];
        foreach($rows as $r)foreach($r['signals'] as $sig)$byDay[$r['date']][$sig['symbol']]=$sig;
        $first=$rows[0]['date']??null;$last=$rows!==[]?end($rows)['date']:null;
        $matched=0;$differs=[];$missing=[];$outcomes=[];$inWindow=0;$known=[];
        foreach($population['events'] as $e){
            if($e['period']!==$period||$first===null||$e['date']<$first||$e['date']>$last)continue;
            $inWindow++;$sig=$byDay[$e['date']][$e['symbol']]??null;
            if($sig===null){$missing[]=$e['symbol'].'@'.$e['date'];continue;}
            $known[$e['symbol'].'@'.$e['date']]=true;
            $l=$e['levels'];
            $same=($sig['pattern']??null)==='trend_pullback_v1'&&abs($sig['entry']-$l['entry'])<1e-9&&abs($sig['stop']-$l['stop'])<1e-9&&abs($sig['target']-$l['target'])<1e-9;
            if($same){$matched++;$outcomes[$sig['outcome']??'none']=($outcomes[$sig['outcome']??'none']??0)+1;}
            else $differs[]=$e['symbol'].'@'.$e['date'];
        }
        $other=['trend_pullback_repeat_or_unlisted'=>0,'other_patterns'=>0];
        foreach($byDay as $date=>$list)foreach($list as $sym=>$sig){
            if(isset($known[$sym.'@'.$date]))continue;
            if(($sig['pattern']??null)==='trend_pullback_v1')$other['trend_pullback_repeat_or_unlisted']++;else $other['other_patterns']++;
        }
        ksort($outcomes);
        return ['window'=>[$first,$last],'population_pullbacks_in_window'=>$inWindow,'same_signal_and_levels'=>$matched,
            'levels_or_pattern_differ'=>$differs,'missing_from_account_signals'=>$missing,'outcome_of_matched_signals'=>$outcomes,
            'account_signals_not_in_population'=>$other];
    }

    /**
     * Read-only: how often the existing quality rule would block a symbol on a session. A held position on a blocked session
     * halts the account (existing rule), so this is the exposure a full run would face. It places no orders.
     */
    public static function qualityScan(array $data,?string $startDay=null,?string $throughDay=null):array
    {
        $sessions=self::sessions($data,$startDay,$throughDay);$byReason=[];$perSymbol=[];$perSession=[];$total=0;$blocked=0;
        foreach($data['symbols'] as $symbol=>$d){
            $rows=$d['rows'];$past=[];$i=0;$n=count($rows);
            foreach($sessions as $date){
                while($i<$n&&$rows[$i]['available_at']<=$date){$past[]=$rows[$i];$i++;}
                $q=PaperQuality::inspect($past,$symbol,$date,['sha256'=>$d['bars_sha256'],'price_basis'=>PaperHistoryResearch::PRICE_BASIS],[]);
                $total++;
                if($q['can_simulate'])continue;
                $blocked++;$perSession[$date]=($perSession[$date]??0)+1;$perSymbol[$symbol]['blocked_sessions']=($perSymbol[$symbol]['blocked_sessions']??0)+1;
                $perSymbol[$symbol]['first']??=PaperHistoryResearch::day($date);$perSymbol[$symbol]['last']=PaperHistoryResearch::day($date);
                foreach($q['reasons'] as $r){
                    $byReason[$r]['symbol_sessions']=($byReason[$r]['symbol_sessions']??0)+1;
                    $byReason[$r]['symbols'][$symbol]=true;$byReason[$r]['sessions'][$date]=true;
                }
            }
        }
        foreach($byReason as $r=>$v)$byReason[$r]=['symbol_sessions'=>$v['symbol_sessions'],'distinct_symbols'=>count($v['symbols']),'distinct_sessions'=>count($v['sessions'])];
        ksort($byReason);uasort($perSymbol,fn($a,$b)=>$b['blocked_sessions']<=>$a['blocked_sessions']);
        return ['dataset'=>$data['dataset'],'sessions'=>count($sessions),'symbols'=>count($data['symbols']),'symbol_sessions'=>$total,
            'blocked_symbol_sessions'=>$blocked,'blocked_share'=>$total>0?round($blocked/$total,4):null,
            'sessions_with_a_blocked_symbol'=>count($perSession),'max_blocked_in_one_session'=>$perSession?max($perSession):0,
            'by_reason'=>$byReason,'symbols_ever_blocked'=>count($perSymbol),'most_blocked_symbols'=>array_slice($perSymbol,0,10,true),
            'note'=>'A held position on a blocked session halts the account under the existing rule; a pending order is cancelled.'];
    }

    // ---- file safety ---------------------------------------------------------------------------------------

    /** Content hash of every file under a folder, for before/after comparison. Paths are relative, order is sorted. */
    public static function treeHash(string $dir,array $skipPrefixes=[]):string
    {
        if(!is_dir($dir))return hash('sha256','absent');
        $dir=rtrim(str_replace('\\','/',$dir),'/');$items=[];
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
        foreach($it as $f){
            if(!$f->isFile())continue;
            $rel=substr(str_replace('\\','/',$f->getPathname()),strlen($dir)+1);
            foreach($skipPrefixes as $p)if(str_starts_with($rel,$p))continue 2;
            $items[$rel]=hash_file('sha256',$f->getPathname());
        }
        ksort($items);
        return hash('sha256',PaperJournal::encode($items));
    }

    /** Files directly inside the state folder (operational accounts live there). Sub folders are not read. */
    public static function topLevelHash(string $dir):string
    {
        $items=[];
        foreach(is_dir($dir)?scandir($dir):[] as $name){
            $p=$dir.'/'.$name;if(is_file($p))$items[$name]=hash_file('sha256',$p);
        }
        ksort($items);
        return hash('sha256',PaperJournal::encode($items));
    }

    /** Orders paired with their own fill, exit or cancellation. */
    public static function trades(array $journal):array
    {
        $trades=[];$cur=[];
        foreach($journal['events'] as $e){
            $p=$e['payload'];$sym=$p['symbol']??'';
            if($e['type']==='order'){
                $trades[]=['symbol'=>$sym,'signal_date'=>PaperHistoryResearch::day($p['session']),'pattern'=>$p['pattern']??null,'sector'=>$p['sector']??null,
                    'quantity'=>$p['quantity'],'entry'=>$p['entry'],'stop'=>$p['stop'],'target'=>$p['target'],'planned_risk'=>$p['planned_risk'],
                    'reserved_cash'=>$p['reserved_cash'],'fill'=>null,'exit'=>null,'cancelled'=>null,'status'=>'pending'];
                $cur[$sym]=array_key_last($trades);continue;
            }
            if(!isset($cur[$sym]))continue;$i=$cur[$sym];
            if($e['type']==='fill'){$trades[$i]['fill']=['date'=>PaperHistoryResearch::day($p['session']),'price'=>$p['price'],'fee'=>$p['fee']];$trades[$i]['status']='open';}
            elseif($e['type']==='exit'){$trades[$i]['exit']=['date'=>PaperHistoryResearch::day($p['session']),'price'=>$p['price'],'reason'=>$p['reason'],'net_pnl'=>$p['net_pnl'],'ambiguous_bar'=>$p['ambiguous_bar']];$trades[$i]['status']='closed';}
            elseif($e['type']==='order_cancelled'){$trades[$i]['cancelled']=['date'=>PaperHistoryResearch::day($p['session']),'reason'=>$p['reason']];$trades[$i]['status']='cancelled';}
        }
        return $trades;
    }

    /** Bytes that decide what a replay does besides the strategy fingerprint. A change refuses a resume. */
    public const RUNTIME_CODE=['bin/paper/AccountReplay.php','bin/paper/HistoryResearch.php','bin/paper/StrategyVersion.php',
        'config/paper-history-kr-recovery-v1.json','config/paper-kr-recovery-v1.json','config/paper-strategy-files.json'];
    public static function runtimeCodeHashes():array
    {
        $root=dirname(__DIR__,2);$out=[];
        foreach(self::RUNTIME_CODE as $p)$out[$p]=PaperStrategyVersion::fileHash($root.'/'.$p);
        return $out;
    }

    public static function codeHashes(string $root):array
    {
        $out=[];foreach(self::CODE as $p)if(is_file($root.'/'.$p))$out[$p]=PaperStrategyVersion::fileHash($root.'/'.$p);
        return $out;
    }
}
