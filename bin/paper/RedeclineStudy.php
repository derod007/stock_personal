<?php
declare(strict_types=1);
require_once __DIR__.'/RedeclineStop.php';
use ChartEntryLab\{CandleClock,PriceCandidate};

/** Population, frozen alternative stops, paired replay and summaries for the one-stop comparison. Research only. */
final class PaperRedeclineStudy
{
    public const VERSION='recent_redecline_stop_study_v1';
    public const PERIODS=['prior'=>'kr-saved-scan-20251008','recent'=>'kr-saved-scan-20261009'];
    /** Fixed before any alternative result was computed. A "big loss" is a net trade return at or below this. */
    public const BIG_LOSS_PCT=-10.0;
    public const STATUSES=['closed','incomplete','unfilled','pending','cancelled_before_entry','future_quality_blocked','invalid_levels','alt_stop_not_below_fill'];

    public static function sha(string $path):string{return hash_file('sha256',$path);}
    public static function lfSha(string $path):string{return hash('sha256',str_replace("\r\n","\n",(string)file_get_contents($path)));}

    /** Rows of one symbol with the close time attached, ascending. The file hash must equal the manifest. */
    public static function rows(string $dir,string $symbol):array
    {
        static $cache=[];static $manifests=[];$key=$dir.'|'.$symbol;
        if(isset($cache[$key]))return $cache[$key];
        $m=$manifests[$dir]??=PaperHistoryResearch::manifest($dir);$entry=null;
        foreach($m['symbols'] as $s)if($s['symbol']===$symbol)$entry=$s;
        if($entry===null)throw new RuntimeException('Symbol not in manifest: '.$symbol);
        $path=$dir.'/'.$entry['bars_path'];
        if(!hash_equals($entry['bars_sha256'],hash_file('sha256',$path)))throw new RuntimeException('Changed bars: '.$symbol);
        $rows=PaperHistoryResearch::readJson($path)['rows'];
        foreach($rows as &$b)$b['available_at']=CandleClock::closeTime($b,$symbol);unset($b);
        usort($rows,fn($a,$b)=>$a['available_at']<=>$b['available_at']);
        return $cache[$key]=['rows'=>$rows,'bars_sha256'=>$entry['bars_sha256']];
    }

    /** Rows closed at or before the session. */
    public static function past(array $rows,int $session):array
    {
        return array_values(array_filter($rows,fn($b)=>$b['available_at']<=$session));
    }

    /** Stop the engine would set from bars alone, used to prove the ATR and bars are the engine's. */
    public static function baselineStop(array $completed,float $atr):float
    {
        return PriceCandidate::trunc(min(array_column(array_slice($completed,-10),'low'))-0.2*$atr);
    }

    public static function levelsOf(array $plan):array
    {
        return ['entry'=>$plan['entry'],'stop'=>$plan['stop'],'target'=>$plan['target'],'reward_risk'=>$plan['reward_risk'],'signal_at'=>$plan['signal_at']];
    }

