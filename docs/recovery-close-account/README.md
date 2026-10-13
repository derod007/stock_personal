# 커서 로컬 실행 요청

이 브랜치를 별도 작업 공간에서 사용하세요. 최신 작업 내용이 있는 원래 폴더를 초기화하지 마세요.
`docs/work-log.md`와 이 폴더의 `protocol.md`를 먼저 읽으세요.
코드·조건·고정 해시를 수정하거나 자료를 재수집하지 말고 아래 연구 실행만 진행하세요. 병합하지 마세요.
기존 `recovery-close-evidence-v1.json`을 다시 만들 필요 없습니다.

자료 위치: `C:\Users\acdun\Desktop\dev\stock-personal-paper`.
이 아래 `history-research\kr-dated-amount-top100-20251008-20261008-v2`가 있어야 합니다.

## PowerShell

각 명령이 실패하면 이후 단계는 진행하지 마세요. 실패 로그와 해당 검증 JSON만 보고하세요.
전체 실행 전에 8세션의 `books_pass`, `order_audit_pass`가 true이며 정지가 없는지 확인하세요.
기준 전체 실행 후 `baseline_match.pass=true`, `sessions=244`여야 대안으로 넘어갑니다.

```powershell
$env:PAPER_STATE_DIR = 'C:\Users\acdun\Desktop\dev\stock-personal-paper'
function Invoke-StudyPhp {
    & php -d memory_limit=3G @args
    if ($LASTEXITCODE -ne 0) { throw "연구 실행 실패: 종료 코드 $LASTEXITCODE" }
}
Invoke-StudyPhp tests/recovery_close_account.php
Invoke-StudyPhp tests/dated_top100_replay.php
Invoke-StudyPhp tests/opportunity_research.php
Invoke-StudyPhp bin/paper_recovery_close_account.php validate
Invoke-StudyPhp bin/paper_recovery_close_account.php baseline --sessions=8
```

소규모 기준 검증 통과 후:

```powershell
Invoke-StudyPhp bin/paper_recovery_close_account.php baseline --sessions=all --confirm-full-run=after-code-review
```

244세션 기준 대조 통과 후:

```powershell
Invoke-StudyPhp bin/paper_recovery_close_account.php variant --sessions=8
```

소규모 대안 검증 통과 후:

```powershell
Invoke-StudyPhp bin/paper_recovery_close_account.php variant --sessions=all --confirm-full-run=after-code-review
Invoke-StudyPhp bin/paper_recovery_close_account.php report
```

같은 명령을 재실행하면 남은 날짜만 진행합니다. 코드/데이터/설정/PHP 버전이 바뀌어 이어하기가 거부되면
기존 산출물을 삭제하거나 해시를 고치지 말고 중단하세요. 중간 파일도 기존 연구 결과와 섞지 마세요.

## 전달할 결과

`C:\Users\acdun\Desktop\dev\stock-personal-paper\history-research\recovery-close-account-v1\comparison.json`

위 파일과 각 명령 종료 코드, 실행 커밋, PHP 버전, 파일 SHA256을 보내세요.
오류 시 `baseline-verification.json` 또는 `variant-verification.json`과 실패 로그를 보내세요.

보고서는 두 계좌의 자산·낙폭·추천/주문 건수·종료/보유/취소/대기와 주문별 추가/누락/패턴 교체/수량 차이를 포함합니다.
조건 완화가 유리한지 결론 내리거나 운영에 반영하지 마세요. 해석과 후속 결정은 결과 검토 뒤 진행합니다.

## 개발 검증

PHP 8.3.6: 새 테스트 80항목, 기존 dated TOP100 32항목, opportunity 41항목 통과(경고 0).
연구 판정기를 실제 포트폴리오에 연결한 고정 사례에서 주문 생성·장부 대사·분할/일괄 일치·멱등성을 확인했습니다.
전체 933종목 일봉은 개발 환경에 없어 244세션 기준/대안 실행은 미실시입니다.
운영 `src/`, `config/`, 전략 지문, 원본과 기존 연구 결과는 변경하지 않았습니다.
