# 커서 실행 요청: 이전 243거래일 비교

이 PR 브랜치를 **별도 작업 공간**에서 사용한다. #84와 #85를 함께 포함하며 main 병합은 하지 않는다.
최신 작업이 있는 원래 폴더를 초기화하지 않는다. work-log와 protocol.md를 읽고 코드/조건/고정 해시를 변경하지 않는다.

필수 로컬 자료:
- `C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\kr-dated-amount-top100-20241008-20251002-v2`
- `C:\Users\acdun\Desktop\dev\stock-personal-paper\history-account\hist-rc-account-v1-baseline\account-replay.json` (최근 기간 기준, 읽기 전용)

기존 `paper_recovery_close_account.php`는 최근 기간 전용 그대로다. 이번에는 **prior** 명령을 사용한다.

```powershell
$env:PAPER_STATE_DIR = 'C:\Users\acdun\Desktop\dev\stock-personal-paper'
function Invoke-StudyPhp {
    & php -d memory_limit=3G @args
    if ($LASTEXITCODE -ne 0) { throw "연구 실행 실패: 종료 코드 $LASTEXITCODE" }
}
Invoke-StudyPhp tests/recovery_close_prior_account.php
Invoke-StudyPhp bin/paper_recovery_close_prior_account.php validate
Invoke-StudyPhp bin/paper_recovery_close_prior_account.php baseline --sessions=8
```

소규모 `verification_pass=true`, `run.halted=false` 확인 후:

```powershell
Invoke-StudyPhp bin/paper_recovery_close_prior_account.php baseline --sessions=all --confirm-full-run=after-code-review
Invoke-StudyPhp bin/paper_recovery_close_prior_account.php baseline-check --sessions=all --confirm-full-run=after-code-review
```

기준 `complete=true`, 기준 체크 `split_check.pass=true`, `sessions=243` 확인 후:

```powershell
Invoke-StudyPhp bin/paper_recovery_close_prior_account.php variant --sessions=8
```

소규모 검증과 정지 없음 확인 후:

```powershell
Invoke-StudyPhp bin/paper_recovery_close_prior_account.php variant --sessions=all --confirm-full-run=after-code-review
Invoke-StudyPhp bin/paper_recovery_close_prior_account.php variant-check --sessions=all --confirm-full-run=after-code-review
Invoke-StudyPhp bin/paper_recovery_close_prior_account.php report
```

`*-check`는 별도 검사용 경로에서 전체 기간을 단일 트랜잭션으로 계산한다. 기준/대안 각각 두 번 계산하므로 실행 시간이 늘어난다.
중단·오류가 나면 다음 명령을 실행하지 말고 해당 로그를 전달한다. 정지 날짜나 실패 종목을 임의 제외하지 않는다.
이어하기 거부 시 기존 계좌/inputs.json을 삭제하거나 해시를 바꾸지 않는다. 자료를 재수집하지 않는다.

결과는 아래 파일 하나와 실행 커밋·PHP 버전·종료 코드·크기·SHA256을 전달한다.

`C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\recovery-close-prior-account-v1\comparison.json`

수익성 결론·조건 조정·운영 반영·병합은 하지 않는다.

## 개발 검증

PHP 8.3.6에서 최근 회귀 80 + 이전 기간 검사 30항목 통과(경고 0).
243개 실제 달력 날짜에 합성 가격을 사용하여 실제 엔진/포트폴리오로 분할/일괄 일치, 멱등, 장부·주문 대사를 검사했다.
이 검사는 실제 1248종목 수익률 재현이 아니다. 실제 전체 계좌 실행은 사용자 로컬 자료로 진행해야 한다.