    /**
     * Signal-day computation. No bar after the session is read. Returns the engine plan and the frozen record.
     * @param array{id:string,symbol:string,session:int,input_hash:string,levels:array} $ev
     */
    public static function freezeCase(array $ev,array $rows):array
    {
        $past=self::past($rows,$ev['session']);
        $d=PaperPatternReplay::day($past,$ev['symbol'],$ev['session'],'account1');
        if($d['status']!=='evaluated')throw new RuntimeException($ev['id'].': day not evaluated');
        if($d['input_hash']!==$ev['input_hash'])throw new RuntimeException($ev['id'].': input hash differs from population');
        $plan=$d['analysis']['plan'];
        if(empty($plan['ready'])||self::levelsOf($plan)!=$ev['levels'])throw new RuntimeException($ev['id'].': signal levels differ from population');
        $atr=(float)$d['analysis']['features']['atr14'];
        $completed=CandleClock::completed($past,$ev['symbol'],$ev['session']);
        if(self::baselineStop($completed,$atr)!==(float)$plan['stop'])throw new RuntimeException($ev['id'].': engine stop is not reproduced from bars and ATR');
        $alt=PaperRedeclineStop::forSignal($past,$ev['symbol'],$ev['session'],$atr,(float)$plan['entry']);
        if(end($completed)['available_at']!==$ev['session'])throw new RuntimeException($ev['id'].': last bar is not the signal day');
        $rec=[
            'id'=>$ev['id'],'pack_case_id'=>$ev['pack_case_id']??null,'period'=>$ev['period'],'symbol'=>$ev['symbol'],'name'=>$ev['name'],'date'=>$ev['date'],'session'=>$ev['session'],
            'input_hash'=>$ev['input_hash'],'completed_bars'=>count($completed),
            'plan'=>['entry'=>$plan['entry'],'stop'=>$plan['stop'],'target'=>$plan['target']],
            'status'=>$alt['status'],'reason'=>$alt['reason'],'alt_stop'=>$alt['alt_stop'],'atr14'=>$atr,
            'flags'=>$alt['flags'],'window'=>$alt['window'],'H_big'=>$alt['H_big'],'L_big'=>$alt['L_big'],
            'H_recent'=>$alt['H_recent'],'rule_found_re_decline'=>$alt['rule_found_re_decline'],'low_after_recent'=>$alt['low_after_recent'],
        ];
        if($alt['alt_stop']!==null)$rec['alt_stop_vs_plan_stop_pct']=round(($alt['alt_stop']/$plan['stop']-1)*100,4);
        return ['plan'=>$plan,'record'=>$rec,'day'=>$d];
    }

    /**
     * Step 1 of a case: baseline recomputed by the engine and by the study simulator (unchanged stop) and compared with the
     * stored outcome. No alternative result is produced here.
     */
    public static function baselineCase(array $frozen,array $ev,array $rows,int $cutoff,array $stored):array
    {
        $fc=self::freezeCase($ev,$rows);$plan=$fc['plan'];
        // The frozen record must be what the signal-day code produces now.
        $now=$fc['record'];
        foreach(['status','reason','alt_stop','atr14','H_recent','low_after_recent'] as $k)
            if($now[$k]!=$frozen[$k])throw new RuntimeException($ev['id'].': frozen record differs from recomputation at '.$k);
        $future=PaperPatternReplay::future($rows,$ev['symbol'],$ev['session'],$cutoff);
        $engine=PaperPatternReplay::trade($plan,$future);
        $mine=(new PaperRedeclineSimulator())->trade($plan,$future);
        $same=$engine==$mine;
        $matchStored=$engine==$stored;
        $b=self::view($engine,(float)$plan['entry'],(float)$plan['stop'],(float)$plan['stop']);
        $row=['id'=>$ev['id'],'period'=>$ev['period'],'symbol'=>$ev['symbol'],'name'=>$ev['name'],'date'=>$ev['date'],'session'=>$ev['session'],
            'plan'=>$frozen['plan'],'alt_stop'=>$frozen['alt_stop'],'baseline'=>$b,
            'repro'=>['engine_equals_stored'=>$matchStored,'study_simulator_equals_engine'=>$same]];
        return ['row'=>$row,'plan'=>$plan,'future'=>$future,'engine'=>$engine,'frozen'=>$frozen];
    }

    /** Step 2: only after every baseline matched. Applies the frozen alternative stop. */
    public static function altCase(array $ctx):array
    {
        $row=$ctx['row'];$plan=$ctx['plan'];$future=$ctx['future'];$engine=$ctx['engine'];$frozen=$ctx['frozen'];$b=$row['baseline'];
        if($frozen['status']!=='applicable'){
            $row['applicability']=['signal'=>'not_applicable','reason'=>$frozen['reason'],'post_fill'=>null];
            $row['alt']=null;$row['alt_state']='not_applicable';
            return $row;
        }
        $alt=(new PaperRedeclineSimulator())->trade($plan,$future,(float)$frozen['alt_stop']);
        $row['applicability']=['signal'=>'applicable','reason'=>null,'post_fill'=>$alt['status']==='alt_stop_not_below_fill'?'alt_stop_not_below_fill':null];
        $row['alt']=self::view($alt,(float)$plan['entry'],(float)$plan['stop'],(float)$frozen['alt_stop']);
        $row['alt_state']=$alt['status'];
        // Before the fill the two runs must be identical; after it only the stop differs.
        if(!$b['filled'])$row['pre_fill_identical']=$engine['status']===$alt['status'];
        return $row;
    }

