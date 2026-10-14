# KRX 원가격 기준·대안 추천 비교 실행

PR #93 수정 커밋 `9103bbbc1126e39ed9f4c00a49de6a610c468179` 기반. 이 PR은 #93 브랜치를 대상으로 쌓았다. 상위 PR들을 병합하지 않았으며 운영 전략/계좌를 변경하지 않는다.

## 이번에 하는 일

KRX 원가격을 기존 엔진과 `recovery_close` 하나를 제외한 엔진에 똑같이 넣어 **신규 추천만** 비교한다. 수익률·체결·보유·대기·섹터/현금 한도 처리는 없다. 전체 243세션 결과도 계좌 실행 승인이 아니다. 위험한 가격 단절을 자동으로 필터링하거나 보정하지 않는다. 정책을 정하기 위한 진단 자료다.

- `baseline_ready` / `variant_ready`: 공통 위험·추세를 포함한 엔진 자체 ready.
- `*_quality_eligible`: 위 ready이고 기존 PaperQuality까지 통과. 실제 매수 가능성이나 기업행사 안전성은 의미하지 않는다.
- `transitions`: 위 품질 포함 상태의 0→1·1→0·1→1·0→0. 반복 추천을 거래로 세지 않는다.
- `eligible_with_break_history`: 품질을 통과해도 단절 경고가 과거 입력에 있는 수.
- `plans_differ`: 준비 여부뿐 아니라 진입·손절·목표·독립 패턴 진단 등 plan 차이.
- `review_cases`: 어느 쪽이든 ready이거나 plan이 달라진 사례. 자세한 날짜 원인은 `symbols/{symbol}.json`.

## 입력과 경로

원본 JSON·jsonl은 로컬에 있어야 한다. 인터넷 요청 없음. PR #92 manifest에 기록된 다음 경로를 기본으로 읽는다(폴더 위치가 다르면 옵션으로 지정).

- `--bars`: `C:\Users\acdun\Desktop\dev\noramu-work-krx-tdd-dataset\data\raw\research\krx-tdd-dataset-20231013-20251002\bars`
- `--prep-raw`: `C:\Users\acdun\Desktop\dev\noramu-work-krx-prep-collect\data\raw\research\krx-prep-bars-before-20241008\raw`
- `--eval-raw`: `C:\Users\acdun\Desktop\dev\noramu-work-top100-202410\data\raw\research\historical-amount-top100-20241008-20251002\raw`

매 실행마다 원본 966개 해시와 모든 JSONL 사용 필드를 원본과 대조한다. manifest에 해시만 붙어 있다고 원가격 일치를 가정하지 않는다. RAW가 없으면 JSONL만으로 우회 실행하지 않는다. 경로 누락은 어떤 옵션을 지정할지 오류에 표시한다.

`--state-dir`는 기존 `stock-personal-paper`를 지정한다. 출력은 그 아래 새 `history-research/krx_signal_compare_v1/`뿐이다. 운영 계좌 파일은 읽거나 쓰지 않는다. 기본 `PAPER_STATE_DIR` 또는 기존 연구의 stateRoot도 사용 가능하다.

## PowerShell — 먼저 테스트·검증·8세션

이 PR의 별도 작업 공간에서 실행한다. 기존 미커밋 파일을 덮지 않는다. 아래 경로가 다르면 실제 위치로 수정한다. 각 단계가 0이 아니면 멈추고 오류 원문을 전달한다.

```powershell
php tests/krx_signal_compare.php
if ($LASTEXITCODE -ne 0) { throw '오프라인 테스트 실패' }

$krxArgs = @(
  '--state-dir=C:\Users\acdun\Desktop\dev\stock-personal-paper'
)
php -d memory_limit=1G bin/paper_krx_signal_compare.php validate @krxArgs
if ($LASTEXITCODE -ne 0) { throw '원본 대조 실패' }

php -d memory_limit=1G bin/paper_krx_signal_compare.php run --sessions=8 @krxArgs
if ($LASTEXITCODE -ne 0) { throw '8세션 실패' }
php -d memory_limit=1G bin/paper_krx_signal_compare.php report --sessions=8 @krxArgs
if ($LASTEXITCODE -ne 0) { throw '8세션 보고 실패' }
```

먼저 `report-8.json`과 종료 코드를 전달한다. `summary.pairs=800`, `sessions=8`이어야 한다. 엔진 40봉 미달은 정상적으로 별도 기록하므로 mirror_checked가 항상 800일 필요는 없다. 실제 자료의 숫자를 미리 맞추지 않는다.

이동한 입력 경로는 예를 들어 `$krxArgs`에 `'--bars=D:\...\bars'`, `'--prep-raw=D:\...\raw'`, `'--eval-raw=D:\...\raw'`를 추가한다. 인증키는 필요 없다.

## 8세션 검토 후 전체 확장

```powershell
php -d memory_limit=1G bin/paper_krx_signal_compare.php run --sessions=all @krxArgs
if ($LASTEXITCODE -ne 0) { throw '전체 신규 추천 비교 실패' }
php -d memory_limit=1G bin/paper_krx_signal_compare.php report --sessions=all @krxArgs
if ($LASTEXITCODE -ne 0) { throw '전체 보고 실패' }
```

`--sessions`는 추가 처리 횟수가 아니라 첫 N세션의 누적 범위다. 전체 이후 같은 실행은 `processed_pairs=0`. 8세션 보고서는 보존하고 `report-243.json`을 새로 쓴다. 부분 실행에서 중단되면 같은 명령으로 이어 갈 수 있다. 243 이후 8로 줄이는 실행은 거부한다. 입력·코드·PHP 버전이 바뀌면 같은 출력 폴더로 이어 갈 수 없다. 해시를 고쳐 통과시키거나 기존 결과를 삭제하지 말고 보고한다.

## 검증 범위

여기서 실제 KRX 원본 전체 실행은 하지 못했다(사용자 PC에만 있음). PHP 8.3.6 오프라인 신규 37개 검사와 기존 recovery_close_account, dated_top100_replay, Python overlap 13개 검사가 통과했다. 실제 사용 PHP 8.2에서도 로컬 테스트 후 실행한다. GitHub CI 결과는 별도 확인 대상이다.

검사: 원본/JSONL 가격·해시 변조, 이전 시장 연결, 날짜/종목 대조, 중복 원본 거부, 300봉 유지, 0가격과 내부 공백, 미래봉·미래 단절 영향 없음, baseline 미러 일치, 단일 조건 실패 사례, 분할 실행 동등성·반복 0건·입력 변경 거부. 진단이므로 수익성/실제 체결 가능성/가격 조정 기준은 검증하지 않았다.
