<?php
declare(strict_types=1);
require __DIR__.'/v3.php';
use ChartEntryLab\RecoveryGate;
check(!RecoveryGate::passes([]),'Recovery needs two completed bars');
check(!RecoveryGate::passes([['high'=>100,'close'=>99],['high'=>102,'close'=>100]]),'Equal previous high is not recovery');
check(RecoveryGate::passes([['high'=>100,'close'=>99],['high'=>102,'close'=>101]]),'Close above previous high passes');
check(!RecoveryGate::passes([['high'=>100,'close'=>99],['high'=>102,'close'=>99]]),'Intraday high alone does not confirm');
echo 'V4 PASS '.$checks.' total checks'.PHP_EOL;
