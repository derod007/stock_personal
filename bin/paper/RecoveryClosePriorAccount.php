<?php
declare(strict_types=1);
require_once __DIR__.'/RecoveryCloseAccount.php';
use ChartEntryLab\PaperJournal;

/** Prior period only. The PR84 files and recent account journals remain immutable. */
final class PaperRecoveryClosePriorAccount
{
    public const VERSION='recovery_close_prior_account_v1';
    public const START='2024-10-08';
    public const END='2025-10-02';
    public const SESSIONS=243;
    public const DATASET='kr-dated-amount-top100-20241008-20251002-v2';
    public const DOCS='docs/historical-top100-20241008-20251002';
    public const OUTPUT='history-research/recovery-close-prior-account-v1';
    public const PREFIX='hist-rc-prior-v1-';
    public const EXTRA=['bin/paper/RecoveryClosePriorAccount.php','bin/paper_recovery_close_prior_account.php',
        'docs/recovery-close-prior-account/protocol.md','docs/recovery-close-prior-account/frozen-inputs.json'];

    public static function demand(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
    public static function codeFiles():array{return array_merge(PaperRecoveryCloseAccount::FILES,self::EXTRA);}
    public static function journalPath(string $state,string $arm,bool $check=false):string
    {
        self::demand(in_array($arm,['baseline','variant'],true),'Unknown arm');
        return $state.'/history-account/'.self::PREFIX.$arm.($check?'-check':'').'/account-replay.json';
    }
    public static function config(string $root,string $arm):array
    {
        self::demand(in_array($arm,['baseline','variant'],true),'Unknown arm');
        $c=PaperDatedTop100Replay::configs($root,PaperDatedTop100Replay::RESEARCH_CONFIG_YEAR);
        $r=$c['research'];$r['id_prefix']=self::PREFIX;
        return PaperAccountReplay::accountConfig($c['base'],self::PREFIX.$arm,$r);
    }
    public static function completed(array $journal,array $days):void
    {
        self::demand(empty($journal['state']['halted']),'Account halted');
        self::demand(array_column(PaperAccountReplay::dailyLog($journal),'date')===$days,'Account sessions incomplete or different');
    }
    public static function splitCheck(array $split,array $once,array $days):array
    {
        self::completed($split,$days);self::completed($once,$days);
        $pass=PaperAccountReplay::normalized($split)===PaperAccountReplay::normalized($once);
        self::demand($pass,'Split and single-transaction replay differ');
        return ['pass'=>true,'sessions'=>count($days),'split_normalized_sha256'=>PaperAccountReplay::normalizedHash($split),
            'once_normalized_sha256'=>PaperAccountReplay::normalizedHash($once),'independent_strategy_implementation'=>false];
    }
    public static function recentGuard(string $root,string $state):array
    {
        $fixed=PaperRecoveryCloseAccount::read($root.'/docs/recovery-close-account/frozen-inputs.json');
        foreach($fixed['repository_lf_sha256'] as $file=>$sha)
            self::demand(PaperStrategyVersion::fileHash($root.'/'.$file)===$sha,'Recent frozen input changed: '.$file);
        $path=$state.'/history-account/hist-rc-account-v1-baseline/account-replay.json';
        $j=(new PaperJournal($path))->read()??throw new RuntimeException('PR84 recent baseline journal is required, read only');
        PaperStrategyVersion::verify($j['state'],PaperDatedTop100Replay::STRATEGY_FINGERPRINT);
        $match=PaperRecoveryCloseAccount::baselineCheck($j,PaperRecoveryCloseAccount::reference($root));
        $books=PaperAccountReplay::reconcile($j);
        PaperRecoveryCloseAccount::requireBaseline($match,$books);
        return ['pass'=>true,'journal_sha256'=>hash_file('sha256',$path),'baseline_match'=>$match,'books'=>$books,
            'writes'=>false,'scope'=>'PR76 all saved daily/trade/summary fields + event books; no recent dataset re-simulation'];
    }
    public static function verify(array $j,array $data,array $cand,bool $variant):array
    {
        $books=PaperAccountReplay::reconcile($j,$data);
        $orders=PaperRecoveryCloseAccount::orderAudit($j,$data,$cand,$variant);
        self::demand($books['pass']&&$orders['pass'],'Books or as-of order audit failed');
        return ['pass'=>true,'books'=>$books,'orders'=>$orders];
    }
    public static function assertIdentity(array $j,array $identity,string $arm):void
    {
        $s=$j['state'];PaperStrategyVersion::verify($s,PaperStrategyVersion::current());
        $cfg=\ChartEntryLab\PaperScanUniverse::pinned($identity['configs'][$arm]);
        self::demand(($s['config_hash']??null)===hash('sha256',PaperJournal::encode($cfg)),'Journal config identity differs');
        $want=['dataset'=>self::DATASET,'dataset_files'=>$identity['dataset_files'],'bars_sha256'=>$identity['bars_sha256'],
            'candidates_sha256'=>$identity['candidates_sha256'],'sector_map_file_sha256'=>$identity['sector_sha256'],
            'code_sha256_lf'=>$identity['code_sha256_lf'],'plan_source'=>$arm==='variant'?'injected_test':'engine',
            'start_day'=>self::START,'as_of_day'=>self::END];
        foreach($want as $k=>$v)self::demand(PaperRecoveryCloseAccount::differences($v,$s['research'][$k]??null)===[],'Journal research identity differs: '.$k);
    }

    public static function compare(array $base,array $variant):array
    {
        $r=PaperRecoveryCloseAccount::compare($base,$variant);$r['kind']=self::VERSION;
        $r['period']=['from'=>self::START,'to'=>self::END,'sessions'=>self::SESSIONS];
        $r['half_definition']='first 122 sessions / remaining 121; account carries holdings across boundary';
        $r['baseline_reference']='New prior-period baseline, not PR76. Verified with full split/once replay and books.';
        $r['notes'][]='이전 고정 종목군 연구를 이미 본 기간이다. 이번 날짜별 TOP100 비교도 완전한 블라인드가 아니다.';
        return $r;
    }
}
