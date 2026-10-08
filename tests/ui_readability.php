<?php
declare(strict_types=1);
require __DIR__.'/../bin/paper/Chrome.php';
function uiCheck(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
uiCheck(paper_number(null)==='—' && paper_number(0)==='0' && paper_number(48050)==='48,050' && paper_number(3.994)==='3.994','missing values remain distinct from zero and price precision is retained');
uiCheck(paper_status_text('ready → await_confirmation')==='확인됨 → 확인 대기','status transition translated without changing direction');
uiCheck(paper_ko('historical_revision_or_missing')==='과거 자료 변경 또는 누락','ambiguous evidence status retains both possible causes');
uiCheck(paper_ko('unknown_new_code')==='unknown_new_code','unknown status stays inspectable');
$prices=paper_prices(['entry'=>48050,'stop'=>46260,'target'=>55200]);
uiCheck(str_contains($prices,'진입가')&&str_contains($prices,'48,050')&&str_contains($prices,'손절가')&&str_contains($prices,'목표가'),'each frozen level is labelled');
ob_start();paper_open(['title'=>'<script>bad</script>','page'=>'journey','account'=>'paper-kr','mode'=>'forward']);paper_close();$html=ob_get_clean();
uiCheck(!str_contains($html,'<script>bad')&&str_contains($html,'&lt;script&gt;bad'),'page title stays escaped');
uiCheck(str_contains($html,'paper_journey.php?account=paper-kr&amp;mode=forward" aria-current="page"'),'active navigation keeps account and record mode');
uiCheck(str_contains($html,'paper_compare.php?experiment=kr-identity&amp;mode=forward'),'research navigation keeps selected market');
uiCheck(str_contains($html,'assets/readability.js')&&str_contains($html,'assets/readability.css'),'shared screens load presentation assets');
echo "UI_READABILITY_PASS\n";
