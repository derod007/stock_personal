<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bin/bootstrap.php';
use ChartEntryLab\PaperPortfolio;

/** Opt-in research pre-open step. No I/O, source fetching, or operating integration. */
final class ResearchEventOrders
{
    private const KINDS=['rights_issue','bonus_issue','split','consolidation'];

    public static function apply(array $state,array $events,int $cutoff,int $sessionOpen):array
    {
        if(($state['mode']??null)!=='replay' ||
            !str_starts_with($state['config']['id']??'','research-event-'))
            throw new InvalidArgumentException('Explicit research replay account required');
        if(!empty($state['halted']))throw new InvalidArgumentException('Halted state cannot advance');
        if($cutoff>=$sessionOpen || $cutoff<=($state['last_session']??0))
            throw new InvalidArgumentException('Cutoff must follow last session and precede open');
        $tz=new DateTimeZone('Asia/Seoul');
        $open=(new DateTimeImmutable('@'.$sessionOpen))->setTimezone($tz);
        $cut=(new DateTimeImmutable('@'.$cutoff))->setTimezone($tz);
        if(($state['config']['currency']??null)!=='KRW' || $open->format('H:i:s')!=='09:00:00' ||
            $open->format('Y-m-d')!==$cut->format('Y-m-d'))
            throw new InvalidArgumentException('KR session date and pre-open cutoff required');
        // Validate the entire input before creating any mutation or audit event.
        $ids=[];
        foreach($events as $e){
            if(!is_array($e))throw new InvalidArgumentException('Invalid event');
            foreach(['id','symbol','kind'] as $k)if(!is_string($e[$k]??null)||$e[$k]==='')throw new InvalidArgumentException('Missing event '.$k);
            if(isset($ids[$e['id']]))throw new InvalidArgumentException('Duplicate event ID');
            $ids[$e['id']]=true;
            if(!in_array($e['kind'],self::KINDS,true)||!is_bool($e['confirmed']??null))throw new InvalidArgumentException('Invalid event kind/status');
            foreach(['published_at','available_at','effective_open'] as $k)
                if(!is_int($e[$k]??null)||$e[$k]<=0)throw new InvalidArgumentException('Exact timestamp required: '.$k);
            if($e['available_at']<$e['published_at'])throw new InvalidArgumentException('Availability before publication');
        }
        foreach($state['active'] as $o){
            if(!is_bool($o['filled']??null))throw new InvalidArgumentException('Invalid order state');
            if(!$o['filled'] && (!is_int($o['recorded_at']??null)||$o['recorded_at']>$cutoff||
                !is_numeric($o['reservation']??null)||!is_finite((float)$o['reservation'])||$o['reservation']<0))
                throw new InvalidArgumentException('Invalid pending reservation/time');
        }
        $by=[];$ignored=[];
        foreach($events as $e){
            $reason=(!$e['confirmed']?'unconfirmed':($e['effective_open']!==$sessionOpen?'other_session':
                ($e['available_at']>$cutoff?'not_available_at_cutoff':null)));
            if($reason!==null){$ignored[]=['event_id'=>$e['id'],'reason'=>$reason];continue;}
            $by[$e['symbol']][]=$e['id'];
        }
        ksort($by);$next=$state;$log=[];$held=[];$released=0.;
        $before=PaperPortfolio::reserved($state);
        foreach($by as $sym=>$eventIds){
            if(!isset($next['active'][$sym]))continue;
            $o=$next['active'][$sym];sort($eventIds);
            if($o['filled']){$held[]=['symbol'=>$sym,'event_ids'=>$eventIds];continue;}
            $amount=(float)$o['reservation'];unset($next['active'][$sym]);$released+=$amount;
            $log[]=['type'=>'order_cancelled','symbol'=>$sym,'reason'=>'known_corporate_action_before_open',
                'event_ids'=>$eventIds,'decision_at'=>$cutoff,'effective_open'=>$sessionOpen,'released_reservation'=>$amount];
        }
        $after=PaperPortfolio::reserved($next);
        if(abs($before-$after-$released)>.000001 || $next['cash']!==$state['cash'])throw new RuntimeException('Reservation reconciliation failed');
        return ['state'=>$next,'events'=>$log,'ignored'=>$ignored,'held_requires_handler'=>$held,
            'can_advance'=>$held===[],'reserved_before'=>$before,'reserved_after'=>$after,
            'released'=>$released,'cash_unchanged'=>true];
    }
}
