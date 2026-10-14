# KRX 기준·대안 연구 계좌 비교

PR #95 위에 쌓인 별도 연구다. [프로토콜](protocol.md)을 먼저 읽는다. 운영 설정/지문/계좌와 이전 Yahoo·KRX 연구 결과는 수정하지 않는다. 실제 전체 가격 자료가 있는 로컬에서 실행한다.

## 전제

- PR95 전체 `history-research/krx-continuity-v1/report.json`의 해시는 `81c38d188ecb0e04ccb83f4c7d6c3a6f0b47ad854a117a99486f06a2af052928`.
- PR94 `history-research/krx_signal_compare_v1/inputs.json` 및 고정된 KRX JSONL 1248개가 필요하다. `--bars`를 생략하면 기존 데이터셋 manifest의 경로다. 없으면 **기존** bars 위치를 명시하고 재수집하지 않는다.
- 전부 같은 커밋·PHP로 진행한다. 코드·해시·설정이 바뀌면 이어 하기를 거부한다.
- 단절/품질 문제로 계좌가 정지하면 종료 코드 1이다. 정상적인 보호 동작일 수 있다. 이후 명령은 실행하지 말고 날짜·종목·사유를 보고한다. 장부를 지우거나 데이터를 채워서 재시작하지 않는다.

## 실행 순서

별도 워크트리에서 아래 테스트를 먼저 실행해 둘 다 종료 코드 0인지 확인한다.

```powershell
php tests/krx_account_study.php
php tests/krx_continuity_review.php
```

다음 PowerShell은 각 명령이 실패하면 즉시 중단한다. 8세션은 처음부터 누적 8세션이다. 같은 8 명령을 다시 실행해도 다음 8세션을 추가하지 않는다. `-check`는 별도 장부에서 같은 기간을 일괄 실행해 대조한다.

```powershell
$state = 'C:\Users\acdun\Desktop\dev\stock-personal-paper'
function Invoke-KrxStudy([string]$command, [string]$sessions = '8') {
    $runArgs = @('-d', 'memory_limit=3G', 'bin/paper_krx_account_study.php', $command, "--state-dir=$state", "--sessions=$sessions")
    if ($sessions -eq 'all') { $runArgs += '--confirm-full-run=after-code-review' }
    & php @runArgs
    if ($LASTEXITCODE -ne 0) { throw "실행 중단: $command / $sessions / exit=$LASTEXITCODE" }
}
Invoke-KrxStudy validate
Invoke-KrxStudy baseline
Invoke-KrxStudy baseline-check
Invoke-KrxStudy variant
Invoke-KrxStudy variant-check
```

8세션 양쪽 장부·주문 대사 및 일괄 대조 통과 후 아래를 실행한다. 코드는 양쪽 8세션 check 장부가 없으면 전체 실행을 거부한다.

```powershell
Invoke-KrxStudy baseline all
Invoke-KrxStudy baseline-check all
Invoke-KrxStudy variant all
Invoke-KrxStudy variant-check all
Invoke-KrxStudy report all
```

재실행 무쓰기 확인은 마지막에 `Invoke-KrxStudy variant all`을 다시 실행하여 `processed_sessions=0` 및 variant/account-replay.json의 크기·mtime·해시를 비교한다. 새 lock 파일은 최초 실행 때만 생긴다. 보고서 내용이 같으면 다시 쓰지 않는다.

## 출력

모든 새 파일은 `PAPER_STATE_DIR/history-research/krx-continuity-account-v1/`에 저장한다.

- `inputs.json`: 실행 코드/설정/PHP/원 보고서 고정
- `baseline/`, `variant/`: 연구 계좌 장부
- `baseline-check-8/`, `variant-check-8/`, `baseline-check-243/`, `variant-check-243/`: 별도 일괄 대조 장부
- `<명령>-<8 또는 243>-verification.json`: 장부/주문 검증, 정지 사유
- `<명령>-<8 또는 243>-trades.json`: 주문·체결·청산·미완료 내역
- `comparison.json`: 양쪽 243세션 완료·대조 후에만 생성

정지일에는 체결·평가를 하지 않으므로 요약은 **직전 유효 세션**까지다. 정지 시점의 수익률이나 연간 완성 성과로 표시하지 않는다. 보유 기간 말 평가와 미완료는 강제 청산하지 않는다.

## 개발 환경 검증 범위

PHP 8.3.6에서 새 시험 28개: 실제 엔진 주문, 두 팔 as-of 대조, 기존 dated replay와 무위험 사례의 장부 일치, 분할/일괄 일치, 동일/정지 재실행 무쓰기, 보유·대기 단절/누락/0가격, 이탈 종목 관리, 부분 체결 방지, 미래/무관 단절 무영향, 변조 거부.

전체 KRX 원본/JSONL은 이 환경에 없어 실데이터 8/243세션을 실행하지 않았다. 실제 성과 수치는 없다. 원본 검증과 제한된 가격 연속성 확인을 바탕으로 한 탐색 연구이며, 수정 기준과 모든 기업행사/장중 경로를 해결한 검증이 아니다.
