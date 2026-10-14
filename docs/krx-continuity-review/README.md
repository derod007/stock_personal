# 가격 단절 전 근거 사용 검사

PR #94 위에 쌓은 연구 전용 도구다. 원자료와 이전 결과를 바꾸지 않는다. 보류 기록은 운영 필터가 아니며 계좌 실행을 허용하는 판정도 아니다. 정의는 [protocol.md](protocol.md).

## 로컬 전체 실행

이 브랜치를 별도 워크트리에 적용한다. 기존 PR94 `inputs.json`, `report-243.json`, `symbols/*.json` 및 KRX JSONL이 필요하다. `--bars` 생략 시 기존 데이터셋 manifest에 저장된 경로를 사용한다. 경로가 없으면 실제 기존 bars 폴더를 명시한다. 재수집하지 않는다.

```powershell
php tests/krx_continuity_review.php
php tests/krx_signal_compare.php
php -d memory_limit=3G bin/paper_krx_continuity_review.php --state-dir="C:\Users\acdun\Desktop\dev\stock-personal-paper"
```

출력: `PAPER_STATE_DIR/history-research/krx-continuity-v1/report.json`. 결과를 다시 만드는 명령은 같으며, 내용이 같으면 쓰기 없이 종료한다. 결과/코드가 바뀌었으면 기존 파일을 덮어쓰지 말고 새 `--output` 경로를 지정한다. 해시 실패 시 임의로 고정 해시를 바꾸지 말고 중단한다.

`complete=true`는 243세션의 기존 결과 24,300쌍 무결성과 전체 품질 통과 추천 합집합의 근거 검사를 마쳤다는 뜻이다. 계좌·수익성·가격 조정 기준을 검증했다는 뜻이 아니다. `hold_unresolved_provenance`가 나오면 원인과 사례를 검토하고 통과 처리하지 않는다.

## 첨부 8종목 재현

별도 `--source`, `--bars`, `--report`, `--output`, `--symbols`를 지정한다. ZIP은 경로 구분자를 `/`로 정규화해 풀되 원파일 내용은 바꾸지 않는다.

```text
php -d memory_limit=3G bin/paper_krx_continuity_review.php --source=<압축해제/history-research/krx_signal_compare_v1> --bars=<압축해제/krx-tdd-dataset-20231013-20251002/bars> --report=<report-243.json> --output=<새경로/report.json> --symbols=278470.KS,437730.KQ,086280.KS,314930.KQ,089850.KQ,430690.KQ,408900.KQ,462860.KQ
```

부분 검사에서는 `complete=false`이며 전체 results 해시는 대조하지 않는다. 해당 종목의 저장 결과 payload 해시, 입력 JSONL 해시, 보고서의 해당 추천 내용은 대조한다. 검사는 기준/대안 품질 통과 추천 합집합을 재계산하며, 비추천 전체를 엔진 재실행하지는 않는다.

## 현재 검증 범위

- PHP 8.3.6 로컬: 새 시험 21개, 기존 KRX 비교 37개, 기존 recovery_close 계좌 시험 통과.
- 첨부 8종목: 저장 결과 331쌍 해시·날짜 확인, 품질 통과 합집합 9쌍 as-of 엔진 재계산 일치.
- 기준: 원추천 5, 단절 교차 보류 2, 추적 범위 교차 없음 3.
- 대안: 원추천 9, 단절 교차 보류 2, 추적 범위 교차 없음 7.
- 두 팔의 보류는 같은 에이피알(278470.KS, 2025-05-09), 삼현(437730.KQ, 2025-09-09)이다. 각각 2024-10-14 고가 274500, 2024-06-26 고가 27050을 목표 근거로 썼으며 단절 이전이다. 단절 원인은 미확정으로 유지한다.
- 전체 201쌍의 검사 결과는 아직 없다. 전체 데이터가 있는 로컬에서 위 명령을 실행해야 한다.

8종목 검증 요약은 [review-8-summary.json](review-8-summary.json). 전체 가격·원본·인증키는 넣지 않는다.