    /** Compact view of one simulated trade. Risk is measured at the real fill (0 when never filled). */
    public static function view(array $t,float $entry,float $planStop,float $stop):array
    {
        $fill=$t['entry_fill'];
        $v=['status'=>$t['status'],'filled'=>(bool)$t['filled'],'first_exit'=>$t['first_exit'],'net_return_pct'=>$t['net_return_pct'],
            'entry_fill'=>$fill,'entry_at'=>$t['entry_at'],'exit_fill'=>$t['exit_fill'],'exit_at'=>$t['exit_at'],'bars'=>$t['bars'],
            'ambiguous_bar'=>$t['ambiguous_bar'],'stop'=>$stop,
            'risk_signal_pct'=>round(($entry-$stop)/$entry*100,6),'risk_fill_pct'=>$fill!==null?round(($fill-$stop)/$fill*100,6):null];
        // A gap stop fills at the open, below the stop level (the stop level itself would give stop*(1-slippage)).
        $v['gap_stop']=$t['status']==='closed'&&$t['first_exit']==='stop'&&(float)$t['exit_fill']<$stop*(1-0.0005)-1e-4;
        return $v;
    }

    public static function mean(array $v):?float{return $v?array_sum($v)/count($v):null;}
    public static function median(array $v):?float
    {
        if(!$v)return null;sort($v,SORT_NUMERIC);$n=count($v);
        return $n%2?$v[intdiv($n,2)]:($v[$n/2-1]+$v[$n/2])/2;
    }
    private static function r(?float $v,int $d=4):?float{return $v===null?null:round($v,$d);}
    private static function stats(array $v):array{return ['n'=>count($v),'mean'=>self::r(self::mean($v)),'median'=>self::r(self::median($v))];}

