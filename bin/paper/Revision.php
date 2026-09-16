<?php
declare(strict_types=1);
use ChartEntryLab\PaperJournal;
final class PaperRevision
{
    public static function describe(array $state,array $incoming,array $sources,array $events,int $now):array
    {
        $changes=[];$affected=[];$origins=[];
        foreach($state['config']['symbols'] as $symbol=>$sector) {
            $horizon=0;foreach($state['frozen'] as $key=>$frozen){[$sym,$date]=explode(':',$key);if($sym===$symbol)$horizon=max($horizon,(int)$date);}
            if(!$horizon)continue;
            $before=[];$after=[];
            foreach($state['history'][$symbol]??[] as $b)if($b['available_at']<=$horizon)$before[$b['available_at']]=$b;
            foreach($incoming[$symbol]??[] as $b)if($b['available_at']<=$horizon)$after[$b['available_at']]=$b;
            $dates=array_unique(array_merge(array_keys($before),array_keys($after)));sort($dates,SORT_NUMERIC);
            $first=null;
            foreach($dates as $at) {
                $a=$before[$at]??null;$b=$after[$at]??null;
                if(PaperJournal::encode($a)===PaperJournal::encode($b))continue;
                $first??=$at;$fields=[];
                foreach(array_unique(array_merge(array_keys($a??[]),array_keys($b??[]))) as $field) {
                    $old=$a[$field]??null;$new=$b[$field]??null;$op=array_key_exists($field,$a??[]);$np=array_key_exists($field,$b??[]);
                    if($op===$np && PaperJournal::encode($old)===PaperJournal::encode($new))continue;
                    $numeric=is_numeric($old)&&is_numeric($new);
                    $fields[]=['field'=>$field,'old_present'=>$op,'new_present'=>$np,'old'=>$old,'new'=>$new,
                        'delta'=>$numeric?(float)$new-(float)$old:null,
                        'change_pct'=>$numeric && (float)$old!=0?100*((float)$new-(float)$old)/abs((float)$old):null,
                        'representation_only'=>$numeric && (float)$old===(float)$new];
                }
                $changes[]=['symbol'=>$symbol,'session'=>(int)$at,'kind'=>$a===null?'added':($b===null?'removed':($fields?'changed':'key_order_only')),'fields'=>$fields];
            }
            if($first!==null) {
                foreach($state['frozen'] as $key=>$frozen){[$sym,$date]=explode(':',$key);if($sym===$symbol && (int)$date>=$first)$affected[]=$key;}
                $origins[$symbol]=['previous_latest_snapshot_source'=>$state['last_snapshots'][$symbol]['quality']['source']??null,'incoming_source'=>$sources[$symbol]??null,'earliest_change'=>$first];
            }
        }
        $related=[];
        foreach($events as $e) {
            $p=$e['payload'];$sym=$p['symbol']??'';
            if(isset($origins[$sym]) && ($p['session']??0)>=$origins[$sym]['earliest_change'] && in_array($e['type'],['order','fill','exit','order_cancelled'],true))
                $related[]=['event_id'=>$e['id'],'type'=>$e['type'],'symbol'=>$sym,'session'=>$p['session']];
        }
        $active=[];foreach($state['active'] as $sym=>$order)if(isset($origins[$sym]))$active[$sym]=['filled'=>$order['filled'],'quantity'=>$order['quantity'],'snapshot_key'=>$order['snapshot_key']];
        return ['schema'=>1,'detected_at'=>$now,'changes'=>$changes,'change_count'=>count($changes),'sources'=>$origins,
            'potentially_affected_snapshots'=>$affected,'related_trade_events'=>$related,'active_orders_or_positions'=>$active,
            'classification'=>$changes?'input_difference':'hash_mismatch_without_row_difference',
            'cause'=>'unconfirmed','impact'=>'potential_only_not_recalculated'];
    }
    public static function read(string $directory,string $hash):array
    {
        if(!preg_match('/^[a-f0-9]{64}$/',$hash))throw new RuntimeException('Invalid report hash');
        $path=$directory.'/inputs/'.$hash.'.data';
        if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('Revision report missing or modified');
        return json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    }
}
