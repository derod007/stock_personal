<?php
declare(strict_types=1);
require_once __DIR__.'/Chrome.php';
function paper_rediagnosis_load(string $state,string $id):?array
{
    if(!preg_match('/^[a-z0-9_-]{1,64}$/',$id))throw new InvalidArgumentException('Invalid account');
    $path=$state.'/rediagnosis/'.$id.'/latest.json';if(!is_file($path))return null;
    $r=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if(($r['kind']??'')!=='saved_audit_rediagnosis'||($r['schema']??0)!==1||($r['account']??'')!==$id||!is_array($r['rows']??null))throw new RuntimeException('Invalid rediagnosis report');
    return $r;
}
function paper_rediagnosis_panel(?array $report,string $id):void
{
    $e=static fn($v)=>htmlspecialchars(paper_status_text((string)$v),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $date=static fn($t)=>$t?(new DateTimeImmutable('@'.$t))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i'):'—';
    echo '<section class="paper-card"><h2>기존 감사 로그 일괄 재진단</h2>';
    echo '<p>프로젝트 폴더에서 실행: <code>php bin/paper_rediagnose.php --account='.$e($id).'</code></p>';
    echo '<p>당시 저장 판정과 현재 코드 재계산을 비교합니다. 당시 코드 버전 재현은 검증되지 않았습니다. 원본·운영 계좌·기존 후속 추적은 보존합니다. 원본 차이는 그대로 표시합니다. 후속 추적은 오래된 앞쪽 봉 보존·거래량 차이 기록을 허용하며 가격/종목 불일치는 차단합니다.</p>';
    if($report===null){echo '<p>아직 재진단 결과가 없습니다. 명령 실행 후 이 화면을 새로고침하세요.</p></section>';return;}
    echo '<p>생성 '.$e($date($report['generated_at'])).' · '.$report['count'].'개 원본 기록 (재실행 포함, 독립 표본 수 아님) · <a href="?account='.rawurlencode($id).'&amp;rediagnosis=json">재진단 JSON 내려받기</a></p>';
    $counts=[];foreach($report['rows'] as $r){$s=$r['history']['status']??$r['status'];$counts[$s]=($counts[$s]??0)+1;}
    echo '<p>'.$e(json_encode($counts,JSON_UNESCAPED_UNICODE)).'</p>';
    foreach($report['errors'] as $error)echo '<p>처리 오류: '.$e(json_encode($error,JSON_UNESCAPED_UNICODE)).'</p>';
    foreach($report['rows'] as $r){
        $old=$r['original']['analysis']['plan']['status']??$r['original']['status'];
        $new=$r['replay']['analysis']['plan']['status']??'재계산 없음';
        echo '<details><summary>'.$e($date($r['session']).' '.$r['name'].' · '.$old.' → '.$new.' · '.$r['replay']['status'].' · 원본 차이 '.$r['history']['status'].' · 추적 '.($r['followup']['status']??'없음')).'</summary>';
        $v=$r['reconstructed_metrics']['volume']??[];
        echo '<p>거래량 기준 평균 '.$e($v['baseline_mean']??'—').' / 눌림 평균 '.$e($v['pullback_mean']??'—').' / 비율 '.$e(isset($v['ratio'])?round($v['ratio']*100,2).'%':'—').' / 기준 구간 0 거래량 '.$e($v['baseline_zero_bars']??'—').'봉</p>';
        if(!empty($r['history']['differences'])){
            echo '<table><thead><tr><th>판정봉 시간</th><th>항목</th><th>저장값</th><th>추적값</th></tr></thead><tbody>';
            foreach($r['history']['differences'] as $d)echo '<tr><td>'.$e($date($d['session'])).'</td><td>'.$e($d['field']).'</td><td>'.$e(is_array($d['saved'])?json_encode($d['saved'],JSON_UNESCAPED_UNICODE):$d['saved']).'</td><td>'.$e($d['tracking']??'누락').'</td></tr>';
            echo '</tbody></table>';
        }
        echo '<details><summary>조건·판정 차이·후속 결과 전체 근거</summary><pre>'.$e(json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</pre></details></details>';
    }
    echo '</section>';
}
