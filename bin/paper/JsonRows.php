<?php
declare(strict_types=1);

/** Iterate one top-level collection without decoding the complete report into PHP arrays. */
final class PaperJsonRows
{
    private $file;
    private string $buffer='';
    private int $offset=0;
    private function __construct(string $path)
    {
        $this->file=fopen($path,'rb');if(!$this->file)throw new RuntimeException('Cannot open JSON report');
    }
    private function peek():string
    {
        if($this->offset>=strlen($this->buffer)){
            $this->buffer=fread($this->file,65536);$this->offset=0;
            if($this->buffer===false)throw new RuntimeException('Cannot read JSON report');
        }
        return $this->buffer[$this->offset]??'';
    }
    private function take():string{$c=$this->peek();if($c!=='')$this->offset++;return $c;}
    private function space():void{while(($c=$this->peek())!==''&&str_contains(" \r\n\t",$c))$this->take();}
    private function expect(string $c):void{$this->space();if($this->take()!==$c)throw new RuntimeException('Invalid JSON delimiter');}
    private function value():mixed
    {
        $this->space();$bytes='';$depth=0;$quoted=false;$escaped=false;
        while(($c=$this->peek())!==''){
            if(!$quoted&&$depth===0&&($c===','||$c===']'||$c==='}'||str_contains(" \r\n\t",$c)))break;
            $this->take();$bytes.=$c;
            if($quoted){if($escaped)$escaped=false;elseif($c==='\\')$escaped=true;elseif($c==='"')$quoted=false;}
            elseif($c==='"')$quoted=true;
            elseif($c==='['||$c==='{')$depth++;
            elseif($c===']'||$c==='}')$depth--;
            if(!$quoted&&$depth===0&&($c==='"'||$c===']'||$c==='}'))break;
        }
        return json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
    }
    /** Yields key => row. getReturn() contains all other top-level fields. */
    public static function read(string $path,string $field='rows'):Generator
    {
        $p=new self($path);$meta=[];$seen=[];
        try{
            $p->expect('{');$p->space();
            if($p->peek()!=='}')while(true){
                $key=$p->value();if(!is_string($key)||isset($seen[$key]))throw new RuntimeException('Invalid or duplicate JSON key');$seen[$key]=true;
                $p->expect(':');
                if($key!==$field)$meta[$key]=$p->value();
                else{
                    $p->space();$open=$p->take();if(!in_array($open,['{','['],true))throw new RuntimeException('Invalid JSON rows');
                    $close=$open==='{'?'}':']';$p->space();$i=0;$rowKeys=[];
                    if($p->peek()!==$close)while(true){
                        $rowKey=$open==='{'?$p->value():$i++;
                        if(!is_string($rowKey)&&!is_int($rowKey))throw new RuntimeException('Invalid row key');
                        if(isset($rowKeys[$rowKey]))throw new RuntimeException('Duplicate row key');$rowKeys[$rowKey]=true;
                        if($open==='{')$p->expect(':');
                        $row=$p->value();if(!is_array($row))throw new RuntimeException('Invalid JSON row');
                        yield $rowKey=>$row;unset($row);
                        $p->space();if($p->peek()===$close)break;$p->expect(',');
                    }
                    $p->expect($close);
                }
                $p->space();if($p->peek()==='}')break;$p->expect(',');
            }
            $p->expect('}');$p->space();if($p->peek()!=='')throw new RuntimeException('Trailing JSON data');
            if(!isset($seen[$field]))throw new RuntimeException('Missing JSON rows');
            return $meta;
        }finally{fclose($p->file);}
    }
}