    /** Summary of one period from run rows. Never substitutes zero for a trade that was not closed. */
    public static function summarize(array $rows):array
    {
        $o=['selected'=>count($rows)];
        $app=['applicable'=>0,'not_applicable'=>0,'reasons'=>[],'applicable_filled'=>0,'applicable_not_filled'=>0,'post_fill_not_applicable'=>0,'applied'=>0];
        $trans=[];$common=[];$oneBase=[];$oneAlt=[];
        foreach($rows as $r){
            $bs=$r['baseline']['status'];
            if($r['applicability']['signal']==='not_applicable'){
                $app['not_applicable']++;$reason=$r['applicability']['reason'];$app['reasons'][$reason]=($app['reasons'][$reason]??0)+1;
                $trans[$bs]['not_applicable']=($trans[$bs]['not_applicable']??0)+1;continue;
            }
            $app['applicable']++;
            if($r['baseline']['filled'])$app['applicable_filled']++;else $app['applicable_not_filled']++;
            $as=$r['alt']['status'];$trans[$bs][$as]=($trans[$bs][$as]??0)+1;
            if($as==='alt_stop_not_below_fill'){$app['post_fill_not_applicable']++;continue;}
            if($r['baseline']['filled'])$app['applied']++;
            if($bs==='closed'&&$as==='closed')$common[]=$r;
            elseif($bs==='closed')$oneBase[]=$r;
            elseif($as==='closed')$oneAlt[]=$r;
        }
        ksort($trans);foreach($trans as &$t)ksort($t);unset($t);
        // How often the alternative stop is a different level at all (the same level gives the same trade by construction).
        $lv=['same_level'=>0,'tighter_higher'=>0,'wider_lower'=>0];
        foreach($rows as $r){
            if($r['applicability']['signal']!=='applicable'||!isset($r['plan']['stop']))continue;
            $d=$r['alt_stop']<=>$r['plan']['stop'];$lv[$d===0?'same_level':($d>0?'tighter_higher':'wider_lower')]++;
        }
        $app['stop_level_vs_baseline']=$lv;
        $o['applicability']=$app;$o['transitions']=$trans;
        $bn=array_map(fn($r)=>(float)$r['baseline']['net_return_pct'],$common);$an=array_map(fn($r)=>(float)$r['alt']['net_return_pct'],$common);
        $diff=[];$imp=0;$wor=0;$same=0;
        foreach($common as $i=>$r){$d=round($an[$i]-$bn[$i],4);$diff[]=$d;if($d>0)$imp++;elseif($d<0)$wor++;else $same++;}
        $o['common_closed']=['n'=>count($common),'baseline'=>self::stats($bn),'alt'=>self::stats($an),'alt_minus_baseline'=>self::stats($diff),
            'improved'=>$imp,'worse'=>$wor,'same'=>$same,
            'baseline_positive'=>count(array_filter($bn,fn($x)=>$x>0)),'alt_positive'=>count(array_filter($an,fn($x)=>$x>0))];
        // The same comparison restricted to trades whose stop level actually differs (identical levels cannot differ in outcome).
        $cb=[];$ca=[];$cd=[];$ci=0;$cw=0;$cs=0;
        foreach($common as $i=>$r){
            if(!isset($r['plan']['stop'])||$r['alt_stop']==$r['plan']['stop'])continue;
            $cb[]=$bn[$i];$ca[]=$an[$i];$d=round($an[$i]-$bn[$i],4);$cd[]=$d;if($d>0)$ci++;elseif($d<0)$cw++;else $cs++;
        }
        $o['common_closed_stop_level_changed']=['n'=>count($cb),'baseline'=>self::stats($cb),'alt'=>self::stats($ca),'alt_minus_baseline'=>self::stats($cd),'improved'=>$ci,'worse'=>$cw,'same'=>$cs];
        // exits
        $ex=[];$winToStop=0;$winners=0;$stopB=0;$stopA=0;$gapB=0;$gapA=0;$ambB=0;$ambA=0;
        foreach($common as $r){
            $be=$r['baseline']['first_exit'];$ae=$r['alt']['first_exit'];$ex[$be.'->'.$ae]=($ex[$be.'->'.$ae]??0)+1;
            $stopB+=(int)($be==='stop');$stopA+=(int)($ae==='stop');
            $gapB+=(int)$r['baseline']['gap_stop'];$gapA+=(int)$r['alt']['gap_stop'];
            $ambB+=(int)$r['baseline']['ambiguous_bar'];$ambA+=(int)$r['alt']['ambiguous_bar'];
            if($r['baseline']['net_return_pct']>0){$winners++;if($ae==='stop')$winToStop++;}
        }
        ksort($ex);
        $o['exits_common_closed']=['baseline_stop'=>$stopB,'alt_stop'=>$stopA,'baseline_gap_stops'=>$gapB,'alt_gap_stops'=>$gapA,'baseline_same_bar_stop_and_target_touch'=>$ambB,'alt_same_bar_stop_and_target_touch'=>$ambA,'exit_pairs'=>$ex,'baseline_winners'=>$winners,'baseline_winner_became_alt_stop_exit'=>$winToStop];
        // one-sided
        $o['only_baseline_closed']=array_map(fn($r)=>self::side($r),$oneBase);
        $o['only_alt_closed']=array_map(fn($r)=>self::side($r),$oneAlt);
        // big losses
        $big=self::BIG_LOSS_PCT;$bb=0;$ab=0;$red=[];$inc=[];
        foreach($common as $i=>$r){
            if($bn[$i]<=$big)$bb++;if($an[$i]<=$big)$ab++;
            if($bn[$i]<=$big&&$an[$i]>$big)$red[]=self::pair($r);
            if($an[$i]<=$big&&$bn[$i]>$big)$inc[]=self::pair($r);
        }
        $o['big_loss']=['threshold_pct'=>$big,'baseline_count'=>$bb,'alt_count'=>$ab,'reduced_below_threshold'=>$red,'increased_to_threshold'=>$inc];
        $pairs=array_map(fn($r)=>self::pair($r),$common);
        usort($pairs,fn($a,$b)=>$a['delta']<=>$b['delta']);
        $o['largest_deterioration']=array_slice($pairs,0,5);$o['largest_improvement']=array_slice(array_reverse($pairs),0,5);
        // initial stop width: real fill, applied trades; and signal level for every applicable case
        $wb=[];$wa=[];$wider=0;$narrower=0;$equal=0;
        foreach($rows as $r){
            if(($r['alt']['status']??null)===null||$r['alt']['status']==='alt_stop_not_below_fill'||!$r['baseline']['filled'])continue;
            $x=$r['baseline']['risk_fill_pct'];$y=$r['alt']['risk_fill_pct'];$wb[]=$x;$wa[]=$y;
            if($y>$x+1e-9)$wider++;elseif($y<$x-1e-9)$narrower++;else $equal++;
        }
        $sb=[];$sa=[];
        foreach($rows as $r)if($r['applicability']['signal']==='applicable'){$sb[]=$r['baseline']['risk_signal_pct'];$sa[]=$r['alt']['risk_signal_pct'];}
        $o['initial_stop_width']=['applied_at_fill'=>['n'=>count($wb),'baseline_pct'=>self::stats($wb),'alt_pct'=>self::stats($wa),'alt_wider'=>$wider,'alt_narrower'=>$narrower,'equal'=>$equal,
            'median_ratio_alt_to_baseline'=>self::r(self::median(array_map(fn($x,$y)=>$y/$x,$wb,$wa)))],
            'applicable_at_signal'=>['n'=>count($sb),'baseline_pct'=>self::stats($sb),'alt_pct'=>self::stats($sa)]];
        // R
        $r1b=[];$r1a=[];$r2a=[];$r2b=[];
        foreach($common as $i=>$r){
            $rb=$r['baseline']['risk_fill_pct'];$ra=$r['alt']['risk_fill_pct'];
            $r1b[]=$bn[$i]/$rb;$r1a[]=$an[$i]/$rb;$r2b[]=$bn[$i]/$rb;$r2a[]=$an[$i]/$ra;
        }
        $o['R_common_closed']=['common_denominator_baseline_risk'=>['baseline'=>self::stats($r1b),'alt'=>self::stats($r1a)],
            'own_initial_risk'=>['baseline'=>self::stats($r2b),'alt'=>self::stats($r2a)],
            'note'=>'R = net return % divided by the initial risk % at the real fill price. Trade averages, not account returns.'];
        return $o;
    }

    private static function pair(array $r):array
    {
        return ['id'=>$r['id'],'symbol'=>$r['symbol'],'name'=>$r['name'],'date'=>$r['date'],'baseline'=>$r['baseline']['net_return_pct'],'alt'=>$r['alt']['net_return_pct'],
            'delta'=>round($r['alt']['net_return_pct']-$r['baseline']['net_return_pct'],4),'baseline_exit'=>$r['baseline']['first_exit'],'alt_exit'=>$r['alt']['first_exit'],
            'baseline_stop'=>$r['baseline']['stop'],'alt_stop'=>$r['alt']['stop']];
    }
    private static function side(array $r):array
    {
        return ['id'=>$r['id'],'symbol'=>$r['symbol'],'name'=>$r['name'],'date'=>$r['date'],'baseline_status'=>$r['baseline']['status'],'baseline_exit'=>$r['baseline']['first_exit'],
            'baseline_net'=>$r['baseline']['net_return_pct'],'alt_status'=>$r['alt']['status'],'alt_exit'=>$r['alt']['first_exit'],'alt_net'=>$r['alt']['net_return_pct']];
    }
}
