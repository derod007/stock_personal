<?php
declare(strict_types=1);

/** Read-only view of the stored dated TOP100 research account trades. Does not replay or rewrite journals. */
final class PaperDatedTop100Trades
{
    public const ACCOUNT='hist-kr-dated-top100-v1-20251008-20261008';
    public const TRADES='docs/historical-top100-replay-20251008-20261008/account/trades.json';
    public const SUMMARY='docs/historical-top100-replay-20251008-20261008/account/summary.json';
    public const UNIVERSE='docs/historical-top100-20251008-20261008/universe-v2.json';
    public const EXPECTED_ORDERS=68;
    public const EXPECTED_FILLS=59;
    public const EXPECTED_CLOSED=56;
    public const EXPECTED_OPEN=3;
    public const EXPECTED_CANCELLED=9;

    public static function load(string $root): array
    {
        return self::build(self::readJson($root.'/'.self::TRADES),self::readJson($root.'/'.self::SUMMARY),self::readJson($root.'/'.self::UNIVERSE));
    }

    /** @param array<string,mixed> $tradesFile */
    public static function build(array $tradesFile,array $summary,array $universe): array
    {
        $account=(string)($summary['account']??'');
        if($account!=='' && $account!==self::ACCOUNT){
            throw new RuntimeException('summary account is not the dated TOP100 research ledger');
        }
        $names=self::names($universe);
        $marks=[];
        foreach($summary['open_positions']??[] as $p){
            $sym=(string)($p['symbol']??'');
            if($sym==='')throw new RuntimeException('open position missing symbol');
            if(isset($marks[$sym]))throw new RuntimeException('duplicate open mark '.$sym);
            $marks[$sym]=$p;
        }
        $fills=[];$cancelled=[];$seen=[];
        foreach($tradesFile['trades']??[] as $i=>$raw){
            if(!is_array($raw))throw new RuntimeException('trade row is not an object');
            $status=(string)($raw['status']??'');
            if($status==='cancelled' || (($raw['fill']??null)===null && ($raw['cancelled']??null)!==null)){
                $cancelled[]=self::cancelRow($raw,$names,(int)$i);
                continue;
            }
            if(($raw['fill']??null)===null){
                throw new RuntimeException('non-cancel trade has no stored fill');
            }
            $row=self::fillRow($raw,$names,$marks,$summary,(int)$i);
            $key=$row['symbol'].'|'.$row['signal_date'].'|'.$row['buy_date'];
            if(isset($seen[$key]))throw new RuntimeException('duplicate fill '.$key);
            $seen[$key]=true;
            $fills[]=$row;
        }
        usort($fills,self::fillOrder(...));
        foreach($fills as $n=>$row)$fills[$n]['display_id']=$n+1;
        $closed=array_values(array_filter($fills,fn($r)=>$r['status']==='closed'));
        $open=array_values(array_filter($fills,fn($r)=>$r['status']==='open'));
        $closedPnl=0.0;
        foreach($closed as $r)$closedPnl+=(float)$r['net_pnl'];
        $openSymbols=array_column($open,'symbol');
        $markSymbols=array_keys($marks);
        sort($openSymbols);sort($markSymbols);
        $issues=[];
        $orders=count($fills)+count($cancelled);
        if($orders!==self::EXPECTED_ORDERS)$issues[]='order_count';
        if(count($fills)!==self::EXPECTED_FILLS)$issues[]='fill_count';
        if(count($closed)!==self::EXPECTED_CLOSED)$issues[]='closed_count';
        if(count($open)!==self::EXPECTED_OPEN)$issues[]='open_count';
        if(count($cancelled)!==self::EXPECTED_CANCELLED)$issues[]='cancel_count';
        if(count($seen)!==count($fills))$issues[]='duplicate_fill';
        $realized=(float)($summary['realized_pnl']??NAN);
        if(!is_finite($realized) || abs($closedPnl-$realized)>0.01)$issues[]='realized_mismatch';
        if($openSymbols!==$markSymbols)$issues[]='open_mark_mismatch';
        foreach($open as $r){
            if($r['net_pnl']!==null || $r['net_return_pct']!==null)$issues[]='open_in_realized';
            if($r['mark_price']===null || $r['unrealized_pnl']===null)$issues[]='open_mark_missing';
        }
        return [
            'account'=>self::ACCOUNT,
            'label'=>'KRX 일별 거래대금 TOP100 연구',
            'period'=>'2025-10-08~2026-10-08',
            'last_day'=>(string)($summary['last_day']??''),
            'fills'=>$fills,
            'cancelled'=>$cancelled,
            'closed_count'=>count($closed),
            'open_count'=>count($open),
            'fill_count'=>count($fills),
            'cancel_count'=>count($cancelled),
            'order_count'=>$orders,
            'closed_net_pnl'=>$closedPnl,
            'summary_realized_pnl'=>is_finite($realized)?$realized:null,
            'issues'=>$issues,
            'ok'=>$issues===[],
        ];
    }

