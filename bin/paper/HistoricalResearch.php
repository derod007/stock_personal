<?php
declare(strict_types=1);
require_once __DIR__.'/Rediagnosis.php';
require_once __DIR__.'/StrategyVersion.php';
use ChartEntryLab\PaperQuality;

/** Current rules on frozen inputs; the returned research copy is never persisted as an original. */
final class PaperHistoricalResearch
{
    public static function evaluate(array $r, ?array $prices, int $asOf, string $fingerprint, ?int $evidenceAsOf=null): array
    {
        $out=['symbol'=>$r['symbol'],'name'=>$r['name']??$r['symbol'],'session'=>$r['session'],
            'source_file'=>$r['source_file'],'observation_hash'=>$r['observation_hash'],
            'input_hash'=>$r['input_hash'],'basis'=>'current_rules_on_saved_input','patterns'=>[], 'followup'=>null];
        $identity=PaperTrackingInput::identity($r);
        if($identity['status']==='analysis_symbol_mismatch')return $out+['status'=>'analysis_symbol_mismatch'];
        if(ChartEntryLab\KrAmountLeadersClient::excludedFromAmountRank($r['name']??''))return $out+['status'=>'excluded_instrument'];
        $d=PaperRediagnosis::evaluate($r,null,$asOf,$fingerprint);
        if(($d['status']??'')!=='diagnosed'||($d['replay']['status']??'')==='error')
            return $out+['status'=>$d['status']==='diagnosed'?'replay_error':$d['status'],'error'=>$d['replay']['error']??null];
        $a=$d['replay']['analysis'];
        // A decision made before its declared candle close cannot be reconstructed as a close signal.
        if($r['captured_at']<$r['session']||$a['plan']['asof']<$r['session'])return $out+['status'=>'invalid_observation_time'];
        $q=PaperQuality::inspect($r['bars'],$r['symbol'],(int)$r['session'],['sha256'=>$r['input_hash']]);
        $out['status']='evaluated';$out['quality']=$q;
        $out['decision']=$a['decision'];$out['plan']=$a['plan'];
        $out['patterns']=$a['plan']['diagnostics']['patterns'];
        $out['rr_research']=PaperRrAudit::evaluate($a,$q);
        if($prices!==null){
            // Replace only the in-memory decision: original identity, bars, hashes and timing stay fixed.
            // Existing followup performs reconciliation, late-observation and future-quality checks.
            $current=$r;$current['analysis']=$a;$current['patterns']=$out['rr_research'];
            $out['followup']=PaperFollowup::evaluate($current,$prices,min($asOf,$evidenceAsOf??$asOf));
            $out['followup']['basis']='current_rule_research_not_original_signal';
        }
        return $out;
    }

    public static function summarize(array $rows): array
    {
        $s=['rows'=>count($rows),'statuses'=>[],'final_statuses'=>[],'patterns'=>[], 'tracking'=>[], 'horizons'=>[], 'groups'=>[], 'trades'=>[]];
        foreach($rows as $r){
            $k=$r['status'];$s['statuses'][$k]=($s['statuses'][$k]??0)+1;
            if($k!=='evaluated')continue;
            $k=$r['plan']['status'];$s['final_statuses'][$k]=($s['final_statuses'][$k]??0)+1;
            foreach($r['patterns'] as $p=>$v){$k=$v['status'];$s['patterns'][$p][$k]=($s['patterns'][$p][$k]??0)+1;}
            $f=$r['followup'];$k=$f['status']??'evidence_unavailable';$s['tracking'][$k]=($s['tracking'][$k]??0)+1;
            foreach($f['horizons']??[] as $n=>$h)if($h['status']==='complete'){
                $s['horizons'][$n]??=['n'=>0,'up'=>0,'sum_return_pct'=>0];
                $s['horizons'][$n]['n']++;$s['horizons'][$n]['up']+=(int)($h['return_pct']>0);
                $s['horizons'][$n]['sum_return_pct']+=$h['return_pct'];
            }
            foreach($r['patterns'] as $pattern=>$raw){
                $key=$pattern.':'.$raw['status'].':'.$r['plan']['status'];
                $s['groups'][$key]??=['observations'=>0,'horizons'=>[]];$s['groups'][$key]['observations']++;
                foreach($f['horizons']??[] as $n=>$h)if($h['status']==='complete'){
                    $g=&$s['groups'][$key]['horizons'][$n];$g??=['n'=>0,'up'=>0,'mean_return_pct'=>0];
                    $g['n']++;$g['up']+=(int)($h['return_pct']>0);
                    $g['mean_return_pct']+=($h['return_pct']-$g['mean_return_pct'])/$g['n'];unset($g);
                }
            }
            foreach($f['trades']??[] as $t){$k=$t['kind'].':'.($t['pattern']??$r['plan']['pattern']).':'.$t['outcome']['status'];$s['trades'][$k]=($s['trades'][$k]??0)+1;}
        }
        foreach($s['horizons'] as &$h){$h['mean_return_pct']=$h['sum_return_pct']/$h['n'];unset($h['sum_return_pct']);}unset($h);
        return $s;
    }
}
