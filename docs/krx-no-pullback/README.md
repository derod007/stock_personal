# 상승 눌림 제외 계좌 연구 실행

PR96 위 별도 PR. 원조건 baseline 대 눌림 제외 variant이며 recovery_close 완화는 쓰지 않는다.
운영과 기존 계좌를 바꾸지 않는다. 실제 전체 데이터는 사용자 PC에 있으므로 아래 순서로 실행한다.

필수 기존 자료:
- PR96 `history-research/krx-continuity-account-v1/baseline/account-replay.json`
- PR95 `history-research/krx-continuity-v1/report.json`
- PR94 `history-research/krx_signal_compare_v1/inputs.json`
- 준비+평가 KRX 원본 JSONL 1248개(기존 manifest의 bars 경로)

ZIP의 64종목만으로 계좌를 실행하지 않는다. `validate`는 고정 해시·입력을 읽기만 한다.
경로가 다르면 `--bars=실제 bars 폴더`를 모든 명령에 함께 전달한다. 임의 대체·해시 갱신 금지.

아래는 PR 브랜치를 별도 워크트리에 적용한 뒤 그 폴더에서 PowerShell로 실행한다.
실패하면 다음 단계는 실행하지 않는다. PHP 버전·코드·입력이 바뀌면 기존 새 계좌에 이어갈 수 없다.

```powershell
$ErrorActionPreference = 'Stop'
$studyState = 'C:\Users\acdun\Desktop\dev\stock-personal-paper'
function Invoke-StudyPhp {
    param([string[]]$StudyArgs)
    & php @StudyArgs
    if ($LASTEXITCODE -ne 0) { throw "중단: 종료 코드 $LASTEXITCODE" }
}
Invoke-StudyPhp -StudyArgs @('tests/no_pullback_study.php')
Invoke-StudyPhp -StudyArgs @('tests/krx_continuity_review.php')
Invoke-StudyPhp -StudyArgs @('tests/dated_top100_replay.php')
$studyCommon = @('-d','memory_limit=3G','bin/paper_no_pullback_study.php')
Invoke-StudyPhp -StudyArgs ($studyCommon + @('validate',"--state-dir=$studyState"))
foreach ($step in @('baseline','baseline-check','variant','variant-check')) {
    Invoke-StudyPhp -StudyArgs ($studyCommon + @($step,'--sessions=8',"--state-dir=$studyState"))
}
foreach ($step in @('baseline','baseline-check','variant','variant-check','report')) {
    Invoke-StudyPhp -StudyArgs ($studyCommon + @($step,'--sessions=all','--confirm-full-run=after-code-review',"--state-dir=$studyState"))
}
# 같은 명령 반복: 새 세션이 없으므로 장부 변경 없이 처리 0이어야 한다.
Invoke-StudyPhp -StudyArgs ($studyCommon + @('variant','--sessions=all','--confirm-full-run=after-code-review',"--state-dir=$studyState"))
Get-FileHash "$studyState\history-research\krx-no-pullback-account-v1\comparison.json" -Algorithm SHA256
```

보고 위치는 `PAPER_STATE_DIR/history-research/krx-no-pullback-account-v1/comparison.json`.
양쪽 243세션, 정지 없음, 장부 대사, 분할/일괄 대조, 기준의 `baseline_match.pass=true`를 확인한다.
보고서의 label/kind로 PR96/회복 확인 완화와 구분한다. 대안 signals/order_changes에 눌림 주문이 없어야 한다.
실패·정지 시 종료 코드·날짜·종목·사유만 보고하고 저널을 삭제·보정하거나 조건을 바꾸지 않는다.
수익이 좋아져도 운영 적용·병합은 별도 검토다. 전달할 것은 comparison.json, 크기·SHA256·실행 커밋·PHP 버전이다.

## 개발 검증
새 통합 테스트는 PR96의 31개 안전 검사를 포함한다. 선언된 엔진 선택 변경 외 차이,
원조건 일치, 눌림 제거·다른 패턴 유지·미래봉 차단, 새 회복 추천 및 단절 보류,
계좌 대사/분할/일괄/반복 무쓰기, 코드·입력 변경 거부, 기준 현금 변조를 확인한다.
로컬 부분 원본만으로 8/243세션 성과를 계산했다고 주장하지 않는다.