    /** @param array<string,mixed> $universe @return array<string,string> */
    public static function names(array $universe): array
    {
        $out=[];
        foreach($universe['rows']??[] as $u){
            if(!is_array($u))continue;
            $sym=(string)($u['symbol']??'');
            $name=trim((string)($u['name']??''));
            if($sym!=='' && $name!=='')$out[$sym]=$name;
        }
        return $out;
    }

    /** @param array<string,mixed> $raw @param array<string,string> $names */
    private static function cancelRow(array $raw,array $names,int $index): array
    {
        $sym=(string)$raw['symbol'];
        $c=$raw['cancelled']??[];
        return [
            'index'=>$index,
            'symbol'=>$sym,
            'code'=>self::code($sym),
            'name'=>$names[$sym]??$sym,
            'pattern'=>(string)($raw['pattern']??''),
            'signal_date'=>(string)($raw['signal_date']??''),
            'cancel_date'=>(string)($c['date']??''),
            'cancel_reason'=>(string)($c['reason']??''),
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,string> $names
     * @param array<string,array<string,mixed>> $marks
     * @param array<string,mixed> $summary
     */
    private static function fillRow(array $raw,array $names,array $marks,array $summary,int $index): array
    {
        $sym=(string)$raw['symbol'];
        $fill=$raw['fill'];
        $qty=(int)$raw['quantity'];
        $buyPrice=(float)$fill['price'];
        $buyFee=(float)($fill['fee']??0);
        $buyAmount=$qty*$buyPrice;
        $cost=$buyAmount+$buyFee;
        $status=(string)$raw['status'];
        $exit=$raw['exit']??null;
        $row=[
            'index'=>$index,
            'symbol'=>$sym,
            'code'=>self::code($sym),
            'name'=>$names[$sym]??$sym,
            'pattern'=>(string)($raw['pattern']??''),
            'signal_date'=>(string)($raw['signal_date']??''),
            'buy_date'=>(string)$fill['date'],
            'buy_price'=>$buyPrice,
            'quantity'=>$qty,
            'buy_amount'=>$buyAmount,
            'buy_fee'=>$buyFee,
            'entry_cost'=>$cost,
            'sell_date'=>null,
            'sell_price'=>null,
            'sell_amount'=>null,
            'sell_fee'=>null,
            'sell_quantity'=>null,
            'net_pnl'=>null,
            'net_return_pct'=>null,
            'exit_reason'=>null,
            'status'=>$status,
            'mark_date'=>null,
            'mark_price'=>null,
            'unrealized_pnl'=>null,
            'plan_entry'=>isset($raw['entry'])?(float)$raw['entry']:null,
        ];
        if($exit!==null){
            $sellPrice=(float)$exit['price'];
            $sellAmount=$qty*$sellPrice;
            $gross=$qty*($sellPrice-$buyPrice);
            $net=(float)$exit['net_pnl'];
            $row['sell_date']=(string)$exit['date'];
            $row['sell_price']=$sellPrice;
            $row['sell_amount']=$sellAmount;
            $row['sell_quantity']=$qty;
            $row['sell_fee']=$gross-$net-$buyFee;
            $row['net_pnl']=$net;
            $row['net_return_pct']=$cost>0?100.0*$net/$cost:null;
            $row['exit_reason']=(string)($exit['reason']??'');
            $row['status']='closed';
            return $row;
        }
        $row['status']='open';
        $mark=$marks[$sym]??null;
        if($mark!==null){
            $row['mark_price']=isset($mark['mark_close'])?(float)$mark['mark_close']:null;
            $row['unrealized_pnl']=isset($mark['unrealized_pnl_after_sell_cost'])?(float)$mark['unrealized_pnl_after_sell_cost']:null;
            if(isset($mark['mark_session']) && is_numeric($mark['mark_session'])){
                $row['mark_date']=self::day((int)$mark['mark_session']);
            } elseif(!empty($summary['last_day'])){
                $row['mark_date']=(string)$summary['last_day'];
            }
        }
        return $row;
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private static function fillOrder(array $a,array $b): int
    {
        return [$a['buy_date'],$a['symbol'],$a['signal_date']]<=>[$b['buy_date'],$b['symbol'],$b['signal_date']];
    }

    public static function code(string $symbol): string
    {
        return preg_match('/^([0-9A-Z]+)\.(KS|KQ)$/',$symbol,$m)===1?$m[1]:$symbol;
    }

    private static function day(int $ts): string
    {
        return (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
    }

    /** @return array<string,mixed> */
    private static function readJson(string $path): array
    {
        if(!is_file($path))throw new RuntimeException('missing stored file '.$path);
        $raw=file_get_contents($path);
        if($raw===false)throw new RuntimeException('cannot read '.$path);
        $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('invalid json '.$path);
        return $data;
    }
}
