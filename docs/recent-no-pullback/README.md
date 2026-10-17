# 최근 244세션 상승 눌림 제외 계좌 비교

PR97 위에 쌓인 별도 연구다. 운영과 기존 결과를 변경하지 않는다.
기간은 2025-10-10~2026-10-08, 가격은 최근 Yahoo 기반 v2 저장 자료다.
이전 KRX 결과와 가격 기준이 다르며 독립 검증이나 단순 기간 비교로 해석하지 않는다.
기준은 PR76 원조건, 대안은 선택 단계에서 상승 눌림만 제외한다. 회복 확인 조건 완화가 아니다.

## 필요한 자료

`PAPER_STATE_DIR/history-research/kr-dated-amount-top100-20251008-20261008-v2` 전체.
PR76 기준 summary/trades/daily, 후보·섹터 맵은 저장소의 고정 파일을 읽는다.
PR96/97 저널·이전 KRX bars는 실행 입력으로 필요하지 않다.
입력 해시가 다르면 기존 고정값을 갱신하거나 자료를 임의 대체하지 말고 중단한다.

## PowerShell 실행

새 PR을 별도 워크트리에 적용하고 그 폴더에서 실행한다. 기존 미커밋 작업은 그대로 둔다.
첫 실패 또는 정지 후 다음 단계 금지. 코드·PHP·입력이 바뀌면 이어하기를 거절한다.

```powershell
$ErrorActionPreference = 'Stop'
$studyState = 'C:\Users\acdun\Desktop\dev\stock-personal-paper'
function Invoke-StudyPhp {
    param([string[]]$StudyArgs)
    & php @StudyArgs
    if ($LASTEXITCODE -ne 0) { throw "중단: 종료 코드 $LASTEXITCODE" }
}
Invoke-StudyPhp -StudyArgs @('tests/recent_no_pullback_study.php')
Invoke-StudyPhp -StudyArgs @('tests/dated_top100_replay.php')
$studyCommon = @('-d','memory_limit=3G','bin/paper_recent_no_pullback_study.php')
Invoke-StudyPhp -StudyArgs ($studyCommon + @('validate',"--state-dir=$studyState"))
foreach ($step in @('baseline','baseline-check','variant','variant-check')) {
    Invoke-StudyPhp -StudyArgs ($studyCommon + @($step,'--sessions=8',"--state-dir=$studyState"))
}
foreach ($step in @('baseline','baseline-check','variant','variant-check','report')) {
    Invoke-StudyPhp -StudyArgs ($studyCommon + @($step,'--sessions=all','--confirm-full-run=after-code-review',"--state-dir=$studyState"))
}
$studyJournal = "$studyState\history-research\recent-no-pullback-account-v1\variant\account-replay.json"
$studyBefore = (Get-FileHash $studyJournal -Algorithm SHA256).Hash
Invoke-StudyPhp -StudyArgs ($studyCommon + @('variant','--sessions=all','--confirm-full-run=after-code-review',"--state-dir=$studyState"))
if ((Get-FileHash $studyJournal -Algorithm SHA256).Hash -ne $studyBefore) { throw '반복 실행 장부 변경' }
Get-FileHash "$studyState\history-research\recent-no-pullback-account-v1\comparison.json" -Algorithm SHA256
```

## 확인과 전달

- 기준 8세션은 PR76 날짜별 기록 앞부분 전체 필드 일치. 전체는 계좌 ID 외 summary·거래·일별 기록 모두 일치.
- 기준/대안 각각 장부 대사·as-of 주문 감사·분할/일괄 대조, 244세션 완료, 정지 없음.
- 재실행 처리 0, 장부 해시 불변. 대안 주문에 trend_pullback_v1 없음.
- 출력 `history-research/recent-no-pullback-account-v1/comparison.json`과 크기·SHA256·실행 커밋·PHP 버전·종료 코드를 전달.
- 실패하면 로그만 전달한다. 해시·조건·코드·원본을 고쳐 통과시키거나 정지 계좌를 삭제하지 않는다.

`baseline-check`/`variant-check`는 별도 일괄 계좌를 만들어 같은 ID의 정규화 이벤트·상태를 대조한다.
8세션 체크 계좌는 보존하며, 전체 체크는 별도 폴더다. 두 팔 모두 8세션 확인 뒤 전체로 넘어간다.
기준 전체 일치 전 대안 전체 금지. 전체 실데이터 실행은 사용자 PC에서만 수행한다.
수익률은 결과를 받은 뒤 검토하며, 이번 도구 추가로 운영 채택·병합을 승인한 것은 아니다.
