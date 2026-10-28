<?php
// Offline stdin JSONL only. No operating store or network calls.
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
foreach(['Classifier','Tracker'] as $f)require __DIR__.'/../bin/type1/'.$f.'.php';
$c=new \ChartEntryLab\Type1Scan\Classifier(__DIR__.'/../data/type1/centers.json');$state=\ChartEntryLab\Type1Scan\Tracker::empty();
while(($line=fgets(STDIN))!==false){$state=\ChartEntryLab\Type1Scan\Tracker::advance($state,json_decode($line,true,512,JSON_THROW_ON_ERROR),$c);}
echo json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
