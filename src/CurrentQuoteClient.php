<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Request-time quote, separate from historical OHLC and replay inputs. No disk cache fallback. */
final class CurrentQuoteClient
{
    public function __construct(private readonly ?\Closure $transport=null) {}

    public function fetch(string $symbol):array
    {
        $base=['symbol'=>$symbol,'price'=>null,'source'=>null,'quoted_at'=>null,'fetched_at'=>date(DATE_ATOM),'status'=>'unavailable'];
        $code=NaverDailyQuotes::codeOf($symbol);
        if($code!==null){
            try{
                $d=$this->get('https://finance.daum.net/api/quotes/A'.$code);
                $id=(string)($d['symbolCode']??$d['code']??'');
                if(!in_array($id,[$code,'A'.$code],true))throw new \RuntimeException('Quote symbol mismatch');
                $price=$this->positive($d['tradePrice']??null);
                if($price===null)throw new \RuntimeException('Missing current quote');
                return array_replace($base,['price'=>$price,'source'=>'Daum 현재 시세','status'=>'available']);
            }catch(\Throwable){}
        }
        try{
            $d=$this->get('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol).'?interval=1d&range=1d&includePrePost=true');
            $meta=$d['chart']['result'][0]['meta']??[];
            if(strtoupper((string)($meta['symbol']??''))!==strtoupper($symbol))throw new \RuntimeException('Quote symbol mismatch');
            $price=null;$at=0;$session='regular';
            foreach(['regular','pre','post'] as $s){
                $p=$this->positive($meta[$s.'MarketPrice']??null);$t=$meta[$s.'MarketTime']??null;
                if($p!==null&&is_numeric($t)&&(int)$t>$at){$price=$p;$at=(int)$t;$session=$s;}
            }
            if($price===null)throw new \RuntimeException('No timestamped quote');
            return array_replace($base,['price'=>$price,'source'=>'Yahoo '.$session.' 시세(지연 가능)','quoted_at'=>date(DATE_ATOM,$at),'status'=>'available']);
        }catch(\Throwable){return $base;}
    }
    /** Fresh regular-session OHLCV. Missing fields/dates never become synthetic candles. */
    public function daily(string $symbol,?int $asOf=null):?array
    {
        $kr=NaverDailyQuotes::codeOf($symbol);
        if($kr!==null){
            try{
                $d=$this->get('https://finance.daum.net/api/quotes/A'.$kr);
                if(!in_array((string)($d['symbolCode']??$d['code']??''),[$kr,'A'.$kr],true))throw new \RuntimeException('Wrong symbol');
                if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($d['date']??''))
                    ||!preg_match('/^\d{2}:\d{2}:\d{2}$/',(string)($d['time']??'')))throw new \RuntimeException('Missing quote date/time');
                $at=(new \DateTimeImmutable($d['date'].' '.$d['time'],new \DateTimeZone('Asia/Seoul')))->getTimestamp();
                $bar=['time'=>$at,'open'=>$d['openingPrice']??null,'high'=>$d['highPrice']??null,
                    'low'=>$d['lowPrice']??null,'close'=>$d['tradePrice']??null,'volume'=>$d['accTradeVolume']??null,
                    'observed_at'=>$at,'source'=>'Daum'];
                if(IntradayAnalysis::validBar($bar,$symbol,$asOf??time()))return $bar;
            }catch(\Throwable){}
        }
        try{
            $d=$this->get('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol).'?interval=1d&range=5d');
            $r=$d['chart']['result'][0]??[];$m=$r['meta']??[];
            if(strtoupper((string)($m['symbol']??''))!==strtoupper($symbol))return null;
            $ts=$r['timestamp']??[];$q=$r['indicators']['quote'][0]??[];
            if(!$ts)return null;$i=array_key_last($ts);
            $bar=['time'=>$ts[$i],'observed_at'=>$m['regularMarketTime']??null,'source'=>'Yahoo'];
            foreach(['open','high','low','close','volume'] as $key)$bar[$key]=$q[$key][$i]??null;
            return IntradayAnalysis::validBar($bar,$symbol,$asOf??time())?$bar:null;
        }catch(\Throwable){return null;}
    }

    private function positive(mixed $v):?float
    {
        return is_numeric($v)&&is_finite((float)$v)&&(float)$v>0?(float)$v:null;
    }
    private function get(string $url):array
    {
        if($this->transport!==null)return ($this->transport)($url);
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>4,
            CURLOPT_ENCODING=>'',CURLOPT_HTTPHEADER=>['User-Agent: Mozilla/5.0','Accept: application/json','Cache-Control: no-cache','Referer: https://finance.daum.net/']]);
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if(!is_string($body)||$status!==200)throw new \RuntimeException('Quote request failed');
        $data=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new \RuntimeException('Invalid quote payload');
        return $data;
    }
}
